<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Subtitle\SubtitleValidatorService;
use RuntimeException;

/**
 * Orquesta la traducción completa de un subtítulo:
 * leer → parsear → traducir por bloques → reconstruir → validar → guardar.
 *
 * Nunca modifica el video ni el subtítulo original.
 */
final class SubtitleTranslatorService
{
    public function __construct(
        private readonly SubtitleParserService $parser,
        private readonly SubtitleValidatorService $validator,
        private readonly TranslationBatchService $batch,
    ) {
    }

    /**
     * Traduce un archivo SRT (o lo extrae de una pista interna) a español.
     *
     * @param  MediaFile|null       $media     Video asociado (para historial y salida).
     * @param  SubtitleTrack|null   $track     Pista de origen (opcional).
     * @param  string               $inputSrt  Contenido SRT de entrada.
     * @param  string               $outputPath Ruta del SRT de salida.
     * @param  callable(int,int):void|null $onProgress fn($done, $total) por bloque
     * @return array{outputPath:string, blocks:int}
     */
    public function translateSrt(
        ?MediaFile $media,
        ?SubtitleTrack $track,
        string $inputSrt,
        string $outputPath,
        ?callable $onProgress = null,
    ): array {
        $task = null;

        if ($media !== null) {
            $task = new ProcessingTask();
            $task->uuid = $this->uuid();
            $task->mediaFileId = $media->id;
            $task->subtitleTrackId = $track?->id;
            $task->action = ProcessingTask::ACTION_TRANSLATE;
            $task->status = ProcessingTask::STATUS_RUNNING;
            $task->sourceLanguage = $track?->language ?? $track?->languageDetected;
            $task->targetLanguage = (string) config('translation.target_language', 'es');
            $task->inputPath = $track?->path ?? $media->path;
            $task->outputPath = $outputPath;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->save();
        }

        try {
            $blocks = $this->parser->parse($inputSrt);

            if ($blocks === []) {
                throw new RuntimeException('El subtítulo no contiene bloques válidos.');
            }

            $target = (string) config('translation.target_language', 'es');

            // ── Checkpointing: reanudar si existe un .part previo ──
            $checkpointPath = $outputPath . '.part';
            $already = $this->loadCheckpoint($checkpointPath);

            $pending = array_values(array_filter(
                $blocks,
                fn (array $b) => ! isset($already[$b['index']])
            ));

            if ($already !== [] && $onProgress !== null) {
                $onProgress(count($already), count($blocks));
            }

            $translated = $this->batch->translateBlocks(
                $pending,
                $target,
                $onProgress,
                // Guarda el progreso acumulado tras cada lote (reanudable)
                function (array $accumulated) use ($already, $checkpointPath): void {
                    $merged = $this->mergeByIndex($already, $accumulated);
                    $ordered = $this->orderByIndex($merged);
                    file_put_contents($checkpointPath, $this->parser->build($ordered), LOCK_EX);
                }
            );

            // Unir los ya traducidos (checkpoint) con los nuevos
            $merged = $this->mergeByIndex($already, $translated);
            $ordered = $this->orderByIndex($merged);

            $output = $this->parser->build($ordered);

            $validation = $this->validator->validate($output);

            if (! $validation['valid']) {
                throw new RuntimeException(
                    'Validación fallida: ' . implode(' ', array_slice($validation['errors'], 0, 3))
                );
            }

            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            if (file_put_contents($outputPath, $output, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo escribir el archivo de salida.');
            }

            // Limpiar el checkpoint al completar
            if (is_file($checkpointPath)) {
                @unlink($checkpointPath);
            }

            if ($task !== null) {
                $task->status = ProcessingTask::STATUS_COMPLETED;
                $task->progress = 100;
                $task->completedAt = date('Y-m-d H:i:s');
                $task->save();
            }

            return ['outputPath' => $outputPath, 'blocks' => $validation['blocks']];
        } catch (\Throwable $e) {
            if ($task !== null) {
                $task->status = ProcessingTask::STATUS_FAILED;
                $task->errorMessage = $e->getMessage();
                $task->completedAt = date('Y-m-d H:i:s');
                $task->save();
            }

            throw $e;
        }
    }

    /**
     * Carga un checkpoint previo (bloques ya traducidos, por índice).
     *
     * @return array<int, array>
     */
    private function loadCheckpoint(string $checkpointPath): array
    {
        if (! is_file($checkpointPath) || filesize($checkpointPath) === 0) {
            return [];
        }

        $already = [];

        foreach ($this->parser->parse((string) file_get_contents($checkpointPath)) as $b) {
            $already[$b['index']] = $b;
        }

        return $already;
    }

    /**
     * Une bloques por índice; los nuevos tienen prioridad.
     *
     * @param  array<int, array>  ...$sets
     * @return array<int, array>
     */
    private function mergeByIndex(array ...$sets): array
    {
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $block) {
                $merged[$block['index']] = $block;
            }
        }

        return $merged;
    }

    /**
     * Ordena bloques por índice numérico.
     *
     * @param  array<int, array>  $blocks
     * @return array<int, array>
     */
    private function orderByIndex(array $blocks): array
    {
        $sorted = $blocks;

        uasort($sorted, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return array_values($sorted);
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
