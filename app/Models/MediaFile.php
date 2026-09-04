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
            $this->uuid ??= self::generateUuid();
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

    public function tracks(): array
    {
        return SubtitleTrack::forMediaFile($this->id);
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

        $files = @scandir($dir);
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

    public function directory(): string
    {
        return dirname($this->path);
    }

    private static function fromRow(array $row): self
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

    private static function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
