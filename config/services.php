<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        // Optus, the in-app assistant. Billed to the platform key above, not
        // to a tenant's own OpenAI integration.
        'optus_model' => env('OPTUS_MODEL', 'gpt-4o-mini'),
    ],

    /*
     * Our own Evolution API server, shared by every tenant.
     *
     * Customers used to bring their own Evolution deployment and paste its URL
     * and key into the CRM, which meant nobody could connect WhatsApp without
     * first standing up infrastructure. We host it now, so these values are the
     * defaults every new WhatsApp integration is created with.
     *
     * They are defaults, not a hard-coded destination: WhatsAppEvoIntegration
     * still stores a base_url and api_key per company, so a tenant can be moved
     * onto a dedicated or self-hosted server later by writing those columns,
     * with no change to the code that talks to Evolution.
     */
    'evolution' => [
        'url' => env('EVOLUTION_API_URL'),
        'key' => env('EVOLUTION_API_KEY'),
    ],

];
