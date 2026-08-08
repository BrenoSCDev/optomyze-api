<?php

use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;

/**
 * LLM PROVIDERS
 *
 * AI Agents talk to one generic provider interface; each vendor is a driver
 * registered here. Adding a vendor means writing one class that implements
 * App\Services\Ai\Contracts\AiProviderInterface and adding an entry below —
 * the integrations controller, the App Store card, the config modal and the
 * agent form all read this registry, so none of them change.
 *
 * Credentials are NOT stored here. They are per-company (each tenant is billed
 * on their own vendor account) and live encrypted on the `llm_provider_accounts`
 * table. This file describes the *driver*: how to reach the vendor, which
 * models we offer, and which request knobs that vendor accepts.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | Which provider a newly created AI Agent starts on.
    |
    */
    'default' => env('LLM_DEFAULT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | `credential_fields` drives the config modal's form: the frontend renders
    | whatever is listed here, so a vendor needing an extra field gets one
    | without a frontend change.
    |
    | `models` is curated on purpose. Every vendor's list endpoint returns
    | dozens of embedding/fine-tune/legacy ids that make no sense in an agent
    | dropdown, so we publish the chat-capable subset we support and use the
    | live listing only to confirm the key can actually reach them.
    |
    */
    'providers' => [

        'openai' => [
            'driver'   => OpenAiProvider::class,
            'label'    => 'OpenAI',
            'api_base' => env('OPENAI_API_BASE', 'https://api.openai.com/v1'),
            'timeout'  => (int) env('OPENAI_TIMEOUT', 30),
            'docs_url' => 'https://platform.openai.com/api-keys',

            'credential_fields' => [
                [
                    'key'         => 'api_key',
                    'label'       => 'API Key',
                    'type'        => 'password',
                    'required'    => true,
                    'placeholder' => 'sk-...',
                ],
                [
                    'key'         => 'organization_id',
                    'label'       => 'Organization ID',
                    'type'        => 'text',
                    'required'    => false,
                    'placeholder' => 'org-... (optional)',
                ],
            ],

            'models' => [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4.1',
                'gpt-4.1-mini',
                'gpt-4.1-nano',
            ],

            'default_model' => 'gpt-4o-mini',

            // OpenAI transcribes audio itself, so voice notes need no fallback.
            'transcribes' => true,
        ],

        'gemini' => [
            'driver'   => GeminiProvider::class,
            'label'    => 'Google Gemini',
            'api_base' => env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'),
            'timeout'  => (int) env('GEMINI_TIMEOUT', 30),
            'docs_url' => 'https://aistudio.google.com/app/apikey',

            'credential_fields' => [
                [
                    'key'         => 'api_key',
                    'label'       => 'API Key',
                    'type'        => 'password',
                    'required'    => true,
                    'placeholder' => 'AIza...',
                ],
            ],

            'models' => [
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
                'gemini-2.0-flash',
            ],

            'default_model' => 'gemini-2.5-flash',

            // Gemini takes audio inline in generateContent, so it transcribes
            // voice notes natively.
            'transcribes' => true,
        ],

        'claude' => [
            'driver'   => AnthropicProvider::class,
            'label'    => 'Anthropic Claude',
            'api_base' => env('ANTHROPIC_API_BASE', 'https://api.anthropic.com/v1'),
            'timeout'  => (int) env('ANTHROPIC_TIMEOUT', 30),
            'docs_url' => 'https://console.anthropic.com/settings/keys',

            // Pinned: the Messages API is versioned by header, not by URL, and
            // an unpinned client would silently follow breaking changes.
            'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),

            'credential_fields' => [
                [
                    'key'         => 'api_key',
                    'label'       => 'API Key',
                    'type'        => 'password',
                    'required'    => true,
                    'placeholder' => 'sk-ant-...',
                ],
            ],

            'models' => [
                'claude-opus-5',
                'claude-sonnet-5',
                'claude-opus-4-8',
                'claude-sonnet-4-6',
                'claude-haiku-4-5',
            ],

            'default_model' => 'claude-sonnet-4-6',

            /*
             * Anthropic has no audio endpoint. Voice notes reaching a Claude
             * agent fall back to the platform OpenAI key for transcription —
             * see AnthropicProvider::transcribe().
             */
            'transcribes' => false,
        ],

    ],
];
