<?php

declare(strict_types=1);

namespace App\Services\Subtitle;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Services\Container;
use App\Services\Translation\DeepTranslatorProvider;
use App\Services\Translation\OpenAICompatibleProvider;
use App\Services\Translation\TranslationBatchService;
use RuntimeException;

/**
 * Revisión manual de subtítulos generados.
 *
 * Lee el registro de bloques problemáticos ({archivo}.review.json) creado
 * durante la traducción y los RE-TRADUCE FORZOSAMENTE con DeepSeek
 * (independiente del proveedor configurado). Así el volumen se traduce
 * gratis con Ollama local y la corrección puntual se paga solo cuando
 * el usuario la activa.
 */
final class SubtitleReviewService
{
    public function __construct(
        private readonly SubtitleParserService $parser,
        private readonly TranslationBatchService $batch,
    ) {
    }

    /**
     * Devuelve los bloques problemáticos pendientes de revisión para un SRT.
     *
     * @return array<int, array{index:int, reason:string, original:string}>|null
     */
    public function pendingProblems(string $outputSrtPath): ?array
    {
        $reviewPath = $outputSrtPath . '.review.json';

        if (! is_file($reviewPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($reviewPath), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Re-traduce los bloques problemáticos con DeepSeek (forzado) y
     * actualiza el SRT final.
     *
     * @return array{reviewed:int, total:int, outputPath:string}
     */
    public function reviewWithDeepSeek(
        MediaFile $media,
        string $outputSrtPath,
        ?callable $onProgress = null,
    ): array {
        $problems = $this->pendingProblems($outputSrtPath) ?? [];

        if ($problems === []) {
            return ['reviewed' => 0, 'total' => 0, 'outputPath' => $outputSrtPath];
        }

        // Proveedor DeepSeek forzado (nunca el configurado)
        $deepseek = new OpenAICompatibleProvider([
            'key' => 'deepseek_api_key',
            'base_url' => 'deepseek_base_url',
            'model' => 'deepseek_model',
            'label' => 'DeepSeek',
        ]);

        $forcedBatch = new TranslationBatchService($deepseek);

        // Registrar tarea de revisión en el historial
        $task = new ProcessingTask();
        $task->uuid = $this->uuid();
        $task->mediaFileId = $media->id;
        $task->action = ProcessingTask::ACTION_TRANSLATE;
        $task->status = ProcessingTask::STATUS_RUNNING;
        $task->sourceLanguage = 'eng';
        $task->targetLanguage = (string) config('translation.target_language', 'es');
        $task->inputPath = $outputSrtPath;
        $task->outputPath = $outputSrtPath;
        $task->startedAt = date('Y-m-d H:i:s');
        $task->errorMessage = 'Revisión puntual con DeepSeek';
        $task->save();

        try {
            // Parsear el SRT actual
            $srtBlocks = $this->parser->parse((string) file_get_contents($outputSrtPath));
            $byIndex = [];
            foreach ($srtBlocks as $b) {
                $byIndex[$b['index']] = $b;
            }

            // Recuperar el texto ORIGINAL real desde el MKV (la pista en inglés
            // puede haber sido reemplazada por una "respuesta" del modelo).
            $originalFromMkv = $this->extractOriginalFromMkv($media);

            $reviewed = 0;
            $failed = [];

            foreach ($problems as $problem) {
                $index = (int) ($problem['index'] ?? 0);
                $original = (string) ($problem['original'] ?? '');

                if (! isset($byIndex[$index])) {
                    continue;
                }

                // El texto a re-traducir: el REAL del MKV si está disponible,
                // si no, el que quedó en el bloque (puede ser la respuesta del modelo).
                $realOriginal = $originalFromMkv[$index] ?? $original;

                // Si el texto real es solo un marcador/etiqueta ("[Spanish]", "[♪]"),
                // no hay nada que traducir: se restaura tal cual sin gastar API.
                if ($this->isMarkerOnly($realOriginal)) {
                    $byIndex[$index]['text'] = $realOriginal;
                    $reviewed++;
                    continue;
                }

                // Re-traducir con DeepSeek (con el texto original en inglés)
                try {
                    $result = $forcedBatch->translateBlock(
                        [
                            'index' => $byIndex[$index]['index'],
                            'start' => $byIndex[$index]['start'],
                            'end' => $byIndex[$index]['end'],
                            'text' => $realOriginal !== '' ? $realOriginal : $byIndex[$index]['text'],
                        ],
                        (string) config('translation.target_language', 'es')
                    );

                    $byIndex[$index]['text'] = $result['text'];
                    $reviewed++;
                } catch (\Throwable $e) {
                    $failed[] = $index;
                }
            }

            // Reconstruir el SRT
            ksort($byIndex);
            $content = $this->parser->build(array_values($byIndex));
            file_put_contents($outputSrtPath, $content, LOCK_EX);

            // Limpiar el registro de revisión si todo se resolvió
            if ($failed === []) {
                @unlink($outputSrtPath . '.review.json');
            } else {
                // Dejar solo los que fallaron
                $remaining = array_values(array_filter(
                    $problems,
                    fn ($p) => in_array((int) $p['index'], $failed, true)
                ));
                file_put_contents(
                    $outputSrtPath . '.review.json',
                    json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    LOCK_EX
                );
            }

            $task->status = ProcessingTask::STATUS_COMPLETED;
            $task->progress = 100;
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();

            return [
                'reviewed' => $reviewed,
                'total' => count($problems),
                'outputPath' => $outputSrtPath,
            ];
        } catch (\Throwable $e) {
            $task->status = ProcessingTask::STATUS_FAILED;
            $task->errorMessage = $e->getMessage();
            $task->completedAt = date('Y-m-d H:i:s');
            $task->save();

            throw $e;
        }
    }

    /**
     * Extrae del MKV la pista de subtítulos en inglés y devuelve su texto
     * por índice de bloque, para recuperar el original real cuando el modelo
     * reemplazó el subtítulo por una "respuesta".
     *
     * @return array<int, string>  índice → texto en inglés
     */
    private function extractOriginalFromMkv(MediaFile $media): array
    {
        $result = [];

        try {
            $track = $media->bestEnglishTextTrack();
            if (! $track || ! $track->isTextBased || $track->streamIndex === null) {
                return $result;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'sub_review_') . '.srt';
            @unlink($tmp);

            /** @var \App\Infrastructure\FFmpeg $ffmpeg */
            $ffmpeg = Container::get(\App\Infrastructure\FFmpeg::class);
            $ffmpeg->extractSubtitle($media->path, $track->streamIndex, $tmp, true);

            if (is_file($tmp)) {
                $blocks = $this->parser->parse((string) file_get_contents($tmp));
                foreach ($blocks as $b) {
                    $result[$b['index']] = $b['text'];
                }
            }

            @unlink($tmp);
        } catch (\Throwable) {
            // Sin original del MKV: se usará el texto guardado en el review.json
        }

        return $result;
    }

    /**
     * ¿El texto es solo un marcador/etiqueta técnica que no debe traducirse?
     */
    private function isMarkerOnly(string $text): bool
    {
        $cleaned = preg_replace('/[\[\]\(\){}<>♪]+/u', '', $text) ?? $text;
        $words = preg_split('/\s+/', trim($cleaned)) ?: [];
        $words = array_filter($words, fn ($w) => $w !== '');

        return count($words) <= 1;
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
