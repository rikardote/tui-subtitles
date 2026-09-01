<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
use App\Storage\Database;

/**
 * Gestiona la cola de procesamiento de subtítulos en SQLite.
 */
final class QueueService
{
    /**
     * Encola una tarea de traducción para un archivo multimedia.
     */
    public function enqueueTranslation(MediaFile $media, ?SubtitleTrack $track = null): ProcessingTask
    {
        // Si ya hay una tarea activa o pendiente para este archivo, devolverla
        $existing = $this->findActiveForMedia($media->id, ProcessingTask::ACTION_TRANSLATE);
        if ($existing !== null) {
            return $existing;
        }

        $task = new ProcessingTask();
        $task->mediaFileId = $media->id;
        $task->subtitleTrackId = $track?->id;
        $task->action = ProcessingTask::ACTION_TRANSLATE;
        $task->status = ProcessingTask::STATUS_PENDING;
        $task->progress = 0;
        $task->sourceLanguage = $track?->language ?? $track?->languageDetected ?? 'eng';
        $task->targetLanguage = (string) config('translation.target_language', 'es');
        $task->inputPath = $track?->path ?? $media->path;
        $task->save();

        return $task;
    }

    /**
     * Encola múltiples archivos de video para traducción en lote.
     *
     * @param int[] $mediaIds
     * @return ProcessingTask[]
     */
    public function enqueueBatch(array $mediaIds): array
    {
        $tasks = [];
        foreach ($mediaIds as $id) {
            $media = MediaFile::findById((int) $id);
            if (! $media || $media->hasSpanish()) {
                continue;
            }

            $englishTracks = $media->englishTracks();
            $track = $englishTracks[0] ?? null;

            $tasks[] = $this->enqueueTranslation($media, $track);
        }

        return $tasks;
    }

    /**
     * Cancela una tarea pendiente o en ejecución.
     */
    public function cancel(int $taskId): bool
    {
        $task = ProcessingTask::findById($taskId);
        if (! $task) {
            return false;
        }

        if (in_array($task->status, [ProcessingTask::STATUS_PENDING, ProcessingTask::STATUS_RUNNING], true)) {
            $task->status = ProcessingTask::STATUS_CANCELLED;
            $task->errorMessage = 'Cancelado por el usuario';
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();
            return true;
        }

        return false;
    }

    /**
     * Devuelve la siguiente tarea pendiente en orden FIFO.
     */
    public function nextPending(): ?ProcessingTask
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM processing_tasks WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row ? ProcessingTask::fromRow($row) : null;
    }

    /**
     * Devuelve la tarea actualmente en ejecución si existe.
     */
    public function activeTask(): ?ProcessingTask
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM processing_tasks WHERE status = 'running' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();

        return $row ? ProcessingTask::fromRow($row) : null;
    }

    /**
     * Cuenta tareas pendientes en cola.
     */
    public function pendingCount(): int
    {
        return (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM processing_tasks WHERE status = 'pending'"
        )->fetchColumn();
    }

    /**
     * Lista de tareas pendientes.
     *
     * @return ProcessingTask[]
     */
    public function pendingList(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM processing_tasks WHERE status = 'pending' ORDER BY id ASC"
        );

        return array_map(fn (array $row) => ProcessingTask::fromRow($row), $stmt->fetchAll());
    }

    private function findActiveForMedia(int $mediaId, string $action): ?ProcessingTask
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM processing_tasks WHERE media_file_id = ? AND action = ? AND status IN ('pending', 'running') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$mediaId, $action]);
        $row = $stmt->fetch();

        return $row ? ProcessingTask::fromRow($row) : null;
    }
}
