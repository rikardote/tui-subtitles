<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/**
 * Ejecuta procesos externos (FFmpeg, FFprobe, proveedores CLI) de forma segura.
 */
final class ProcessRunner
{
    /**
     * Ejecuta un comando y devuelve [exitCode, stdout, stderr].
     *
     * @param  string[]  $args
     * @return array{0:int,1:string,2:string}
     */
    public function run(array $args, ?float $timeout = null): array
    {
        $cmd = $this->buildCommand($args);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);

        if (! is_resource($proc)) {
            throw new RuntimeException('No se pudo iniciar el proceso: ' . $cmd);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);

            if (! $status['running']) {
                break;
            }

            if ($timeout !== null && (microtime(true) - $start) > $timeout) {
                proc_terminate($proc, 15);
                usleep(200_000);
                proc_terminate($proc, 9);
                $status = proc_get_status($proc);
                break;
            }

            usleep(50_000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($timeout !== null && (microtime(true) - $start) > $timeout) {
            throw new RuntimeException('El proceso excedió el tiempo límite: ' . $cmd);
        }

        return [$exitCode !== false ? $exitCode : -1, $stdout, $stderr];
    }

    /** @param  string[]  $args */
    private function buildCommand(array $args): string
    {
        return implode(' ', array_map(fn (string $a) => escapeshellarg($a), $args));
    }
}
