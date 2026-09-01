<?php

/**
 * Configuración global de la aplicación.
 *
 * La lógica de negocio lee toda su configuración desde aquí,
 */

date_default_timezone_set(env('APP_TIMEZONE', env('TZ', 'America/Tijuana')));

return [

    'name' => 'Subtitle Processor',

    'version' => '0.1.0-poc',

    'timezone' => env('APP_TIMEZONE', env('TZ', 'America/Tijuana')),

    // Rutas multimedia autorizadas.
    // La TUI nunca permitirá navegar fuera de estas rutas.
    'media_paths' => [
        'Movies' => env('MEDIA_PATH_MOVIES', '/media/Movies'),
        'TvShows' => env('MEDIA_PATH_TV', '/media/TvShows'),
        'Pruebas' => env('MEDIA_PATH_TEST', dirname(__DIR__) . '/storage/test-library/Movies'),
    ],

    // Extensiones de video soportadas para escaneo.
    'video_extensions' => [
        'mkv',
        'mp4',
    ],

    // Extensiones de subtítulos externos soportadas.
    'subtitle_extensions' => [
        'srt',
        'ass',
        'ssa',
        'vtt',
    ],

    // Binarios del sistema (se resuelven con PATH si quedan vacíos o no existen en el entorno actual).
    'binaries' => [
        'ffmpeg' => is_executable((string) env('FFMPEG_BIN', '')) ? (string) env('FFMPEG_BIN') : 'ffmpeg',
        'ffprobe' => is_executable((string) env('FFPROBE_BIN', '')) ? (string) env('FFPROBE_BIN') : 'ffprobe',
    ],

    // Traducción: proveedor y parámetros.
    // Proveedores disponibles:
    //   deep-translator → Google Translate gratuito (sin modelo)
    //   ollama          → LLM local gratuito (modelo configurable)
    //   openai          → OpenAI u API compatible (GPT, Groq, OpenRouter...)
    //   deepseek        → DeepSeek (deepseek-chat / deepseek-reasoner)
    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'deep-translator'),
        'target_language' => 'es',
        'batch_size' => (int) env('TRANSLATION_BATCH_SIZE', 50),          // bloques por solicitud de traducción
        'max_retries' => 3,
        'timeout_seconds' => (int) env('TRANSLATION_TIMEOUT_SECONDS', 300),

        // Ollama (LLM local)
        'ollama_url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'ollama_model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),

        // OpenAI o API compatible
        'openai_api_key' => env('OPENAI_API_KEY', ''),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

        // DeepSeek (API compatible con OpenAI)
        'deepseek_api_key' => env('DEEPSEEK_API_KEY', ''),
        'deepseek_base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'deepseek_model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),

        // Meta Muse Spark (Meta Model API — API compatible con OpenAI)
        'meta_muse_api_key' => env('META_MUSE_API_KEY', ''),
        'meta_muse_base_url' => env('META_MUSE_BASE_URL', 'https://api.ai.meta.com/v1'),
        'meta_muse_model' => env('META_MUSE_MODEL', 'muse-spark-1.2'),
    ],

    // Integración con Jellyfin (opcional).
    // Jellyfin detecta automáticamente los .srt que la app genera junto al video,
    // así que no se necesita ningún plugin en el servidor.
    'jellyfin' => [
        'url' => env('JELLYFIN_URL', 'http://localhost:8096'),
        'api_key' => env('JELLYFIN_API_KEY', ''),

        // Prefijo de rutas dentro del contenedor Jellyfin (docker).
        'container_prefix' => env('JELLYFIN_CONTAINER_PREFIX', '/data'),

        // Mapa explícito contenedor=host separado por comas (opcional).
        // Ej: /data/movies=/mnt/disk2tb/data/media/movies,/data/tvshows=/mnt/disk2tb/data/media/tv
        // Si está vacío se deduce de media_paths: /data/<carpeta> → <ruta host>.
        'path_map' => array_filter(array_reduce(
            explode(',', env('JELLYFIN_PATH_MAP', '')),
            function (array $acc, string $pair): array {
                [$container, $host] = array_pad(explode('=', trim($pair), 2), 2, '');
                if ($container !== '' && $host !== '') {
                    $acc[trim($container)] = trim($host);
                }
                return $acc;
            },
            []
        )),
    ],

    // Escaneo automático periódico (intervalo en minutos).
    'scan' => [
        'interval_minutes' => (int) env('SCAN_INTERVAL_MINUTES', 15),
        'stabilize_seconds' => 10,   // espera para archivos en proceso de copia
    ],

    // Rutas internas de la aplicación.
    'storage_path' => dirname(__DIR__) . '/storage',
    'database_path' => dirname(__DIR__) . '/storage/database.sqlite',
    'log_path' => dirname(__DIR__) . '/storage/logs/app.log',

];
