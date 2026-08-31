<?php

declare(strict_types=1);

namespace App\Services\Jellyfin;

use App\Models\MediaFile;
use App\Models\SubtitleTrack;
use App\Services\Library\MediaChangeDetectorService;
use App\Services\Media\SubtitleExtractorService;
use App\Services\Subtitle\SubtitleAnalyzerService;

/**
 * Sincroniza el catálogo de Jellyfin con la base local y traduce
 * los subtítulos que faltan en español.
 *
 * Jellyfin detecta automáticamente los .srt generados junto al video
 * (Pelicula.es.srt), así que no se necesita ningún plugin en el servidor.
 */
final class JellyfinSyncService
{
    public function __construct(
        private readonly JellyfinApiClient $api,
        private readonly JellyfinPathMapper $mapper,
        private readonly MediaChangeDetectorService $detector,
        private readonly SubtitleAnalyzerService $analyzer,
        private readonly SubtitleExtractorService $extractor,
    ) {
    }

    /**
     * @param  array{limit?: int, itemTypes?: string, dryRun?: bool, onProgress?: callable}  $options
     * @return array{
     *   total: int,
     *   notFound: int,
     *   alreadySpanish: int,
     *   noSource: int,
     *   translated: int,
     *   errors: array<int, array{name: string, message: string}>
     * }
     */
    public function sync(array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 0);
        $itemTypes = (string) ($options['itemTypes'] ?? 'Movie,Episode');
        $dryRun = (bool) ($options['dryRun'] ?? false);
        $onProgress = $options['onProgress'] ?? null;

        $stats = [
            'total' => 0,
            'notFound' => 0,
            'alreadySpanish' => 0,
            'noSource' => 0,
            'translated' => 0,
            'errors' => [],
        ];

        $items = $this->api->items($itemTypes, $limit);
        $stats['total'] = count($items);

        foreach ($items as $item) {
            $label = $item['seriesName'] !== null && $item['seriesName'] !== ''
                ? "{$item['seriesName']} — {$item['name']}"
                : $item['name'];

            if ($item['path'] === null) {
                $stats['notFound']++;
                continue;
            }

            $hostPath = $this->mapper->toHostPath($item['path']);

            if ($hostPath === null || ! is_file($hostPath)) {
                $stats['notFound']++;
                $onProgress?->__invoke("  ⚠ [{$label}] ruta no localizable: {$item['path']}");
                continue;
            }

            try {
                $result = $this->processFile($hostPath, $dryRun, $label, $onProgress);
                $stats[$result]++;
            } catch (\Throwable $e) {
                $stats['errors'][] = ['name' => $label, 'message' => $e->getMessage()];
                $onProgress?->__invoke("  ✗ [{$label}] " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * @param  callable(string): void|null  $onProgress
     * @return string 'alreadySpanish' | 'noSource' | 'translated'
     */
    private function processFile(string $hostPath, bool $dryRun, string $label, ?callable $onProgress): string
    {
        $media = MediaFile::findByPath($hostPath);

        if ($media === null) {
            $media = $this->detector->register([
                'path' => $hostPath,
                'size' => (int) filesize($hostPath),
                'mtime' => filemtime($hostPath) ?: time(),
            ]);
        }

        // Analizar si nunca se analizó o no tiene pistas registradas.
        $needsAnalyze = $media->status === MediaFile::STATUS_PENDING
            || $media->lastAnalyzedAt === null
            || $media->tracks() === [];

        if ($needsAnalyze) {
            $this->analyzer->analyze($media);
            $media->status = MediaFile::STATUS_ANALYZED;
            $media->lastAnalyzedAt = gmdate('Y-m-d H:i:s');
            $media->save();
        }

        if ($media->hasSpanish()) {
            return 'alreadySpanish';
        }

        $source = $this->bestSourceTrack($media);

        if ($source === null) {
            return 'noSource';
        }

        $sourceInfo = "{$source->languageLabel()} ({$source->codecLabel()}"
            . ($source->sourceType === SubtitleTrack::SOURCE_EXTERNAL ? ', externo' : '')
            . ')';

        $onProgress?->__invoke("  → [{$label}] traduciendo desde {$sourceInfo}");

        if ($dryRun) {
            return 'translated';
        }

        $this->extractor->extractAndTranslate($media, $source, function (int $done, int $total) use ($onProgress): void {
            static $lastPct = -1;

            $pct = (int) round($done / max(1, $total) * 100);

            if ($pct !== $lastPct && $onProgress !== null) {
                $onProgress("      bloques {$done}/{$total} ({$pct}%)");
                $lastPct = $pct;
            }
        });

        return 'translated';
    }

    /**
     * Mejor pista fuente: preferentemente inglés en texto (interna o externa);
     * si no hay, cualquier pista de texto que no sea español.
     */
    private function bestSourceTrack(MediaFile $media): ?SubtitleTrack
    {
        $candidates = [...$media->internalTextTracks(), ...$media->externalTracks()];

        $candidates = array_values(array_filter(
            $candidates,
            fn (SubtitleTrack $t) => ! in_array($t->languageDetected ?? $t->language, ['spa', 'es'], true)
        ));

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $track) {
            $lang = $track->languageDetected ?? $track->language;

            if (in_array($lang, ['eng', 'en'], true)) {
                return $track;
            }
        }

        return $candidates[0];
    }
}
