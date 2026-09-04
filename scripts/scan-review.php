#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Escáner de calidad: detecta bloques deficientes (sin traducir / basura)
 * en los subtítulos generados y crea los .review.json para que la web
 * muestre los avisos de revisión. NO traduce nada.
 *
 * Uso:
 *   php scripts/scan-review.php            # solo reporta
 *   php scripts/scan-review.php --apply    # crea los .review.json
 *   php scripts/scan-review.php --limit=5  # analiza solo los primeros N
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\SubtitleTrack;
use App\Services\Container;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Translation\TranslationBatchService;
use App\Storage\Database;

$apply = in_array('--apply', $argv, true);
$limit = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

// Detector heurístico de texto en inglés sin traducir (reutiliza el del batch service)
$batch = Container::get(TranslationBatchService::class);
$reflect = new ReflectionMethod($batch, 'containsEnglish');
$reflect->setAccessible(true);

/**
 * Detector ESTRICTO de inglés: solo palabras inequívocamente inglesas
 * (excluye "no", "so", "to", "in", "is" que son ambiguas con español).
 */
$isLikelyEnglish = function (string $text): bool {
    // Con acentos españoles o ¿/¡ no es inglés puro
    if (preg_match('/[áéíóúñü¿¡]/u', $text)) {
        return false;
    }

    $strong = [
        'the', 'and', 'with', 'that', 'this', 'you', 'are', 'what', 'but', 'from',
        'they', 'will', 'your', 'because', 'about', 'just', 'have', 'were', 'been',
        'there', 'where', 'when', 'would', 'could', 'should', 'them', 'their',
        'these', 'those', 'which', 'into', 'over', 'again', 'some', 'most', 'other',
        'only', 'own', 'same', 'than', 'very', 'don', 'cant', 'wont', 'im', 'ive',
        'isnt', 'doesnt', 'didnt', 'its', 'was', 'had', 'did', 'get', 'got', 'going',
    ];

    $lower = strtolower($text);
    $words = preg_split('/[^a-z]+/', $lower) ?: [];
    $significant = array_values(array_filter($words, fn ($w) => strlen($w) > 2));

    if (count($significant) < 3) {
        return false;
    }

    $hits = 0;
    foreach ($significant as $w) {
        if (in_array($w, $strong, true)) {
            $hits++;
        }
    }

    // ≥2 palabras fuertemente inglesas, y que representen buena parte del texto
    $ratio = $hits / count($significant);

    return $hits >= 2 && $ratio >= 0.2;
};

$parser = Container::get(SubtitleParserService::class);

// Lista de subtítulos generados
$stmt = Database::pdo()->query(
    "SELECT id, path FROM subtitle_tracks
     WHERE source_type = 'generated' AND path IS NOT NULL
     ORDER BY id ASC"
);
$tracks = $stmt->fetchAll();

if ($limit > 0) {
    $tracks = array_slice($tracks, 0, $limit);
}

echo "Analizando " . count($tracks) . " subtítulos generados...\n\n";

$totalFilesWithProblems = 0;
$totalBlocksProblem = 0;

foreach ($tracks as $track) {
    $srtPath = $track['path'];

    if (! is_file($srtPath)) {
        continue;
    }

    $content = (string) file_get_contents($srtPath);
    $blocks = $parser->parse($content);
    $problems = [];

    foreach ($blocks as $block) {
        $text = trim($block['text']);
        if ($text === '' || $text === '...') {
            continue;
        }

        // Limpiar etiquetas HTML/ASS, nombres de hablante, música
        $clean = preg_replace('/<[^>]+>|\\\\{[^\\\\}]+\\\\}|\\[[A-Z][^\\]]*\\]|♪|♪/u', ' ', $text) ?? $text;
        $words = preg_split('/[^a-záéíóúñü]+/u', strtolower($clean)) ?: [];
        $significant = array_filter($words, fn ($w) => strlen($w) > 2);

        // Requiere mínimo de contenido para no marcar nombres sueltos
        if (count($significant) < 3) {
            continue;
        }

        $reason = null;

        // 0) Bug conocido: texto de contexto del prompt que se coló en el bloque
        if (preg_match('/previous subtitle|context only|subtitle to translate/i', $text)) {
            $reason = 'Texto de contexto del prompt (bug) que quedó en el bloque.';
        }

        // 1) Texto que parece inglés sin traducir (detector estricto)
        if ($reason === null && $isLikelyEnglish($clean)) {
            $reason = 'Posible texto sin traducir (parece inglés).';
        }

        // 2) Basura de páginas de error
        if ($reason === null && preg_match('/error\s*500|that.s an error|please try again|server error|model_not_found/i', $text)) {
            $reason = 'Contenido de error de API (basura).';
        }

        if ($reason !== null) {
            $problems[] = [
                'index' => $block['index'],
                'reason' => $reason,
                'original' => $text,
            ];
        }
    }

    if ($problems !== []) {
        $totalFilesWithProblems++;
        $totalBlocksProblem += count($problems);
        $reviewPath = $srtPath . '.review.json';

        if ($apply) {
            file_put_contents($reviewPath, json_encode($problems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo "⚠ " . basename($srtPath) . ": " . count($problems) . " bloque(s) → review.json CREADO\n";
        } else {
            echo "⚠ " . basename($srtPath) . ": " . count($problems) . " bloque(s) sospechoso(s)\n";
            foreach (array_slice($problems, 0, 3) as $p) {
                echo "    [" . $p['index'] . "] " . substr(str_replace("\n", " / ", $p['original']), 0, 80) . "\n";
            }
            if (count($problems) > 3) {
                echo "    ... y " . (count($problems) - 3) . " más\n";
            }
        }
    }
}

echo "\n====================\n";
echo "Archivos con problemas: {$totalFilesWithProblems}\n";
echo "Bloques problemáticos: {$totalBlocksProblem}\n";
if (! $apply) {
    echo "\nEjecuta con --apply para crear los .review.json (avisos en la web).\n";
}
