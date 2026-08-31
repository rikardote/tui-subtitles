<?php

declare(strict_types=1);

namespace App\Services\Translation;

use RuntimeException;

/**
 * Procesa bloques de subtítulos por lotes con reintentos.
 * Preserva timestamps: solo traduce el contenido textual.
 */
final class TranslationBatchService
{
    public function __construct(
        private readonly TranslationProviderInterface $provider,
    ) {
    }

    /**
     * Traduce el texto de un bloque SRT.
     *
     * @param  array{index:int, start:string, end:string, text:string}  $block
     * @return array{index:int, start:string, end:string, text:string}
     *
     * @throws RuntimeException si falla tras los reintentos.
     */
    public function translateBlock(array $block, string $targetLanguage): array
    {
        $text = $block['text'];

        // No traducir bloques vacíos ni puramente numéricos/simbólicos
        if (trim($text) === '') {
            return $block;
        }

        $maxRetries = (int) config('translation.max_retries', 3);
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $translated = $this->provider->translate($text, $targetLanguage);

                // Conserva el texto original si el proveedor devolvió algo vacío
                if (trim($translated) === '') {
                    throw new RuntimeException('Traducción vacía.');
                }

                return [
                    'index' => $block['index'],
                    'start' => $block['start'],
                    'end' => $block['end'],
                    'text' => $translated,
                ];
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep(500_000 * $attempt); // backoff: 0.5s, 1s, 1.5s...
            }
        }

        throw new RuntimeException(
            sprintf(
                'Fallo al traducir el bloque %d después de %d intentos: %s',
                $block['index'],
                $maxRetries,
                $lastError?->getMessage() ?? 'error desconocido'
            )
        );
    }

    /**
     * Traduce múltiples bloques con reporte de progreso.
     *
     * @param  array<int, array>  $blocks
     * @param  callable(int, int):void|null  $onProgress  fn($done, $total)
     * @return array<int, array>
     */
    public function translateBlocks(array $blocks, string $targetLanguage, ?callable $onProgress = null): array
    {
        $result = [];
        $total = count($blocks);
        $done = 0;

        foreach ($blocks as $block) {
            $result[] = $this->translateBlock($block, $targetLanguage);
            $done++;
            $onProgress?->__invoke($done, $total);
        }

        return $result;
    }
}
