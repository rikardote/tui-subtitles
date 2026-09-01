<?php

declare(strict_types=1);

namespace App\Services\Translation;

use RuntimeException;

/**
 * Procesa bloques de subtítulos por lotes con reintentos.
 *
 * Optimización: cada lote (config: translation.batch_size) se traduce en
 * UNA sola llamada al proveedor usando marcadores <<N>>, en vez de una
 * llamada por bloque. Si el proveedor no preserva los marcadores, se
 * hace fallback bloque a bloque.
 *
 * Preserva timestamps: solo traduce el contenido textual.
 */
final class TranslationBatchService
{
    private const MARKER = '<<%d>>';

    public function __construct(
        private readonly TranslationProviderInterface $provider,
    ) {
    }

    /**
     * Traduce el texto de un bloque SRT (llamada individual).
     *
     * @param  array{index:int, start:string, end:string, text:string}  $block
     * @return array{index:int, start:string, end:string, text:string}
     *
     * @throws RuntimeException si falla tras los reintentos.
     */
    public function translateBlock(array $block, string $targetLanguage): array
    {
        $text = trim($block['text']);

        if ($text === '') {
            return $block;
        }

        $maxRetries = (int) config('translation.max_retries', 3);
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $translated = $this->provider->translate($text, $targetLanguage);

                if (trim($translated) === '') {
                    throw new RuntimeException('Traducción vacía.');
                }

                if ($this->isJunk($translated)) {
                    throw new RuntimeException('El proveedor devolvió texto de error (basura).');
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
     * Textos de error conocidos que NUNCA deben aceptarse como traducción.
     * (Páginas de error de Google/DeepSeek que se cuelan como contenido).
     */
    private const JUNK_PATTERNS = [
        'error 500',
        'that\'s an error',
        'there was an error',
        'please try again later',
        'that\'s all we know',
        'http error',
        'server error',
        '429 too many requests',
        'rate limit',
        'bad gateway',
        'model_not_found',
        'invalid_api_key',
        'translation result:',
    ];

    /**
     * Determina si un texto es basura (página de error de la API) y no
     * una traducción real. Devuelve true si debe rechazarse.
     */
    private function isJunk(string $text): bool
    {
        $lower = strtolower($text);

        // Página de error completa: varios patrones juntos
        $hits = 0;
        foreach (self::JUNK_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                $hits++;
            }
        }

        if ($hits >= 2) {
            return true;
        }

        // Frases de error en inglés dentro de una traducción al español
        if (preg_match('/\b(error|errors?|failed|failure)\b/i', $text)
            && ! preg_match('/[áéíóúñ¿¡]/', $text)
            && strlen($text) > 30) {
            return true;
        }

        return false;
    }

    /**
     * Traduce múltiples bloques agrupados en lotes (una llamada por lote).
     *
     * @param  array<int, array>  $blocks
     * @param  callable(int, int):void|null  $onProgress  fn($done, $total) por lote
     * @param  callable(array<int, array>):void|null  $onBatch  fn($acumulado) tras cada lote
     * @return array<int, array>
     */
    public function translateBlocks(array $blocks, string $targetLanguage, ?callable $onProgress = null, ?callable $onBatch = null): array
    {
        $batchSize = $this->resolveBatchSize();
        $result = [];
        $total = count($blocks);
        $done = 0;

        foreach (array_chunk($blocks, $batchSize) as $batch) {
            $translatedBatch = $this->translateBatch($batch, $targetLanguage);

            foreach ($translatedBatch as $block) {
                $result[] = $block;
            }

            $done += count($translatedBatch);
            $onProgress?->__invoke($done, $total);
            $onBatch?->__invoke($result);
        }

        return $result;
    }

    /**
     * Tamaño de lote según el proveedor:
     *  - APIs en la nube (DeepSeek, Muse Spark, OpenAI): lotes grandes (el tiempo
     *    está dominado por el overhead de la llamada, no por los tokens).
     *  - Ollama local (GPU débil): lotes pequeños para no saturar la generación.
     */
    private function resolveBatchSize(): int
    {
        $configured = max(1, (int) config('translation.batch_size', 50));
        $provider = (string) config('translation.provider', 'deep-translator');

        if ($provider === 'ollama') {
            return min($configured, 15);
        }

        return $configured;
    }

    /**
     * Traduce un lote en una sola llamada al proveedor.
     * Si el proveedor no preserva los marcadores, hace fallback individual.
     *
     * @param  array<int, array>  $blocks
     * @return array<int, array>
     */
    private function translateBatch(array $blocks, string $targetLanguage): array
    {
        // Bloques vacíos se devuelven tal cual
        $nonEmpty = array_values(array_filter(
            $blocks,
            fn ($b) => trim($b['text']) !== ''
        ));

        if ($nonEmpty === []) {
            return $blocks;
        }

        // Construir el lote con marcadores
        $payload = [];
        foreach ($nonEmpty as $i => $block) {
            $payload[] = sprintf(self::MARKER, $i + 1) . "\n" . $block['text'];
        }
        $batchText = implode("\n\n", $payload);

        $maxRetries = (int) config('translation.max_retries', 3);
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->provider->translate($batchText, $targetLanguage);

                if ($this->isJunk($response)) {
                    throw new RuntimeException('El proveedor devolvió texto de error (basura).');
                }

                $parsed = $this->parseMarkers($response, count($nonEmpty));

                if ($parsed !== null) {
                    // Reconstruir con timestamps originales
                    $out = [];
                    foreach ($nonEmpty as $i => $block) {
                        $out[] = [
                            'index' => $block['index'],
                            'start' => $block['start'],
                            'end' => $block['end'],
                            'text' => $parsed[$i],
                        ];
                    }

                    return $out;
                }

                // El proveedor no preservó los marcadores → fallback individual
                return array_map(
                    fn ($b) => $this->translateBlock($b, $targetLanguage),
                    $blocks
                );
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep(500_000 * $attempt);
            }
        }

        // Fallo total del lote → intentar individual; si también falla, propagar
        $out = [];
        foreach ($blocks as $block) {
            $out[] = $this->translateBlock($block, $targetLanguage);
        }

        return $out;
    }

    /**
     * Parsea la respuesta del proveedor con marcadores <<N>>.
     *
     * @return string[]|null Textos en orden, o null si el formato no coincide.
     */
    private function parseMarkers(string $response, int $expected): ?array
    {
        $response = trim($response);

        // Busca todos los marcadores <<N>>...<<N+1>> separando los textos
        if (! preg_match_all('/<<(\d+)>>\s*(.*?)(?=<<\d+>>|$)/s', $response, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $texts = [];
        $lastIndex = 0;

        foreach ($matches as $match) {
            $num = (int) $match[1];
            // Los índices deben ser consecutivos empezando en 1
            if ($num !== $lastIndex + 1) {
                return null;
            }
            $texts[] = trim($match[2]);
            $lastIndex = $num;
        }

        if (count($texts) !== $expected) {
            return null;
        }

        // Rechazar si algún segmento es basura (página de error)
        foreach ($texts as $segment) {
            if ($this->isJunk($segment)) {
                return null;
            }
        }

        return $texts;
    }
}
