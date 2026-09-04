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
    public function translateBlock(array $block, string $targetLanguage, ?callable $onProblem = null): array
    {
        $text = trim($block['text']);

        if ($text === '') {
            return [
                'index' => $block['index'],
                'start' => $block['start'],
                'end' => $block['end'],
                'text' => '...',
            ];
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

                if ($this->looksUntranslated($text, $translated)) {
                    throw new RuntimeException('El proveedor devolvió el texto sin traducir (sigue en inglés).');
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

        // Modo suave: si hay un callback de problemas, no romper la tarea;
        // se conserva el texto original (inglés) y se notifica para revisión manual.
        if ($onProblem !== null) {
            $onProblem($block['index'], $lastError?->getMessage() ?? 'error desconocido', $block['text']);

            return $block;
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
     * Detecta cuando la "traducción" es idéntica (o casi) al original en inglés
     * — el modelo devolvió el texto sin traducir.
     *
     * Nombres propios y textos sin palabras funcionales inglesas NO se marcan
     * (p.ej. "New York", "Dancing with the Stars" en contexto español).
     */
    private function looksUntranslated(string $original, string $translated): bool
    {
        $norm = function (string $s): string {
            // Quitar etiquetas HTML/ASS, puntuación y nombres de hablante [Xxx]
            $s = preg_replace('/<[^>]+>|\\{[^\\}]+\\}|\[[A-Z][^\]]*\]|♪/u', ' ', $s) ?? $s;
            $s = strtolower($s);
            $s = preg_replace('/[^a-záéíóúñü0-9\s]/u', '', $s) ?? $s;

            return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        };

        $o = $norm($original);
        $t = $norm($translated);

        // Sin contenido textual comparable → no marcar
        if (str_word_count($o) < 3 || str_word_count($t) < 3) {
            return false;
        }

        // Idénticas → sospechoso
        if ($t === $o) {
            return $this->containsEnglish($t);
        }

        // Casi idénticas (variaciones menores del modelo) → sospechoso
        if (mb_strlen($o) < 500 && mb_strlen($t) < 500) {
            $distance = levenshtein($o, $t);
            $ratio = $distance / max(strlen($o), strlen($t), 1);
            if ($ratio < 0.15) {
                return $this->containsEnglish($t);
            }
        }

        return false;
    }

    /**
     * Heurística: el texto parece inglés (sin caracteres españoles y con
     * palabras funcionales inglesas de alta frecuencia).
     */
    private function containsEnglish(string $text): bool
    {
        // Si tiene caracteres propios del español, ya no es inglés puro
        if (preg_match('/[áéíóúñ¿¡]/u', $text)) {
            return false;
        }

        $functional = [
            'the', 'and', 'that', 'with', 'this', 'you', 'are', 'what', 'but', 'from',
            'they', 'will', 'your', 'because', 'about', 'just', 'have', 'were', 'been',
            'there', 'where', 'when', 'would', 'could', 'should', 'them', 'their',
            'these', 'those', 'which', 'into', 'over', 'again', 'some', 'most', 'other',
            'only', 'own', 'same', 'than', 'too', 'very', 'not', 'for', 'was', 'has',
            'had', 'did', 'get', 'got', 'going', 'don', 'can', 'im', 'ive', 'youre',
            'its', 'it', 'is', 'to', 'of', 'in', 'he', 'she', 'we', 'if', 'so', 'no',
        ];

        $words = preg_split('/[^a-z]+/', $text) ?: [];
        $hits = 0;

        foreach ($words as $word) {
            if (in_array($word, $functional, true)) {
                $hits++;
            }
        }

        $wordCount = count(array_filter($words, fn ($w) => strlen($w) > 2));

        return $hits >= 3 || ($wordCount >= 5 && $hits >= 2);
    }

    /**
     * Traduce múltiples bloques agrupados en lotes (una llamada por lote).
     *
     * @param  array<int, array>  $blocks
     * @param  callable(int, int):void|null  $onProgress  fn($done, $total) por lote
     * @param  callable(array<int, array>):void|null  $onBatch  fn($acumulado) tras cada lote
     * @return array<int, array>
     */
    public function translateBlocks(array $blocks, string $targetLanguage, ?callable $onProgress = null, ?callable $onBatch = null, ?callable $onProblem = null): array
    {
        $batchSize = $this->resolveBatchSize();
        $result = [];
        $total = count($blocks);
        $done = 0;

        // Contexto: último texto del lote anterior (frases que continúan)
        $prevContext = null;

        foreach (array_chunk($blocks, $batchSize) as $batch) {
            $translatedBatch = $this->translateBatch($batch, $targetLanguage, $prevContext, $onProblem);

            foreach ($translatedBatch as $block) {
                $result[] = $block;
            }

            // Último texto ORIGINAL del lote (para contexto del siguiente)
            $last = end($batch);
            $prevContext = $last !== false ? trim($last['text']) : null;

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
    private function translateBatch(array $blocks, string $targetLanguage, ?string $prevContext = null, ?callable $onProblem = null): array
    {
        // Bloques vacíos se devuelven tal cual
        $nonEmpty = array_values(array_filter(
            $blocks,
            fn ($b) => trim($b['text']) !== ''
        ));

        if ($nonEmpty === []) {
            return $blocks;
        }

        $useContext = $this->supportsContext();

        // Construir el lote con marcadores (+ contexto del bloque anterior)
        $payload = [];
        foreach ($nonEmpty as $i => $block) {
            $entry = sprintf(self::MARKER, $i + 1) . "\n";

            if ($useContext && $i === 0 && $prevContext !== null) {
                $entry .= "[Context: previous line was \"{$prevContext}\"]\n";
            }

            $entry .= trim($block['text']);
            $payload[] = $entry;
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
                        $translatedText = trim($parsed[$i] ?? '');
                        if ($translatedText === '') {
                            $translatedText = trim($block['text']) !== '' ? $block['text'] : '...';
                        }

                        // Si el segmento quedó sin traducir → fallback individual (con reintentos)
                        if ($this->looksUntranslated($block['text'], $translatedText)) {
                            throw new RuntimeException('El proveedor devolvió texto sin traducir en el lote.');
                        }

                        $out[] = [
                            'index' => $block['index'],
                            'start' => $block['start'],
                            'end' => $block['end'],
                            'text' => $translatedText,
                        ];
                    }

                    return $out;
                }

                // El proveedor no preservó los marcadores → fallback individual
                return array_map(
                    fn ($b) => $this->translateBlock($b, $targetLanguage, $onProblem),
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
            $out[] = $this->translateBlock($block, $targetLanguage, $onProblem);
        }

        return $out;
    }

    /**
     * Indica si el proveedor activo entiende instrucciones (LLM).
     * deep-translator (Google) traduciría las etiquetas de contexto.
     */
    private function supportsContext(): bool
    {
        $provider = (string) config('translation.provider', 'deep-translator');

        return in_array($provider, ['deepseek', 'meta-muse', 'meta-muse-spark', 'openai', 'openai-compatible', 'ollama'], true);
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
            $clean = $this->cleanSegment($match[2]);
            $texts[] = $clean;
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

    /**
     * Limpia un segmento traducido eliminando etiquetas del prompt que el LLM pudiera haber repetido.
     */
    private function cleanSegment(string $text): string
    {
        $text = trim($text);

        // Elimina cabeceras residuales de subtítulo o prompt repetidas por el modelo
        $text = preg_replace('/^(?:SUBTITLE\s+TO\s+TRANSLATE|SUBT[IÍ]TULO\s+A\s+TRADUCIR|TRANSLATION|TRADUCCI[OÓ]N|PREVIOUS\s+SUBTITLE[^\n]*|\[Context[^\n]*\])\s*:\s*/im', '', $text) ?? $text;
        $text = preg_replace('/^(?:---|---|\*\*\*)\s*/m', '', $text) ?? $text;

        return trim($text, "\"'\n\r ");
    }
}
