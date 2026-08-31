<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Contrato de proveedor de traducción.
 * La lógica de negocio nunca depende de un proveedor concreto.
 */
interface TranslationProviderInterface
{
    /**
     * Traduce un texto al idioma destino.
     *
     * @return string Texto traducido.
     *
     * @throws \RuntimeException si el proveedor falla.
     */
    public function translate(string $text, string $targetLanguage): string;

    /**
     * Nombre legible del proveedor (para la TUI).
     */
    public function name(): string;

    /**
     * Indica si el proveedor está disponible/operativo.
     */
    public function available(): bool;
}
