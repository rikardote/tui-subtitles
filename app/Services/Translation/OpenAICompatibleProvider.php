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
    public function name(): string
    {
        $model = getenv('OPENAI_MODEL') ?: config('translation.openai_model', 'gpt-4o-mini');

        return 'OpenAI-compatible (' . $model . ')';
    }

    public function available(): bool
    {
        return (getenv('OPENAI_API_KEY') ?: config('translation.openai_api_key', '')) !== '';
    }

    public function translate(string $text, string $targetLanguage): string
    {
        $apiKey = getenv('OPENAI_API_KEY') ?: config('translation.openai_api_key', '');
        $baseUrl = rtrim(getenv('OPENAI_BASE_URL') ?: config('translation.openai_base_url', 'https://api.openai.com/v1'), '/');
        $model = getenv('OPENAI_MODEL') ?: config('translation.openai_model', 'gpt-4o-mini');

        if ($apiKey === '') {
            throw new RuntimeException('Falta OPENAI_API_KEY en el .env.');
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
                        . 'conversational language; preserve line breaks exactly as in the source.',
                ],
                ['role' => 'user', 'content' => $text],
            ],
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
