<?php

declare(strict_types=1);

namespace App\Services\Jellyfin;

use RuntimeException;

/**
 * Cliente HTTP mínimo para la API REST de Jellyfin.
 * Documentación: https://api.jellyfin.org/
 *
 * Se autentica con una API key (Dashboard → Advanced → API Keys)
 * mediante la cabecera X-Emby-Token.
 */
final class JellyfinApiClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * GET /System/Info — versión y estado del servidor.
     *
     * @return array<string, mixed>
     */
    public function systemInfo(): array
    {
        return $this->get('/System/Info');
    }

    /**
     * GET /Items — catálogo de películas y episodios con sus rutas.
     *
     * @param  string  $itemType  'Movie', 'Episode' o ambos separados por coma.
     * @return array<int, array{id:string, name:string, type:string, path:?string, seriesName:?string}>
     */
    public function items(string $itemType = 'Movie,Episode', int $limit = 0): array
    {
        $params = [
            'recursive' => 'true',
            'includeItemTypes' => $itemType,
            'mediaTypes' => 'Video',
            'fields' => 'Path,MediaSources',
            'sortBy' => 'SortName',
        ];

        if ($limit > 0) {
            $params['limit'] = (string) $limit;
        }

        $data = $this->get('/Items', $params);

        $items = [];

        foreach ($data['Items'] ?? [] as $raw) {
            $sources = array_column($raw['MediaSources'] ?? [], 'Path');
            $path = $sources[0] ?? $raw['Path'] ?? null;

            $items[] = [
                'id' => (string) ($raw['Id'] ?? ''),
                'name' => (string) ($raw['Name'] ?? ''),
                'type' => (string) ($raw['Type'] ?? ''),
                'path' => is_string($path) && $path !== '' ? $path : null,
                'seriesName' => isset($raw['SeriesName']) ? (string) $raw['SeriesName'] : null,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $params = []): array
    {
        $this->assertConfigured();

        $url = $this->baseUrl . $endpoint;

        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Emby-Token: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("No se pudo conectar con Jellyfin ({$url}): {$error}");
        }

        if ($status === 401) {
            throw new RuntimeException(
                'API key de Jellyfin inválida (401). Créala en el Dashboard de Jellyfin → Advanced → API Keys.'
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                "Jellyfin respondió HTTP {$status} en {$endpoint}: " . substr($body, 0, 200)
            );
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Respuesta JSON inválida de Jellyfin en {$endpoint}.");
        }

        return $decoded;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Jellyfin no configurado. Define JELLYFIN_URL y JELLYFIN_API_KEY en el .env ' .
                '(Dashboard de Jellyfin → Advanced → API Keys).'
            );
        }
    }
}
