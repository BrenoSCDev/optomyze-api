<?php

namespace App\Events;

use App\Models\SaleTransaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SaleTransactionNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SaleTransaction $transaction;

    public function __construct(SaleTransaction $transaction)
    {
        $this->transaction = $transaction->load([
            'sale:id,name,total,status,lead_id',
            'sale.lead:id,first_name,last_name',
            'user:id,name,profile_pic',
        ]);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->transaction->company_id);
    }

    public function broadcastAs(): string
    {
        return 'deal.transaction';
    }

    public function broadcastWith(): array
    {
        $transaction = $this->transaction;
        $sale        = $transaction->sale;
        $user        = $transaction->user;

        return [
            'id'          => $transaction->id,
            'type'        => $transaction->type,
            'action'      => $transaction->action,
            'description' => $transaction->description,
            'source'      => $transaction->source,
            'deal'        => [
                'id'     => $sale?->id,
                'name'   => $sale?->name,
                'total'  => $sale?->total,
                'status' => $sale?->status,
                'lead'   => $sale?->lead ? [
                    'id'         => $sale->lead->id,
                    'first_name' => $sale->lead->first_name,
                    'last_name'  => $sale->lead->last_name,
                ] : null,
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
