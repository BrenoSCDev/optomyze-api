<?php

namespace App\Services\Signatures\Data;

/**
 * What a vendor can actually do, declared by its driver.
 *
 * The UI renders from this instead of from hard-coded vendor knowledge: the
 * auth-method picker, the accepted file types, whether a "resend" button
 * exists at all. A new provider therefore changes the UI's options without
 * changing the UI's code.
 */
class ProviderCapabilities
{
    /**
     * @param array<int, array{key: string, label: string, requires?: array<int, string>, billed?: bool, note?: string}> $authMethods
     * @param array<int, array{key: string, label: string, type?: string, required?: bool, help?: string}> $credentialFields
     * @param string[] $environments
     * @param string[] $acceptedMimeTypes
     * @param string[] $locales
     * @param array<int, int|null> $reminderIntervals
     */
    public function __construct(
        /**
         * The credentials this vendor needs, declared by its driver so the
         * connect form can be rendered without the frontend knowing any
         * vendor's field names. One access token here; an integration key,
         * account id and keypair somewhere else.
         */
        public readonly array $credentialFields = [],

        /** Environments the driver can talk to, e.g. sandbox / production. */
        public readonly array $environments = ['production'],

        public readonly array $authMethods = [],
        public readonly array $acceptedMimeTypes = [],
        public readonly ?int $maxFileBytes = null,
        public readonly array $locales = [],
        public readonly array $reminderIntervals = [],
        public readonly bool $registersWebhooks = false,
        public readonly bool $supportsSigningOrder = false,
        public readonly bool $supportsCancel = false,
        public readonly bool $supportsResend = false,
        public readonly bool $supportsMultipleDocuments = false,
        public readonly bool $supportsDeadline = false,
        public readonly bool $supportsRefusal = false,
        /**
         * Fields the vendor requires on a signer beyond name — 'email',
         * 'phone', 'document_number', 'birthdate'. Validation reads this.
         */
        public readonly array $requiredSignerFields = ['email'],
    ) {
    }

    public function supportsAuthMethod(string $method): bool
    {
        foreach ($this->authMethods as $candidate) {
            if (($candidate['key'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    public function authMethodKeys(): array
    {
        return array_values(array_filter(array_map(
            fn (array $method) => $method['key'] ?? null,
            $this->authMethods
        )));
    }

    /**
     * Extra signer fields an auth method needs — e.g. Clicksign's `pix`
     * cannot run without a CPF. Used to validate before a request is sent
     * rather than letting the vendor reject it later.
     *
     * @return string[]
     */
    public function requirementsForAuthMethod(string $method): array
    {
        foreach ($this->authMethods as $candidate) {
            if (($candidate['key'] ?? null) === $method) {
                return $candidate['requires'] ?? [];
            }
        }

        return [];
    }

    public function toArray(): array
    {
        return [
            'credential_fields'           => $this->credentialFields,
            'environments'                => $this->environments,
            'auth_methods'                => $this->authMethods,
            'accepted_mime_types'         => $this->acceptedMimeTypes,
            'max_file_bytes'              => $this->maxFileBytes,
            'locales'                     => $this->locales,
            'reminder_intervals'          => $this->reminderIntervals,
            'registers_webhooks'          => $this->registersWebhooks,
            'supports_signing_order'      => $this->supportsSigningOrder,
            'supports_cancel'             => $this->supportsCancel,
            'supports_resend'             => $this->supportsResend,
            'supports_multiple_documents' => $this->supportsMultipleDocuments,
            'supports_deadline'           => $this->supportsDeadline,
            'supports_refusal'            => $this->supportsRefusal,
            'required_signer_fields'      => $this->requiredSignerFields,
        ];
    }
}
