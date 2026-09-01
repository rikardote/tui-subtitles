<?php

declare(strict_types=1);

namespace App\Services\Translation;

use RuntimeException;

/**
 * Proveedor de traducción con API compatible con OpenAI
 * (OpenAI, Groq, OpenRouter, LM Studio, vLLM, etc.).
 *
 * Configuración (.env):
 *   TRANSLATION_PROVIDER=openai
 *   OPENAI_API_KEY=sk-...
 *   OPENAI_BASE_URL=https://api.openai.com/v1
 *   OPENAI_MODEL=gpt-4o-mini
 */
final class OpenAICompatibleProvider implements TranslationProviderInterface
{
    /**
     * @param  array{key:string, base_url:string, model:string, label:string}  $config
     *        Claves de configuración (config('translation...')) y etiqueta visible.
     */
    public function __construct(
        private readonly array $config = [
            'key' => 'openai_api_key',
            'base_url' => 'openai_base_url',
            'model' => 'openai_model',
            'label' => 'OpenAI-compatible',
        ],
    ) {
    }

    public function name(): string
    {
        $model = getenv(strtoupper($this->config['model'])) ?: config('translation.' . $this->config['model'], 'gpt-4o-mini');

        return $this->config['label'] . ' (' . $model . ')';
    }

    public function available(): bool
    {
        return (getenv(strtoupper($this->config['key'])) ?: config('translation.' . $this->config['key'], '')) !== '';
    }

    public function translate(string $text, string $targetLanguage): string
    {
        $apiKey = getenv(strtoupper($this->config['key'])) ?: config('translation.' . $this->config['key'], '');
        $baseUrl = rtrim(getenv(strtoupper($this->config['base_url'])) ?: config('translation.' . $this->config['base_url'], 'https://api.openai.com/v1'), '/');
        $model = getenv(strtoupper($this->config['model'])) ?: config('translation.' . $this->config['model'], 'gpt-4o-mini');

        if ($apiKey === '') {
            throw new RuntimeException('Falta la API key en el .env (' . $this->config['key'] . ').');
        }

        $target = $targetLanguage === 'es' ? 'Spanish (es)' : $targetLanguage;

        $payload = json_encode([
            'model' => $model,
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a professional subtitle translator. Translate the user's subtitle text to {$target}. "
                        . 'Rules: translate ONLY the text without explanations; do NOT translate proper names '
                        . '(people, places, brands); keep names, numbers and symbols unchanged; use natural, '
                        . 'conversational language; preserve line breaks exactly as in the source. '
                        . 'If the input contains numbered markers like <<1>>, <<2>>, translate only the text '
                        . 'after each marker and keep the markers themselves EXACTLY unchanged (they are used '
                        . 'to split the result, do not translate or merge them). '
                        . 'Each marker contains the text to translate under the label "SUBTITLE TO TRANSLATE". '
                        . 'If a marker also has a "PREVIOUS SUBTITLE" section, use it ONLY as context to translate '
                        . 'naturally (the subtitle may continue a previous sentence); never translate or repeat '
                        . 'the context in your output — output ONLY the translated subtitle text.',
                ],
                ['role' => 'user', 'content' => $text],
            ],
            // Muse Spark y otros modelos de razonamiento necesitan: esfuerzo mínimo
            // (evita gastar tokens "pensando") y un límite generoso de salida.
            'reasoning_effort' => 'minimal',
            'max_tokens' => (int) (strlen($text) * 2.5) + 200,
        ], JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . "Authorization: Bearer {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => (int) config('translation.timeout_seconds', 30),
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($baseUrl . '/chat/completions', false, $context);

        if ($response === false) {
            throw new RuntimeException('No se pudo conectar con la API en ' . $baseUrl);
        }

        $data = json_decode($response, true);

        if (! is_array($data)) {
            throw new RuntimeException('La API devolvió una respuesta inválida.');
        }

        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? 'desconocido') : $data['error'];
            throw new RuntimeException('API: ' . $msg);
        }

        $translated = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        $translated = trim($translated, "\"'\n\r ");

        if ($translated === '') {
            throw new RuntimeException('La API no devolvió traducción.');
        }

        return $translated;
    }
}
