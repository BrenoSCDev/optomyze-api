<?php

namespace App\Events;

use App\Models\Document;
use App\Models\SignatureSigner;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A signer declined to sign. */
class DocumentRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly ?SignatureSigner $signer = null,
    ) {
    }
}
