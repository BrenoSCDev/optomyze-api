<?php

namespace App\Events;

use App\Models\Record;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Single broadcast event for all Records (Lead + Deal), replacing the old
 * LeadTransactionNotification/SaleTransactionNotification events.
 *
 * To avoid a frontend rewrite of NotificationContext (toasts + activity
 * dashboard listeners), this preserves the exact wire format/event names
 * ('lead.transaction' / 'deal.transaction') those consumers already expect.
 */
class RecordCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Record $record;

    public function __construct(Record $record)
    {
        $record->loadMissing('user:id,name,profile_pic');
        $record->load('recordable');

        if ($record->recordable_type === Record::RECORDABLE_SALE && $record->recordable) {
            $record->recordable->loadMissing('lead:id,first_name,last_name,avatar_key');
        }

        $this->record = $record;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->record->company_id);
    }

    public function broadcastAs(): string
    {
        return $this->record->recordable_type === Record::RECORDABLE_SALE
            ? 'deal.transaction'
            : 'lead.transaction';
    }

    public function broadcastWith(): array
    {
        $record = $this->record;
        $user   = $record->user;

        $payload = [
            'id'          => $record->id,
            'type'        => $record->type,
            'action'      => $record->action,
            'title'       => $record->title,
            'description' => $record->description,
            'source'      => $record->source,
            'user'        => $user ? [
                'id'              => $user->id,
                'name'            => $user->name,
                'profile_pic_url' => $user->profile_pic
                    ? Storage::url($user->profile_pic)
                    : null,
            ] : null,
            'created_at'  => $record->created_at->toISOString(),
        ];

        if ($record->recordable_type === Record::RECORDABLE_SALE) {
            $sale = $record->recordable;
            $payload['deal'] = [
                'id'     => $sale?->id,
                'name'   => $sale?->name,
                'total'  => $sale?->total,
                'status' => $sale?->status,
                'lead'   => $sale?->lead ? [
                    'id'         => $sale->lead->id,
                    'first_name' => $sale->lead->first_name,
                    'last_name'  => $sale->lead->last_name,
                ] : null,
            ];
        } else {
            $lead = $record->recordable;
            // Preserves prior LeadTransactionNotification behavior, where
            // is_important was cast from a non-existent column and always false.
            $payload['is_important'] = false;
            $payload['lead'] = $lead ? [
                'id'         => $lead->id,
                'first_name' => $lead->first_name,
                'last_name'  => $lead->last_name,
            ] : null;
        }

        return $payload;
    }
}
