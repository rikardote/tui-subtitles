<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/**
 * Wrapper sobre FFmpeg: extracción de subtítulos y conversión a SRT.
 */
final class FFmpeg
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * Extrae una pista de subtítulos (por índice de stream) a un archivo.
     *
     * @param  int   $streamIndex  Índice del stream en el contenedor.
     * @param  bool  $convertToSrt Si el codec de origen no es texto plano, convierte.
     */
    public function extractSubtitle(string $videoPath, int $streamIndex, string $outputPath, bool $convertToSrt = true): void
    {
        $map = '0:' . $streamIndex;

        if ($convertToSrt) {
            [$code, , $stderr] = $this->runner->run([
                (string) config('binaries.ffmpeg'),
                '-y',
                '-i', $videoPath,
                '-map', $map,
                '-c:s', 'srt',
                $outputPath,
            ], 300);

            if ($code !== 0) {
                throw new RuntimeException('FFmpeg no pudo extraer el subtítulo: ' . trim($stderr));
            }
        } else {
            [$code, , $stderr] = $this->runner->run([
                (string) config('binaries.ffmpeg'),
                '-y',
                '-i', $videoPath,
                '-map', $map,
                '-c:s', 'copy',
                $outputPath,
            ], 300);

            if ($code !== 0) {
                throw new RuntimeException('FFmpeg no pudo extraer el subtítulo: ' . trim($stderr));
            }
        }
    }

    /**
     * Convierte un archivo de subtítulos (ASS/VTT/SSA) a SRT.
     */
    public function convertToSrt(string $inputPath, string $outputPath): void
    {
        [$code, , $stderr] = $this->runner->run([
            (string) config('binaries.ffmpeg'),
            '-y',
            '-i', $inputPath,
            '-c:s', 'srt',
            $outputPath,
        ], 120);

        if ($code !== 0) {
            throw new RuntimeException('FFmpeg no pudo convertir a SRT: ' . trim($stderr));
        }
    }
}
