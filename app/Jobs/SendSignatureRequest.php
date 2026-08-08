<?php

namespace App\Jobs;

use App\Models\SignatureRequest;
use App\Services\Signatures\Exceptions\ProviderNotConfiguredException;
use App\Services\Signatures\Exceptions\SignatureProviderException;
use App\Services\Signatures\SignatureProviderRegistry;
use App\Services\Signatures\SignatureRequestService;
use App\Services\Signatures\Support\SignatureStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hands a request to its vendor.
 *
 * Queued rather than inline for two reasons: it is several vendor calls
 * (Clicksign needs six-plus), and those calls run against a per-account rate
 * limit that a burst of sends will hit. Neither belongs in a web request.
 *
 * Safe to retry. The driver persists each provider id as the vendor issues
 * it, so a retry resumes the flow rather than restarting it — which matters
 * because restarting would create a second envelope and bill the customer
 * for it. ShouldBeUnique adds a second guard: the same request cannot be in
 * flight twice at once.
 */
class SendSignatureRequest implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180;

    public function __construct(public readonly int $signatureRequestId)
    {
    }

    public function uniqueId(): string
    {
        return 'signature-request:' . $this->signatureRequestId;
    }

    /** Long enough to cover the whole send, short enough to allow a retry. */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function tries(): int
    {
        return (int) config('signatures.retry.attempts', 5);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return config('signatures.retry.backoff', [10, 30, 60, 180, 600]);
    }

    public function handle(
        SignatureProviderRegistry $registry,
        SignatureRequestService $service,
    ): void {
        $request = SignatureRequest::with(['documents', 'signers', 'providerAccount', 'document'])
            ->find($this->signatureRequestId);

        if (!$request) {
            return;
        }

        // A cancel or a completion that landed while this sat in the queue
        // wins — do not resurrect it.
        if (!in_array($request->status, [SignatureStatus::QUEUED, SignatureStatus::FAILED], true)) {
            Log::info('SendSignatureRequest skipped: request is no longer queued', [
                'signature_request_id' => $request->id,
                'status'               => $request->status,
            ]);

            return;
        }

        $request->increment('attempt_count');

        try {
            $registry->get($request->provider)->createRequest($request);

            $service->markSent($request);
        } catch (SignatureProviderException $e) {
            if ($e->retryable && $this->attempts() < $this->tries()) {
                Log::warning('SendSignatureRequest will retry', [
                    'signature_request_id' => $request->id,
                    'attempt'              => $this->attempts(),
                    'error'                => $e->getMessage(),
                ]);

                // Rate limits carry their own reset window; honour it rather
                // than the generic backoff.
                $this->release($e->retryAfterSeconds ?? $this->backoffForAttempt());

                return;
            }

            $this->fail($request, $e->getMessage());
        } catch (ProviderNotConfiguredException $e) {
            $this->fail($request, $e->getMessage());
        }
    }

    /** Terminal failure — the queue gave up, or the vendor said no. */
    public function failed(\Throwable $exception): void
    {
        $request = SignatureRequest::find($this->signatureRequestId);

        if ($request && $request->status === SignatureStatus::QUEUED) {
            $request->markFailed($exception->getMessage());
        }
    }

    private function fail(SignatureRequest $request, string $message): void
    {
        Log::error('SendSignatureRequest failed permanently', [
            'signature_request_id' => $request->id,
            'provider'             => $request->provider,
            'error'                => $message,
        ]);

        $request->markFailed($message);

        // Put the document back where a user can act on it. Leaving it at
        // "waiting signature" would tell the board a contract is out for
        // signature when nothing was ever sent.
        app(SignatureRequestService::class)->markSendFailed($request);
    }

    private function backoffForAttempt(): int
    {
        $backoff = $this->backoff();

        return $backoff[min($this->attempts() - 1, count($backoff) - 1)] ?? 60;
    }
}
