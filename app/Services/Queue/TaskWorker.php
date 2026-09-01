<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
use App\Services\Media\SubtitleExtractorService;
use App\Services\Subtitle\SubtitleAnalyzerService;
use App\Services\Subtitle\SubtitleFilenameService;
use App\Services\Translation\SubtitleTranslatorService;
use Throwable;

/**
 * Worker en segundo plano para procesar tareas de traducción de forma asíncrona.
 */
final class TaskWorker
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly QueueService $queue,
        private readonly SubtitleExtractorService $extractor,
        private readonly SubtitleTranslatorService $translator,
        private readonly SubtitleFilenameService $filenameService,
        private readonly SubtitleAnalyzerService $analyzer,
    ) {
    }

    /**
     * Bucle continuo del worker.
     */
    public function start(): void
    {
        $this->registerSignalHandlers();
        $this->log('Subtitle Worker iniciado. Esperando tareas...');

        while (! $this->shouldStop) {
            $task = $this->queue->nextPending();

            if ($task === null) {
                // No hay tareas en cola, dormir 1 segundo
                sleep(1);
                continue;
            }

            $this->processTask($task);
        }

        $this->log('Subtitle Worker detenido limpiamente.');
    }

    /**
     * Procesa una única tarea pendiente (útil para pruebas y cron).
     */
    public function processNext(): bool
    {
        $task = $this->queue->nextPending();
        if ($task === null) {
            return false;
        }

        $this->processTask($task);
        return true;
    }

    /**
     * Procesa una tarea específica.
     */
    public function processTask(ProcessingTask $task): void
    {
        $media = MediaFile::findById($task->mediaFileId);
        if (! $media) {
            $task->status = ProcessingTask::STATUS_FAILED;
            $task->errorMessage = 'El archivo de video ya no existe en la base de datos.';
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();
            return;
        }

        $task->status = ProcessingTask::STATUS_RUNNING;
        $task->startedAt = date('Y-m-d H:i:s');
        $task->progress = 0;
        $task->errorMessage = null;
        $task->save();

        $this->log("Iniciando tarea #{$task->id} para {$media->filename}");

        try {
            // Resolver la pista a traducir
            $track = null;
            if ($task->subtitleTrackId) {
                $track = SubtitleTrack::findById($task->subtitleTrackId);
            }

            if (! $track) {
                $englishTracks = $media->englishTracks();
                $track = $englishTracks[0] ?? null;
            }

            if (! $track) {
                // Re-analizar por si no se habían cargado las pistas
                $this->analyzer->analyze($media);
                $englishTracks = $media->englishTracks();
                $track = $englishTracks[0] ?? null;
            }

            if (! $track) {
                throw new \RuntimeException('No se encontró ninguna pista de subtítulos en inglés para traducir.');
            }

            // Obtener el contenido SRT
            $rawSrt = $this->extractor->getSrtContent($media, $track);

            // Determinar la ruta de salida
            $lang = (string) config('translation.target_language', 'es');
            $flags = ['sdh' => $track->isSdh, 'forced' => $track->isForced];
            $outputPath = $this->filenameService->pathForMedia($media, $lang, $flags);

            // Traducir con checkpointing
            $this->translator->translateSrt(
                $media,
                $track,
                $rawSrt,
                $outputPath,
                function (int $done, int $total) use ($task): void {
                    // Comprobar si la tarea fue cancelada externamente
                    $current = ProcessingTask::findById($task->id);
                    if ($current && $current->status === ProcessingTask::STATUS_CANCELLED) {
                        throw new \RuntimeException('Tarea cancelada por el usuario.');
                    }

                    $percent = min(99, (int) round(($done / max(1, $total)) * 100));
                    $task->progress = $percent;
                    $task->save();
                }
            );

            // Re-analizar para registrar el nuevo archivo .srt en la base de datos
            $this->analyzer->analyze($media);

            $media->status = MediaFile::STATUS_PROCESSED;
            $media->save();

            $task->subtitleTrackId = null;
            $task->status = ProcessingTask::STATUS_COMPLETED;
            $task->progress = 100;
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();

            $this->log("Tarea #{$task->id} completada con éxito: {$outputPath}");
        } catch (Throwable $e) {
            $isCancelled = str_contains($e->getMessage(), 'cancelada');
            $task->status = $isCancelled ? ProcessingTask::STATUS_CANCELLED : ProcessingTask::STATUS_FAILED;
            $task->errorMessage = $e->getMessage();
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();

            $this->log("Error en tarea #{$task->id}: " . $e->getMessage());
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function log(string $msg): void
    {
        echo date('[Y-m-d H:i:s] ') . $msg . "\n";
    }

    private function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function (): void {
                $this->stop();
            });
            pcntl_signal(SIGINT, function (): void {
                $this->stop();
            });
        }
    }
}
