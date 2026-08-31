<?php

/**
 * Configuración global de la aplicación.
 *
 * La lógica de negocio lee toda su configuración desde aquí,
 * de modo que la futura versión web reutilice los mismos servicios.
 */

return [

    'name' => 'Subtitle Processor',

    'version' => '0.1.0-poc',

    // Rutas multimedia autorizadas.
    // La TUI nunca permitirá navegar fuera de estas rutas.
    'media_paths' => [
        'Movies' => env('MEDIA_PATH_MOVIES', '/media/Movies'),
        'TvShows' => env('MEDIA_PATH_TV', '/media/TvShows'),
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

    // Binarios del sistema (se resuelven con PATH si quedan vacíos).
    'binaries' => [
        'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),
    ],

    // Traducción: proveedor y parámetros.
    // Proveedores disponibles:
    //   deep-translator → Google Translate gratuito (sin modelo)
    //   ollama          → LLM local gratuito (modelo configurable)
    //   openai          → OpenAI u API compatible (GPT, Groq, OpenRouter...)
    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'deep-translator'),
        'target_language' => 'es',
        'batch_size' => 50,          // bloques por solicitud de traducción
        'max_retries' => 3,
        'timeout_seconds' => 30,

        // Ollama (LLM local)
        'ollama_url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'ollama_model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),

        // OpenAI o API compatible
        'openai_api_key' => env('OPENAI_API_KEY', ''),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
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
