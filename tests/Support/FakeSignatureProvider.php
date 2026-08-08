<?php

namespace Tests\Support;

use App\Models\SignatureDocument;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\Signatures\Contracts\SignatureProvider;
use App\Services\Signatures\Data\ConnectionResult;
use App\Services\Signatures\Data\DownloadedFile;
use App\Services\Signatures\Data\ProviderCapabilities;
use App\Services\Signatures\Data\RemoteRequestState;
use App\Services\Signatures\Data\WebhookRegistration;
use App\Services\Signatures\Support\SignatureStatus;
use Illuminate\Http\Request as HttpRequest;

/**
 * A second, entirely fictional vendor.
 *
 * Its only job is to prove the claim the module is built on: that adding a
 * provider means writing one class and adding a config entry, with no change
 * to controllers, services, schema or frontend. If this file ever needs the
 * core to bend for it, the abstraction has leaked.
 */
class FakeSignatureProvider implements SignatureProvider
{
    public function key(): string
    {
        return 'acme';
    }

    public function label(): string
    {
        return (string) config('signatures.providers.acme.label', 'Acme Sign');
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            credentialFields: [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
                ['key' => 'account_id', 'label' => 'Account ID', 'type' => 'text', 'required' => false],
            ],
            environments: ['production'],
            authMethods: [['key' => 'email', 'label' => 'Email token']],
            acceptedMimeTypes: ['application/pdf'],
            locales: ['en-US'],
            reminderIntervals: [null, 7],
            registersWebhooks: false,
            supportsCancel: true,
            supportsResend: true,
        );
    }

    public function verifyCredentials(SignatureProviderAccount $account): ConnectionResult
    {
        return ConnectionResult::ok('Connected.');
    }

    public function registerWebhook(SignatureProviderAccount $account, string $callbackUrl): WebhookRegistration
    {
        throw \App\Services\Signatures\Exceptions\UnsupportedCapabilityException::make(
            $this->key(),
            'webhook registration',
            'Register the callback URL manually in the vendor dashboard.'
        );
    }

    public function removeWebhook(SignatureProviderAccount $account): void
    {
    }

    public function validateDraft(SignatureRequest $request): array
    {
        return [];
    }

    public function createRequest(SignatureRequest $request): void
    {
        $request->forceFill(['provider_request_id' => 'acme-' . $request->id])->save();
    }

    public function cancelRequest(SignatureRequest $request): void
    {
    }

    public function notify(SignatureRequest $request, ?SignatureSigner $signer = null): void
    {
    }

    public function fetchStatus(SignatureRequest $request): RemoteRequestState
    {
        return new RemoteRequestState(status: SignatureStatus::SENT);
    }

    public function downloadSignedDocument(SignatureDocument $document, string $url): DownloadedFile
    {
        return new DownloadedFile('%PDF-fake', 'signed.pdf');
    }

    public function verifyWebhook(SignatureProviderAccount $account, HttpRequest $httpRequest): bool
    {
        return true;
    }

    public function parseWebhook(SignatureProviderAccount $account, HttpRequest $httpRequest): array
    {
        return [];
    }
}
