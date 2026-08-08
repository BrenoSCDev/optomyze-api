<?php

namespace App\Services\Ai;

use App\Models\LlmProviderAccount;

/**
 * Reads config/llm.php. Everything that needs to know "which LLM vendors
 * exist and what can they do" goes through here rather than hardcoding keys.
 */
class LlmProviderRegistry
{
    /** @return string[] */
    public static function keys(): array
    {
        return array_keys((array) config('llm.providers', []));
    }

    public static function supports(string $provider): bool
    {
        return (bool) config("llm.providers.{$provider}.driver");
    }

    public static function defaultKey(): string
    {
        return (string) config('llm.default', 'openai');
    }

    /** Config for one provider, or a dot-path within it. */
    public static function config(string $provider, ?string $path = null, mixed $default = null): mixed
    {
        $base = "llm.providers.{$provider}";

        return config($path ? "{$base}.{$path}" : $base, $default);
    }

    /** The chat models we offer for a provider. */
    public static function models(string $provider): array
    {
        return (array) self::config($provider, 'models', []);
    }

    /**
     * Every registered provider with the metadata the frontend renders:
     * label, credential form, model list, docs link — plus this company's
     * account when one exists.
     *
     * @return array<int, array>
     */
    public static function catalog(?int $companyId = null): array
    {
        $accounts = $companyId
            ? LlmProviderAccount::fromCompany($companyId)->get()->keyBy('provider')
            : collect();

        return array_map(function (string $key) use ($accounts) {
            $account = $accounts->get($key);

            return [
                'key'               => $key,
                'label'             => self::config($key, 'label', $key),
                'docs_url'          => self::config($key, 'docs_url'),
                'credential_fields' => self::config($key, 'credential_fields', []),
                'models'            => self::models($key),
                'default_model'     => self::config($key, 'default_model'),
                'account'           => $account ? self::formatAccount($account) : null,
            ];
        }, self::keys());
    }

    public static function formatAccount(LlmProviderAccount $account): array
    {
        return [
            'id'                => $account->id,
            'provider'          => $account->provider,
            'name'              => $account->name,
            'has_api_key'       => !empty($account->api_key),
            'api_key_hint'      => $account->apiKeyHint(),
            'organization_id'   => $account->organization_id,
            'connection_status' => $account->connection_status,
            'last_tested_at'    => $account->last_tested_at,
            'is_active'         => $account->is_active,
        ];
    }
}
