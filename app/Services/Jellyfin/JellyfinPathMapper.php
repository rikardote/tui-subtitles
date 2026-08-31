<?php

declare(strict_types=1);

namespace App\Services\Jellyfin;

/**
 * Traduce rutas dentro del contenedor Jellyfin (p.ej. /data/movies/...)
 * a rutas del host (p.ej. /mnt/disk2tb/data/media/movies/...).
 *
 * La API de Jellyfin siempre devuelve rutas del contenedor; como la app
 * corre en el host, necesita este mapeo para acceder a los archivos.
 */
final class JellyfinPathMapper
{
    /** @var array<string, string> prefijoContenedor => raízHost */
    private array $map = [];

    /**
     * @param  array<string, string>  $explicitMap  (config 'jellyfin.path_map')
     * @param  array<string, string>  $mediaPaths   (config 'media_paths')
     */
    public function __construct(array $explicitMap, array $mediaPaths, string $containerPrefix = '/data')
    {
        foreach ($explicitMap as $container => $host) {
            if ($host !== '' && $container !== '') {
                $this->map[rtrim((string) $container, '/')] = rtrim((string) $host, '/');
            }
        }

        // Deducción automática: /data/<carpeta> → <ruta host> por biblioteca.
        // Ej: media_paths['Movies']=/mnt/disk2tb/data/media/movies
        //     → contenedor /data/movies → host /mnt/disk2tb/data/media/movies
        foreach ($mediaPaths as $hostPath) {
            if ($hostPath === '' || ! is_dir($hostPath)) {
                continue;
            }

            $container = rtrim($containerPrefix, '/') . '/' . basename((string) $hostPath);
            $this->map[$container] ??= rtrim((string) $hostPath, '/');
        }
    }

    /**
     * Convierte una ruta del contenedor a ruta del host.
     * Devuelve null si no se puede mapear.
     */
    public function toHostPath(string $containerPath): ?string
    {
        $normalized = str_replace('\\', '/', $containerPath);

        $best = null;
        $bestLen = 0;

        foreach ($this->map as $containerPrefix => $hostRoot) {
            $prefix = rtrim($containerPrefix, '/') . '/';

            if (str_starts_with($normalized, $prefix) && strlen($prefix) > $bestLen) {
                $best = $hostRoot . '/' . substr($normalized, strlen($prefix));
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    /** @return array<string, string> Mapa contenedor → host (para depuración). */
    public function map(): array
    {
        return $this->map;
    }
}
