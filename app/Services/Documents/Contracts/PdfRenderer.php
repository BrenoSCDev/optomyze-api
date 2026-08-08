<?php

namespace App\Services\Documents\Contracts;

/**
 * Turns rendered HTML into PDF bytes.
 *
 * A driver for the same reason signature providers are: the API image can run
 * pure-PHP dompdf today, and a Chrome-backed renderer produces far better
 * output but needs Node and Chromium in that image. Keeping this seam means
 * upgrading the renderer never touches the document engine.
 */
interface PdfRenderer
{
    /**
     * @param array{header_html?: ?string, footer_html?: ?string, paper?: string, orientation?: string, title?: string} $options
     * @return string Raw PDF bytes.
     *
     * @throws \App\Services\Documents\Exceptions\DocumentRenderException
     */
    public function render(string $html, array $options = []): string;

    /** Driver key, for logging and diagnostics. */
    public function key(): string;
}
