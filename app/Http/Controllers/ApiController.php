<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Router;
use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Models\SubtitleTrack;
use App\Services\Container;
use App\Services\Jellyfin\JellyfinApiClient;
use App\Services\Jellyfin\JellyfinPathMapper;
use App\Services\Jellyfin\JellyfinSyncService;
use App\Services\Library\MediaPathService;
use App\Services\Library\MediaScannerService;
use App\Services\Media\SubtitleExtractorService;
use App\Services\Media\SubtitleRemovalService;
use App\Services\Subtitle\SubtitleAnalyzerService;
use App\Services\Subtitle\SubtitleFilenameService;
use App\Services\Translation\TranslationProviderInterface;
use App\Storage\Database;
use Throwable;

final class ApiController
{
    /**
     * Resumen del Dashboard.
     */
    public function dashboard(): array
    {
        $totalFiles = MediaFile::count();
        $pendingFiles = (int) Database::pdo()->query("SELECT COUNT(*) FROM media_files WHERE status = 'pending'")->fetchColumn();
        $analyzedFiles = (int) Database::pdo()->query("SELECT COUNT(*) FROM media_files WHERE status != 'pending'")->fetchColumn();

        // Conteo de archivos con español
        $sqlSpanish = "SELECT COUNT(DISTINCT media_file_id) FROM subtitle_tracks WHERE language_detected IN ('spa', 'es') OR language IN ('spa', 'es')";
        $hasSpanishCount = (int) Database::pdo()->query($sqlSpanish)->fetchColumn();
        $missingSpanishCount = max(0, $analyzedFiles - $hasSpanishCount);

        // Proveedor activo
        /** @var TranslationProviderInterface $provider */
        $provider = Container::get(TranslationProviderInterface::class);
        $providerName = $provider->name();
        $providerAvailable = $provider->available();

        // Bibliotecas
        /** @var MediaPathService $pathService */
        $pathService = Container::get(MediaPathService::class);
        $libraryStats = [];
        foreach ($pathService->libraries() as $name => $path) {
            $count = (int) Database::pdo()->prepare("SELECT COUNT(*) FROM media_files WHERE path LIKE ?")->execute([$path . '%']) ? Database::pdo()->query("SELECT COUNT(*) FROM media_files WHERE path LIKE '{$path}%'")->fetchColumn() : 0;
            $libraryStats[] = [
                'name' => $name,
                'path' => $path,
                'exists' => is_dir($path),
                'files' => (int) $count,
            ];
        }

        // Tareas recientes
        $recentTasks = array_map(function (ProcessingTask $t) {
            $media = MediaFile::findById($t->mediaFileId);
            return [
                'id' => $t->id,
                'uuid' => $t->uuid,
                'filename' => $media?->filename ?? '(desconocido)',
                'action' => $t->actionLabel(),
                'status' => $t->status,
                'status_label' => $t->statusLabel(),
                'progress' => $t->progress,
                'error' => $t->errorMessage,
                'created_at' => $t->createdAt,
            ];
        }, ProcessingTask::recent(5));

        return [
            'total_files' => $totalFiles,
            'analyzed_files' => $analyzedFiles,
            'pending_files' => $pendingFiles,
            'has_spanish' => $hasSpanishCount,
            'missing_spanish' => $missingSpanishCount,
            'spanish_percentage' => $analyzedFiles > 0 ? round(($hasSpanishCount / $analyzedFiles) * 100, 1) : 0,
            'provider' => [
                'id' => config('translation.provider'),
                'name' => $providerName,
                'available' => $providerAvailable,
            ],
            'libraries' => $libraryStats,
            'recent_tasks' => $recentTasks,
        ];
    }

    /**
     * Listado paginado de archivos multimedia con filtros.
     */
    public function mediaList(): array
    {
        $search = trim((string) Router::query('q', ''));
        $library = trim((string) Router::query('library', ''));
        $status = trim((string) Router::query('status', ''));
        $spanishFilter = Router::query('has_spanish', 'all'); // 'all', '1', '0'
        $page = max(1, (int) Router::query('page', 1));
        $perPage = min(100, max(6, (int) Router::query('per_page', 24)));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = 'filename LIKE ?';
            $params[] = "%{$search}%";
        }

        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        if ($library !== '') {
            $paths = (array) config('media_paths', []);
            if (isset($paths[$library])) {
                $where[] = 'path LIKE ?';
                $params[] = $paths[$library] . '%';
            }
        }

        if ($spanishFilter === '1') {
            $where[] = "id IN (SELECT DISTINCT media_file_id FROM subtitle_tracks WHERE language_detected IN ('spa', 'es') OR language IN ('spa', 'es'))";
        } elseif ($spanishFilter === '0') {
            $where[] = "status != 'pending' AND id NOT IN (SELECT DISTINCT media_file_id FROM subtitle_tracks WHERE language_detected IN ('spa', 'es') OR language IN ('spa', 'es'))";
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // Conteo total
        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM media_files {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Consulta de datos
        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT * FROM media_files {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}";
        $dataStmt = Database::pdo()->prepare($dataSql);
        $dataStmt->execute($params);

        $items = [];
        foreach ($dataStmt->fetchAll() as $row) {
            $media = MediaFile::findById((int) $row['id']);
            if (! $media) continue;

            $tracks = $media->tracks();
            $hasSpanish = $media->hasSpanish();
            $englishCount = count($media->englishTracks());

            $items[] = [
                'id' => $media->id,
                'uuid' => $media->uuid,
                'filename' => $media->filename,
                'path' => $media->path,
                'extension' => strtoupper($media->extension),
                'file_size' => $this->humanSize($media->fileSize),
                'file_size_raw' => $media->fileSize,
                'duration' => $media->duration !== null ? round($media->duration / 60, 1) : null,
                'duration_formatted' => $media->duration !== null ? gmdate('H:i:s', (int) $media->duration) : null,
                'status' => $media->status,
                'last_analyzed_at' => $media->lastAnalyzedAt,
                'has_spanish' => $hasSpanish,
                'english_tracks_count' => $englishCount,
                'tracks_count' => count($tracks),
                'tracks_summary' => array_map(fn (SubtitleTrack $t) => [
                    'id' => $t->id,
                    'source_type' => $t->sourceType,
                    'language' => $t->languageLabel(),
                    'lang_code' => $t->languageDetected ?? $t->language ?? 'und',
                    'codec' => $t->codecLabel(),
                    'is_text' => $t->isTextBased,
                    'is_sdh' => $t->isSdh,
                    'is_forced' => $t->isForced,
                    'is_generated' => $t->sourceType === SubtitleTrack::SOURCE_GENERATED,
                ], $tracks),
            ];
        }

        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Detalle completo de un video y sus pistas de subtítulos.
     */
    public function mediaDetail(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $media = MediaFile::findById($id);

        if (! $media) {
            throw new \RuntimeException('Archivo no encontrado', 404);
        }

        // Si nunca se analizó, analizarlo automáticamente
        if ($media->status === MediaFile::STATUS_PENDING || $media->lastAnalyzedAt === null) {
            /** @var SubtitleAnalyzerService $analyzer */
            $analyzer = Container::get(SubtitleAnalyzerService::class);
            $analyzer->analyze($media);
            $media = MediaFile::findById($id) ?? $media;
            $media->status = MediaFile::STATUS_ANALYZED;
            $media->lastAnalyzedAt = gmdate('Y-m-d H:i:s');
            $media->save();
        }

        $tracks = $media->tracks();

        /** @var SubtitleFilenameService $filenameService */
        $filenameService = Container::get(SubtitleFilenameService::class);
        $expectedOutputPath = $filenameService->pathForMedia($media);
        $esFileExists = file_exists($expectedOutputPath);

        return [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'filename' => $media->filename,
            'path' => $media->path,
            'directory' => $media->directory(),
            'extension' => strtoupper($media->extension),
            'file_size' => $this->humanSize($media->fileSize),
            'file_size_raw' => $media->fileSize,
            'duration' => $media->duration !== null ? round($media->duration / 60, 1) : null,
            'status' => $media->status,
            'last_analyzed_at' => $media->lastAnalyzedAt,
            'has_spanish' => $media->hasSpanish(),
            'expected_output_path' => $expectedOutputPath,
            'output_file_exists' => $esFileExists,
            'tracks' => array_map(fn (SubtitleTrack $t) => [
                'id' => $t->id,
                'source_type' => $t->sourceType,
                'stream_index' => $t->streamIndex,
                'path' => $t->path,
                'language' => $t->languageLabel(),
                'lang_code' => $t->languageDetected ?? $t->language ?? 'und',
                'language_confidence' => $t->languageConfidence,
                'codec' => $t->codecLabel(),
                'raw_codec' => $t->codec,
                'title' => $t->title,
                'is_text' => $t->isTextBased,
                'is_sdh' => $t->isSdh,
                'is_forced' => $t->isForced,
                'is_default' => $t->isDefault,
                'is_generated' => $t->sourceType === SubtitleTrack::SOURCE_GENERATED,
                'can_delete' => $t->sourceType !== SubtitleTrack::SOURCE_INTERNAL,
                'can_translate' => $t->isTextBased,
            ], $tracks),
        ];
    }

    /**
     * Forzar re-análisis FFprobe de un archivo.
     */
    public function mediaAnalyze(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $media = MediaFile::findById($id);

        if (! $media) {
            throw new \RuntimeException('Archivo no encontrado', 404);
        }

        /** @var SubtitleAnalyzerService $analyzer */
        $analyzer = Container::get(SubtitleAnalyzerService::class);
        $res = $analyzer->analyze($media);

        $media->status = MediaFile::STATUS_ANALYZED;
        $media->lastAnalyzedAt = Database::now();
        $media->save();

        return [
            'success' => true,
            'message' => 'Análisis completado',
            'internal_tracks' => count($res['internal']),
            'external_tracks' => count($res['external']),
        ];
    }

    /**
     * Traducir una pista de subtítulo al español.
     */
    public function mediaTranslate(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $media = MediaFile::findById($id);

        if (! $media) {
            throw new \RuntimeException('Archivo no encontrado', 404);
        }

        $body = Router::body();
        $trackId = isset($body['track_id']) ? (int) $body['track_id'] : null;
        $tracks = $media->tracks();
        $targetTrack = null;

        if ($trackId !== null && $trackId > 0) {
            foreach ($tracks as $t) {
                if ($t->id === $trackId) {
                    $targetTrack = $t;
                    break;
                }
            }
        } else {
            // Auto-seleccionar mejor pista de texto (preferencia inglés)
            $candidates = array_values(array_filter($tracks, fn ($t) => $t->isTextBased));
            foreach ($candidates as $c) {
                $l = $c->languageDetected ?? $c->language;
                if (in_array($l, ['eng', 'en'], true)) {
                    $targetTrack = $c;
                    break;
                }
            }
            if (! $targetTrack && $candidates !== []) {
                $targetTrack = $candidates[0];
            }
        }

        if (! $targetTrack) {
            throw new \RuntimeException('No se encontró una pista de subtítulos de texto adecuada para traducir.');
        }

        if (! $targetTrack->isTextBased) {
            throw new \RuntimeException('La pista seleccionada es de imagen (PGS/VobSub) y no se puede traducir sin OCR.');
        }

        /** @var \App\Services\Queue\QueueService $queue */
        $queue = Container::get(\App\Services\Queue\QueueService::class);
        $task = $queue->enqueueTranslation($media, $targetTrack);

        return [
            'success' => true,
            'queued' => true,
            'task_id' => $task->id,
            'message' => 'Tarea agregada a la cola de traducción',
        ];
    }

    /**
     * Encola múltiples videos para traducción masiva.
     */
    public function mediaBatchTranslate(): array
    {
        $body = Router::body();
        $ids = (array) ($body['media_ids'] ?? []);

        if ($ids === []) {
            throw new \RuntimeException('No se especificaron IDs de video.', 400);
        }

        /** @var \App\Services\Queue\QueueService $queue */
        $queue = Container::get(\App\Services\Queue\QueueService::class);
        $tasks = $queue->enqueueBatch($ids);

        return [
            'success' => true,
            'queued_count' => count($tasks),
            'message' => count($tasks) . ' videos agregados a la cola de traducción',
        ];
    }

    /**
     * Extraer una pista interna directamente a .srt.
     */
    public function mediaExtract(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $media = MediaFile::findById($id);

        if (! $media) {
            throw new \RuntimeException('Archivo no encontrado', 404);
        }

        $body = Router::body();
        $trackId = (int) ($body['track_id'] ?? 0);
        $targetTrack = SubtitleTrack::findById($trackId);

        if (! $targetTrack || $targetTrack->mediaFileId !== $media->id) {
            throw new \RuntimeException('Pista no válida');
        }

        /** @var SubtitleFilenameService $filenameService */
        $filenameService = Container::get(SubtitleFilenameService::class);
        $lang = $targetTrack->languageDetected ?? $targetTrack->language ?? 'und';
        $flags = ['sdh' => $targetTrack->isSdh, 'forced' => $targetTrack->isForced];
        $outputPath = $filenameService->pathForMedia($media, $lang, $flags);

        /** @var SubtitleExtractorService $extractor */
        $extractor = Container::get(SubtitleExtractorService::class);
        $srt = $extractor->getSrtContent($media, $targetTrack);

        file_put_contents($outputPath, $srt, LOCK_EX);

        return [
            'success' => true,
            'output_path' => $outputPath,
        ];
    }

    /**
     * Eliminar subtítulos generados por la app para un video.
     */
    public function mediaDeleteGenerated(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $media = MediaFile::findById($id);

        if (! $media) {
            throw new \RuntimeException('Archivo no encontrado', 404);
        }

        /** @var SubtitleRemovalService $removal */
        $removal = Container::get(SubtitleRemovalService::class);
        $deletedCount = $removal->deleteGeneratedFor($media);

        return [
            'success' => true,
            'deleted_count' => $deletedCount,
        ];
    }

    /**
     * Eliminar una pista de subtítulo específica (archivo externo/generado).
     */
    public function trackDelete(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        $track = SubtitleTrack::findById($id);

        if (! $track) {
            throw new \RuntimeException('Pista no encontrada', 404);
        }

        $media = MediaFile::findById($track->mediaFileId);
        if (! $media) {
            throw new \RuntimeException('Video asociado no encontrado', 404);
        }

        /** @var SubtitleRemovalService $removal */
        $removal = Container::get(SubtitleRemovalService::class);
        $res = $removal->deleteTrack($media, $track);

        return [
            'success' => true,
            'deleted_file' => $res['deletedFile'],
        ];
    }

    /**
     * Ejecutar escaneo de biblioteca.
     */
    public function scanLibraries(): array
    {
        $body = Router::body();
        $library = $body['library'] ?? 'all';

        /** @var MediaScannerService $scanner */
        $scanner = Container::get(MediaScannerService::class);

        if ($library === 'all' || $library === '') {
            $results = $scanner->scanAll();
        } else {
            $results = [$scanner->scanLibrary((string) $library)];
        }

        return [
            'success' => true,
            'results' => $results,
        ];
    }

    /**
     * Historial de tareas.
     */
    public function tasksList(): array
    {
        $tasks = ProcessingTask::recent(50);

        return [
            'tasks' => array_map(function (ProcessingTask $t) {
                $media = MediaFile::findById($t->mediaFileId);
                return [
                    'id' => $t->id,
                    'uuid' => $t->uuid,
                    'media_file_id' => $t->mediaFileId,
                    'filename' => $media?->filename ?? '(video borrado)',
                    'action' => $t->action,
                    'action_label' => $t->actionLabel(),
                    'status' => $t->status,
                    'status_label' => $t->statusLabel(),
                    'progress' => $t->progress,
                    'source_language' => $t->sourceLanguage,
                    'target_language' => $t->targetLanguage,
                    'error_message' => $t->errorMessage,
                    'started_at' => $t->startedAt,
                    'completed_at' => $t->completedAt,
                    'created_at' => $t->createdAt,
                ];
            }, $tasks),
        ];
    }

    /**
     * Devuelve la tarea activa actual si existe.
     */
    public function activeTask(): array
    {
        $stmt = Database::pdo()->query("SELECT * FROM processing_tasks WHERE status = 'running' ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch();

        if (! $row) {
            return ['active' => false];
        }

        $task = ProcessingTask::fromRow($row);
        $media = MediaFile::findById($task->mediaFileId);

        return [
            'active' => true,
            'task_id' => $task->id,
            'media_id' => $task->mediaFileId,
            'filename' => $media?->filename ?? '',
            'action' => $task->action,
            'action_label' => $task->actionLabel(),
            'progress' => $task->progress,
            'started_at' => $task->startedAt,
        ];
    }

    /**
     * Devuelve el estado completo de la cola de traducción.
     */
    public function queueStatus(): array
    {
        /** @var \App\Services\Queue\QueueService $queue */
        $queue = Container::get(\App\Services\Queue\QueueService::class);
        $active = $queue->activeTask();
        $activeMedia = $active ? MediaFile::findById($active->mediaFileId) : null;

        return [
            'active' => $active !== null,
            'running_task' => $active ? [
                'task_id' => $active->id,
                'media_id' => $active->mediaFileId,
                'filename' => $activeMedia?->filename ?? '',
                'progress' => $active->progress,
                'action' => $active->action,
                'action_label' => $active->actionLabel(),
                'started_at' => $active->startedAt,
            ] : null,
            'pending_count' => $queue->pendingCount(),
            'pending_tasks' => array_map(function (ProcessingTask $t) {
                $media = MediaFile::findById($t->mediaFileId);
                return [
                    'task_id' => $t->id,
                    'media_id' => $t->mediaFileId,
                    'filename' => $media?->filename ?? '',
                    'action' => $t->action,
                    'action_label' => $t->actionLabel(),
                    'created_at' => $t->createdAt,
                ];
            }, $queue->pendingList()),
        ];
    }

    /**
     * Cancela una tarea en cola o en ejecución.
     */
    public function taskCancel(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        /** @var \App\Services\Queue\QueueService $queue */
        $queue = Container::get(\App\Services\Queue\QueueService::class);
        $success = $queue->cancel($id);

        return [
            'success' => $success,
            'message' => $success ? 'Tarea cancelada con éxito' : 'No se pudo cancelar la tarea',
        ];
    }

    /**
     * Estado y comprobación de Jellyfin.
     */
    public function jellyfinStatus(): array
    {
        $url = (string) config('jellyfin.url', '');
        $apiKey = (string) config('jellyfin.api_key', '');
        $pathMap = (array) config('jellyfin.path_map', []);

        /** @var JellyfinApiClient $api */
        $api = Container::get(JellyfinApiClient::class);

        $connected = false;
        $itemCount = 0;
        $error = null;

        if ($url !== '' && $apiKey !== '') {
            try {
                $items = $api->items('Movie,Episode', 5);
                $connected = true;
                $itemCount = count($items);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'configured' => $url !== '' && $apiKey !== '',
            'url' => $url,
            'api_key_masked' => $apiKey !== '' ? substr($apiKey, 0, 4) . '...' . substr($apiKey, -4) : '',
            'connected' => $connected,
            'sample_items_found' => $itemCount,
            'error' => $error,
            'path_map' => $pathMap,
        ];
    }

    /**
     * Sincronización Jellyfin.
     */
    public function jellyfinSync(): array
    {
        $body = Router::body();
        $limit = (int) ($body['limit'] ?? 0);
        $dryRun = (bool) ($body['dry_run'] ?? false);
        $itemTypes = (string) ($body['item_types'] ?? 'Movie,Episode');

        /** @var JellyfinSyncService $sync */
        $sync = Container::get(JellyfinSyncService::class);
        $stats = $sync->sync([
            'limit' => $limit,
            'dryRun' => $dryRun,
            'itemTypes' => $itemTypes,
        ]);

        return [
            'success' => true,
            'stats' => $stats,
        ];
    }

    /**
     * Configuración del sistema.
     */
    public function settingsGet(): array
    {
        /** @var MediaPathService $pathService */
        $pathService = Container::get(MediaPathService::class);

        return [
            'translation' => [
                'provider' => config('translation.provider'),
                'target_language' => config('translation.target_language', 'es'),
                'batch_size' => config('translation.batch_size', 25),
                'timeout_seconds' => config('translation.timeout_seconds', 300),
                'ollama_url' => config('translation.ollama_url'),
                'ollama_model' => config('translation.ollama_model'),
                'deepseek_model' => config('translation.deepseek_model'),
                'deepseek_api_key_masked' => config('translation.deepseek_api_key') ? '••••••••' : '',
                'meta_muse_model' => config('translation.meta_muse_model'),
                'meta_muse_api_key_masked' => config('translation.meta_muse_api_key') ? '••••••••' : '',
                'openai_model' => config('translation.openai_model'),
                'openai_base_url' => config('translation.openai_base_url'),
            ],
            'jellyfin' => [
                'url' => config('jellyfin.url'),
                'api_key_masked' => config('jellyfin.api_key') ? '••••••••' : '',
                'container_prefix' => config('jellyfin.container_prefix'),
            ],
            'libraries' => $pathService->libraryStatus(),
            'providers' => [
                ['id' => 'ollama', 'name' => 'Ollama (LLM local en GPU/Red)', 'model_field' => 'ollama_model'],
                ['id' => 'deepseek', 'name' => 'DeepSeek (Cloud API)', 'model_field' => 'deepseek_model'],
                ['id' => 'meta-muse', 'name' => 'Meta Muse Spark (Cloud API)', 'model_field' => 'meta_muse_model'],
                ['id' => 'deep-translator', 'name' => 'Google Translate (Gratis, CLI)', 'model_field' => null],
                ['id' => 'openai', 'name' => 'OpenAI / OpenRouter / Compatible', 'model_field' => 'openai_model'],
            ],
        ];
    }

    /**
     * Guardar configuración (.env).
     */
    public function settingsSave(): array
    {
        $body = Router::body();
        $envVars = [];

        $map = [
            'provider' => 'TRANSLATION_PROVIDER',
            'target_language' => 'TRANSLATION_TARGET_LANGUAGE',
            'batch_size' => 'TRANSLATION_BATCH_SIZE',
            'timeout_seconds' => 'TRANSLATION_TIMEOUT_SECONDS',
            'ollama_url' => 'OLLAMA_URL',
            'ollama_model' => 'OLLAMA_MODEL',
            'deepseek_api_key' => 'DEEPSEEK_API_KEY',
            'deepseek_base_url' => 'DEEPSEEK_BASE_URL',
            'deepseek_model' => 'DEEPSEEK_MODEL',
            'meta_muse_api_key' => 'META_MUSE_API_KEY',
            'meta_muse_base_url' => 'META_MUSE_BASE_URL',
            'meta_muse_model' => 'META_MUSE_MODEL',
            'openai_api_key' => 'OPENAI_API_KEY',
            'openai_base_url' => 'OPENAI_BASE_URL',
            'openai_model' => 'OPENAI_MODEL',
            'jellyfin_url' => 'JELLYFIN_URL',
            'jellyfin_api_key' => 'JELLYFIN_API_KEY',
            'jellyfin_container_prefix' => 'JELLYFIN_CONTAINER_PREFIX',
        ];

        foreach ($map as $param => $envKey) {
            $value = $body[$param] ?? null;
            // Ignorar: campos ausentes, vacíos o el placeholder de keys
            if ($value === null || $value === '' || $value === '••••••••') {
                continue;
            }
            $envVars[$envKey] = (string) $value;
        }

        $this->writeEnv($envVars);

        env_clear_cache();
        config_clear_cache();
        Container::flush();

        return [
            'success' => true,
            'message' => 'Configuración guardada correctamente',
        ];
    }

    /**
     * Probar proveedor de traducción con una frase de prueba.
     */
    public function settingsTestProvider(): array
    {
        $body = Router::body();
        $text = trim((string) ($body['text'] ?? 'The movie has started. Welcome to the theater!'));
        $target = trim((string) ($body['target_language'] ?? 'es'));

        /** @var TranslationProviderInterface $provider */
        $provider = Container::get(TranslationProviderInterface::class);

        $start = microtime(true);
        $translated = $provider->translate($text, $target);
        $elapsedMs = round((microtime(true) - $start) * 1000);

        return [
            'success' => true,
            'provider' => $provider->name(),
            'source' => $text,
            'translation' => $translated,
            'latency_ms' => $elapsedMs,
        ];
    }

    /**
     * Devuelve el árbol jerárquico de bibliotecas y carpetas con sus archivos.
     */
    public function tree(): array
    {
        $library = trim((string) Router::query('library', ''));
        $search = trim((string) Router::query('q', ''));
        $spanishFilter = Router::query('has_spanish', 'all');

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = 'filename LIKE ?';
            $params[] = "%{$search}%";
        }
        if ($spanishFilter === '1') {
            $where[] = "id IN (SELECT DISTINCT media_file_id FROM subtitle_tracks WHERE language_detected IN ('spa', 'es') OR language IN ('spa', 'es'))";
        } elseif ($spanishFilter === '0') {
            $where[] = "status != 'pending' AND id NOT IN (SELECT DISTINCT media_file_id FROM subtitle_tracks WHERE language_detected IN ('spa', 'es') OR language IN ('spa', 'es'))";
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM media_files {$whereSql} ORDER BY path ASC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $files = $stmt->fetchAll();

        $pathService = Container::get(MediaPathService::class);
        $libraries = $pathService->libraries();

        $tree = [];

        foreach ($files as $row) {
            $media = MediaFile::findById((int) $row['id']);
            if (! $media) continue;

            $tracks = $media->tracks();
            $hasSpanish = $media->hasSpanish();
            $path = $media->path;

            // Identificar biblioteca
            $libName = 'Otras';
            $relPath = $path;
            foreach ($libraries as $name => $libPath) {
                if (str_starts_with($path, $libPath)) {
                    $libName = $name;
                    $relPath = ltrim(substr($path, strlen($libPath)), '/');
                    break;
                }
            }

            if ($library !== '' && $libName !== $library) {
                continue;
            }

            $parts = explode('/', $relPath);
            $filename = array_pop($parts);
            $folderPath = implode('/', $parts) ?: 'Raíz';

            if (! isset($tree[$libName])) {
                $tree[$libName] = [
                    'name' => $libName,
                    'folders' => [],
                    'total_files' => 0,
                    'has_spanish' => 0,
                ];
            }

            if (! isset($tree[$libName]['folders'][$folderPath])) {
                $tree[$libName]['folders'][$folderPath] = [
                    'name' => $folderPath,
                    'rel_path' => $folderPath,
                    'files' => [],
                    'total_files' => 0,
                    'has_spanish' => 0,
                ];
            }

            $tree[$libName]['total_files']++;
            $tree[$libName]['folders'][$folderPath]['total_files']++;
            if ($hasSpanish) {
                $tree[$libName]['has_spanish']++;
                $tree[$libName]['folders'][$folderPath]['has_spanish']++;
            }

            $tree[$libName]['folders'][$folderPath]['files'][] = [
                'id' => $media->id,
                'uuid' => $media->uuid,
                'filename' => $media->filename,
                'path' => $media->path,
                'rel_path' => $relPath,
                'folder' => $folderPath,
                'library' => $libName,
                'extension' => strtoupper($media->extension),
                'file_size' => $this->humanSize($media->fileSize),
                'duration' => $media->duration !== null ? round($media->duration / 60, 1) : null,
                'duration_formatted' => $media->duration !== null ? gmdate('H:i:s', (int) $media->duration) : null,
                'status' => $media->status,
                'has_spanish' => $hasSpanish,
                'english_tracks_count' => count($media->englishTracks()),
                'tracks_count' => count($tracks),
                'tracks' => array_map(fn (SubtitleTrack $t) => [
                    'id' => $t->id,
                    'source_type' => $t->sourceType,
                    'stream_index' => $t->streamIndex,
                    'language' => $t->languageLabel(),
                    'lang_code' => $t->languageDetected ?? $t->language ?? 'und',
                    'codec' => $t->codecLabel(),
                    'is_text' => $t->isTextBased,
                    'is_sdh' => $t->isSdh,
                    'is_forced' => $t->isForced,
                    'can_translate' => $t->isTextBased,
                    'can_delete' => $t->sourceType !== SubtitleTrack::SOURCE_INTERNAL,
                ], $tracks),
            ];
        }

        $result = [];
        foreach ($tree as $libData) {
            $folders = array_values($libData['folders']);
            $libData['folders'] = $folders;
            $result[] = $libData;
        }

        return ['tree' => $result];
    }

    private function writeEnv(array $values): void
    {
        $file = base_path('.env');
        $content = is_file($file) ? (string) file_get_contents($file) : '';

        foreach ($values as $key => $value) {
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            $line = $key . '=' . $value;

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $line, $content);
            } else {
                $content .= ($content === '' || str_ends_with($content, "\n") ? '' : "\n") . $line . "\n";
            }
        }

        file_put_contents($file, $content, LOCK_EX);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024, 1) . ' KB';
    }
}
