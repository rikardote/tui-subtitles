<?php

declare(strict_types=1);

namespace App\Models;

use App\Storage\Database;
use PDO;

/**
 * Tarea de procesamiento (extracción o traducción) con historial y errores.
 */
final class ProcessingTask
{
    public const ACTION_EXTRACT = 'extract';
    public const ACTION_TRANSLATE = 'translate';
    public const ACTION_DELETE = 'delete';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public int $id = 0;
    public string $uuid = '';
    public int $mediaFileId = 0;
    public ?int $subtitleTrackId = null;
    public string $action = self::ACTION_EXTRACT;
    public string $status = self::STATUS_PENDING;
    public int $progress = 0;
    public ?string $sourceLanguage = null;
    public ?string $targetLanguage = null;
    public ?string $inputPath = null;
    public ?string $outputPath = null;
    public ?string $startedAt = null;
    public ?string $completedAt = null;
    public ?string $errorMessage = null;
    public string $createdAt = '';
    public string $updatedAt = '';

    /** @return self[] */
    public static function recent(int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM processing_tasks ORDER BY id DESC LIMIT ?');
        $stmt->execute([$limit]);

        return array_map(fn (array $row) => self::fromRow($row), $stmt->fetchAll());
    }

    public static function pendingCount(): int
    {
        return (int) Database::pdo()->query(
            "SELECT COUNT(*) FROM processing_tasks WHERE status = 'pending'"
        )->fetchColumn();
    }

    public function save(): void
    {
        $now = Database::now();

        if ($this->id > 0) {
            $sql = 'UPDATE processing_tasks SET
                        media_file_id = ?, subtitle_track_id = ?,
                        action = ?, status = ?, progress = ?,
                        source_language = ?, target_language = ?,
                        input_path = ?, output_path = ?,
                        started_at = ?, completed_at = ?, error_message = ?,
                        updated_at = ?
                    WHERE id = ?';
            Database::pdo()->prepare($sql)->execute([
                $this->mediaFileId, $this->subtitleTrackId,
                $this->action, $this->status, $this->progress,
                $this->sourceLanguage, $this->targetLanguage,
                $this->inputPath, $this->outputPath,
                $this->startedAt, $this->completedAt, $this->errorMessage,
                $now, $this->id,
            ]);
        } else {
            $this->uuid ??= self::generateUuid();
            $this->createdAt = $now;
            $this->updatedAt = $now;

            $sql = 'INSERT INTO processing_tasks
                        (uuid, media_file_id, subtitle_track_id, action, status, progress,
                         source_language, target_language, input_path, output_path,
                         started_at, completed_at, error_message, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            Database::pdo()->prepare($sql)->execute([
                $this->uuid, $this->mediaFileId, $this->subtitleTrackId,
                $this->action, $this->status, $this->progress,
                $this->sourceLanguage, $this->targetLanguage,
                $this->inputPath, $this->outputPath,
                $this->startedAt, $this->completedAt, $this->errorMessage,
                $this->createdAt, $this->updatedAt,
            ]);

            $this->id = (int) Database::pdo()->lastInsertId();
        }
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_RUNNING => 'En proceso',
            self::STATUS_COMPLETED => 'Completado',
            self::STATUS_FAILED => 'Error',
            self::STATUS_CANCELLED => 'Cancelado',
            default => $this->status,
        };
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_EXTRACT => 'Extracción',
            self::ACTION_TRANSLATE => 'Traducción',
            self::ACTION_DELETE => 'Eliminación',
            default => $this->action,
        };
    }

    private static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int) $row['id'];
        $t->uuid = $row['uuid'];
        $t->mediaFileId = (int) $row['media_file_id'];
        $t->subtitleTrackId = $row['subtitle_track_id'] !== null ? (int) $row['subtitle_track_id'] : null;
        $t->action = $row['action'];
        $t->status = $row['status'];
        $t->progress = (int) $row['progress'];
        $t->sourceLanguage = $row['source_language'];
        $t->targetLanguage = $row['target_language'];
        $t->inputPath = $row['input_path'];
        $t->outputPath = $row['output_path'];
        $t->startedAt = $row['started_at'];
        $t->completedAt = $row['completed_at'];
        $t->errorMessage = $row['error_message'];
        $t->createdAt = $row['created_at'];
        $t->updatedAt = $row['updated_at'];

        return $t;
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
