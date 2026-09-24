<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
use App\Services\Ocr\OcrService;
use App\Services\Subtitle\SubtitleFilenameService;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Subtitle\SubtitleValidatorService;
use App\Services\Translation\SubtitleTranslatorService;
use RuntimeException;

/**
 * Extrae una pista de subtítulos del video (o lee un archivo externo)
 * y opcionalmente la traduce al español en un solo flujo.
 */
final class SubtitleExtractorService
{
    public function __construct(
        private readonly \App\Infrastructure\FFmpeg $ffmpeg,
        private readonly SubtitleFilenameService $filenames,
        private readonly SubtitleValidatorService $validator,
        private readonly SubtitleParserService $parser,
        private readonly SubtitleTranslatorService $translator,
        private readonly OcrService $ocr,
    ) {
    }

    /**
     * Extrae una pista interna de texto y la devuelve como SRT.
     * Si la pista es de imagen (VobSub/PGS) y Tesseract está disponible,
     * realiza OCR automáticamente.
     */
    public function extractInternal(
        MediaFile $media,
        SubtitleTrack $track,
        ?callable $onProgress = null,
        ?ProcessingTask $parentTask = null,
    ): string {
        if (! $track->isTextBased) {
            return $this->extractImageWithOcr($media, $track, $onProgress, $parentTask);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sub_extract_') . '.srt';
        @unlink($tmp);

        $task = $parentTask;
        $isOwnTask = false;
        if ($task === null) {
            $task = new ProcessingTask();
            $task->uuid = $this->uuid();
            $task->mediaFileId = $media->id;
            $task->subtitleTrackId = $track->id;
            $task->action = ProcessingTask::ACTION_EXTRACT;
            $task->status = ProcessingTask::STATUS_RUNNING;
            $task->sourceLanguage = $track->language ?? $track->languageDetected;
            $task->inputPath = $media->path;
            $task->outputPath = $tmp;
            $task->startedAt = gmdate('Y-m-d H:i:s');
            $task->save();
            $isOwnTask = true;
        }

        try {
            $this->ffmpeg->extractSubtitle($media->path, (int) $track->streamIndex, $tmp, true);

            if (! is_file($tmp) || filesize($tmp) === 0) {
                throw new RuntimeException('La extracción no produjo un archivo válido.');
            }

            $content = (string) file_get_contents($tmp);

            $validation = $this->validator->validate($content);
            if (! $validation['valid']) {
                throw new RuntimeException(
                    'El subtítulo extraído no es válido: ' . implode(' ', array_slice($validation['errors'], 0, 3))
                );
            }

            if ($isOwnTask) {
                $task->subtitleTrackId = null;
                $task->status = ProcessingTask::STATUS_COMPLETED;
                $task->progress = 100;
                $task->completedAt = gmdate('Y-m-d H:i:s');
                $task->save();
            }

            return $content;
        } catch (\Throwable $e) {
            if ($isOwnTask) {
                $task->subtitleTrackId = null;
                $task->status = ProcessingTask::STATUS_FAILED;
                $task->errorMessage = $e->getMessage();
                $task->completedAt = gmdate('Y-m-d H:i:s');
                $task->save();
            }

            throw $e;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Realiza OCR de una pista de subtítulos de imagen (VobSub/PGS/DVD).
     * Registra la tarea en processing_tasks y devuelve el SRT resultante.
     *
     * @param  callable|null  $onProgress  fn(int $done, int $total)
     */
    public function extractImageWithOcr(
        MediaFile $media,
        SubtitleTrack $track,
        ?callable $onProgress = null,
        ?ProcessingTask $parentTask = null,
    ): string {
        if (! $this->ocr->available()) {
            throw new RuntimeException(
                'Este subtítulo es de imagen (' . ($track->codec ?? 'imagen') . '). ' .
                'Instala Tesseract OCR para procesarlo: sudo apt install tesseract-ocr tesseract-ocr-eng tesseract-ocr-spa'
            );
        }

        $task = $parentTask;
        $isOwnTask = false;
        if ($task === null) {
            $task = new ProcessingTask();
            $task->uuid = $this->uuid();
            $task->mediaFileId = $media->id;
            $task->subtitleTrackId = $track->id;
            $task->action = ProcessingTask::ACTION_EXTRACT;
            $task->status = ProcessingTask::STATUS_RUNNING;
            $task->sourceLanguage = $track->language ?? $track->languageDetected;
            $task->inputPath = $media->path;
            $task->startedAt = gmdate('Y-m-d H:i:s');
            $task->save();
            $isOwnTask = true;
        }

        try {
            // Seleccionar idioma Tesseract según el idioma de la pista
            $ocrLang = $this->resolveOcrLanguage($track);

            $lastPercent = 0;
            $srt = $this->ocr->extractAndOcr(
                $media->path,
                (int) $track->streamIndex,
                (string) $track->codec,
                $ocrLang,
                function (int $done, int $total) use ($task, $onProgress, &$lastPercent, $isOwnTask) {
                    $percent = (int) round(($done / max(1, $total)) * 100);
                    if ($percent !== $lastPercent && ($percent % 2 === 0 || $done === $total)) {
                        if ($isOwnTask) {
                            $task->progress = min(99, $percent);
                            $task->save();
                        }
                        $lastPercent = $percent;
                    }
                    if ($onProgress !== null) {
                        $onProgress($done, $total);
                    }
                },
            );

            if (trim($srt) === '') {
                throw new RuntimeException('El OCR no produjo texto reconocible.');
            }

            if ($isOwnTask) {
                $task->subtitleTrackId = null;
                $task->status = ProcessingTask::STATUS_COMPLETED;
                $task->progress = 100;
                $task->completedAt = gmdate('Y-m-d H:i:s');
                $task->save();
            }

            return $srt;
        } catch (\Throwable $e) {
            if ($isOwnTask) {
                $task->subtitleTrackId = null;
                $task->status = ProcessingTask::STATUS_FAILED;
                $task->errorMessage = $e->getMessage();
                $task->completedAt = gmdate('Y-m-d H:i:s');
                $task->save();
            }

            throw $e;
        }
    }

    /**
     * Traduce una pista de imagen: OCR → traducción → guarda SRT.
     *
     * @param  callable|null  $onProgress  fn(int $done, int $total)
     * @return array{outputPath:string, blocks:int}
     */
    public function extractImageAndTranslate(
        MediaFile $media,
        SubtitleTrack $track,
        ?callable $onProgress = null,
    ): array {
        // Fase 1: OCR → SRT
        $srt = $this->extractImageWithOcr($media, $track);

        // Fase 2: traducir y guardar
        return $this->saveTranslated($media, $track, $srt, $onProgress);
    }

    /**
     * Devuelve el código de idioma Tesseract para la pista dada.
     * Tesseract usa ISO 639-3 (eng, spa, fra…) igual que FFprobe.
     */
    private function resolveOcrLanguage(SubtitleTrack $track): string
    {
        $lang = $track->languageDetected ?? $track->language ?? 'eng';

        // Normalizar los alias más comunes
        $map = [
            'en'  => 'eng',
            'es'  => 'spa',
            'fr'  => 'fra',
            'de'  => 'deu',
            'it'  => 'ita',
            'pt'  => 'por',
            'ja'  => 'jpn',
            'zh'  => 'chi_sim',
            'ko'  => 'kor',
            'ru'  => 'rus',
            'ar'  => 'ara',
        ];

        $lang = $map[$lang] ?? $lang;

        // Verificar que el idioma está instalado; si no, intentar 'eng'
        $available = $this->ocr->availableLanguages();
        if (! in_array($lang, $available, true) && in_array('eng', $available, true)) {
            $lang = 'eng';
        }

        return $lang;
    }

    /**
     * Reads the external subtitle file content.
     */
    public function readExternal(SubtitleTrack $track): string
    {
        if ($track->path === null || ! is_file($track->path) || ! is_readable($track->path)) {
            throw new RuntimeException('El subtítulo externo no es legible.');
        }

        return (string) file_get_contents($track->path);
    }

    /**
     * Fase 1: obtiene el contenido SRT de una pista (interna, externa o de imagen con OCR).
     * Convierte ASS/VTT a SRT cuando es necesario.
     */
    public function getSrtContent(
        MediaFile $media,
        SubtitleTrack $track,
        ?callable $onProgress = null,
        ?ProcessingTask $parentTask = null,
    ): string {
        if ($track->sourceType === SubtitleTrack::SOURCE_EXTERNAL) {
            $srt = $this->readExternal($track);

            $ext = strtolower(pathinfo((string) $track->path, PATHINFO_EXTENSION));
            if (in_array($ext, ['ass', 'ssa', 'vtt'], true)) {
                $srt = $this->convertContentToSrt($track->path);
            }

            return $srt;
        }

        // Pistas internas: texto directo o imagen con OCR
        return $this->extractInternal($media, $track, $onProgress, $parentTask);
    }

    /**
     * Fase 2: traduce un SRT ya obtenido, valida y guarda junto al video,
     * registrando la tarea y la pista generada. Reporta progreso por bloque.
     *
     * @param  callable(int,int):void|null  $onProgress  fn($done, $total)
     * @return array{outputPath:string, blocks:int}
     */
    public function saveTranslated(
        MediaFile $media,
        SubtitleTrack $track,
        string $srt,
        ?callable $onProgress = null,
    ): array {
        $flags = ['sdh' => $track->isSdh, 'forced' => $track->isForced];
        $outputPath = $this->filenames->pathForMedia($media, (string) config('translation.target_language', 'es'), $flags);

        if (file_exists($outputPath)) {
            throw new RuntimeException("Ya existe el archivo de salida: {$outputPath}");
        }

        $result = $this->translator->translateSrt($media, $track, $srt, $outputPath, $onProgress);

        // Registrar la pista generada
        $generated = new SubtitleTrack();
        $generated->mediaFileId = $media->id;
        $generated->sourceType = SubtitleTrack::SOURCE_GENERATED;
        $generated->path = $outputPath;
        $generated->language = (string) config('translation.target_language', 'es');
        $generated->codec = 'subrip';
        $generated->isTextBased = true;
        $generated->isSdh = $track->isSdh;
        $generated->isForced = $track->isForced;
        $generated->save();

        // El objeto en memoria ya no refleja la BD: invalidar cache de pistas
        $media->clearTracksCache();

        $media->status = MediaFile::STATUS_PROCESSED;
        $media->save();

        return $result;
    }

    /**
     * Flujo completo para una pista: extraer (si interna) y traducir al español.
     *
     * @param  callable(int,int):void|null  $onProgress  fn($done, $total)
     * @return array{outputPath:string, blocks:int}
     */
    public function extractAndTranslate(MediaFile $media, SubtitleTrack $track, ?callable $onProgress = null): array
    {
        // 1. Obtener el contenido SRT de la pista
        $srt = $this->getSrtContent($media, $track);

        // 2. Traducir, validar y guardar
        return $this->saveTranslated($media, $track, $srt, $onProgress);
    }

    private function convertContentToSrt(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sub_conv_') . '.srt';
        @unlink($tmp);

        try {
            $this->ffmpeg->convertToSrt($path, $tmp);
            $content = (string) file_get_contents($tmp);

            if (trim($content) === '') {
                throw new RuntimeException('La conversión a SRT no produjo contenido.');
            }

            return $content;
        } finally {
            @unlink($tmp);
        }
    }

    private function lastTask(MediaFile $media): ProcessingTask
    {
        // Reutiliza el modelo: buscamos la tarea de traducción más reciente
        $stmt = \App\Storage\Database::pdo()->prepare(
            'SELECT * FROM processing_tasks WHERE media_file_id = ? AND action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$media->id, ProcessingTask::ACTION_TRANSLATE]);
        $row = $stmt->fetch();

        $task = new ProcessingTask();
        foreach ($row ?? [] as $key => $value) {
            if (property_exists($task, $key)) {
                $task->{$key} = $value;
            }
        }

        return $task;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
