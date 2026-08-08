<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\LlmProviderAccount;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\OpenAiCredentials;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;

/**
 * Resolves the driver for an agent from its company's stored credentials.
 * Adding a provider = a config/llm.php entry + a provider class + a case here.
 */
class AiProviderFactory
{
    public static function forAgent(AiAgent $agent): ?AiProviderInterface
    {
        $account = self::account($agent->company_id, $agent->provider);

        if (!$account) {
            return null;
        }

        return self::build($agent->provider, $account);
    }

    /**
     * Whether the company can actually run an agent on this provider — the
     * account exists, is active, and has a verified key.
     */
    public static function isUsable(int $companyId, string $provider): bool
    {
        return self::account($companyId, $provider) !== null;
    }

    private static function account(int $companyId, ?string $provider): ?LlmProviderAccount
    {
        if (!$provider || !LlmProviderRegistry::supports($provider)) {
            return null;
        }

        $account = LlmProviderAccount::fromCompany($companyId)
            ->forProvider($provider)
            ->active()
            ->first();

        return $account && $account->isUsable() ? $account : null;
    }

    private static function build(string $provider, LlmProviderAccount $account): ?AiProviderInterface
    {
        return match ($provider) {
            // OpenAI is the one driver taking a credentials object rather than
            // a bare key: it also carries the optional organization scope.
            'openai' => new OpenAiProvider(OpenAiCredentials::fromAccount($account)),
            'gemini' => new GeminiProvider($account->api_key),
            'claude' => new AnthropicProvider($account->api_key),
            default => null,
        };
    }

    /**
     * The platform-billed provider, for Optomyze's own features (Optus, and
     * transcription fallback for vendors with no audio endpoint).
     * Null when OPENAI_API_KEY is unset.
     */
    public static function platformOpenAi(): ?AiProviderInterface
    {
        $credentials = OpenAiCredentials::platform();

        return $credentials ? new OpenAiProvider($credentials) : null;
    }
}
