#!/usr/bin/env php
<?php

/**
 * Smoke test de los servicios (escaneo + análisis) sin TUI interactiva.
 * Uso: php scripts/smoke-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\MediaFile;
use App\Services\Container;
use App\Services\Library\MediaPathService;
use App\Services\Library\MediaScannerService;
use App\Services\Subtitle\LanguageDetectorService;
use App\Services\Subtitle\SubtitleAnalyzerService;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Subtitle\SubtitleValidatorService;

$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? "  ✓ " : "  ✗ ") . $label . ($detail ? " — {$detail}" : '') . PHP_EOL;
    if (! $ok) {
        $fail++;
    }
}

echo "== Escaneo de bibliotecas ==" . PHP_EOL;
$scanner = Container::get(MediaScannerService::class);
$results = $scanner->scanAll();
foreach ($results as $r) {
    check("{$r['library']}: {$r['total']} archivos ({$r['new']} nuevos)", $r['total'] >= 1, json_encode(['nuevos' => $r['new'], 'modificados' => $r['modified']]));
}

echo PHP_EOL . "== Base de datos ==" . PHP_EOL;
$total = MediaFile::count();
check("MediaFiles registrados: {$total}", $total >= 1, "se esperaban archivos registrados");

echo PHP_EOL . "== Análisis con FFprobe ==" . PHP_EOL;
$analyzer = Container::get(SubtitleAnalyzerService::class);
$lang = Container::get(LanguageDetectorService::class);

$analysisLimit = (int) (getenv('SMOKE_ANALYSIS_LIMIT') ?: 3);
foreach (array_slice(MediaFile::all(), 0, $analysisLimit) as $media) {
    $result = $analyzer->analyze($media);
    $media->status = MediaFile::STATUS_ANALYZED;
    $media->lastAnalyzedAt = gmdate('Y-m-d H:i:s');
    $media->save();

    echo PHP_EOL . "  {$media->filename}:" . PHP_EOL;
    foreach ($result['internal'] as $t) {
        check(
            "interna #{$t->streamIndex} {$t->languageLabel()} [{$t->codec}] texto=" . ($t->isTextBased ? 'S' : 'N'),
            $t->languageDetected !== null,
            'idioma: ' . ($t->languageDetected ?? '??')
        );
    }
    foreach ($result['external'] as $t) {
        check(
            "externa {$t->path} {$t->languageLabel()}",
            true
        );
    }

    check("tiene español: " . ($media->hasSpanish() ? 'S' : 'N'), true);
    check("tiene inglés: " . (count($media->englishTracks()) > 0 ? 'S' : 'N'), true);
}

echo PHP_EOL . "== Detector de idioma (nivel 2) ==" . PHP_EOL;
check("'English SDH' → eng", $lang->detectFromTitle('English SDH') === 'eng');
check("'Spanish (Latin)' → spa", $lang->detectFromTitle('Spanish (Latin)') === 'spa');
check("'Latino' → spa", $lang->detectFromTitle('Latino') === 'spa');
check("'Deutsch' → deu", $lang->detectFromTitle('Deutsch') === 'deu');
check("'es' → spa", $lang->detectFromTitle('es') === 'spa');
check("'en' → eng", $lang->detectFromTitle('en') === 'eng');

echo PHP_EOL . "== Detector de idioma (nivel 3 — contenido) ==" . PHP_EOL;
$sampleEn = "The quick brown fox jumps over the lazy dog. This is a test of the engine and you should trust it because they are here.";
check("muestra EN → eng", $lang->detectFromContent($sampleEn) === 'eng');
$sampleEs = "El zorro marrón salta sobre el perro perezoso. Esto es una prueba del motor y usted debe confiar en él porque están aquí.";
check("muestra ES → spa", $lang->detectFromContent($sampleEs) === 'spa');

echo PHP_EOL . "== Parser y Validador SRT ==" . PHP_EOL;
$parser = Container::get(SubtitleParserService::class);
$validator = Container::get(SubtitleValidatorService::class);

$srt = <<<SRT
1
00:00:01,000 --> 00:00:03,000
Hello world.

2
00:00:04,000 --> 00:00:06,000
How are you today?
SRT;

$blocks = $parser->parse($srt);
check("parse: 2 bloques", count($blocks) === 2);
check("parse: timestamps preservados", $blocks[0]['start'] === '00:00:01,000' && $blocks[0]['end'] === '00:00:03,000');
check("parse: texto correcto", $blocks[1]['text'] === 'How are you today?');

$rebuilt = $parser->build($blocks);
check("rebuild: contenido idéntico", rtrim($rebuilt) === rtrim($srt));

$validation = $validator->validate($srt);
check("validar: SRT válido", $validation['valid'] === true, implode('; ', $validation['errors']));

$badSrt = "1\n00:00:01,000 --> 00:00:03,000\nHello.\n\n3\n00:00:04,000 --> 00:00:02,000\nBroken.";
$validationBad = $validator->validate($badSrt);
check("validar: detecta errores", $validationBad['valid'] === false, implode('; ', array_slice($validationBad['errors'], 0, 2)));

echo PHP_EOL;
exit($fail === 0 ? 0 : 1);
