<?php

declare(strict_types=1);

namespace App\Services\Library;

use RuntimeException;

/**
 * Gestiona las rutas multimedia permitidas.
 * La TUI nunca debe navegar fuera de estas rutas.
 */
final class MediaPathService
{
    /**
     * Bibliotecas configuradas: ['Movies' => '/media/Movies', ...]
     *
     * @return array<string, string>
     */
    public function libraries(): array
    {
        $paths = config('media_paths', []);

        return array_filter($paths, fn ($p) => is_string($p) && $p !== '');
    }

    public function hasLibraries(): bool
    {
        return $this->libraries() !== [];
    }

    public function libraryPath(string $name): ?string
    {
        $paths = $this->libraries();

        return $paths[$name] ?? null;
    }

    /**
     * Verifica que una ruta exista y sea legible.
     *
     * @throws RuntimeException
     */
    public function validate(string $path): void
    {
        if (! is_dir($path)) {
            throw new RuntimeException("La ruta no existe o no es un directorio: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("La ruta no es legible: {$path}");
        }
    }

    /**
     * Determina si una ruta está dentro de alguna biblioteca autorizada.
     */
    public function isAuthorized(string $path): bool
    {
        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        foreach ($this->libraries() as $libraryPath) {
            $libraryReal = realpath($libraryPath);

            if ($libraryReal !== false && str_starts_with($real, rtrim($libraryReal, '/') . '/')) {
                return true;
            }

            if ($libraryReal !== false && $real === $libraryReal) {
                return true;
            }
        }

        return false;
    }

    /** Estado de cada biblioteca para mostrarlo en la TUI. */
    public function libraryStatus(): array
    {
        $status = [];

        foreach ($this->libraries() as $name => $path) {
            if (! is_dir($path)) {
                $status[] = ['name' => $name, 'path' => $path, 'state' => 'missing'];
            } elseif (! is_readable($path)) {
                $status[] = ['name' => $name, 'path' => $path, 'state' => 'unreadable'];
            } else {
                $status[] = ['name' => $name, 'path' => $path, 'state' => 'ok'];
            }
        }

        return $status;
    }
}
