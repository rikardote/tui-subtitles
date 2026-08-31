<?php

declare(strict_types=1);

/**
 * Acceso simple a la configuración.
 */

function config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
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

function env(string $key, ?string $default = null): ?string
{
    static $env = null;

    if ($env === null) {
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
    }

    return $env[$key] ?? $default;
}

function base_path(string $path = ''): string
{
    return dirname(__DIR__, 2) . ($path !== '' ? '/' . $path : '');
}
