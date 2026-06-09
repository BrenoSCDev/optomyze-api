<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class DealLiveUpdate implements ShouldBroadcastNow
{
    /**
     * @param string   $action      'created' | 'stage_changed' | 'deleted'
     * @param Sale     $sale
     * @param int|null $oldStageId  Populated for stage_changed
     * @param string|null $newStageType  Stage type of destination (e.g. 'won','lost','default')
     */
    public function __construct(
        private readonly string  $action,
        private readonly Sale    $sale,
        private readonly ?int    $oldStageId   = null,
        private readonly ?string $newStageType = null,
    ) {
        if ($action === 'created') {
            $this->sale->loadMissing([
                'lead:id,first_name,last_name,email,phone,stage_id,funnel_id,assigned_to',
                'lead.assignedUser:id,name',
                'lead.stage:id,name,color',
                'lead.funnel:id,name',
                'user:id,name',
                'items:id,sale_id,product_name,quantity,total_price',
                'charges',
                'discounts',
            ]);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('company.' . $this->sale->company_id);
    }

    public function broadcastAs(): string
    {
        return 'deal.live';
    }

    public function broadcastWith(): array
    {
        $sale = $this->sale;

        if ($this->action === 'deleted') {
            return [
                'action'    => 'deleted',
                'deal_id'   => $sale->id,
                'funnel_id' => $sale->funnel_id,
                'stage_id'  => $sale->stage_id,
            ];
        }

        if ($this->action === 'stage_changed') {
            return [
                'action'         => 'stage_changed',
                'deal_id'        => $sale->id,
                'funnel_id'      => $sale->funnel_id,
                'old_stage_id'   => $this->oldStageId,
                'new_stage_id'   => $sale->stage_id,
                'new_stage_type' => $this->newStageType,
            ];
        }

        // 'created' — full deal shape matching the kanban deals array
        $lead = $sale->lead;

        return [
            'action' => 'created',
            'deal'   => [
                ...$sale->only([
                    'id', 'company_id', 'lead_id', 'funnel_id', 'stage_id',
                    'user_id', 'name', 'status', 'pricing_model',
                    'subtotal', 'total', 'discount_total',
                    'commission_type', 'commission_value', 'reference_value',
                    'notes', 'created_at', 'updated_at',
                ]),
                'lead'  => $lead ? [
                    ...$lead->only([
                        'id', 'first_name', 'last_name', 'email', 'phone',
                        'stage_id', 'funnel_id', 'assigned_to',
                    ]),
                    'assigned_user' => $lead->assignedUser
                        ? ['id' => $lead->assignedUser->id, 'name' => $lead->assignedUser->name]
                        : null,
                    'stage'  => $lead->stage  ? $lead->stage->only(['id', 'name', 'color'])  : null,
                    'funnel' => $lead->funnel ? $lead->funnel->only(['id', 'name']) : null,
                ] : null,
                'user'      => $sale->user     ? $sale->user->only(['id', 'name'])             : null,
                'items'     => $sale->items?->toArray()     ?? [],
                'charges'   => $sale->charges?->toArray()   ?? [],
                'discounts' => $sale->discounts?->toArray() ?? [],
            ],
        ];
    }
}
