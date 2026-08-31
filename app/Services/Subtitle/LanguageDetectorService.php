<?php

declare(strict_types=1);

namespace App\Services\Subtitle;

/**
 * Detección de idioma en tres niveles:
 *  1. Metadata (código ISO del contenedor).
 *  2. Título de la pista ("English", "Español", "Latino"...).
 *  3. Análisis del contenido (muestra de texto).
 */
final class LanguageDetectorService
{
    /** Códigos ISO 639-2 → 639-1 y viceversa para traducción. */
    private const ISO_MAP = [
        'eng' => 'en', 'spa' => 'es', 'jpn' => 'ja', 'fra' => 'fr',
        'deu' => 'de', 'ita' => 'it', 'por' => 'pt', 'rus' => 'ru',
        'chi' => 'zh', 'kor' => 'ko', 'ara' => 'ar', 'tur' => 'tr',
        'nld' => 'nl', 'pol' => 'pl', 'swe' => 'sv', 'dan' => 'da',
        'fin' => 'fi', 'nor' => 'no', 'ces' => 'cs', 'ell' => 'el',
        'hun' => 'hu', 'hin' => 'hi', 'heb' => 'he', 'tha' => 'th',
        'vie' => 'vi', 'ukr' => 'uk', 'cat' => 'ca', 'glg' => 'gl',
        'eus' => 'eu',
    ];

    /** Patrones por idioma (nivel 2 — título). Orden importa. */
    private const TITLE_PATTERNS = [
        'spa' => ['español', 'espanol', 'spanish', 'castellano', 'latino', 'es', 'span'],
        'eng' => ['english', 'inglés', 'ingles', 'en', 'eng', 'sdh'],
        'jpn' => ['japanese', 'japonés', 'japones', 'jpn', '日本語'],
        'fra' => ['french', 'francés', 'frances', 'fra'],
        'deu' => ['german', 'alemán', 'aleman', 'deu', 'deutsch'],
        'ita' => ['italian', 'italiano', 'ita'],
        'por' => ['portuguese', 'portugués', 'portugues', 'por'],
        'rus' => ['russian', 'ruso', 'rus'],
        'chi' => ['chinese', 'chino', 'chi', '中文'],
        'kor' => ['korean', 'coreano', 'kor'],
        'ara' => ['arabic', 'árabe', 'arabe', 'ara'],
    ];

    /**
     * Nivel 1 + 2: detecta por metadata y título.
     * Devuelve el código ISO 639-2 o null si no hay certeza.
     */
    public function detectFromMetadata(?string $language, ?string $title): ?string
    {
        $lang = strtolower((string) $language);

        if ($lang !== '') {
            // Códigos ISO 639-2/639-1 directos
            $iso2 = self::ISO_MAP[$lang] ?? null;
            if ($iso2 !== null) {
                return $lang;
            }

            // Código 639-1
            $iso1 = array_search($lang, self::ISO_MAP, true);
            if ($iso1 !== false) {
                return $iso1;
            }

            // Nombre completo en inglés ("English", "Spanish")
            $byName = $this->detectFromTitle($lang);
            if ($byName !== null) {
                return $byName;
            }
        }

        if ($title !== null && $title !== '') {
            $byTitle = $this->detectFromTitle($title);
            if ($byTitle !== null) {
                return $byTitle;
            }
        }

        return null;
    }

    /**
     * Nivel 2: detecta el idioma a partir del título de la pista.
     */
    public function detectFromTitle(string $title): ?string
    {
        $normalized = strtolower(trim($title));

        // Palabras individuales (English, Español, SDH...)
        $words = preg_split('/[\s\-_.()\[\]]+/', $normalized) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));

        foreach ($words as $word) {
            foreach (self::TITLE_PATTERNS as $lang => $patterns) {
                if (in_array($word, $patterns, true)) {
                    // "es" y "en" solos pueden ser falsos positivos;
                    // solo se aceptan si son la única palabra significativa.
                    if (($word === 'es' || $word === 'en') && count($words) > 1) {
                        continue;
                    }

                    return $lang;
                }
            }
        }

        // Frases compuestas
        foreach (self::TITLE_PATTERNS as $lang => $patterns) {
            foreach ($patterns as $pattern) {
                if (strlen($pattern) > 3 && str_contains($normalized, $pattern)) {
                    return $lang;
                }
            }
        }

        return null;
    }

    /**
     * Nivel 3: detecta por contenido (muestra de texto).
     * Heurística basada en palabras funcionales de alta frecuencia.
     */
    public function detectFromContent(string $sample): ?string
    {
        $sample = mb_strtolower(strip_tags($sample));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $sample) ?: [];
        $words = array_filter($words, fn ($w) => mb_strlen($w) > 2);
        $words = array_values($words);

        if (count($words) < 10) {
            return null;
        }

        // Frases de alta frecuencia por idioma
        $markers = [
            'spa' => ['que', 'para', 'los', 'las', 'una', 'está', 'esta', 'como', 'bien', 'muy', 'pero', 'cuando', 'dijo', 'hace', 'todo', 'eres', 'vamos', 'sobre', 'porque', 'usted', 'ustedes', 'ellos', 'ellas', 'nada', 'algo', 'otro', 'otros', 'otra', 'mismo', 'misma', 'puede', 'pueden', 'donde', 'siempre', 'nunca', 'también', 'todavía', 'estaba', 'estaban', 'tenía', 'tenían', 'después', 'antes', 'entre', 'desde', 'hasta', 'saber', 'decir', 'hacer', 'ver'],
            'eng' => ['the', 'and', 'that', 'with', 'this', 'have', 'you', 'are', 'what', 'but', 'from', 'they', 'will', 'your', 'because', 'about', 'just', 'were', 'been', 'there', 'where', 'when', 'would', 'could', 'should', 'them', 'their', 'these', 'those', 'which', 'after', 'before', 'between'],
            'fra' => ['les', 'une', 'des', 'pour', 'dans', 'pas', 'avec', 'mais', 'tout', 'cette', 'nous', 'vous', 'être', 'faire', 'comme'],
            'deu' => ['der', 'die', 'das', 'und', 'nicht', 'ich', 'ist', 'wir', 'sie', 'sind', 'was', 'ein', 'eine', 'mit', 'wie'],
            'ita' => ['che', 'per', 'una', 'non', 'sono', 'come', 'cosa', 'ma', 'questo', 'della', 'delle', 'con', 'siamo'],
            'por' => ['que', 'uma', 'para', 'não', 'nao', 'como', 'mais', 'mas', 'você', 'voce', 'está', 'esta', 'com', 'eles'],
        ];

        $best = null;
        $bestScore = 0;

        foreach ($markers as $lang => $terms) {
            $score = 0;
            foreach ($terms as $term) {
                $score += count(array_filter($words, fn ($w) => $w === $term));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $lang;
            }
        }

        return $bestScore >= 2 ? $best : null;
    }

    public function toIso1(string $iso2): string
    {
        return self::ISO_MAP[$iso2] ?? $iso2;
    }
}
