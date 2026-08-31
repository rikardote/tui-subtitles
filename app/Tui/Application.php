<?php

declare(strict_types=1);

namespace App\Tui;

use App\Models\MediaFile;
use App\Models\ProcessingTask;
use App\Services\Container;
use App\Services\Library\MediaPathService;
use App\Services\Library\MediaScannerService;
use App\Services\Media\SubtitleExtractorService;
use App\Services\Subtitle\SubtitleAnalyzerService;
use App\Services\Subtitle\SubtitleFilenameService;
use Laravel\Prompts\Progress;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Aplicación TUI principal. Solo orquesta pantallas; la lógica vive en Services.
 */
final class Application
{
    public function __construct(
        private readonly MediaPathService $paths,
        private readonly MediaScannerService $scanner,
        private readonly SubtitleAnalyzerService $analyzer,
        private readonly SubtitleExtractorService $extractor,
        private readonly SubtitleFilenameService $filenames,
    ) {
    }

    public static function boot(): self
    {
        return new self(
            Container::get(MediaPathService::class),
            Container::get(MediaScannerService::class),
            Container::get(SubtitleAnalyzerService::class),
            Container::get(SubtitleExtractorService::class),
            Container::get(SubtitleFilenameService::class),
        );
    }

    public function run(): void
    {
        $this->printHeader();

        while (true) {
            $choice = select(
                label: '¿Qué desea hacer?',
                options: [
                    'browse' => 'Explorar biblioteca',
                    'scan' => 'Escanear biblioteca',
                    'pending' => 'Ver archivos pendientes',
                    'history' => 'Ver historial',
                    'settings' => 'Configuración',
                    'exit' => 'Salir',
                ],
                default: 'browse',
            );

            match ($choice) {
                'browse' => $this->browse(),
                'scan' => $this->scan(),
                'pending' => $this->pending(),
                'history' => $this->history(),
                'settings' => $this->settings(),
                'exit' => $this->exitApp(),
            };
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Explorar biblioteca
    // ────────────────────────────────────────────────────────────────

    private function browse(): void
    {
        $libraries = $this->paths->libraries();

        if ($libraries === []) {
            warning('No hay bibliotecas configuradas. Edite config/app.php → media_paths.');
            return;
        }

        // Pantalla: seleccionar biblioteca
        $libraryOptions = [];
        foreach ($libraries as $name => $path) {
            $state = is_dir($path) ? '' : ' (no existe)';
            $libraryOptions[$name] = $name . $state;
        }
        $libraryOptions['__back'] = '← Volver';

        $lib = select('Seleccione una biblioteca', $libraryOptions, default: array_key_first($libraries));

        if ($lib === '__back') {
            return;
        }

        $root = $libraries[$lib];

        if (! is_dir($root)) {
            warning("La biblioteca no existe: {$root}");
            return;
        }

        $this->browseDirectory($lib, $root);
    }

    private function browseDirectory(string $library, string $dir): void
    {
        // Listar subdirectorios y videos (solo archivos de video; subtítulos se ven en detalle)
        $videoExts = config('video_extensions', ['mkv', 'mp4']);
        $entries = [];

        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $full = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full)) {
                $entries['dir:' . $entry] = '📁 ' . $entry . '/';
            } elseif (is_file($full) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $videoExts, true)) {
                $entries['video:' . $full] = '🎬 ' . $entry;
            }
        }

        if ($entries === []) {
            warning('No hay carpetas ni videos en esta ubicación.');
            return;
        }

        // Orden alfabético de carpetas/videos, con "Volver" siempre al final
        ksort($entries);
        $entries['__back'] = '← Volver';

        $choice = select(basename($dir) ?: $library, $entries, default: array_key_first($entries));

        if ($choice === '__back') {
            return;
        }

        if (str_starts_with($choice, 'dir:')) {
            $sub = substr($choice, 4);
            $this->browseDirectory($library, $dir . DIRECTORY_SEPARATOR . $sub);
            return;
        }

        if (str_starts_with($choice, 'video:')) {
            $path = substr($choice, 6);
            $media = MediaFile::findByPath($path);

            if ($media === null) {
                // Registrar si aún no está en BD
                $media = new MediaFile();
                $media->uuid = \App\Services\Library\MediaChangeDetectorService::uuid();
                $media->path = $path;
                $media->filename = basename($path);
                $media->extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $media->fileSize = (int) @filesize($path);
                $media->lastModifiedAt = gmdate('Y-m-d H:i:s', (int) @filemtime($path));
                $media->status = MediaFile::STATUS_PENDING;
                $media->save();
            }

            $this->mediaDetail($media);
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Detalle de un video
    // ────────────────────────────────────────────────────────────────

    private function mediaDetail(MediaFile $media): void
    {
        // Análisis si está pendiente o nunca analizado
        if ($media->status === MediaFile::STATUS_PENDING || $media->lastAnalyzedAt === null) {
            spin(
                fn () => $this->analyzer->analyze($media),
                'Analizando archivo con FFprobe...'
            );

            $media = MediaFile::findById($media->id) ?? $media;
            $media->status = MediaFile::STATUS_ANALYZED;
            $media->lastAnalyzedAt = gmdate('Y-m-d H:i:s');
            $media->save();
        }

        $tracks = $media->tracks();

        $this->printFileInfo($media, $tracks);

        $options = [];
        foreach ($tracks as $track) {
            $label = sprintf(
                '[%s] %s — %s',
                $track->sourceType === 'internal' ? (string) $track->streamIndex : 'ext',
                $track->languageLabel(),
                $track->codecLabel()
            );

            if ($track->isSdh) {
                $label .= ' (SDH)';
            }
            if ($track->isForced) {
                $label .= ' (forced)';
            }
            if (! $track->isTextBased) {
                $label .= ' ⚠ imagen';
            }

            $options['track:' . $track->id] = $label;
        }

        $hasSpanish = $media->hasSpanish();
        $esExists = $this->filenames->existsForMedia($media);

        $options['__separator'] = '────────────────────────────────';
        $options['__back'] = '← Volver al explorador';

        $choice = select(
            $hasSpanish ? 'Pistas de subtítulos:' : 'Pistas de subtítulos (sin español detectado):',
            $options,
            default: array_key_first($options)
        );

        if ($choice === '__back') {
            return;
        }

        if ($choice === '__separator') {
            return;
        }

        if (str_starts_with($choice, 'track:')) {
            $trackId = (int) substr($choice, 6);
            $track = null;

            foreach ($tracks as $t) {
                if ($t->id === $trackId) {
                    $track = $t;
                    break;
                }
            }

            if ($track === null) {
                return;
            }

            $this->trackActions($media, $track);
        }
    }

    private function printFileInfo(MediaFile $media, array $tracks): void
    {
        $size = $this->humanSize($media->fileSize);

        table(
            ['Archivo', 'Tamaño', 'Estado', 'Duración'],
            [[
                $media->filename,
                $size,
                $this->statusLabel($media->status),
                $media->duration !== null ? round($media->duration / 60, 1) . ' min' : '—',
            ]]
        );

        if ($tracks !== []) {
            $rows = [];
            foreach ($tracks as $track) {
                $rows[] = [
                    $track->sourceType === 'internal' ? 'Interna #' . $track->streamIndex : 'Externa',
                    $track->languageLabel(),
                    $track->codecLabel(),
                    $track->isTextBased ? 'Texto' : 'Imagen',
                    $track->isSdh ? 'SDH' : '',
                ];
            }
            table(['Origen', 'Idioma', 'Formato', 'Tipo', 'Notas'], $rows);
        } else {
            warning('No se detectaron subtítulos.');
        }

        if ($media->hasSpanish()) {
            note('✓ Ya existe un subtítulo en español.');
        } elseif ($media->englishTracks() !== []) {
            note('⚠ No existe español, pero hay inglés disponible para traducción.');
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Acciones sobre una pista
    // ────────────────────────────────────────────────────────────────

    private function trackActions(MediaFile $media, \App\Models\SubtitleTrack $track): void
    {
        if (! $track->isTextBased) {
            warning('Este subtítulo es de imagen (PGS/VobSub). Requiere OCR, no disponible en esta PoC.');
            return;
        }

        $options = [
            'translate' => 'Traducir al español',
            'extract' => 'Solo extraer',
            '__back' => '← Volver',
        ];

        $action = select('Seleccione la acción para ' . $track->languageLabel(), $options, default: 'translate');

        if ($action === '__back') {
            return;
        }

        $output = $this->filenames->pathForMedia($media);

        if (file_exists($output)) {
            $overwrite = confirm(
                "Ya existe {$output}. ¿Desea sobrescribirlo?",
                default: false
            );

            if (! $overwrite) {
                warning('Operación cancelada. El archivo existente no fue modificado.');
                return;
            }
        }

        if ($action === 'extract') {
            $this->extractOnly($media, $track, $output);
            return;
        }

        $this->translate($media, $track, $output);
    }

    private function extractOnly(MediaFile $media, \App\Models\SubtitleTrack $track, string $output): void
    {
        try {
            $result = spin(
                function () use ($media, $track, $output) {
                    if ($track->sourceType === 'external') {
                        // Copiar el archivo externo al nombre destino (convención)
                        $srt = $this->extractor->readExternal($track);
                        $ext = strtolower(pathinfo((string) $track->path, PATHINFO_EXTENSION));

                        if (in_array($ext, ['ass', 'ssa', 'vtt'], true)) {
                            $tmp = tempnam(sys_get_temp_dir(), 'sub_conv_') . '.srt';
                            @unlink($tmp);
                            \App\Services\Container::get(\App\Infrastructure\FFmpeg::class)->convertToSrt($track->path, $tmp);
                            $srt = (string) file_get_contents($tmp);
                            @unlink($tmp);
                        }

                        file_put_contents($output, $srt, LOCK_EX);

                        return ['outputPath' => $output, 'blocks' => 0];
                    }

                    $srt = $this->extractor->extractInternal($media, $track);
                    file_put_contents($output, $srt, LOCK_EX);

                    return ['outputPath' => $output, 'blocks' => 0];
                },
                'Extrayendo subtítulo...'
            );

            note('✓ Extracción completada');
            note('Archivo generado: ' . $result['outputPath']);
        } catch (\Throwable $e) {
            warning('Error en la extracción: ' . $e->getMessage());
        }
    }

    private function translate(MediaFile $media, \App\Models\SubtitleTrack $track, string $output): void
    {
        try {
            $result = $this->extractor->extractAndTranslate($media, $track);

            note('✓ Traducción completada');
            note('Archivo generado: ' . $result['outputPath']);
            note('Bloques traducidos: ' . $result['blocks']);
        } catch (\Throwable $e) {
            warning('Error en la traducción: ' . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Escaneo
    // ────────────────────────────────────────────────────────────────

    private function scan(): void
    {
        $options = ['all' => 'Escaneo completo de todas las bibliotecas', '__back' => '← Volver'];

        foreach ($this->paths->libraries() as $name => $path) {
            $options[$name] = "Escaneo de {$name}";
        }

        $choice = select('Seleccione el tipo de escaneo', $options, default: 'all');

        if ($choice === '__back') {
            return;
        }

        try {
            if ($choice === 'all') {
                $results = $this->scanner->scanAll();
            } else {
                $results = [$this->scanner->scanLibrary($choice)];
            }

            foreach ($results as $result) {
                note(sprintf(
                    '%s: %d archivos | nuevos: %d | modificados: %d | sin cambios: %d',
                    $result['library'],
                    $result['total'],
                    $result['new'],
                    $result['modified'],
                    $result['unchanged']
                ));
            }

            $pending = MediaFile::all(MediaFile::STATUS_PENDING);
            if ($pending !== []) {
                warning('Hay ' . count($pending) . ' archivos pendientes de análisis.');
            }
        } catch (\Throwable $e) {
            warning('Error en el escaneo: ' . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Pendientes
    // ────────────────────────────────────────────────────────────────

    private function pending(): void
    {
        $pending = MediaFile::all(MediaFile::STATUS_PENDING);

        if ($pending === []) {
            note('No hay archivos pendientes.');
            return;
        }

        $options = [];
        foreach ($pending as $media) {
            $options['id:' . $media->id] = $media->filename;
        }
        $options['__back'] = '← Volver';

        $choice = select('Archivos pendientes de análisis (' . count($pending) . ')', $options, default: array_key_first($options));

        if ($choice === '__back') {
            return;
        }

        $id = (int) substr($choice, 3);
        $media = MediaFile::findById($id);

        if ($media !== null) {
            $this->mediaDetail($media);
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Historial
    // ────────────────────────────────────────────────────────────────

    private function history(): void
    {
        $tasks = ProcessingTask::recent(20);

        if ($tasks === []) {
            note('No hay procesamientos registrados aún.');
            return;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $media = MediaFile::findById($task->mediaFileId);
            $icon = $task->status === 'completed' ? '✓' : ($task->status === 'failed' ? '✗' : '⟳');

            $rows[] = [
                substr((string) $task->createdAt, 0, 16),
                $media?->filename ?? '(borrado)',
                $task->actionLabel(),
                $task->statusLabel(),
                $task->progress . '%',
                $icon,
            ];
        }

        table(['Fecha', 'Archivo', 'Acción', 'Estado', 'Progreso', ''], $rows);

        confirm('¿Desea ver el detalle de algún error?', default: false)
            ? $this->showFailedDetail($tasks)
            : null;
    }

    private function showFailedDetail(array $tasks): void
    {
        $failed = array_values(array_filter($tasks, fn ($t) => $t->status === 'failed'));

        if ($failed === []) {
            note('No hay errores registrados.');
            return;
        }

        $options = [];
        foreach ($failed as $task) {
            $media = MediaFile::findById($task->mediaFileId);
            $options['id:' . $task->id] = ($media?->filename ?? '?') . ' — ' . $task->actionLabel();
        }
        $options['__back'] = '← Volver';

        $choice = select('Errores registrados', $options, default: array_key_first($options));

        if ($choice === '__back') {
            return;
        }

        $id = (int) substr($choice, 3);
        foreach ($failed as $task) {
            if ($task->id === $id) {
                warning($task->errorMessage ?? 'Sin mensaje de error.');
                break;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Configuración
    // ────────────────────────────────────────────────────────────────

    private function settings(): void
    {
        $statusRows = [];
        foreach ($this->paths->libraryStatus() as $lib) {
            $state = match ($lib['state']) {
                'ok' => '✓ OK',
                'missing' => '✗ No existe',
                'unreadable' => '✗ Sin permisos',
            };
            $statusRows[] = [$lib['name'], $lib['path'], $state];
        }

        table(['Biblioteca', 'Ruta', 'Estado'], $statusRows);

        $provider = \App\Services\Container::get(\App\Services\Translation\TranslationProviderInterface::class);
        note('Proveedor de traducción: ' . $provider->name() . ($provider->available() ? ' (disponible)' : ' (NO disponible)'));

        table(
            ['Parámetro', 'Valor'],
            [
                ['Extensiones de video', implode(', ', config('video_extensions'))],
                ['Extensiones de subtítulos', implode(', ', config('subtitle_extensions'))],
                ['Intervalo de escaneo', config('scan.interval_minutes') . ' min'],
                ['Idioma destino', config('translation.target_language')],
                ['Bloques por lote', (string) config('translation.batch_size')],
                ['Proveedor', (string) config('translation.provider')],
                ['Modelo Ollama', (string) config('translation.ollama_model')],
                ['Modelo OpenAI', (string) config('translation.openai_model')],
            ]
        );

        $change = confirm('¿Desea cambiar el proveedor o el modelo?', default: false);

        if ($change) {
            $this->changeProvider();
        }
    }

    private function changeProvider(): void
    {
        $providers = [
            'deep-translator' => 'deep-translator (Google, gratuito, sin API key)',
            'deepseek' => 'DeepSeek (deepseek-chat / deepseek-reasoner)',
            'ollama' => 'Ollama (LLM local, gratuito, offline)',
            'openai' => 'OpenAI / API compatible (GPT, Groq, OpenRouter...)',
        ];

        $choice = select('Seleccione el proveedor de traducción', $providers, default: (string) config('translation.provider'));

        $model = null;
        if ($choice === 'ollama') {
            $model = \Laravel\Prompts\text(
                'Modelo Ollama (instalados con: ollama pull <modelo>):',
                default: (string) config('translation.ollama_model')
            );
        } elseif ($choice === 'openai') {
            $model = \Laravel\Prompts\text(
                'Modelo (p.ej. gpt-4o-mini, llama-3.1-8b-instant):',
                default: (string) config('translation.openai_model')
            );
        } elseif ($choice === 'deepseek') {
            $model = \Laravel\Prompts\text(
                'Modelo DeepSeek (deepseek-chat o deepseek-reasoner):',
                default: (string) config('translation.deepseek_model')
            );
        }

        // Persistir en el .env
        $this->writeEnv([
            'TRANSLATION_PROVIDER' => $choice,
            'OLLAMA_MODEL' => $choice === 'ollama' ? $model : null,
            'OPENAI_MODEL' => $choice === 'openai' ? $model : null,
            'DEEPSEEK_MODEL' => $choice === 'deepseek' ? $model : null,
        ]);

        // Recargar configuración y limpiar la instancia cacheada del proveedor
        env_clear_cache();
        config_clear_cache();
        \App\Services\Container::flush();

        note('Configuración guardada en .env. El nuevo proveedor se usará en la próxima traducción.');
    }

    private function writeEnv(array $values): void
    {
        $file = base_path('.env');
        $content = is_file($file) ? (string) file_get_contents($file) : '';

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

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

    // ────────────────────────────────────────────────────────────────
    //  Utilidades
    // ────────────────────────────────────────────────────────────────

    private function printHeader(): void
    {
        $title = config('name') . ' — PoC';
        $line = str_repeat('═', min(60, strlen($title) + 4));

        note("\n╔{$line}╗\n║  {$title}  ║\n╚{$line}╝");
        note('Extracción y traducción de subtítulos | versión ' . config('version'));
    }

    private function exitApp(): void
    {
        note('¡Hasta pronto!');
        exit(0);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            MediaFile::STATUS_PENDING => 'Pendiente',
            MediaFile::STATUS_ANALYZED => 'Analizado',
            MediaFile::STATUS_PROCESSING => 'En proceso',
            MediaFile::STATUS_PROCESSED => 'Procesado',
            MediaFile::STATUS_ERROR => 'Error',
            default => $status,
        };
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
