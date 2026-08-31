<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
use RuntimeException;

/**
 * Eliminación de subtítulos generados o externos.
 * Nunca toca pistas internas (están dentro del contenedor del video).
 */
final class SubtitleRemovalService
{
    /**
     * Borra un subtítulo (generado o externo): archivo + registro + historial.
     *
     * @return array{deletedFile:bool, deletedRecord:bool}
     *
     * @throws RuntimeException si la pista es interna.
     */
    public function deleteTrack(MediaFile $media, SubtitleTrack $track): array
    {
        if ($track->sourceType === SubtitleTrack::SOURCE_INTERNAL) {
            throw new RuntimeException('Las pistas internas no se pueden borrar (están dentro del video).');
        }

        $result = ['deletedFile' => false, 'deletedRecord' => false];

        // Borrar el archivo físico si existe
        if ($track->path !== null && is_file($track->path)) {
            if (! @unlink($track->path)) {
                throw new RuntimeException('No se pudo borrar el archivo: ' . $track->path);
            }
            $result['deletedFile'] = true;
        }

        // Eliminar el registro
        $stmt = \App\Storage\Database::pdo()->prepare('DELETE FROM subtitle_tracks WHERE id = ?');
        $stmt->execute([$track->id]);
        $result['deletedRecord'] = true;

        // Historial
        $task = new ProcessingTask();
        $task->uuid = $this->uuid();
        $task->mediaFileId = $media->id;
        $task->subtitleTrackId = null;
        $task->action = ProcessingTask::ACTION_DELETE;
        $task->status = ProcessingTask::STATUS_COMPLETED;
        $task->progress = 100;
        $task->sourceLanguage = $track->languageDetected ?? $track->language;
        $task->inputPath = $track->path;
        $task->startedAt = date('Y-m-d H:i:s');
        $task->completedAt = date('Y-m-d H:i:s');
        $task->save();

        return $result;
    }

    /**
     * Borra todos los subtítulos generados por la aplicación para un video.
     *
     * @return int Número de subtítulos eliminados.
     */
    public function deleteGeneratedFor(MediaFile $media): int
    {
        $deleted = 0;

        foreach ($media->tracks() as $track) {
            if ($track->sourceType !== SubtitleTrack::SOURCE_GENERATED) {
                continue;
            }

            try {
                $this->deleteTrack($media, $track);
                $deleted++;
            } catch (RuntimeException) {
                // continúa con los demás
            }
        }

        return $deleted;
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
