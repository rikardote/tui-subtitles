<?php

declare(strict_types=1);

namespace App\Services\Subtitle;

/**
 * Parser de subtítulos SRT: lee, escribe y extrae muestras.
 * La traducción trabaja sobre bloques para preservar timestamps.
 */
final class SubtitleParserService
{
    /**
     * Parsea un archivo SRT en bloques.
     *
     * @return array<int, array{index:int, start:string, end:string, text:string}>
     */
    public function parse(string $content): array
    {
        // Normaliza saltos de línea y BOM
        $content = preg_replace('/\r\n?/', "\n", $content) ?? '';
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';

        $blocks = [];
        $lines = explode("\n", $content);
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = trim($lines[$i]);

            // Busca la numeración
            if ($line === '' || ! ctype_digit($line)) {
                $i++;
                continue;
            }

            $index = (int) $line;
            $i++;

            // Busca el timestamp (puede haber líneas en blanco entre medio)
            $start = null;
            while ($i < $count && $start === null) {
                $candidate = trim($lines[$i]);
                if ($candidate === '') {
                    $i++;
                    continue;
                }
                if (str_contains($candidate, '-->')) {
                    $start = $candidate;
                }
                $i++;
            }

            if ($start === null) {
                break;
            }

            // Recolecta el texto hasta el siguiente bloque
            $textLines = [];
            while ($i < $count) {
                $line = rtrim($lines[$i]);
                if ($line === '') {
                    if ($textLines !== []) {
                        break;
                    }
                    // Si no hay texto acumulado, mirar si la siguiente línea no vacía es el índice del próximo bloque
                    $peek = $i + 1;
                    while ($peek < $count && trim($lines[$peek]) === '') {
                        $peek++;
                    }
                    if ($peek < $count && ctype_digit(trim($lines[$peek]))) {
                        // El bloque actual no tiene texto (vacío)
                        break;
                    }
                    $i++;
                    continue;
                }
                // Si la línea es numérica y la siguiente contiene '-->', estamos ante el próximo bloque
                if (ctype_digit(trim($line)) && isset($lines[$i + 1]) && str_contains($lines[$i + 1], '-->')) {
                    break;
                }
                $textLines[] = $line;
                $i++;
            }

            $text = trim(implode("\n", $textLines));
            // Limpiar etiquetas de fuentes y estilos ASS (p. ej. <font ...>, {\an8})
            $text = preg_replace('/<font[^>]*>/i', '', $text) ?? $text;
            $text = str_ireplace('</font>', '', $text);
            $text = preg_replace('/\{[^\}]+\}/', '', $text) ?? $text;
            $text = trim($text);

            [$startTime, $endTime] = $this->splitTimestamps($start);

            $blocks[] = [
                'index' => $index,
                'start' => $startTime,
                'end' => $endTime,
                'text' => $text !== '' ? $text : '...',
            ];
        }

        return $blocks;
    }

    /**
     * Serializa bloques de vuelta a contenido SRT.
     *
     * @param  array<int, array{index:int, start:string, end:string, text:string}>  $blocks
     */
    public function build(array $blocks): string
    {
        $out = '';
        $i = 1;

        foreach ($blocks as $block) {
            $index = $block['index'] ?? $i;
            $text = trim($block['text'] ?? '');
            if ($text === '') {
                $text = '...';
            }

            $out .= $index . "\n";
            $out .= $block['start'] . ' --> ' . $block['end'] . "\n";
            $out .= $text . "\n\n";
            $i++;
        }

        return $out;
    }

    /** Extrae una muestra de texto (primeros N caracteres) de un archivo. */
    public function extractSample(string $path, int $maxChars = 1500): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = (string) file_get_contents($path);

        $blocks = $this->parse($content);
        $text = '';

        foreach ($blocks as $block) {
            $text .= $block['text'] . ' ';
            if (mb_strlen($text) >= $maxChars) {
                break;
            }
        }

        $text = trim($text);

        return $text !== '' ? mb_substr($text, 0, $maxChars) : null;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitTimestamps(string $line): array
    {
        $parts = preg_split('/\s*-->\s*/', $line) ?: [];

        return [
            trim($parts[0] ?? ''),
            trim($parts[1] ?? ''),
        ];
    }
}
