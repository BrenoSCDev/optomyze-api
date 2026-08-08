<?php

use App\Services\Documents\Renderers\DompdfRenderer;

/**
 * DOCUMENT AUTOMATION PLATFORM
 *
 * Contracts are the first document type, not a feature of their own. The same
 * engine generates proposals, quotes, NDAs and onboarding forms — a document
 * is a row with a type, not a table.
 *
 * The PDF renderer is a driver for the same reason signature providers are:
 * dompdf is pure PHP and needs no system dependencies, which is what the API
 * image can run today. Browsershot renders through real Chrome and produces far
 * better output, and it drops in here without the engine changing — once Node
 * and Chromium exist in that image.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | PDF rendering
    |--------------------------------------------------------------------------
    */
    'pdf' => [
        'driver' => env('DOCUMENT_PDF_DRIVER', 'dompdf'),

        'drivers' => [
            'dompdf' => DompdfRenderer::class,
            // 'browsershot' => BrowsershotRenderer::class,
        ],

        'paper'       => env('DOCUMENT_PDF_PAPER', 'a4'),
        'orientation' => env('DOCUMENT_PDF_ORIENTATION', 'portrait'),

        /*
         * Remote assets are off deliberately. Template bodies are authored by
         * users, and letting the renderer fetch arbitrary URLs at render time
         * turns a contract template into a server-side request forgery vector.
         * Logos belong in the template as data: URIs.
         */
        'allow_remote_assets' => (bool) env('DOCUMENT_PDF_ALLOW_REMOTE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Numbering
    |--------------------------------------------------------------------------
    |
    | Human-facing document numbers, allocated per company from a sequence
    | table rather than MAX()+1 — two users creating a contract at the same
    | moment must not be handed the same number.
    |
    */
    'numbering' => [
        'prefixes' => [
            'contract' => 'CTR',
            'proposal' => 'PRP',
            'quote'    => 'ORC',
            'nda'      => 'NDA',
            'other'    => 'DOC',
        ],
        'default_prefix' => 'DOC',
        'pad'            => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Template rendering limits
    |--------------------------------------------------------------------------
    |
    | The template language is a deliberately small subset (interpolation,
    | comparison conditionals, one level of loop). These caps stop a
    | pathological template from becoming a denial of service against the
    | queue worker that renders it.
    |
    */
    'rendering' => [
        'max_body_bytes'     => (int) env('DOCUMENT_MAX_BODY_BYTES', 1048576), // 1 MB
        'max_loop_iterations' => (int) env('DOCUMENT_MAX_LOOP_ITERATIONS', 500),
        'max_nested_blocks'  => 8,
    ],

];
