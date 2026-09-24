<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Infrastructure\FFmpeg;
use App\Infrastructure\ProcessRunner;
use RuntimeException;

/**
 * Servicio OCR para subtítulos de imagen (VobSub/DVD, PGS/Blu-ray).
 *
 * Pipeline:
 *   1. FFmpeg extrae el stream de imagen a un directorio de PNGs con timestamps.
 *   2. Tesseract lee cada imagen y devuelve el texto reconocido.
 *   3. Se construye un archivo SRT con los timestamps de FFmpeg.
 *
 * Requiere en el sistema:
 *   - tesseract-ocr  (sudo apt install tesseract-ocr tesseract-ocr-eng tesseract-ocr-spa)
 *   - imagemagick    (sudo apt install imagemagick)  — para preprocesar imágenes PGS RGBA
 */
final class OcrService
{
    /** Codecs de imagen soportados. */
    public const IMAGE_CODECS = [
        'dvd_subtitle',          // VobSub (.sub/.idx) — MP4/MKV
        'hdmv_pgs_subtitle',     // PGS — Blu-ray MKV
        'dvbsub',                // DVB subtitles
        'xsub',                  // DivX subtitles
    ];

    public function __construct(
        private readonly FFmpeg $ffmpeg,
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * Comprueba si Tesseract está disponible en el sistema.
     */
    public function available(): bool
    {
        [$code] = $this->runner->run(['tesseract', '--version'], 5);
        return $code === 0;
    }

    /**
     * Devuelve la versión de Tesseract o null si no está instalado.
     */
    public function version(): ?string
    {
        [$code, $stdout] = $this->runner->run(['tesseract', '--version'], 5);
        if ($code !== 0) {
            return null;
        }

        if (preg_match('/tesseract\s+([\d.]+)/i', $stdout, $m)) {
            return $m[1];
        }

        return 'desconocida';
    }

    /**
     * Devuelve los idiomas Tesseract instalados.
     *
     * @return string[]
     */
    public function availableLanguages(): array
    {
        [$code, $stdout] = $this->runner->run(['tesseract', '--list-langs'], 5);
        if ($code !== 0) {
            return [];
        }

        $lines = array_filter(
            array_map('trim', explode("\n", $stdout)),
            fn (string $l) => $l !== '' && ! str_starts_with($l, 'List of')
        );

        return array_values($lines);
    }

    /**
     * Realiza OCR de un stream de subtítulos de imagen dentro de un video.
     *
     * Extrae todas las imágenes del stream indicado y aplica Tesseract,
     * produciendo un string SRT completo.
     *
     * @param  string    $videoPath     Ruta al archivo de video.
     * @param  int       $streamIndex   Índice del stream de subtítulos en el contenedor.
     * @param  string    $sourceCodec   Codec del stream ('dvd_subtitle', 'hdmv_pgs_subtitle', …)
     * @param  string    $ocrLang       Idioma Tesseract (ej: 'eng', 'spa', 'eng+spa').
     * @param  callable|null $onProgress  fn(int $done, int $total) — callback de progreso.
     * @return string                   Contenido SRT.
     */
    /**
     * Realiza OCR de un stream de subtítulos de imagen dentro de un video.
     *
     * @param  string    $videoPath     Ruta al archivo de video.
     * @param  int       $streamIndex   Índice del stream de subtítulos en el contenedor.
     * @param  string    $sourceCodec   Codec del stream ('dvd_subtitle', 'hdmv_pgs_subtitle', …)
     * @param  string    $ocrLang       Idioma Tesseract (ej: 'eng', 'spa', 'eng+spa').
     * @param  callable|null $onProgress  fn(int $done, int $total) — callback de progreso.
     * @return string                   Contenido SRT.
     */
    public function extractAndOcr(
        string $videoPath,
        int $streamIndex,
        string $sourceCodec,
        string $ocrLang = 'eng',
        ?callable $onProgress = null,
    ): string {
        if (! $this->available()) {
            throw new RuntimeException(
                'Tesseract OCR no está instalado. ' .
                'Instálalo con: sudo apt install tesseract-ocr tesseract-ocr-eng tesseract-ocr-spa'
            );
        }

        $vobsubBin = base_path('bin/vobsub_ocr');
        if (! is_executable($vobsubBin)) {
            chmod($vobsubBin, 0755);
        }

        $tmpSrt = tempnam(sys_get_temp_dir(), 'ocr_out_') . '.srt';
        @unlink($tmpSrt);

        $ffmpegBin = (string) config('binaries.ffmpeg', 'ffmpeg');

        $cmd = [
            $vobsubBin,
            $videoPath,
            (string) $streamIndex,
            $sourceCodec,
            $ocrLang,
            $tmpSrt,
            $ffmpegBin,
        ];

        $commandStr = implode(' ', array_map(fn (string $a) => escapeshellarg($a), $cmd));

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($commandStr, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (! is_resource($proc)) {
            throw new RuntimeException('No se pudo iniciar el proceso de OCR: ' . $commandStr);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderrBuffer = '';
        $lastDone = 0;

        while (true) {
            $errChunk = stream_get_contents($pipes[2]);
            if ($errChunk !== false && $errChunk !== '') {
                $stderrBuffer .= $errChunk;
                while (($pos = strpos($stderrBuffer, "\n")) !== false) {
                    $line = substr($stderrBuffer, 0, $pos);
                    $stderrBuffer = substr($stderrBuffer, $pos + 1);

                    if (preg_match('/PROGRESS:(\d+)\/(\d+)/', $line, $matches)) {
                        $done = (int) $matches[1];
                        $total = (int) $matches[2];
                        if ($onProgress !== null && $done !== $lastDone) {
                            $onProgress($done, $total);
                            $lastDone = $done;
                        }
                    }
                }
            }

            $status = proc_get_status($proc);
            if (! $status['running']) {
                break;
            }
            usleep(50_000);
        }

        $errChunk = stream_get_contents($pipes[2]);
        if ($errChunk !== false && $errChunk !== '') {
            $stderrBuffer .= $errChunk;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0 || ! is_file($tmpSrt) || filesize($tmpSrt) === 0) {
            @unlink($tmpSrt);
            throw new RuntimeException(
                'El proceso de OCR falló (código ' . $exitCode . '): ' . trim($stderrBuffer)
            );
        }

        $content = (string) file_get_contents($tmpSrt);
        @unlink($tmpSrt);

        if (trim($content) === '') {
            throw new RuntimeException('El OCR no produjo ningún texto.');
        }

        return $content;
    }


    // ──────────────────────────────────────────────────────────────────
    //  Extracción de frames
    // ──────────────────────────────────────────────────────────────────

    /**
     * Extrae los frames de imagen del stream usando FFmpeg.
     *
     * Para VobSub (dvd_subtitle): extrae cada fotograma del stream como PNG.
     * Para PGS (hdmv_pgs_subtitle): ídem, pero preprocesa para mejorar el contraste.
     *
     * Devuelve un array de ['path' => string, 'start' => float, 'end' => float]
     * donde start/end son segundos desde el inicio.
     *
     * @return array<int, array{path:string, start:float, end:float}>
     */
    private function extractImageFrames(
        string $videoPath,
        int $streamIndex,
        string $sourceCodec,
        string $tmpDir,
    ): array {
        // Extraer subtítulo completo a un directorio de imágenes individuales
        // usando el filtro 'ass' para VobSub o 'scale' para PGS RGBA.
        //
        // Estrategia: extraer el stream a un .sub/.idx o .sup temporal,
        // luego usar ffmpeg -i video -map 0:N -vf fps=fps=1/0 para obtener PNGs.
        //
        // La forma más robusta es extraer cada frame del stream de subtítulos
        // usando el codec dvdsub/pgssub con el muxer 'image2'.

        // Paso 1: Extraer el stream de imagen a un archivo .mkv temporal
        //         (contenedor que soporta ambos formatos)
        $mkvPath = $tmpDir . '/sub_stream.mkv';

        [$code, , $stderr] = $this->runner->run([
            (string) config('binaries.ffmpeg'),
            '-y',
            '-i', $videoPath,
            '-map', '0:' . $streamIndex,
            '-c:s', 'copy',
            $mkvPath,
        ], 120);

        if ($code !== 0 || ! is_file($mkvPath)) {
            throw new RuntimeException('FFmpeg no pudo extraer el stream de subtítulos de imagen: ' . trim($stderr));
        }

        // Paso 2: Obtener timestamps precisos con ffprobe
        $timestamps = $this->getSubtitleTimestamps($mkvPath);

        if (empty($timestamps)) {
            throw new RuntimeException('No se obtuvieron timestamps del stream de subtítulos.');
        }

        // Paso 3: Extraer cada frame como PNG individualmente
        $frames = [];
        $frameDir = $tmpDir . '/frames';
        mkdir($frameDir, 0775, true);

        foreach ($timestamps as $i => $ts) {
            $outPng = $frameDir . '/' . sprintf('%06d', $i) . '.png';

            // Extraer el frame en el timestamp de inicio
            [$code] = $this->runner->run([
                (string) config('binaries.ffmpeg'),
                '-y',
                '-ss', (string) $ts['start'],
                '-i', $mkvPath,
                '-frames:v', '1',
                '-vf', $this->buildVideoFilter($sourceCodec),
                $outPng,
            ], 30);

            if ($code === 0 && is_file($outPng) && filesize($outPng) > 0) {
                $frames[] = [
                    'path'  => $outPng,
                    'start' => $ts['start'],
                    'end'   => $ts['end'],
                ];
            }
        }

        return $frames;
    }

    /**
     * Obtiene los timestamps (start/end en segundos) de cada paquete de subtítulo.
     *
     * @return array<int, array{start:float, end:float}>
     */
    private function getSubtitleTimestamps(string $mkvPath): array
    {
        [$code, $stdout] = $this->runner->run([
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_packets',
            '-select_streams', 's:0',
            $mkvPath,
        ], 60);

        if ($code !== 0 || trim($stdout) === '') {
            // Fallback: intentar con show_frames
            return $this->getTimestampsFromFrames($mkvPath);
        }

        $data = json_decode($stdout, true);
        if (! is_array($data) || empty($data['packets'])) {
            return $this->getTimestampsFromFrames($mkvPath);
        }

        $timestamps = [];
        foreach ($data['packets'] as $pkt) {
            $pts      = isset($pkt['pts_time'])      ? (float) $pkt['pts_time']      : null;
            $duration = isset($pkt['duration_time']) ? (float) $pkt['duration_time'] : 2.0;

            if ($pts === null) {
                continue;
            }

            $timestamps[] = [
                'start' => $pts,
                'end'   => $pts + $duration,
            ];
        }

        return $timestamps;
    }

    /**
     * Fallback: obtiene timestamps desde show_frames.
     *
     * @return array<int, array{start:float, end:float}>
     */
    private function getTimestampsFromFrames(string $mkvPath): array
    {
        [$code, $stdout] = $this->runner->run([
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_frames',
            '-select_streams', 's:0',
            $mkvPath,
        ], 120);

        if ($code !== 0 || trim($stdout) === '') {
            return [];
        }

        $data = json_decode($stdout, true);
        if (! is_array($data) || empty($data['frames'])) {
            return [];
        }

        $timestamps = [];
        foreach ($data['frames'] as $frame) {
            $pts      = isset($frame['best_effort_timestamp_time']) ? (float) $frame['best_effort_timestamp_time'] : null;
            $duration = isset($frame['pkt_duration_time'])         ? (float) $frame['pkt_duration_time']          : 2.0;

            if ($pts === null) {
                continue;
            }

            $timestamps[] = [
                'start' => $pts,
                'end'   => $pts + $duration,
            ];
        }

        return $timestamps;
    }

    /**
     * Construye el filtro de video FFmpeg según el codec de origen.
     * PGS tiene canal alfa → aplanar sobre fondo negro antes de guardar.
     */
    private function buildVideoFilter(string $sourceCodec): string
    {
        return match ($sourceCodec) {
            'hdmv_pgs_subtitle' => 'scale=iw*2:ih*2,format=rgba,alphaextract,colorchannelmixer=rr=1:gg=1:bb=1',
            default             => 'scale=iw*2:ih*2',  // VobSub: escalar x2 mejora Tesseract
        };
    }

    // ──────────────────────────────────────────────────────────────────
    //  OCR de imagen individual
    // ──────────────────────────────────────────────────────────────────

    /**
     * Aplica Tesseract a una imagen PNG y devuelve el texto reconocido.
     */
    private function ocrImage(string $imagePath, string $lang): string
    {
        // Preprocesar con ImageMagick para mejorar contraste (opcional, mejora mucho el OCR)
        $preprocessed = $this->preprocessImage($imagePath);
        $targetPath   = $preprocessed ?? $imagePath;

        $outBase = $targetPath . '_ocr';

        [$code] = $this->runner->run([
            'tesseract',
            $targetPath,
            $outBase,
            '-l', $lang,
            '--psm', '6',          // Assume a single uniform block of text
            '--oem', '3',          // LSTM + Legacy (más preciso)
            'quiet',
        ], 30);

        $txtPath = $outBase . '.txt';

        $text = '';
        if ($code === 0 && is_file($txtPath)) {
            $text = trim((string) file_get_contents($txtPath));
        }

        // Limpieza de archivos temporales de Tesseract
        if (is_file($txtPath)) {
            @unlink($txtPath);
        }
        if ($preprocessed !== null && is_file($preprocessed)) {
            @unlink($preprocessed);
        }

        return $this->cleanOcrText($text);
    }

    /**
     * Preprocesa la imagen con FFmpeg para mejorar el OCR.
     * - Convierte a escala de grises
     * - Normaliza el contraste (curves/eq)
     * - Umbral para binarizar texto
     *
     * Devuelve la ruta de la imagen preprocesada o null si falla.
     */
    private function preprocessImage(string $imagePath): ?string
    {
        $outPath = $imagePath . '_pre.png';

        // filtro: escala de grises + umbralización para texto blanco sobre fondo oscuro
        [$code] = $this->runner->run([
            (string) config('binaries.ffmpeg'),
            '-y',
            '-i', $imagePath,
            '-vf', 'format=gray,curves=all=\'0/0 0.5/1 1/1\'',
            $outPath,
        ], 15);

        return ($code === 0 && is_file($outPath) && filesize($outPath) > 0) ? $outPath : null;
    }

    /**
     * Limpia el texto OCR: elimina líneas vacías múltiples, caracteres espurios, etc.
     */
    private function cleanOcrText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Eliminar caracteres de control excepto saltos de línea
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', '', (string) $text);

        // Normalizar saltos de línea
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        // Eliminar líneas con solo caracteres de puntuación o basura
        $lines = explode("\n", $text);
        $lines = array_filter($lines, function (string $line): bool {
            $cleaned = trim($line);
            if ($cleaned === '') {
                return false;
            }
            // Descartar líneas con menos de 2 caracteres reales o solo símbolos
            if (preg_match('/^[^a-zA-Z0-9áéíóúñüÁÉÍÓÚÑÜ\'"¡!¿?,.;:\-]{1,3}$/', $cleaned)) {
                return false;
            }

            return true;
        });

        return trim(implode("\n", $lines));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Generación de SRT
    // ──────────────────────────────────────────────────────────────────

    /**
     * Construye el contenido SRT desde bloques [{start, end, text}].
     *
     * @param  array<int, array{start:float, end:float, text:string}>  $blocks
     */
    public function buildSrt(array $blocks): string
    {
        $lines = [];
        $index = 1;

        foreach ($blocks as $block) {
            if (trim($block['text']) === '') {
                continue;
            }

            $lines[] = (string) $index;
            $lines[] = $this->formatSrtTime($block['start']) . ' --> ' . $this->formatSrtTime($block['end']);
            $lines[] = trim($block['text']);
            $lines[] = '';

            $index++;
        }

        return implode("\n", $lines);
    }

    /**
     * Convierte segundos al formato SRT: HH:MM:SS,mmm
     */
    private function formatSrtTime(float $seconds): string
    {
        $ms  = (int) round(($seconds - floor($seconds)) * 1000);
        $sec = (int) floor($seconds) % 60;
        $min = (int) floor($seconds / 60) % 60;
        $hrs = (int) floor($seconds / 3600);

        return sprintf('%02d:%02d:%02d,%03d', $hrs, $min, $sec, $ms);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Utilidades
    // ──────────────────────────────────────────────────────────────────

    /**
     * Elimina el directorio temporal de forma recursiva.
     */
    private function removeTmpDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTmpDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
