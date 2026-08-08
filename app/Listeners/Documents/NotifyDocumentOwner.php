<?php

namespace App\Listeners\Documents;

use App\Events\DocumentExpired;
use App\Events\DocumentRejected;
use App\Events\DocumentSent;
use App\Events\DocumentSigned;
use App\Models\Document;
use App\Services\NotificationService;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Tells the person who owns a document when the outside world acts on it.
 *
 * Enabled by default only for outcomes the owner cannot see coming: a
 * signature landing, a refusal, a deadline passing. Sending is wired but
 * shipped off, because the owner is the one who did it.
 */
class NotifyDocumentOwner
{
    public function onSigned(DocumentSigned $event): void
    {
        $this->notify($event->document, 'document_signed');
    }

    public function onRejected(DocumentRejected $event): void
    {
        $this->notify($event->document, 'document_rejected');
    }

    public function onExpired(DocumentExpired $event): void
    {
        $this->notify($event->document, 'document_expired');
    }

    /**
     * Off by default in config/notification_defaults.php — the owner is the one
     * who sent it. It exists so a company that wants the deal owner or a
     * manager told can switch it on without a code change.
     */
    public function onSent(DocumentSent $event): void
    {
        $this->notify($event->document, 'document_sent', $event->actor);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            DocumentSigned::class   => 'onSigned',
            DocumentRejected::class => 'onRejected',
            DocumentExpired::class  => 'onExpired',
            DocumentSent::class     => 'onSent',
        ];
    }

    private function notify(Document $document, string $eventKey, ?\App\Models\User $actor = null): void
    {
        try {
            app(NotificationService::class)->dispatch($eventKey, $document->company_id, [
                'document_owner_id' => $document->owner_id ?? $document->created_by,
                'deal_owner_id'     => $document->sale?->user_id,
                'lead_owner_id'     => $document->lead?->assigned_to,
                'document'          => [
                    'id'     => $document->id,
                    'number' => $document->number,
                    'title'  => $document->title,
                ],
                // Underscore-prefixed: consumed by the in-app broadcast only,
                // never serialised into an email or WhatsApp payload.
                '_document_model'   => $document,
                '_actor_model'      => $actor,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not notify document owner', [
                'document_id' => $document->id,
                'event'       => $eventKey,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
