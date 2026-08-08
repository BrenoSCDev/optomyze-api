<?php

use App\Services\Signatures\Providers\Clicksign\ClicksignProvider;

/**
 * DIGITAL SIGNATURE PLATFORM
 *
 * The CRM talks to one generic signature module; each vendor is a driver
 * registered here. Adding DocuSign, Adobe Acrobat Sign, Dropbox Sign or
 * SignNow means writing one class that implements
 * App\Services\Signatures\Contracts\SignatureProvider and adding an entry
 * below — no controller, service, schema or frontend change.
 *
 * Credentials are NOT stored here. They are per-company (each tenant signs
 * with, and is billed on, their own vendor account) and live encrypted on the
 * `signature_providers` table. This file describes the *driver*: how to reach
 * the vendor and what it is capable of.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | Which driver a company gets when it enables signatures without picking
    | one explicitly.
    |
    */
    'default' => env('SIGNATURE_DEFAULT_PROVIDER', 'clicksign'),

    /*
    |--------------------------------------------------------------------------
    | Public callback base URL
    |--------------------------------------------------------------------------
    |
    | The externally reachable origin vendors deliver webhooks to. It is kept
    | separate from APP_URL on purpose: APP_URL is still localhost in some
    | environments, and a webhook registered against localhost silently never
    | fires. Production is https://crm-api.optomyze.io.
    |
    */
    'callback_base_url' => env('SIGNATURE_CALLBACK_BASE_URL', env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Retry policy for outbound provider calls
    |--------------------------------------------------------------------------
    |
    | Applied by the queued jobs that talk to vendors. Vendors rate-limit per
    | account (Clicksign: 50 requests / 10s in production, 20/10s in sandbox),
    | and one signature request costs several calls, so sending is always
    | queued and backs off rather than retried tightly.
    |
    */
    'retry' => [
        'attempts' => (int) env('SIGNATURE_RETRY_ATTEMPTS', 5),
        'backoff'  => [10, 30, 60, 180, 600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log retention
    |--------------------------------------------------------------------------
    |
    | Every outbound API call and inbound webhook is recorded. Days to keep
    | them before `signatures:prune-audit-logs` removes them; 0 disables
    | pruning.
    |
    */
    'audit_retention_days' => (int) env('SIGNATURE_AUDIT_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'clicksign' => [
            'driver' => ClicksignProvider::class,
            'label'  => 'Clicksign',

            // Clicksign API 3.0 ("Envelope"). Accounts still on the legacy 1.9
            // API speak a completely different, non-JSON:API dialect and are
            // not supported by this driver.
            // https://developers.clicksign.com/docs/descubra-sua-versao-da-api
            'api_version' => '3.0',

            'environments' => [
                // https://developers.clicksign.com/docs/veja-como-funciona-na-prática
                'sandbox'    => env('CLICKSIGN_SANDBOX_URL', 'https://sandbox.clicksign.com/api/v3'),
                'production' => env('CLICKSIGN_PRODUCTION_URL', 'https://app.clicksign.com/api/v3'),
            ],

            'default_environment' => env('CLICKSIGN_ENVIRONMENT', 'sandbox'),

            'timeout' => (int) env('CLICKSIGN_TIMEOUT', 30),

            /*
             * Fallback host for a *relative* signed-file path.
             *
             * CONFIRMED AGAINST SANDBOX (2026-08-07): in practice the link
             * arrives absolute — a presigned S3 URL on
             * clicksign-<env>-content.s3.amazonaws.com with X-Amz-Expires=299.
             * The driver no longer relies on the delivered URL at all: it mints
             * a fresh one per attempt from
             * `GET /envelopes/{id}/documents/{id}` → data.links.files.signed,
             * because five minutes is shorter than our retry backoff.
             *
             * This setting therefore stays unset. It remains only as the
             * fallback for the relative form the docs' example shows
             * ("/2023/03/13/1q42v26p3o_blank_4_Clicksign.pdf"), which sandbox
             * has not produced. Left null, such a delivery is flagged
             * `download_unavailable` with the reason recorded rather than
             * having a hostname invented for it.
             */
            'download_base_url' => env('CLICKSIGN_DOWNLOAD_BASE_URL'),
        ],

    ],
];
