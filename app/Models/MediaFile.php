<?php

declare(strict_types=1);

namespace App\Models;

use App\Storage\Database;
use PDO;

/**
 * Representa un archivo de video detectado en una biblioteca.
 */
final class MediaFile
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ANALYZED = 'analyzed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_ERROR = 'error';

    public int $id = 0;
    public string $uuid = '';
    public string $path = '';
    public string $filename = '';
    public string $extension = '';
    public int $fileSize = 0;
    public ?string $lastModifiedAt = null;
    public ?float $duration = null;
    public string $status = self::STATUS_PENDING;
    public ?string $lastAnalyzedAt = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /** Caché de pistas para evitar múltiples queries dentro del mismo request. */
    private ?array $tracksCache = null;

    public static function findById(int $id): ?self
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media_files WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    public static function findByPath(string $path): ?self
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media_files WHERE path = ?');
        $stmt->execute([$path]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media_files WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function all(string $status = null, string $order = 'filename ASC'): array
    {
        $sql = 'SELECT * FROM media_files';
        $params = [];

        if ($status !== null) {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY ' . $order;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => self::fromRow($row), $stmt->fetchAll());
    }

    public static function count(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM media_files')->fetchColumn();
    }

    /**
     * Índice ligero path → {id, size, mtime} para comparar cambios en disco
     * sin hidratar todos los objetos ni cargar subtítulos (evita cargar
     * toda la tabla en memoria).
     *
     * @return array<string, array{id:int, size:int, mtime:?string}>
     */
    public static function pathIndex(): array
    {
        $rows = Database::pdo()
            ->query('SELECT id, path, file_size, last_modified_at FROM media_files')
            ->fetchAll();

        $map = [];

        foreach ($rows as $row) {
            $map[$row['path']] = [
                'id' => (int) $row['id'],
                'size' => (int) $row['file_size'],
                'mtime' => $row['last_modified_at'],
            ];
        }

        return $map;
    }

    public function save(): void
    {
        $now = Database::now();

        if ($this->id > 0) {
            $sql = 'UPDATE media_files SET
                        path = ?, filename = ?, extension = ?, file_size = ?,
                        last_modified_at = ?, duration = ?, status = ?,
                        last_analyzed_at = ?, updated_at = ?
                    WHERE id = ?';
            Database::pdo()->prepare($sql)->execute([
                $this->path, $this->filename, $this->extension, $this->fileSize,
                $this->lastModifiedAt, $this->duration, $this->status,
                $this->lastAnalyzedAt, $now, $this->id,
            ]);
        } else {
            $this->uuid ??= \App\Support\Uuid::generate();
            $this->createdAt = $now;
            $this->updatedAt = $now;

            $sql = 'INSERT INTO media_files
                        (uuid, path, filename, extension, file_size, last_modified_at,
                         duration, status, last_analyzed_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            Database::pdo()->prepare($sql)->execute([
                $this->uuid, $this->path, $this->filename, $this->extension, $this->fileSize,
                $this->lastModifiedAt, $this->duration, $this->status,
                $this->lastAnalyzedAt, $this->createdAt, $this->updatedAt,
            ]);

            $this->id = (int) Database::pdo()->lastInsertId();
        }
    }

    /**
     * Pistas del archivo. Se cachea en memoria para que llamadas
     * sucesivas (hasSpanish, englishTracks, reviewPendingCount…) no
     * vuelvan a la base de datos dentro del mismo request.
     */
    public function tracks(): array
    {
        return $this->tracksCache ??= SubtitleTrack::forMediaFile($this->id);
    }

    /**
     * Pre-inyecta las pistas desde fuera (batch loading).
     * Después de llamar esto, tracks() devolverá el array inyectado
     * sin tocar la BD.
     *
     * @param SubtitleTrack[] $tracks
     */
    public function setTracksCache(array $tracks): void
    {
        $this->tracksCache = $tracks;
    }

    /**
     * Invalida el cache de pistas (obligatorio tras analizar, crear o borrar
     * pistas, para que la siguiente consulta lea el estado real de la BD).
     */
    public function clearTracksCache(): void
    {
        $this->tracksCache = null;
    }

    /**
     * Carga múltiples archivos por sus IDs en una sola query.
     *
     * @param  int[]  $ids
     * @return array<int, self>  Indexado por id
     */
    public static function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM media_files WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $media = self::fromRow($row);
            $map[$media->id] = $media;
        }

        return $map;
    }

    /** @return SubtitleTrack[] Subtítulos externos existentes junto al video */
    public function externalTracks(): array
    {
        return array_values(array_filter(
            $this->tracks(),
            fn (SubtitleTrack $t) => $t->sourceType === 'external'
        ));
    }

    /** @return SubtitleTrack[] Pistas internas de texto */
    public function internalTextTracks(): array
    {
        return array_values(array_filter(
            $this->tracks(),
            fn (SubtitleTrack $t) => $t->sourceType === 'internal' && $t->isTextBased
        ));
    }

    public function hasSpanish(): bool
    {
        foreach ($this->tracks() as $track) {
            $lang = $track->languageDetected ?? $track->language;
            if (in_array($lang, ['spa', 'es', 'spanish', 'castellano', 'latino'], true)) {
                return true;
            }
        }

        // Comprobación directa en disco de archivo compañero (ej. Movie.srt, Movie.es.srt, sensible/insensible a mayúsculas)
        $dir = $this->directory();
        $baseLower = mb_strtolower(pathinfo($this->filename, PATHINFO_FILENAME));
        $extensions = ['srt', 'ass', 'ssa', 'vtt'];

        $files = is_dir($dir) && is_readable($dir) ? scandir($dir) : false;
        if ($files !== false) {
            foreach ($files as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (! in_array($ext, $extensions, true)) {
                    continue;
                }
                $entryBaseLower = mb_strtolower(pathinfo($entry, PATHINFO_FILENAME));
                if ($entryBaseLower === $baseLower
                    || str_starts_with($entryBaseLower, $baseLower . '.es')
                    || str_starts_with($entryBaseLower, $baseLower . '.spa')
                    || str_starts_with($entryBaseLower, $baseLower . '.spanish')
                    || str_starts_with($entryBaseLower, $baseLower . '.latino')
                    || str_starts_with($entryBaseLower, $baseLower . '_es')
                    || str_starts_with($entryBaseLower, $baseLower . '-es')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    public function englishTracks(): array
    {
        $tracks = array_values(array_filter(
            $this->tracks(),
            fn (SubtitleTrack $t) => in_array($t->languageDetected ?? $t->language, ['eng', 'en'], true)
        ));

        // Prioridad para selección automática: normal (0) → SDH (1) → forced (2+)
        // La pista "forced" suele tener solo unos pocos bloques (frases especiales)
        // y no representa el subtítulo completo.
        usort($tracks, function (SubtitleTrack $a, SubtitleTrack $b): int {
            $score = fn (SubtitleTrack $t): int => ((int) $t->isForced * 2) + (int) $t->isSdh;

            return $score($a) <=> $score($b);
        });

        return $tracks;
    }

    /**
     * Mejor pista de texto en inglés para traducción automática:
     *  1. NUNCA elige pistas forced (si hay alternativa).
     *  2. Prioriza normal (sin SDH) sobre SDH.
     *
     * Devuelve null si solo hay pistas forced (el usuario no usa forzados).
     */
    public function bestEnglishTextTrack(): ?SubtitleTrack
    {
        $tracks = $this->englishTracks();

        $text = array_values(array_filter($tracks, fn (SubtitleTrack $t) => $t->isTextBased));
        if ($text === []) {
            return null;
        }

        // Si hay alguna pista no-forced, elegir la mejor de esas (ya ordenadas)
        $nonForced = array_values(array_filter($text, fn (SubtitleTrack $t) => ! $t->isForced));
        if ($nonForced !== []) {
            return $nonForced[0];
        }

        // Solo hay pistas forced → el usuario nunca las usa
        return null;
    }

    /**
     * Mejor pista en inglés global (texto o imagen procesable con OCR).
     */
    public function bestEnglishTrack(): ?SubtitleTrack
    {
        $textTrack = $this->bestEnglishTextTrack();
        if ($textTrack !== null) {
            return $textTrack;
        }

        /** @var \App\Services\Ocr\OcrService $ocr */
        $ocr = \App\Services\Container::get(\App\Services\Ocr\OcrService::class);
        if (! $ocr->available()) {
            return null;
        }

        $tracks = $this->englishTracks();
        $nonForced = array_values(array_filter($tracks, fn (SubtitleTrack $t) => ! $t->isForced));

        return $nonForced[0] ?? ($tracks[0] ?? null);
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    public static function fromRow(array $row): self
    {
        $m = new self();
        $m->id = (int) $row['id'];
        $m->uuid = $row['uuid'];
        $m->path = $row['path'];
        $m->filename = $row['filename'];
        $m->extension = $row['extension'];
        $m->fileSize = (int) $row['file_size'];
        $m->lastModifiedAt = $row['last_modified_at'];
        $m->duration = $row['duration'] !== null ? (float) $row['duration'] : null;
        $m->status = $row['status'];
        $m->lastAnalyzedAt = $row['last_analyzed_at'];
        $m->createdAt = $row['created_at'];
        $m->updatedAt = $row['updated_at'];

        return $m;
    }

}
