<?php

namespace App\Events;

use App\Models\Document;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The bell notification for a document milestone.
 *
 * Deliberately separate from DocumentLiveUpdate even though both fire on the
 * same transitions: the board wants every change including ones the viewer
 * caused, while the bell wants only the ones worth interrupting somebody for.
 * Collapsing them would mean the client deciding what deserves a toast, which
 * is a policy question the server already answers via NotificationSetting.
 *
 * `actor` is carried so the client can suppress a notification for the person
 * who caused it — the existing convention in NotificationContext.
 */
class DocumentTransactionNotification implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly string   $eventKey,
        public readonly ?User    $actor = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->document->company_id);
    }

    public function broadcastAs(): string
    {
        return 'document.transaction';
    }

    public function broadcastWith(): array
    {
        $document = $this->document;
        $document->loadMissing(['type:id,label,category', 'owner:id,name', 'lead', 'sale']);

        return [
            'id'        => uniqid('docnotif_'),
            'type'      => $this->eventKey,
            'message'   => $this->message(),
            'priority'  => config("notification_defaults.events.{$this->eventKey}.priority", 'normal'),
            'document'  => [
                'id'        => $document->id,
                'number'    => $document->number,
                'title'     => $document->title,
                'status'    => $document->status,
                'category'  => $document->type?->category,
                'lead_id'   => $document->lead_id,
                'sale_id'   => $document->sale_id,
                'owner_id'  => $document->owner_id,
            ],
            'actor'     => $this->actor
                ? ['id' => $this->actor->id, 'name' => $this->actor->name]
                : null,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * English here to match the other broadcast events; the frontend
     * translates on `type` and only falls back to this string.
     */
    private function message(): string
    {
        $label = $this->document->number ?: $this->document->title;

        return match ($this->eventKey) {
            'document_signed'   => "{$label} was signed by everyone",
            'document_rejected' => "{$label} was refused by a signer",
            'document_expired'  => "{$label} expired without every signature",
            'document_sent'     => "{$label} was sent for signature",
            default             => "{$label} was updated",
        };
    }
}
