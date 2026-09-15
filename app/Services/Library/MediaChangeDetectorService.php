<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Models\MediaFile;

/**
 * Compara archivos encontrados en disco contra la base de datos
 * para detectar nuevos, modificados y sin cambios.
 */
final class MediaChangeDetectorService
{
    /**
     * @param  array<int, array{path:string, size:int, mtime:int}>  $discovered
     * @return array{new: array, modified: array, unchanged: array}
     */
    public function diff(array $discovered): array
    {
        // Índice ligero (sin hidratar objetos completos ni cargar subtítulos)
        $known = MediaFile::pathIndex();

        $new = [];
        $modified = [];
        $unchanged = [];

        foreach ($discovered as $item) {
            $path = $item['path'];

            if (! isset($known[$path])) {
                $new[] = $item;
                continue;
            }

            $file = $known[$path];
            $lastMtime = $file['mtime'] !== null
                ? strtotime($file['mtime'] . ' UTC')
                : null;

            if ($lastMtime === null || abs($lastMtime - $item['mtime']) > 2 || $file['size'] !== $item['size']) {
                $modified[] = $item;
                continue;
            }

            $unchanged[] = $item;
        }

        return compact('new', 'modified', 'unchanged');
    }

    /**
     * Registra (o actualiza) un archivo descubierto en la base de datos.
     */
    public function register(array $item): MediaFile
    {
        $existing = MediaFile::findByPath($item['path']);

        if ($existing !== null) {
            $existing->fileSize = $item['size'];
            $existing->lastModifiedAt = gmdate('Y-m-d H:i:s', $item['mtime']);
            $existing->updatedAt = \App\Storage\Database::now();
            $existing->save();

            return $existing;
        }

        $file = new MediaFile();
        $file->uuid = \App\Support\Uuid::generate();
        $file->path = $item['path'];
        $file->filename = basename($item['path']);
        $file->extension = strtolower(pathinfo($item['path'], PATHINFO_EXTENSION));
        $file->fileSize = $item['size'];
        $file->lastModifiedAt = gmdate('Y-m-d H:i:s', $item['mtime']);
        $file->status = MediaFile::STATUS_PENDING;
        $file->save();

        return $file;
    }

}
