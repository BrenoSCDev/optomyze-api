<?php

namespace App\Services\Optus;

use RuntimeException;

/**
 * Optus cannot run at all — no platform API key configured. Distinct from
 * AiProviderException, which means the key works but the call failed: the
 * first is a deployment problem, the second is transient.
 */
class OptusUnavailableException extends RuntimeException
{
}
