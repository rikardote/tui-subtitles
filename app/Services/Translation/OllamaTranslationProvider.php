<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Infrastructure\ProcessRunner;
use RuntimeException;

/**
 * Proveedor de traducción con LLM local vía Ollama.
 * Gratuito, sin API key, funciona offline.
 *
 * Configuración (.env):
 *   TRANSLATION_PROVIDER=ollama
 *   OLLAMA_URL=http://localhost:11434
 *   OLLAMA_MODEL=qwen2.5:7b          (o llama3.1, mistral, etc.)
 */
final class OllamaTranslationProvider implements TranslationProviderInterface
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    public function name(): string
    {
        $url = getenv('OLLAMA_URL') ?: config('translation.ollama_url', 'http://localhost:11434');
        $model = $this->resolveModel($url);

        return 'Ollama (' . $model . ')';
    }

    public function available(): bool
    {
        $url = getenv('OLLAMA_URL') ?: config('translation.ollama_url', 'http://localhost:11434');

        // Consulta la lista de modelos locales
        $handle = @fopen($url . '/api/tags', 'r', false, stream_context_create(['http' => ['timeout' => 2]]));

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    public function translate(string $text, string $targetLanguage): string
    {
        $url = getenv('OLLAMA_URL') ?: config('translation.ollama_url', 'http://localhost:11434');
        $model = $this->resolveModel($url);

        $prompt = $this->buildPrompt($text, $targetLanguage);

        $payload = json_encode([
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.2,
                'num_predict' => (int) (strlen($text) * 1.6) + 50,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => (int) config('translation.timeout_seconds', 30),
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url . '/api/generate', false, $context);

        if ($response === false) {
            throw new RuntimeException('No se pudo conectar con Ollama en ' . $url);
        }

        $data = json_decode($response, true);

        if (! is_array($data)) {
            throw new RuntimeException('Ollama devolvió una respuesta inválida.');
        }

        if (isset($data['error'])) {
            throw new RuntimeException('Ollama: ' . $data['error']);
        }

        $translated = trim((string) ($data['response'] ?? ''));

        // Quita posibles comillas envolventes del LLM
        $translated = trim($translated, "\"'\n\r ");

        if ($translated === '') {
            throw new RuntimeException('Ollama no devolvió traducción.');
        }

        return $translated;
    }

    /**
     * Resuelve el modelo a usar:
     *  1. El configurado (OLLAMA_MODEL).
     *  2. Si no existe, el primer modelo instalado en el servidor.
     */
    private function resolveModel(string $url): string
    {
        $configured = getenv('OLLAMA_MODEL') ?: config('translation.ollama_model', 'qwen2.5:7b');

        if ($this->modelExists($url, $configured)) {
            return $configured;
        }

        // Fallback: primer modelo instalado
        $installed = $this->installedModels($url);

        if ($installed !== []) {
            return $installed[0];
        }

        return $configured;
    }

    private function modelExists(string $url, string $model): bool
    {
        foreach ($this->installedModels($url) as $installed) {
            if ($installed === $model || str_starts_with($installed, $model . ':')) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function installedModels(string $url): array
    {
        $handle = @fopen($url . '/api/tags', 'r', false, stream_context_create(['http' => ['timeout' => 3]]));

        if ($handle === false) {
            return [];
        }

        $json = stream_get_contents($handle);
        fclose($handle);

        $data = json_decode((string) $json, true);

        if (! is_array($data) || ! isset($data['models'])) {
            return [];
        }

        return array_values(array_map(
            fn (array $m) => (string) $m['name'],
            $data['models']
        ));
    }

    private function buildPrompt(string $text, string $targetLanguage): string
    {
        $target = $targetLanguage === 'es' ? 'Spanish (es)' : $targetLanguage;

        return <<<PROMPT
You are a professional subtitle translator. Translate the following subtitle text to {$target}.

Rules:
- Translate ONLY the text. Do not add explanations, notes or comments.
- Do NOT translate proper names (people, places, brands).
- Keep names, numbers and symbols unchanged.
- Use natural, conversational language suitable for subtitles.
- Preserve line breaks exactly as in the source.
- If the text contains numbered markers like <<1>>, <<2>>, translate only the text after each marker
  and keep the markers EXACTLY unchanged (they split the result; never translate or merge them).
- Each marker contains the text to translate under the label "SUBTITLE TO TRANSLATE".
- If a marker has a "PREVIOUS SUBTITLE" section, use it ONLY as context to translate naturally
  (the subtitle may continue a previous sentence); never translate or repeat the context in your output —
  output ONLY the translated subtitle text.

Source text:
---
{$text}
---

Translation:
PROMPT;
    }
}
