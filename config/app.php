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
        'TV Shows' => env('MEDIA_PATH_TV', '/media/TV'),
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
    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'deep-translator'),
        'target_language' => 'es',
        'batch_size' => 50,          // bloques por solicitud de traducción
        'max_retries' => 3,
        'timeout_seconds' => 30,
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
