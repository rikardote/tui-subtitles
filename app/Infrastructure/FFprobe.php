<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/**
 * Wrapper sobre FFprobe: análisis de streams de un archivo multimedia.
 */
final class FFprobe
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * Analiza un archivo y devuelve la estructura completa de streams.
     *
     * @return array{format: array, streams: array<int, array>}
     */
    public function probe(string $path): array
    {
        [$code, $stdout, $stderr] = $this->runner->run([
            (string) config('binaries.ffprobe'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ], 60);

        if ($code !== 0) {
            throw new RuntimeException('FFprobe falló: ' . trim($stderr ?: $stdout));
        }

        $data = json_decode($stdout, true);

        if (! is_array($data)) {
            throw new RuntimeException('FFprobe devolvió JSON inválido para: ' . $path);
        }

        return $data;
    }

    /** @return array<int, array> */
    public function subtitleStreams(string $path): array
    {
        $data = $this->probe($path);

        return array_values(array_filter(
            $data['streams'] ?? [],
            fn (array $s) => ($s['codec_type'] ?? null) === 'subtitle'
        ));
    }
}
