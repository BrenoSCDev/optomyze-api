<?php

namespace App\Services\Ai\Dto;

use App\Models\OpenAiIntegration;

/**
 * Which OpenAI account a request is billed to.
 *
 * The provider used to take an OpenAiIntegration directly, which tied it to
 * per-company keys. Optus is an Optomyze product feature rather than a tenant
 * integration, so it bills to the platform key in OPENAI_API_KEY — the same
 * provider, a different account.
 */
class OpenAiCredentials
{
    public function __construct(
        public readonly string $apiKey,
        public readonly ?string $organizationId = null,
    ) {
    }

    public static function fromIntegration(OpenAiIntegration $integration): self
    {
        return new self($integration->api_key, $integration->organization_id);
    }

    /**
     * The platform's own key. Null when unconfigured, so callers can degrade
     * to a clear "not configured" message instead of a 500 from OpenAI.
     */
    public static function platform(): ?self
    {
        $key = trim((string) config('services.openai.key'));

        return $key === '' ? null : new self($key, config('services.openai.organization'));
    }
}
