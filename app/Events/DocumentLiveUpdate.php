<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Keeps every open contracts board in sync with reality.
 *
 * The board's whole point is that most of what moves a card is not done by
 * anybody looking at it: the counterparty signs, refuses, or lets a deadline
 * pass, and the change arrives from a webhook on a queue worker. Without this
 * the board is a photograph of whenever it was last loaded.
 *
 * Modelled on DealLiveUpdate: same channel, same private-per-company scoping,
 * an `action` discriminator so the client can patch rather than refetch.
 *
 * ShouldBroadcastNow rather than ShouldBroadcast — these already originate on
 * a queue worker, and queueing a broadcast from a queued job just adds a hop
 * between the signature landing and the card moving.
 */
class DocumentLiveUpdate implements ShouldBroadcastNow
{
    /**
     * @param string $action  'created' | 'updated' | 'status_changed' | 'deleted'
     */
    public function __construct(
        private readonly string   $action,
        private readonly Document $document,
        private readonly ?string  $oldStatus = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->document->company_id);
    }

    public function broadcastAs(): string
    {
        return 'document.live';
    }

    public function broadcastWith(): array
    {
        if ($this->action === 'deleted') {
            return [
                'action'      => 'deleted',
                'document_id' => $this->document->id,
                'status'      => $this->document->status,
            ];
        }

        return [
            'action'      => $this->action,
            'document_id' => $this->document->id,
            'old_status'  => $this->oldStatus,
            'new_status'  => $this->document->status,
            // The full card, so a status change that also altered signed_at,
            // has_pdf or version_number lands as one consistent replacement
            // instead of a patch the client has to reconcile.
            'document'    => $this->document->toCardArray(),
        ];
    }
}
