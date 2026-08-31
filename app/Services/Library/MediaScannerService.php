<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Models\MediaFile;
use App\Storage\Database;
use Closure;

/**
 * Orquesta el escaneo completo de bibliotecas: descubrimiento, diff y registro.
 */
final class MediaScannerService
{
    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaFileDiscoveryService $discovery,
        private readonly MediaChangeDetectorService $detector,
    ) {
    }

    /**
     * Escanea una única biblioteca por nombre.
     *
     * @param  Closure|null  $onProgress  fn(string $filePath) => void
     * @return array{library:string, total:int, new:int, modified:int, unchanged:int, registered: MediaFile[]}
     */
    public function scanLibrary(string $libraryName, ?Closure $onProgress = null): array
    {
        $path = $this->paths->libraryPath($libraryName);

        if ($path === null) {
            throw new \RuntimeException("Biblioteca no configurada: {$libraryName}");
        }

        return $this->scanPath($libraryName, $path, $onProgress);
    }

    /**
     * Escanea todas las bibliotecas configuradas.
     *
     * @return array<int, array>
     */
    public function scanAll(?Closure $onProgress = null): array
    {
        $results = [];

        foreach ($this->paths->libraries() as $name => $path) {
            $results[] = $this->scanPath($name, $path, $onProgress);
        }

        return $results;
    }

    /**
     * @return array{library:string, total:int, new:int, modified:int, unchanged:int, registered: MediaFile[]}
     */
    private function scanPath(string $libraryName, string $path, ?Closure $onProgress): array
    {
        $discovered = $this->discovery->discoverAll($path);
        $diff = $this->detector->diff($discovered);

        $registered = [];

        foreach ($diff['new'] as $item) {
            $registered[] = $this->detector->register($item);
            $onProgress?->__invoke($item['path']);
        }

        foreach ($diff['modified'] as $item) {
            $registered[] = $this->detector->register($item);
            $onProgress?->__invoke($item['path']);
        }

        return [
            'library' => $libraryName,
            'total' => count($discovered),
            'new' => count($diff['new']),
            'modified' => count($diff['modified']),
            'unchanged' => count($diff['unchanged']),
            'registered' => $registered,
        ];
    }
}
