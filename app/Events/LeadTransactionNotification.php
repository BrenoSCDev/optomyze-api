<?php

namespace App\Events;

use App\Models\LeadTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class LeadTransactionNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public LeadTransaction $transaction;

    public function __construct(LeadTransaction $transaction)
    {
        $this->transaction = $transaction->load([
            'lead:id,first_name,last_name,company_id',
            'user:id,name,profile_pic',
        ]);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->transaction->company_id);
    }

    public function broadcastAs(): string
    {
        return 'lead.transaction';
    }

    public function broadcastWith(): array
    {
        $transaction = $this->transaction;
        $lead        = $transaction->lead;
        $user        = $transaction->user;

        return [
            'id'          => $transaction->id,
            'type'        => $transaction->type,
            'action'      => $transaction->action,
            'description' => $transaction->description,
            'source'      => $transaction->source,
            'is_important' => (bool) $transaction->is_important,
            'lead'        => [
                'id'         => $lead->id,
                'first_name' => $lead->first_name,
                'last_name'  => $lead->last_name,
            ],
            'user'        => $user ? [
                'id'              => $user->id,
                'name'            => $user->name,
                'profile_pic_url' => $user->profile_pic
                    ? Storage::url($user->profile_pic)
                    : null,
            ] : null,
            'created_at'  => $transaction->created_at->toISOString(),
        ];
    }
}
