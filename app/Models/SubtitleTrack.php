<?php

declare(strict_types=1);

namespace App\Models;

use App\Storage\Database;
use PDO;

/**
 * Pista de subtítulo: interna (dentro del contenedor), externa (archivo .srt/.ass)
 * o generada (producida por la propia aplicación).
 */
final class SubtitleTrack
{
    public const SOURCE_INTERNAL = 'internal';
    public const SOURCE_EXTERNAL = 'external';
    public const SOURCE_GENERATED = 'generated';

    public int $id = 0;
    public int $mediaFileId = 0;
    public string $sourceType = self::SOURCE_INTERNAL;
    public ?int $streamIndex = null;
    public ?string $path = null;
    public ?string $language = null;
    public ?string $languageDetected = null;
    public ?float $languageConfidence = null;
    public ?string $codec = null;
    public ?string $title = null;
    public bool $isTextBased = false;
    public bool $isForced = false;
    public bool $isSdh = false;
    public bool $isDefault = false;
    public string $createdAt = '';
    public string $updatedAt = '';

    /** @return self[] */
    public static function forMediaFile(int $mediaFileId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subtitle_tracks WHERE media_file_id = ? ORDER BY
                CASE source_type WHEN "external" THEN 0 ELSE 1 END,
                stream_index ASC, id ASC'
        );
        $stmt->execute([$mediaFileId]);

        return array_map(fn (array $row) => self::fromRow($row), $stmt->fetchAll());
    }

    public static function findById(int $id): ?self
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM subtitle_tracks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    public static function deleteForMediaFile(int $mediaFileId): void
    {
        Database::pdo()->prepare('DELETE FROM subtitle_tracks WHERE media_file_id = ?')
            ->execute([$mediaFileId]);
    }

    public function save(): void
    {
        $now = Database::now();

        if ($this->id > 0) {
            $sql = 'UPDATE subtitle_tracks SET
                        source_type = ?, stream_index = ?, path = ?,
                        language = ?, language_detected = ?, language_confidence = ?,
                        codec = ?, title = ?,
                        is_text_based = ?, is_forced = ?, is_sdh = ?, is_default = ?,
                        updated_at = ?
                    WHERE id = ?';
            Database::pdo()->prepare($sql)->execute([
                $this->sourceType, $this->streamIndex, $this->path,
                $this->language, $this->languageDetected, $this->languageConfidence,
                $this->codec, $this->title,
                (int) $this->isTextBased, (int) $this->isForced, (int) $this->isSdh, (int) $this->isDefault,
                $now, $this->id,
            ]);
        } else {
            $this->createdAt = $now;
            $this->updatedAt = $now;

            $sql = 'INSERT INTO subtitle_tracks
                        (media_file_id, source_type, stream_index, path,
                         language, language_detected, language_confidence,
                         codec, title,
                         is_text_based, is_forced, is_sdh, is_default,
                         created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            Database::pdo()->prepare($sql)->execute([
                $this->mediaFileId, $this->sourceType, $this->streamIndex, $this->path,
                $this->language, $this->languageDetected, $this->languageConfidence,
                $this->codec, $this->title,
                (int) $this->isTextBased, (int) $this->isForced, (int) $this->isSdh, (int) $this->isDefault,
                $this->createdAt, $this->updatedAt,
            ]);

            $this->id = (int) Database::pdo()->lastInsertId();
        }
    }

    /** Etiqueta humana del idioma, ej: "Inglés (en)" */
    public function languageLabel(): string
    {
        // languageDetected es más fiable (normalizado a ISO 639-2)
        $lang = $this->languageDetected ?? $this->language;

        return $lang ? self::languageName($lang) : 'Desconocido';
    }

    public static function languageName(string $code): string
    {
        $names = [
            'eng' => 'Inglés', 'spa' => 'Español', 'jpn' => 'Japonés',
            'fra' => 'Francés', 'deu' => 'Alemán', 'ita' => 'Italiano',
            'por' => 'Portugués', 'rus' => 'Ruso', 'chi' => 'Chino',
            'kor' => 'Coreano', 'ara' => 'Árabe', 'tur' => 'Turco',
            'nld' => 'Neerlandés', 'pol' => 'Polaco', 'swe' => 'Sueco',
            'dan' => 'Danés', 'fin' => 'Finlandés', 'nor' => 'Noruego',
            'ces' => 'Checo', 'ell' => 'Griego', 'hun' => 'Húngaro',
            'hin' => 'Hindi', 'heb' => 'Hebreo', 'tha' => 'Tailandés',
            'vie' => 'Vietnamita', 'ukr' => 'Ucraniano', 'cat' => 'Catalán',
            'glg' => 'Gallego', 'eus' => 'Euskera', 'lat' => 'Latín',
        ];

        return $names[$code] ?? strtoupper($code);
    }

    public function codecLabel(): string
    {
        return match ($this->codec) {
            'subrip' => 'SubRip (SRT)',
            'ass' => 'ASS',
            'ssa' => 'SSA',
            'webvtt', 'vtt' => 'WebVTT',
            'hdmv_pgs_subtitle' => 'PGS (imagen)',
            'dvd_subtitle' => 'VobSub (imagen)',
            'mov_text' => 'MOV Text',
            default => $this->codec ?? 'Desconocido',
        };
    }

    public function isImageBased(): bool
    {
        return ! $this->isTextBased;
    }

    private static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int) $row['id'];
        $t->mediaFileId = (int) $row['media_file_id'];
        $t->sourceType = $row['source_type'];
        $t->streamIndex = $row['stream_index'] !== null ? (int) $row['stream_index'] : null;
        $t->path = $row['path'];
        $t->language = $row['language'];
        $t->languageDetected = $row['language_detected'];
        $t->languageConfidence = $row['language_confidence'] !== null ? (float) $row['language_confidence'] : null;
        $t->codec = $row['codec'];
        $t->title = $row['title'];
        $t->isTextBased = (bool) $row['is_text_based'];
        $t->isForced = (bool) $row['is_forced'];
        $t->isSdh = (bool) $row['is_sdh'];
        $t->isDefault = (bool) $row['is_default'];
        $t->createdAt = $row['created_at'];
        $t->updatedAt = $row['updated_at'];

        return $t;
    }
}
