<?php

namespace App\Jobs;

use App\Models\SignatureDocument;
use App\Services\Signatures\Exceptions\SignatureProviderException;
use App\Services\Signatures\SignatureProviderRegistry;
use App\Services\Signatures\SignatureRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pulls the finished document back and stores our own copy.
 *
 * Separate from the webhook on purpose. The vendor needs a fast 2xx, and a
 * download that fails must not cost us the completion event — the signature
 * is legally done either way; only our archived copy is outstanding, and the
 * document row says so.
 */
class DownloadSignedSignatureDocument implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function __construct(public readonly int $signatureDocumentId)
    {
    }

    public function uniqueId(): string
    {
        return 'signature-document-download:' . $this->signatureDocumentId;
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
        $document = SignatureDocument::with('signatureRequest.providerAccount')
            ->find($this->signatureDocumentId);

        if (!$document || $document->hasSignedCopy()) {
            return;
        }

        if (empty($document->signed_file_url)) {
            return;
        }

        $request = $document->signatureRequest;

        if (!$request) {
            return;
        }

        try {
            $file = $registry->get($request->provider)
                ->downloadSignedDocument($document, $document->signed_file_url);

            $service->storeSignedDocument($document, $file);
        } catch (SignatureProviderException $e) {
            if ($e->retryable && $this->attempts() < $this->tries()) {
                $this->release($e->retryAfterSeconds ?? 60);

                return;
            }

            // Permanent: record why, and leave the request completed. The UI
            // shows "signed, copy unavailable" with this reason rather than
            // pretending nothing happened.
            $document->forceFill([
                'download_status' => SignatureDocument::DOWNLOAD_UNAVAILABLE,
                'download_error'  => Str::limit($e->getMessage(), 1000),
            ])->save();

            Log::error('Could not download the signed document', [
                'signature_document_id' => $document->id,
                'signature_request_id'  => $request->id,
                'provider'              => $request->provider,
                'error'                 => $e->getMessage(),
            ]);
        }
    }
}
