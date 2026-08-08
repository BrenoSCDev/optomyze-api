<?php

namespace App\Listeners\Documents;

use App\Events\DocumentExpired;
use App\Events\DocumentRejected;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Models\Record;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Puts document milestones on the lead's and deal's activity timeline.
 *
 * One listener for every lifecycle event rather than one per event, because
 * the mapping is a single table and splitting it across four files would hide
 * that.
 */
class WriteDocumentRecord
{
    public function onSent(DocumentSent $event): void
    {
        $this->write($event->document, Record::ACTION_SENT, $event->actor);
    }

    public function onSigned(DocumentSigned $event): void
    {
        $this->write($event->document, Record::ACTION_COMPLETED);
    }

    public function onRejected(DocumentRejected $event): void
    {
        $this->write($event->document, Record::ACTION_REFUSED);
    }

    public function onExpired(DocumentExpired $event): void
    {
        $this->write($event->document, Record::ACTION_CANCELED);
    }

    /**
     * Laravel resolves these from the listener class when it is registered
     * against multiple events.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            DocumentSent::class     => 'onSent',
            DocumentSigned::class   => 'onSigned',
            DocumentRejected::class => 'onRejected',
            DocumentExpired::class  => 'onExpired',
        ];
    }

    private function write(\App\Models\Document $document, string $action, ?\App\Models\User $actor = null): void
    {
        try {
            Record::createDocumentLifecycle($document, $action, $actor);
        } catch (\Throwable $e) {
            // A timeline entry is a description of work, never the work.
            Log::warning('Could not write document timeline entry', [
                'document_id' => $document->id,
                'action'      => $action,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
