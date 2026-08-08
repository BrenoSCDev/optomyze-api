<?php

namespace App\Http\Controllers;

use App\Services\Signatures\SignatureWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The public endpoint vendors deliver signature events to.
 *
 * No CRM session and no JWT — the callback UUID in the path identifies the
 * tenant and the vendor's signature over the raw body authenticates the
 * delivery. Both are needed: the UUID alone is a bearer secret, and a
 * signature alone would not say whose account it belongs to.
 *
 * Responds 200 to anything it managed to look at, including duplicates and
 * events it does not model. Vendors treat a non-2xx as a delivery failure and
 * redeliver the whole payload, which for an already-applied batch is worse
 * than useless. The two exceptions are a failed signature check and an
 * unknown callback, which are genuinely not our events to accept.
 */
class SignatureWebhookController extends Controller
{
    public function __construct(private readonly SignatureWebhookProcessor $processor)
    {
    }

    public function handle(Request $request, string $provider, string $callbackId)
    {
        try {
            $result = $this->processor->handle($provider, $callbackId, $request);
        } catch (\Throwable $e) {
            // Never let an unexpected failure become a 500: the vendor would
            // redeliver events we may already have applied. The audit row and
            // this log are how it gets noticed instead.
            Log::error('Signature webhook handler crashed', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 200);
        }

        $status = match ($result['status']) {
            'rejected' => 401,
            'ignored'  => 202,
            default    => 200,
        };

        return response()->json($result, $status);
    }
}
