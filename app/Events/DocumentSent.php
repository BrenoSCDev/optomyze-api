<?php

namespace App\Events;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A document was handed to a signature provider and signers were invited. */
class DocumentSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly ?User $actor = null,
    ) {
    }
}
