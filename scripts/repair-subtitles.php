#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Repara un SRT traducido: detecta bloques con basura de la API
 * (páginas de error de Google/DeepSeek) y los re-traduce con el
 * proveedor activo, usando el SRT original en inglés como referencia.
 *
 * Uso:
 *   php scripts/repair-subtitles.php <srt_traducido> <srt_original>
 *
 * Ejemplo:
 *   php scripts/repair-subtitles.php \
 *     "/mnt/.../Cup.S01E01.es.sdh.srt" \
 *     /tmp/americas_original.srt
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\Container;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Translation\TranslationBatchService;

if ($argc < 3) {
    fwrite(STDERR, "Uso: php repair-subtitles.php <srt_es> <srt_original_en>\n");
    exit(1);
}

$translatedPath = $argv[1];
$originalPath = $argv[2];

$parser = Container::get(SubtitleParserService::class);
$batch = Container::get(TranslationBatchService::class);

// Textos que delatan basura de la API
$junkRegex = '/error\s*500|that.s an error|there was an error|please try again later|that.s all we know|server error|http error|model_not_found|invalid_api_key|too many requests|rate limit/i';

$esBlocks = $parser->parse((string) file_get_contents($translatedPath));
$enBlocks = $parser->parse((string) file_get_contents($originalPath));
$enByIndex = [];
foreach ($enBlocks as $b) {
    $enByIndex[$b['index']] = $b;
}

$junk = array_values(array_filter($esBlocks, fn ($b) => preg_match($junkRegex, $b['text'])));
$count = count($junk);

echo "Bloques totales: " . count($esBlocks) . "\n";
echo "Bloques con basura: {$count}\n";

if ($count === 0) {
    echo "✓ No hay basura. Nada que reparar.\n";
    exit(0);
}

// Re-traducir los bloques basura con el proveedor activo
echo "Re-traduciendo con " . Container::get(\App\Services\Translation\TranslationProviderInterface::class)->name() . "...\n";

$fixed = 0;
$failed = [];

// Re-traducir usando referencia para que los cambios se conserven
foreach ($junk as &$block) {
    $original = $enByIndex[$block['index']]['text'] ?? $block['text'];

    try {
        $translated = $batch->translateBlock(
            ['index' => $block['index'], 'start' => $block['start'], 'end' => $block['end'], 'text' => $original],
            (string) config('translation.target_language', 'es')
        );
        $block['text'] = $translated['text'];
        $fixed++;
    } catch (Throwable $e) {
        $failed[] = $block['index'];
    }
}
unset($block);

// Reconstruir el SRT
$byIndex = [];
foreach ($esBlocks as $b) {
    $byIndex[$b['index']] = $b;
}
foreach ($junk as $block) {
    $byIndex[$block['index']] = $block;
}
ksort($byIndex);

file_put_contents($translatedPath, $parser->build(array_values($byIndex)), LOCK_EX);

echo "✓ Reparados: {$fixed} bloques\n";
if ($failed !== []) {
    echo "⚠ Fallaron (quedan con basura): " . implode(', ', $failed) . "\n";
}
echo "Archivo actualizado: {$translatedPath}\n";
