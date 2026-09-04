#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Revisión en masa de subtítulos con bloques problemáticos.
 *
 * Para cada archivo con .review.json:
 *  1. Identifica la pista fuente REAL del MKV (la que tiene el MISMO
 *     número de bloques que el SRT generado → índices alineados).
 *  2. Por cada bloque problemático:
 *       - Si el original es marcador/guion ("-", "[Spanish]") → restaura gratis.
 *       - Si no → re-traduce con DeepSeek (forzado).
 *  3. Post-verificación: si el resultado sigue siendo una "respuesta del
 *     modelo" o basura, reintenta con el texto fuente directo.
 *
 * Uso: php scripts/review-all.php [--media="película"]  (opcional: solo un archivo)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\MediaFile;
use App\Services\Container;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Translation\OpenAICompatibleProvider;
use App\Services\Translation\TranslationBatchService;
use App\Storage\Database;

$filter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--media=')) {
        $filter = substr($arg, 8);
    }
}

$parser = Container::get(SubtitleParserService::class);

// DeepSeek forzado
$deepseek = new OpenAICompatibleProvider([
    'key' => 'deepseek_api_key',
    'base_url' => 'deepseek_base_url',
    'model' => 'deepseek_model',
    'label' => 'DeepSeek',
]);
$forcedBatch = new TranslationBatchService($deepseek);

// Patrones de "respuesta del modelo"
$modelReplyPattern = '/haven.t provided|you.d like me to translate|text to translate in|subtitle text|please provide the|please share the content|happy to help|i.d be happy to|it seems you|i.m ready to translate|content you.d like|do not translate|i can.t translate|as an ai/i';

function markerOnly(string $text): bool
{
    $cleaned = preg_replace('/[\[\]\(\){}<>♪]+/u', '', $text) ?? $text;
    $words = array_filter(preg_split('/\s+/', trim($cleaned)) ?: [], fn ($w) => $w !== '');

    return count($words) <= 1;
}

function looksEnglish(string $text): bool
{
    if (preg_match('/[áéíóúñü¿¡]/u', $text)) {
        return false;
    }
    $strong = ['the', 'and', 'with', 'that', 'this', 'you', 'are', 'what', 'but', 'from', 'they', 'will', 'your', 'because', 'about', 'just', 'have', 'were', 'been', 'there', 'where', 'when', 'would', 'could', 'should', 'them', 'their', 'these', 'those', 'which', 'into', 'over', 'again', 'some', 'most', 'other', 'only', 'own', 'same', 'than', 'very', 'don', 'cant', 'wont', 'im', 'ive', 'isnt', 'doesnt', 'didnt', 'its', 'was', 'had', 'did', 'get', 'got', 'going'];
    $words = array_values(array_filter(preg_split('/[^a-z]+/', strtolower($text)) ?: [], fn ($w) => strlen($w) > 2));
    if (count($words) < 3) {
        return false;
    }
    $hits = count(array_intersect($words, $strong));

    return $hits >= 2 && ($hits / count($words)) >= 0.2;
}

/**
 * Extrae la pista fuente real: la del MKV cuyo número de bloques coincida
 * con el SRT generado. Devuelve índice de bloque → texto.
 */
function sourceByBlockCount(string $mkvPath, int $expectedBlocks): array
{
    $runner = Container::get(\App\Infrastructure\ProcessRunner::class);
    $ffprobe = Container::get(\App\Infrastructure\FFprobe::class);
    $parser = Container::get(SubtitleParserService::class);

    try {
        $data = $ffprobe->probe($mkvPath);
        $subStreams = array_values(array_filter(
            $data['streams'] ?? [],
            fn ($s) => ($s['codec_type'] ?? null) === 'subtitle'
        ));

        foreach ($subStreams as $stream) {
            $index = (int) $stream['index'];
            $tmp = tempnam(sys_get_temp_dir(), 'sub_src_') . '.srt';
            @unlink($tmp);

            [$code] = $runner->run([
                (string) config('binaries.ffmpeg'),
                '-y', '-v', 'error',
                '-i', $mkvPath,
                '-map', '0:' . $index,
                '-c:s', 'srt',
                $tmp,
            ], 120);

            if ($code === 0 && is_file($tmp)) {
                $blocks = $parser->parse((string) file_get_contents($tmp));
                $count = count($blocks);

                // Misma cantidad de bloques → es la fuente que se tradujo
                if (abs($count - $expectedBlocks) <= 2) {
                    $byIndex = [];
                    foreach ($blocks as $b) {
                        $byIndex[$b['index']] = $b['text'];
                    }
                    @unlink($tmp);

                    return $byIndex;
                }
            }
            @unlink($tmp);
        }
    } catch (\Throwable) {
    }

    return [];
}

// ── Recorrer archivos con review pendiente ─────────────────────
$stmt = Database::pdo()->query(
    "SELECT st.path, st.media_file_id FROM subtitle_tracks st
     WHERE st.source_type = 'generated' AND st.path IS NOT NULL"
);

$summary = ['files' => 0, 'markers' => 0, 'translated' => 0, 'failed' => []];

foreach ($stmt->fetchAll() as $row) {
    $srtPath = $row['path'];
    $reviewPath = $srtPath . '.review.json';

    if (! is_file($reviewPath)) {
        continue;
    }

    $media = MediaFile::findById((int) $row['media_file_id']);
    if (! $media) {
        continue;
    }

    if ($filter !== null && ! str_contains($media->filename, $filter)) {
        continue;
    }

    $problems = json_decode((string) file_get_contents($reviewPath), true) ?: [];
    if ($problems === []) {
        continue;
    }

    echo "\n=== " . substr($media->filename, 0, 60) . " (" . count($problems) . " bloques) ===\n";

    // Identificar pista fuente real por conteo de bloques
    $generatedBlocks = count($parser->parse((string) file_get_contents($srtPath)));
    $source = sourceByBlockCount($media->path, $generatedBlocks);
    echo "  Pista fuente alineada: " . (count($source) > 0 ? 'SÍ (' . count($source) . ' bloques)' : 'NO — usando texto guardado') . "\n";

    $srtBlocks = $parser->parse((string) file_get_contents($srtPath));
    $byIndex = [];
    foreach ($srtBlocks as $b) {
        $byIndex[$b['index']] = $b;
    }

    $summary['files']++;
    $remaining = [];

    foreach ($problems as $problem) {
        $index = (int) ($problem['index'] ?? 0);
        if (! isset($byIndex[$index])) {
            continue;
        }

        // Texto real: de la pista fuente alineada, si no del guardado
        $realOriginal = $source[$index] ?? (string) ($problem['original'] ?? '');

        // Marcador/guion → restaurar gratis
        if ($realOriginal === '' || $realOriginal === '-' || markerOnly($realOriginal)) {
            $byIndex[$index]['text'] = $realOriginal !== '' ? $realOriginal : '...';
            $summary['markers']++;
            echo "  ✓ [$index] marcador restaurado: '" . substr($realOriginal, 0, 40) . "'\n";
            continue;
        }

        // El texto guardado puede ser la "respuesta del modelo" → usar el real
        $textToUse = $realOriginal;

        try {
            $result = $forcedBatch->translateBlock(
                [
                    'index' => $byIndex[$index]['index'],
                    'start' => $byIndex[$index]['start'],
                    'end' => $byIndex[$index]['end'],
                    'text' => $textToUse,
                ],
                (string) config('translation.target_language', 'es')
            );

            $translated = $result['text'];

            // Post-verificación: ¿sigue pareciendo respuesta del modelo o inglés?
            if (preg_match($modelReplyPattern, $translated) || looksEnglish($translated)) {
                throw new \RuntimeException('Resultado aún sospechoso: ' . substr($translated, 0, 60));
            }

            $byIndex[$index]['text'] = $translated;
            $summary['translated']++;
            echo "  ✓ [$index] traducido: " . substr(str_replace("\n", ' / ', $translated), 0, 70) . "\n";
        } catch (\Throwable $e) {
            // No se pudo traducir bien: dejar el texto fuente (inglés) para revisión posterior
            $byIndex[$index]['text'] = $textToUse !== '' ? $textToUse : $byIndex[$index]['text'];
            $remaining[] = $problem;
            $summary['failed'][] = substr($media->filename, 0, 40) . " #$index";
            echo "  ⚠ [$index] falló: " . substr($e->getMessage(), 0, 70) . "\n";
        }
    }

    // Guardar SRT y limpiar/actualizar review
    ksort($byIndex);
    file_put_contents($srtPath, $parser->build(array_values($byIndex)), LOCK_EX);

    if ($remaining === []) {
        @unlink($reviewPath);
        echo "  → review.json eliminado\n";
    } else {
        file_put_contents($reviewPath, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo "  → quedan " . count($remaining) . " sin resolver\n";
    }
}

echo "\n====================\n";
echo "Archivos procesados: {$summary['files']}\n";
echo "Marcadores restaurados (gratis): {$summary['markers']}\n";
echo "Traducidos con DeepSeek: {$summary['translated']}\n";
echo "Fallaron: " . count($summary['failed']) . "\n";
foreach ($summary['failed'] as $f) {
    echo "  - $f\n";
}
