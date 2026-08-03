<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\OpenAiIntegration;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Dto\OpenAiCredentials;
use App\Services\Ai\Providers\OpenAiProvider;

/**
 * Resolves the provider implementation for an agent. Adding a provider =
 * new integration model + provider class + a case here.
 */
class AiProviderFactory
{
    public static function forAgent(AiAgent $agent): ?AiProviderInterface
    {
        return match ($agent->provider) {
            AiAgent::PROVIDER_OPENAI => self::openAi($agent),
            default => null,
        };
    }

    private static function openAi(AiAgent $agent): ?AiProviderInterface
    {
        $integration = OpenAiIntegration::fromCompany($agent->company_id)
            ->active()
            ->first();

        if (!$integration || !$integration->isUsable()) {
            return null;
        }

        return new OpenAiProvider(OpenAiCredentials::fromIntegration($integration));
    }

    /**
     * The platform-billed provider, for Optomyze's own features (Optus).
     * Null when OPENAI_API_KEY is unset.
     */
    public static function platformOpenAi(): ?AiProviderInterface
    {
        $credentials = OpenAiCredentials::platform();

        return $credentials ? new OpenAiProvider($credentials) : null;
    }
}
