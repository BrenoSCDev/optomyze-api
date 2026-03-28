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
        if ($toStage->type === 'won') {
            $action      = self::ACTION_WON;
            $description = $fromStage
                ? "deal closed as won — moved from '{$fromStage->name}' to '{$toStage->name}'"
                : "deal closed as won in stage '{$toStage->name}'";
        } elseif ($toStage->type === 'lost') {
            $action      = self::ACTION_LOST;
            $description = $fromStage
                ? "deal marked as lost — moved from '{$fromStage->name}' to '{$toStage->name}'"
                : "deal marked as lost in stage '{$toStage->name}'";
        } else {
            $action      = self::ACTION_MOVED;
            $description = $fromStage
                ? "moved deal from '{$fromStage->name}' to '{$toStage->name}'"
                : "moved deal to '{$toStage->name}'";
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

        return self::create([
            'sale_id'     => $sale->id,
            'company_id'  => $sale->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_STATUS_CHANGE,
            'action'      => $action,
            'description' => "deal status changed from '{$fromStatus}' to '{$toStatus}'",
            'source'      => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Log deal creation.
     */
    public static function createSaleCreation(Sale $sale, ?User $user = null): self
    {
        return self::create([
            'sale_id'     => $sale->id,
            'company_id'  => $sale->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_CREATION,
            'action'      => self::ACTION_CREATED,
            'description' => "deal '{$sale->name}' created",
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
