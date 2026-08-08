<?php

namespace App\Http\Controllers;

use App\Models\SignatureProviderAccount;
use App\Services\Signatures\Exceptions\UnsupportedCapabilityException;
use App\Services\Signatures\Exceptions\UnsupportedProviderException;
use App\Services\Signatures\SignatureProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Connect a company to a digital-signature vendor.
 *
 * Provider-neutral throughout: the vendor is a `{provider}` path segment
 * resolved through the registry, and the credential shape comes from the
 * request rather than from named columns. Adding a vendor adds no route here.
 */
class SignatureProviderController extends Controller
{
    public function __construct(private readonly SignatureProviderRegistry $registry)
    {
    }

    /**
     * Every registered vendor, its capabilities, and this company's account
     * for it — what the integrations screen renders from.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        $accounts = SignatureProviderAccount::fromCompany($user->company_id)
            ->get()
            ->keyBy('provider');

        $providers = array_map(function (array $entry) use ($accounts) {
            $entry['account'] = $accounts->get($entry['key'])?->toPublicArray();

            return $entry;
        }, $this->registry->catalog());

        return response()->json([
            'default_provider' => $this->registry->defaultKey(),
            'providers'        => $providers,
        ]);
    }

    /**
     * "Install" from the integrations marketplace.
     *
     * Creates the company's account row with no credentials yet, which is what
     * flips the card from Install to Configure. Every other integration in the
     * CRM works this way (`POST /api/integrations/{key}`), so signature
     * providers do too rather than being the one card that behaves differently.
     *
     * Idempotent: installing twice returns the existing row instead of failing
     * on the (company, provider) unique index.
     */
    public function install(Request $request, string $provider)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        if (!$this->registry->supports($provider)) {
            return response()->json(['message' => "Unsupported signature provider [{$provider}]."], 404);
        }

        $account = SignatureProviderAccount::fromCompany($user->company_id)
            ->forProvider($provider)
            ->first();

        if ($account) {
            // Re-installing a previously disconnected provider should make it
            // usable again rather than leaving a dead card.
            if (!$account->is_active) {
                $account->forceFill(['is_active' => true])->save();
            }

            return response()->json([
                'message' => 'Integration already installed.',
                'account' => $account->fresh()->toPublicArray(),
            ], 200);
        }

        $account = SignatureProviderAccount::create([
            'company_id'  => $user->company_id,
            'provider'    => $provider,
            'name'        => $this->registry->get($provider)->label(),
            'environment' => $this->registry->config($provider, 'default_environment', 'sandbox'),
            'is_active'   => true,
        ]);

        return response()->json([
            'message' => 'Integration installed successfully.',
            'account' => $account->toPublicArray(),
        ], 201);
    }

    public function show(Request $request, string $provider)
    {
        $account = $this->findAccount($provider);

        if ($account instanceof \Illuminate\Http\JsonResponse) {
            return $account;
        }

        return response()->json($account->toPublicArray());
    }

    /**
     * Save credentials, verify them, and register the callback in one go.
     *
     * One step because a half-connected integration is not useful: credentials
     * that were never tested and a vendor that was never told where to deliver
     * events both look "configured" and neither works.
     */
    public function configure(Request $request, string $provider)
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        if (!$this->registry->supports($provider)) {
            return response()->json(['message' => "Unsupported signature provider [{$provider}]."], 404);
        }

        $validated = $request->validate([
            // Free-form: each vendor names its own credentials, and the schema
            // must not care which. The driver reads the keys it needs.
            'credentials'   => ['required', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2048'],
            'environment'   => ['nullable', 'string', 'in:sandbox,production'],
            'name'          => ['nullable', 'string', 'max:255'],
        ]);

        $account = SignatureProviderAccount::firstOrNew([
            'company_id' => $user->company_id,
            'provider'   => $provider,
        ]);

        $account->fill([
            'name'        => $validated['name'] ?? $this->registry->get($provider)->label(),
            'environment' => $validated['environment']
                ?? $account->environment
                ?? $this->registry->config($provider, 'default_environment', 'sandbox'),
            'credentials'       => array_filter($validated['credentials'], fn ($value) => $value !== null && $value !== ''),
            'connection_status' => SignatureProviderAccount::CONNECTION_NOT_CONFIGURED,
            'is_active'         => true,
        ])->save();

        return $this->test($request, $provider);
    }

    /** Verify the stored credentials, then (re)register the webhook. */
    public function test(Request $request, string $provider)
    {
        $account = $this->findAccount($provider);

        if ($account instanceof \Illuminate\Http\JsonResponse) {
            return $account;
        }

        if (!$account->hasCredentials()) {
            return response()->json(['message' => 'Credenciais não configuradas.'], 422);
        }

        $driver = $this->registry->get($provider);
        $result = $driver->verifyCredentials($account);

        if (!$result->connected) {
            $account->markFailed($result->message);

            return response()->json([
                'message' => $result->message,
                'details' => $result->details,
                'account' => $account->fresh()->toPublicArray(),
            ], 422);
        }

        $account->markConnected();

        $this->ensureWebhook($account, $driver);

        return response()->json([
            'message' => $result->message,
            'details' => $result->details,
            'account' => $account->fresh()->toPublicArray(),
        ]);
    }

    /**
     * Drop the credentials. Requests already sent keep their history — the
     * account row is what stops being usable, not the record of what it did.
     */
    public function disconnect(Request $request, string $provider)
    {
        $account = $this->findAccount($provider);

        if ($account instanceof \Illuminate\Http\JsonResponse) {
            return $account;
        }

        try {
            $this->registry->get($provider)->removeWebhook($account);
        } catch (\Throwable $e) {
            // Best effort: a stale callback at the vendor delivers to an
            // endpoint that will no longer match an account, which is safe.
            Log::warning('Could not remove signature webhook on disconnect', [
                'provider'   => $provider,
                'company_id' => $account->company_id,
                'error'      => $e->getMessage(),
            ]);
        }

        $account->forceFill([
            'credentials'         => null,
            'webhook_secret'      => null,
            'webhook_external_id' => null,
            'webhook_status'      => SignatureProviderAccount::WEBHOOK_PENDING,
            'connection_status'   => SignatureProviderAccount::CONNECTION_NOT_CONFIGURED,
            'last_tested_at'      => null,
            'is_active'           => false,
        ])->save();

        return response()->json([
            'message' => 'Integração desconectada.',
            'account' => $account->fresh()->toPublicArray(),
        ]);
    }

    /**
     * Register the callback with the vendor and store the signing secret.
     *
     * A vendor that cannot register callbacks over its API is recorded as
     * `unsupported` rather than papered over — the UI then tells the user to
     * paste the URL into the vendor's dashboard by hand.
     */
    private function ensureWebhook(SignatureProviderAccount $account, $driver): void
    {
        if (!$driver->capabilities()->registersWebhooks) {
            $account->forceFill([
                'webhook_status' => SignatureProviderAccount::WEBHOOK_UNSUPPORTED,
            ])->save();

            return;
        }

        if ($account->webhook_status === SignatureProviderAccount::WEBHOOK_REGISTERED
            && !empty($account->webhook_secret)) {
            return;
        }

        try {
            $registration = $driver->registerWebhook($account, $account->callbackUrl());

            $account->forceFill([
                'webhook_external_id' => $registration->externalId,
                // Vendors typically reveal this once, at registration.
                'webhook_secret'      => $registration->secret,
                'webhook_status'      => SignatureProviderAccount::WEBHOOK_REGISTERED,
            ])->save();
        } catch (UnsupportedCapabilityException $e) {
            $account->forceFill([
                'webhook_status' => SignatureProviderAccount::WEBHOOK_UNSUPPORTED,
                'last_error'     => $e->getMessage(),
            ])->save();
        } catch (\Throwable $e) {
            $account->forceFill([
                'webhook_status' => SignatureProviderAccount::WEBHOOK_ERROR,
                'last_error'     => $e->getMessage(),
            ])->save();

            Log::error('Could not register signature webhook', [
                'provider'   => $account->provider,
                'company_id' => $account->company_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function findAccount(string $provider): SignatureProviderAccount|\Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if (!$user || !$user->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        if (!$this->registry->supports($provider)) {
            return response()->json(['message' => "Unsupported signature provider [{$provider}]."], 404);
        }

        $account = SignatureProviderAccount::fromCompany($user->company_id)
            ->forProvider($provider)
            ->first();

        if (!$account) {
            return response()->json(['message' => 'Integration not found for this company.'], 404);
        }

        return $account;
    }
}
