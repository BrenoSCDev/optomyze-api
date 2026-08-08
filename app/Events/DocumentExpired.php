<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The signing deadline passed without completion. */
class DocumentExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Document $document)
    {
    }
}
