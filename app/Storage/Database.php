<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Conexión SQLite mínima compartida por todos los repositorios.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = (string) config('database_path');

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            self::$pdo = new PDO('sqlite:' . $path);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            self::$pdo->exec('PRAGMA foreign_keys = ON;');

            self::migrate();
        }

        return self::$pdo;
    }

    public static function migrate(): void
    {
        $schema = <<<'SQL'
CREATE TABLE IF NOT EXISTS media_files (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid              TEXT NOT NULL UNIQUE,
    path              TEXT NOT NULL UNIQUE,
    filename          TEXT NOT NULL,
    extension         TEXT NOT NULL,
    file_size         INTEGER NOT NULL DEFAULT 0,
    last_modified_at  TEXT NULL,
    duration          REAL NULL,
    status            TEXT NOT NULL DEFAULT 'pending',
    last_analyzed_at  TEXT NULL,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS subtitle_tracks (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    media_file_id       INTEGER NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
    source_type         TEXT NOT NULL,          -- internal | external | generated
    stream_index        INTEGER NULL,           -- índice dentro del contenedor (internal)
    path                TEXT NULL,              -- ruta del archivo externo (external)
    language            TEXT NULL,              -- código ISO 639-2, ej: eng, spa
    language_detected   TEXT NULL,              -- idioma inferido por contenido
    language_confidence REAL NULL,
    codec               TEXT NULL,              -- subrip, ass, pgs, hdmv_pgs_subtitle...
    title               TEXT NULL,
    is_text_based       INTEGER NOT NULL DEFAULT 0,
    is_forced           INTEGER NOT NULL DEFAULT 0,
    is_sdh              INTEGER NOT NULL DEFAULT 0,
    is_default          INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS processing_tasks (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid                TEXT NOT NULL UNIQUE,
    media_file_id       INTEGER NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
    subtitle_track_id   INTEGER NULL REFERENCES subtitle_tracks(id) ON DELETE SET NULL,
    action              TEXT NOT NULL,          -- extract | translate
    status              TEXT NOT NULL DEFAULT 'pending', -- pending | running | completed | failed | cancelled
    progress            INTEGER NOT NULL DEFAULT 0,
    source_language     TEXT NULL,
    target_language     TEXT NULL,
    input_path          TEXT NULL,
    output_path         TEXT NULL,
    started_at          TEXT NULL,
    completed_at        TEXT NULL,
    error_message       TEXT NULL,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_media_files_status ON media_files(status);
CREATE INDEX IF NOT EXISTS idx_media_files_path ON media_files(path);
CREATE INDEX IF NOT EXISTS idx_tracks_media ON subtitle_tracks(media_file_id);
CREATE INDEX IF NOT EXISTS idx_tasks_media ON processing_tasks(media_file_id);
CREATE INDEX IF NOT EXISTS idx_tasks_status ON processing_tasks(status);
SQL;

        self::pdo()->exec($schema);
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
