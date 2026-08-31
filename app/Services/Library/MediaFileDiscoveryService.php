<?php

declare(strict_types=1);

namespace App\Services\Library;

use Generator;
use RuntimeException;

/**
 * Descubre archivos de video dentro de una ruta (recursivo, con límites).
 */
final class MediaFileDiscoveryService
{
    public function __construct(
        private readonly MediaPathService $paths
    ) {
    }

    /**
     * Descubre archivos de video recursivamente.
     *
     * @param  string  $root        Ruta base.
     * @param  int     $maxDepth    Profundidad máxima de carpetas.
     * @param  bool    $skipHidden  Omitir carpetas ocultas.
     * @return Generator<array{path:string, size:int, mtime:int}>
     */
    public function discover(string $root, int $maxDepth = 8, bool $skipHidden = true): Generator
    {
        $this->paths->validate($root);

        $extensions = array_map('strtolower', config('video_extensions', ['mkv', 'mp4']));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $iterator->setMaxDepth($maxDepth);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            if ($skipHidden && $this->isHidden($file->getPathname(), $root)) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            yield [
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];
        }
    }

    /** Lista no-generador para uso simple. */
    public function discoverAll(string $root, int $maxDepth = 8): array
    {
        return iterator_to_array($this->discover($root, $maxDepth));
    }

    private function isHidden(string $path, string $root): bool
    {
        $relative = substr($path, strlen(rtrim($root, '/')) + 1);

        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }
}
