<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\FFmpeg;
use App\Infrastructure\FFprobe;
use App\Infrastructure\ProcessRunner;
use App\Services\Library\MediaChangeDetectorService;
use App\Services\Library\MediaFileDiscoveryService;
use App\Services\Library\MediaPathService;
use App\Services\Library\MediaScannerService;
use App\Services\Media\SubtitleExtractorService;
use App\Services\Subtitle\LanguageDetectorService;
use App\Services\Subtitle\SubtitleAnalyzerService;
use App\Services\Subtitle\SubtitleFilenameService;
use App\Services\Subtitle\SubtitleParserService;
use App\Services\Subtitle\SubtitleValidatorService;
use App\Services\Translation\DeepTranslatorProvider;
use App\Services\Translation\SubtitleTranslatorService;
use App\Services\Translation\TranslationBatchService;

/**
 * Contenedor de servicios simple.
 * Todos los servicios se construyen aquí; la TUI y la futura web los consumen igual.
 */
final class Container
{
    /** @var array<string, object> */
    private static array $instances = [];

    public static function get(string $class): object
    {
        return self::$instances[$class] ??= self::build($class);
    }

    private static function build(string $class): object
    {
        return match ($class) {
            ProcessRunner::class => new ProcessRunner(),
            FFprobe::class => new FFprobe(self::get(ProcessRunner::class)),
            FFmpeg::class => new FFmpeg(self::get(ProcessRunner::class)),
            MediaPathService::class => new MediaPathService(),
            MediaFileDiscoveryService::class => new MediaFileDiscoveryService(self::get(MediaPathService::class)),
            MediaChangeDetectorService::class => new MediaChangeDetectorService(),
            MediaScannerService::class => new MediaScannerService(
                self::get(MediaPathService::class),
                self::get(MediaFileDiscoveryService::class),
                self::get(MediaChangeDetectorService::class),
            ),
            LanguageDetectorService::class => new LanguageDetectorService(),
            SubtitleParserService::class => new SubtitleParserService(),
            SubtitleValidatorService::class => new SubtitleValidatorService(),
            SubtitleFilenameService::class => new SubtitleFilenameService(),
            SubtitleAnalyzerService::class => new SubtitleAnalyzerService(
                self::get(FFprobe::class),
                self::get(LanguageDetectorService::class),
                self::get(SubtitleParserService::class),
            ),
            DeepTranslatorProvider::class => new DeepTranslatorProvider(self::get(ProcessRunner::class)),
            TranslationBatchService::class => new TranslationBatchService(self::get(DeepTranslatorProvider::class)),
            SubtitleTranslatorService::class => new SubtitleTranslatorService(
                self::get(SubtitleParserService::class),
                self::get(SubtitleValidatorService::class),
                self::get(TranslationBatchService::class),
            ),
            SubtitleExtractorService::class => new SubtitleExtractorService(
                self::get(FFmpeg::class),
                self::get(SubtitleFilenameService::class),
                self::get(SubtitleValidatorService::class),
                self::get(SubtitleParserService::class),
                self::get(SubtitleTranslatorService::class),
            ),
            default => throw new \InvalidArgumentException("Servicio no registrado: {$class}"),
        };
    }
}
