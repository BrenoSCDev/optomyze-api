<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A new immutable version was rendered for a document. */
class DocumentGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly DocumentVersion $version,
        public readonly ?User $actor = null,
    ) {
    }
}
