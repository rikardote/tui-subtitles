<?php

declare(strict_types=1);

namespace App\Services\Subtitle;

/**
 * Valida un archivo SRT antes de guardarlo como resultado final.
 */
final class SubtitleValidatorService
{
    /** @return array{valid:bool, errors:string[], blocks:int} */
    public function validate(string $content): array
    {
        $errors = [];
        $parser = new SubtitleParserService();
        $blocks = $parser->parse($content);

        if ($blocks === []) {
            return ['valid' => false, 'errors' => ['El archivo no contiene bloques válidos.'], 'blocks' => 0];
        }

        // 1. Numeración secuencial
        $expected = 1;
        foreach ($blocks as $block) {
            if ($block['index'] !== $expected) {
                $errors[] = sprintf('Numeración inválida: se esperaba %d, se encontró %d.', $expected, $block['index']);
                break;
            }
            $expected++;
        }

        // 2. Timestamps válidos
        $prevEnd = null;
        foreach ($blocks as $block) {
            $start = $this->parseTimestamp($block['start']);
            $end = $this->parseTimestamp($block['end']);

            if ($start === null || $end === null) {
                $errors[] = sprintf('Timestamp inválido en el bloque %d.', $block['index']);
                continue;
            }

            if ($end <= $start) {
                $errors[] = sprintf('Timestamp final menor o igual al inicial en el bloque %d.', $block['index']);
            }

            if ($prevEnd !== null && $start < $prevEnd) {
                // Solapamiento leve permitido (común en SRT); solo avisamos si es grave
                if ($start < $prevEnd - 1.0) {
                    $errors[] = sprintf('Bloque %d solapa con el anterior.', $block['index']);
                }
            }

            $prevEnd = $end;
        }

        // 3. Texto no vacío
        foreach ($blocks as $block) {
            if (trim($block['text']) === '') {
                $errors[] = sprintf('El bloque %d tiene texto vacío.', $block['index']);
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'blocks' => count($blocks),
        ];
    }

    private function parseTimestamp(string $ts): ?float
    {
        // 00:00:01,000 o 00:00:01.000
        if (! preg_match('/^(\d{2,}):(\d{2}):(\d{2})[,.](\d{1,3})$/', trim($ts), $m)) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + ((int) str_pad($m[4], 3, '0')) / 1000;
    }
}
