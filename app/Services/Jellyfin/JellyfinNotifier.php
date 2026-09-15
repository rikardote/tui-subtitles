<?php

declare(strict_types=1);

namespace App\Services\Jellyfin;

use App\Services\Container;
use Throwable;

/**
 * Notifica a Jellyfin que un archivo cambió para que detecte
 * los subtítulos recién generados sin esperar su escaneo periódico.
 *
 * Se usa al completar una traducción: refresca el item concreto
 * (o la biblioteca completa como respaldo).
 */
final class JellyfinNotifier
{
    public function __construct(
        private readonly JellyfinApiClient $api,
        private readonly JellyfinPathMapper $mapper,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    /**
     * Refresca el item de Jellyfin correspondiente al video indicado.
     *
     * @return string Descripción del resultado (para el log).
     */
    public function refreshForVideo(string $hostVideoPath): string
    {
        if (! $this->isConfigured()) {
            return 'Jellyfin no configurado (omitido)';
        }

        try {
            $itemId = $this->api->findItemByHostPath($hostVideoPath, $this->mapper);

            if ($itemId !== null) {
                $ok = $this->api->refreshItem($itemId);

                return $ok
                    ? "Jellyfin: item {$itemId} refrescado (subtítulo visible)"
                    : "Jellyfin: no se pudo refrescar el item {$itemId}";
            }

            // No se encontró el item concreto: refrescar la biblioteca completa
            $ok = $this->api->refreshLibrary();

            return $ok
                ? 'Jellyfin: biblioteca refrescada (item no localizado)'
                : 'Jellyfin: no se pudo refrescar la biblioteca';
        } catch (Throwable $e) {
            return 'Jellyfin: error al refrescar — ' . $e->getMessage();
        }
    }
}
