<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
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
    ) {
    }

    /**
     * Extrae una pista interna de texto y la guarda como SRT temporal.
     * Devuelve el contenido SRT.
     */
    public function extractInternal(MediaFile $media, SubtitleTrack $track): string
    {
        if (! $track->isTextBased) {
            throw new RuntimeException('La pista seleccionada es de imagen (requiere OCR, no disponible en esta PoC).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sub_extract_') . '.srt';
        @unlink($tmp); // tempnam crea el archivo; ffmpeg necesita que no exista el .srt final... en realidad -y lo sobreescribe

        $task = new ProcessingTask();
        $task->uuid = $this->uuid();
        $task->mediaFileId = $media->id;
        $task->subtitleTrackId = $track->id;
        $task->action = ProcessingTask::ACTION_EXTRACT;
        $task->status = ProcessingTask::STATUS_RUNNING;
        $task->sourceLanguage = $track->language ?? $track->languageDetected;
        $task->inputPath = $media->path;
        $task->outputPath = $tmp;
        $task->startedAt = date('Y-m-d H:i:s');
        $task->save();

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

            $task->status = ProcessingTask::STATUS_COMPLETED;
            $task->progress = 100;
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();

            return $content;
        } catch (\Throwable $e) {
            $task->status = ProcessingTask::STATUS_FAILED;
            $task->errorMessage = $e->getMessage();
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();

            throw $e;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Lee el contenido de un subtítulo externo.
     */
    public function readExternal(SubtitleTrack $track): string
    {
        if ($track->path === null || ! is_file($track->path) || ! is_readable($track->path)) {
            throw new RuntimeException('El subtítulo externo no es legible.');
        }

        return (string) file_get_contents($track->path);
    }

    /**
     * Flujo completo para una pista: extraer (si interna) y traducir al español.
     *
     * @return array{outputPath:string, blocks:int, task:ProcessingTask}
     */
    public function extractAndTranslate(MediaFile $media, SubtitleTrack $track): array
    {
        // 1. Obtener el contenido SRT de la pista
        if ($track->sourceType === SubtitleTrack::SOURCE_EXTERNAL) {
            $srt = $this->readExternal($track);

            // Convertir a SRT si no lo es (ASS/VTT)
            $ext = strtolower(pathinfo((string) $track->path, PATHINFO_EXTENSION));
            if (in_array($ext, ['ass', 'ssa', 'vtt'], true)) {
                $srt = $this->convertContentToSrt($track->path);
            }
        } else {
            $srt = $this->extractInternal($media, $track);
        }

        // 2. Nombre de salida (junto al video, nunca sobrescribe nada existente)
        $flags = ['sdh' => $track->isSdh, 'forced' => $track->isForced];
        $outputPath = $this->filenames->pathForMedia($media, (string) config('translation.target_language', 'es'), $flags);

        if (file_exists($outputPath)) {
            throw new RuntimeException("Ya existe el archivo de salida: {$outputPath}");
        }

        // 3. Traducir
        $result = $this->translator->translateSrt($media, $track, $srt, $outputPath);

        // 4. Registrar la pista generada
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

        $media->status = MediaFile::STATUS_PROCESSED;
        $media->save();

        return $result + ['task' => $this->lastTask($media)];
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
