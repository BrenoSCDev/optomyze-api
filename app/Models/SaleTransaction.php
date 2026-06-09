<?php

namespace App\Models;

use App\Events\SaleTransactionNotification;
use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleTransaction extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected static function booted(): void
    {
        static::created(function (SaleTransaction $transaction) {
            broadcast(new SaleTransactionNotification($transaction));
        });
    }

    /**
     * Transaction type constants
     */
    const TYPE_STAGE_CHANGE  = 'stage_change';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_CREATION      = 'creation';
    const TYPE_DELETION      = 'deletion';
    const TYPE_UPDATE        = 'update';

    /**
     * Transaction action constants
     */
    const ACTION_MOVED   = 'moved';
    const ACTION_WON     = 'won';
    const ACTION_LOST    = 'lost';
    const ACTION_CLOSED  = 'closed';
    const ACTION_CREATED = 'created';
    const ACTION_DELETED = 'deleted';
    const ACTION_UPDATED = 'updated';

    /**
     * Source constants
     */
    const SOURCE_MANUAL     = 'manual';
    const SOURCE_SYSTEM     = 'system';
    const SOURCE_AUTOMATION = 'automation';
    const SOURCE_API        = 'api';
    const SOURCE_WEBHOOK    = 'webhook';

    protected $fillable = [
        'sale_id',
        'company_id',
        'user_id',
        'type',
        'action',
        'description',
        'source',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log a stage movement. Automatically uses 'won' or 'lost' actions
     * when the destination stage type is 'won' or 'lost'.
     */
    public static function createStageChange(
        Sale $sale,
        ?Stage $fromStage,
        Stage $toStage,
        ?User $user = null,
        array $metadata = []
    ): self {
        $dealName = $sale->name;
        if ($toStage->type === 'won') {
            $action      = self::ACTION_WON;
            $description = self::buildNewSaleDescription($sale);
        } elseif ($toStage->type === 'lost') {
            $action      = self::ACTION_LOST;
            $description = $fromStage
                ? "'{$dealName}' marked as lost — moved from '{$fromStage->name}' to '{$toStage->name}'"
                : "'{$dealName}' marked as lost in stage '{$toStage->name}'";
        } else {
            $action      = self::ACTION_MOVED;
            $description = $fromStage
                ? "'{$dealName}' moved from '{$fromStage->name}' to '{$toStage->name}'"
                : "'{$dealName}' moved to '{$toStage->name}'";
        }

        return self::create([
            'sale_id'     => $sale->id,
            'company_id'  => $sale->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_STAGE_CHANGE,
            'action'      => $action,
            'description' => $description,
            'source'      => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Log a manual status change (via updateStatus endpoint).
     */
    public static function createStatusChange(
        Sale $sale,
        string $fromStatus,
        string $toStatus,
        ?User $user = null
    ): self {
        $action = $toStatus === 'closed' ? self::ACTION_CLOSED : self::ACTION_LOST;
        $description = $toStatus === 'closed'
            ? self::buildNewSaleDescription($sale)
            : "'{$sale->name}' marked as lost";

        return self::create([
            'sale_id'     => $sale->id,
            'company_id'  => $sale->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_STATUS_CHANGE,
            'action'      => $action,
            'description' => $description,
            'source'      => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    private static function buildNewSaleDescription(Sale $sale): string
    {
        $lead      = $sale->lead;
        $leadName  = $lead ? trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) : null;
        $ownerName = $sale->user?->name;

        $description = "New sale! '{$sale->name}'";
        if ($leadName)  $description .= " · {$leadName}";
        if ($ownerName) $description .= " · {$ownerName}";

        return $description;
    }

    /**
     * Log deal creation.
     */
    public static function createSaleCreation(Sale $sale, ?User $user = null): self
    {
        $lead = $sale->lead;
        $leadName = $lead ? trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) : null;
        $description = $leadName
            ? "Deal '{$sale->name}' created for {$leadName}"
            : "Deal '{$sale->name}' created";

        return self::create([
            'sale_id'     => $sale->id,
            'company_id'  => $sale->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_CREATION,
            'action'      => self::ACTION_CREATED,
            'description' => $description,
            'source'      => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Log deal deletion. Call this before the sale is deleted.
     */
    public static function createSaleDeletion(Sale $sale, ?User $user = null): self
    {
        return self::create([
            'sale_id'     => $sale->id,
            'company_id'  => $sale->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_DELETION,
            'action'      => self::ACTION_DELETED,
            'description' => "deal '{$sale->name}' deleted",
            'source'      => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }
}
