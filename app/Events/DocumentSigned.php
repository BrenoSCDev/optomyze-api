<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Every signer signed and the provider finalised the document.
 *
 * The seam the automation engine plugs into. Whatever should follow a signed
 * contract — the deal moving to Won, an invoice being raised, a project being
 * created, a kickoff being scheduled — hangs off this event as a listener, so
 * none of it has to be known by the document module.
 */
class DocumentSigned
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Document $document)
    {
    }
}
