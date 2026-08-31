<?php

declare(strict_types=1);

namespace App\Services\Subtitle;

use App\Models\MediaFile;

/**
 * Convención de nombres de los subtítulos generados.
 *
 *   Movie.mkv            → Movie.es.srt
 *   Movie.en.srt         → Movie.es.srt
 *   Movie.es.forced.srt  → Movie.es.srt
 *   Episode S01E02.mkv   → Episode S01E02.es.srt
 */
final class SubtitleFilenameService
{
    /**
     * Nombre del archivo SRT de salida para un video.
     */
    public function forMedia(MediaFile $file, string $targetLang = 'es', array $flags = []): string
    {
        $base = pathinfo($file->filename, PATHINFO_FILENAME);
        $name = $base . '.' . $targetLang;

        if (! empty($flags['forced'])) {
            $name .= '.forced';
        }
        if (! empty($flags['sdh'])) {
            $name .= '.sdh';
        }

        return $name . '.srt';
    }

    /**
     * Ruta completa del SRT de salida (junto al video).
     */
    public function pathForMedia(MediaFile $file, string $targetLang = 'es', array $flags = []): string
    {
        return $file->directory() . DIRECTORY_SEPARATOR . $this->forMedia($file, $targetLang, $flags);
    }

    /**
     * Verifica si ya existe un SRT en el idioma destino junto al video.
     */
    public function existsForMedia(MediaFile $file, string $targetLang = 'es'): bool
    {
        return file_exists($this->pathForMedia($file, $targetLang));
    }
}
