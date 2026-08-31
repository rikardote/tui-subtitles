<?php

declare(strict_types=1);

/**
 * Acceso simple a la configuración.
 */

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null || ($GLOBALS['__config_force_reload'] ?? false)) {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $GLOBALS['__config_force_reload'] = false;
    }

    $value = $config;

    foreach (explode('.', $key) as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
        } else {
            return $default;
        }
    }

    return $value;
}

/** Invalida la caché de configuración (para cambios en vivo desde la TUI). */
function config_clear_cache(): void
{
    // La caché es estática dentro de config(); usamos GLOBALS para invalidarla
    $GLOBALS['__config_force_reload'] = true;
}

function env(string $key, ?string $default = null): ?string
{
    // 1. Variables de entorno reales del proceso (mayor prioridad)
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }

    // 2. Archivo .env (cacheado en $GLOBALS para poder invalidarlo)
    if (! isset($GLOBALS['__env_loaded']) || ! $GLOBALS['__env_loaded']) {
        $env = [];
        $file = dirname(__DIR__, 2) . '/.env';

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $env[trim($k)] = trim($v);
            }
        }

        $GLOBALS['__env_loaded'] = true;
        $GLOBALS['__env_cache'] = $env;
    }

    return $GLOBALS['__env_cache'][$key] ?? $default;
}

/** Invalida la caché del .env (útil tras guardar configuración en vivo). */
function env_clear_cache(): void
{
    $GLOBALS['__env_loaded'] = false;
    unset($GLOBALS['__env_cache']);
}

function base_path(string $path = ''): string
{
    return dirname(__DIR__, 2) . ($path !== '' ? '/' . $path : '');
}
