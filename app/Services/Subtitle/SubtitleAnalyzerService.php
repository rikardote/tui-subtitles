<?php

declare(strict_types=1);

namespace App\Services\Subtitle;

use App\Infrastructure\FFprobe;
use App\Models\MediaFile;
use App\Models\SubtitleTrack;

/**
 * Analiza un archivo de video: pistas internas (FFprobe) y subtítulos externos.
 * Persiste el resultado en subtitle_tracks.
 */
final class SubtitleAnalyzerService
{
    /** Codecs considerados "de texto" (extraíbles sin OCR). */
    private const TEXT_CODECS = [
        'subrip', 'ass', 'ssa', 'webvtt', 'vtt', 'mov_text', 'text',
    ];

    /** Codecs de imagen (requieren OCR, fuera de la PoC). */
    private const IMAGE_CODECS = [
        'hdmv_pgs_subtitle', 'dvd_subtitle', 'dvdsub', 'pgssub', 'xsub',
    ];

    public function __construct(
        private readonly FFprobe $ffprobe,
        private readonly LanguageDetectorService $language,
        private readonly SubtitleParserService $parser,
    ) {
    }

    /**
     * Analiza un archivo y reemplaza sus pistas registradas.
     *
     * @return array{internal: SubtitleTrack[], external: SubtitleTrack[]}
     */
    public function analyze(MediaFile $file): array
    {
        SubtitleTrack::deleteForMediaFile($file->id);

        $internal = $this->analyzeInternal($file);
        $external = $this->analyzeExternal($file);

        foreach ([...$internal, ...$external] as $track) {
            $track->save();
        }

        return ['internal' => $internal, 'external' => $external];
    }

    /**
     * @return SubtitleTrack[]
     */
    private function analyzeInternal(MediaFile $file): array
    {
        $tracks = [];

        try {
            $streams = $this->ffprobe->subtitleStreams($file->path);
        } catch (\Throwable) {
            return $tracks; // FFprobe falló; se registra en el escaneo general
        }

        foreach ($streams as $stream) {
            $index = (int) ($stream['index'] ?? 0);
            $codec = (string) ($stream['codec_name'] ?? '');
            $title = $stream['tags']['title'] ?? null;
            $language = $stream['tags']['language'] ?? null;
            $isDefault = ($stream['disposition']['default'] ?? 0) === 1;
            $isForced = ($stream['disposition']['forced'] ?? 0) === 1;

            $track = new SubtitleTrack();
            $track->mediaFileId = $file->id;
            $track->sourceType = SubtitleTrack::SOURCE_INTERNAL;
            $track->streamIndex = $index;
            $track->codec = $codec !== '' ? $codec : null;
            $track->title = $title;
            $track->language = $language;
            $track->languageDetected = $this->language->detectFromMetadata($language, $title);
            $track->isDefault = $isDefault;
            $track->isForced = $isForced;
            $track->isTextBased = in_array($codec, self::TEXT_CODECS, true);
            $track->isSdh = $this->isSdh($title, $codec);

            // Nivel 3: si no hay idioma, intentamos detectarlo por contenido
            if ($track->languageDetected === null && $track->isTextBased) {
                $sample = $this->extractSample($file->path, $index);
                if ($sample !== null) {
                    $detected = $this->language->detectFromContent($sample);
                    if ($detected !== null) {
                        $track->languageDetected = $detected;
                        $track->languageConfidence = 0.6;
                    }
                }
            }

            $tracks[] = $track;
        }

        return $tracks;
    }

    /**
     * Detecta archivos de subtítulos externos en la carpeta del video.
     *
     * Convención: Movie.en.srt, Movie.es.srt, Movie.es.forced.srt
     *
     * @return SubtitleTrack[]
     */
    private function analyzeExternal(MediaFile $file): array
    {
        $tracks = [];
        $dir = $file->directory();
        $base = pathinfo($file->filename, PATHINFO_FILENAME);
        $extensions = config('subtitle_extensions', ['srt', 'ass', 'ssa', 'vtt']);

        $files = @scandir($dir);

        if ($files === false) {
            return $tracks;
        }

        foreach ($files as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($ext, $extensions, true)) {
                continue;
            }

            // Debe compartir el nombre base del video
            $entryBase = pathinfo($entry, PATHINFO_FILENAME);
            if (! str_starts_with($entryBase, $base . '.')) {
                continue;
            }

            $suffix = substr($entryBase, strlen($base) + 1); // "en", "es", "es.forced"...
            $parts = explode('.', $suffix);
            $langCode = strtolower($parts[0] ?? '');

            $track = new SubtitleTrack();
            $track->mediaFileId = $file->id;
            $track->sourceType = SubtitleTrack::SOURCE_EXTERNAL;
            $track->path = $dir . DIRECTORY_SEPARATOR . $entry;
            $track->codec = $ext;
            $track->title = $entry;
            $track->isTextBased = true; // srt/ass/ssa/vtt son texto
            $track->language = $langCode !== '' ? $langCode : null;
            $track->languageDetected = $this->language->detectFromMetadata($langCode, null)
                ?? $this->language->detectFromTitle($entryBase);
            $track->isSdh = str_contains($suffix, 'sdh') || stripos($entry, 'sdh') !== false;
            $track->isForced = str_contains($suffix, 'forced');

            // Nivel 3 por contenido
            if ($track->languageDetected === null && is_readable($track->path)) {
                $sample = $this->parser->extractSample((string) $track->path);
                if ($sample !== null) {
                    $detected = $this->language->detectFromContent($sample);
                    if ($detected !== null) {
                        $track->languageDetected = $detected;
                        $track->languageConfidence = 0.6;
                    }
                }
            }

            $tracks[] = $track;
        }

        return $tracks;
    }

    private function isSdh(?string $title, string $codec): bool
    {
        $title = strtolower((string) $title);

        return str_contains($title, 'sdh')
            || str_contains($title, 'hearing')
            || str_contains($title, 'deaf')
            || str_contains($title, 'cc')
            || $codec === 'ass' && str_contains($title, 'sign');
    }

    /** Extrae una muestra corta de texto de una pista interna (para detección de idioma). */
    private function extractSample(string $videoPath, int $streamIndex): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sub_sample_');

        if ($tmp === false) {
            return null;
        }

        try {
            // Extrae los primeros ~8 segundos de la pista
            $cmd = sprintf(
                '%s -v error -i %s -map 0:%d -t 8 -c:s srt -f srt -y %s 2>&1',
                escapeshellarg((string) config('binaries.ffmpeg')),
                escapeshellarg($videoPath),
                $streamIndex,
                escapeshellarg($tmp)
            );
            exec($cmd, $output, $code);

            if ($code !== 0 || ! is_file($tmp) || filesize($tmp) === 0) {
                return null;
            }

            return $this->parser->extractSample($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
