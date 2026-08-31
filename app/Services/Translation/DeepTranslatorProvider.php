<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Infrastructure\ProcessRunner;
use RuntimeException;

/**
 * Proveedor de traducción vía CLI deep-translator (gratuito, sin API key).
 * Usa el motor Google Translate de forma no oficial.
 */
final class DeepTranslatorProvider implements TranslationProviderInterface
{
    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    public function name(): string
    {
        return 'deep-translator (Google)';
    }

    public function available(): bool
    {
        $bin = $this->binary();

        return $bin !== null && is_executable($bin);
    }

    public function translate(string $text, string $targetLanguage): string
    {
        $bin = $this->binary();

        if ($bin === null) {
            throw new RuntimeException('El binario deep-translator no está disponible.');
        }

        $text = trim($text);

        if ($text === '') {
            return '';
        }

        [$code, $stdout, $stderr] = $this->runner->run([
            $bin,
            '--translator', 'google',
            '--source', 'auto',
            '--target', $targetLanguage,
            '--text', $text,
        ], (float) config('translation.timeout_seconds', 30));

        if ($code !== 0) {
            throw new RuntimeException('deep-translator falló: ' . trim($stderr ?: $stdout));
        }

        $translated = $this->extractResult($stdout);

        if ($translated === null || trim($translated) === '') {
            throw new RuntimeException('deep-translator no devolvió traducción.');
        }

        return $translated;
    }

    private function extractResult(string $stdout): ?string
    {
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'Translation result:')) {
                return trim(substr($line, strlen('Translation result:')));
            }
        }

        return null;
    }

    private function binary(): ?string
    {
        $candidates = [
            getenv('DEEP_TRANSLATOR_BIN') ?: null,
            $_SERVER['HOME'] . '/.local/bin/deep-translator',
            $_SERVER['HOME'] . '/.local/bin/dt',
            'deep-translator',
            'dt',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }

            // Busca en PATH
            $which = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($which !== '') {
                return $which;
            }
        }

        return null;
    }
}
