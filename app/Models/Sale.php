<?php

// app/Models/Sale.php
namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\User;

class Sale extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'lead_id',
        'company_id',
        'user_id',
        'status',
        'is_active',
        'pricing_model',
        'commission_type',
        'commission_value',
        'reference_value',
        'subtotal',
        'discount_total',
        'total',
        'currency',
        'notes',
        'closed_at',
        'lost_at',
        'name',
        'funnel_id',
        'stage_id',
    ];

    protected $appends = [
        'charges_total',
        'final_total',
    ];

    /**
     * Mirrors the column default so a freshly created instance already knows
     * it is unarchived. Without this, `Sale::create(...)->is_active` is null
     * until the row is re-read — and null reads as archived to anything
     * checking the flag, including a broadcast built from the new model.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'total'     => 'decimal:2',
        'closed_at' => 'datetime',
        'lost_at'   => 'datetime',
    ];


    public function company():BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function charges()
    {
        return $this->hasMany(SaleCharge::class);
    }

    public function discounts()
    {
        return $this->hasMany(SaleDiscount::class);
    }
    
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function docs()
    {
        return $this->hasMany(SaleDoc::class);
    }

    public function records(): MorphMany
    {
        return $this->morphMany(Record::class, 'recordable');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'sale_tag');
    }

    /**
     * Get the funnel this deal belongs to.
     */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /**
     * Get the current stage of the deal.
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }


    public function calculateSubtotal(): float
    {
        if ($this->pricing_model === 'product') {
            return $this->items->sum('total_price');
        }

        if ($this->pricing_model === 'commission') {
            return $this->commission_type === 'percentage'
                ? $this->reference_value * ($this->commission_value / 100)
                : $this->commission_value;
        }

        if ($this->pricing_model === 'hybrid') {
            $productsTotal = $this->items->sum('total_price');
            $commission = $this->commission_type === 'percentage'
                ? $this->reference_value * ($this->commission_value / 100)
                : $this->commission_value;

            return $productsTotal + $commission;
        }

        return 0;
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('sales.is_active', true);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('sales.is_active', false);
    }

    /**
     * Applies an archive filter from a request value: 'archived', 'all', or
     * anything else (including null) for the default active-only view.
     * Mirrors Lead::scopeArchiveFilter so both boards read the same param.
     */
    public function scopeArchiveFilter(Builder $query, ?string $mode): Builder
    {
        return match ($mode) {
            'archived' => $query->archived(),
            'all'      => $query,
            default    => $query->notArchived(),
        };
    }

    /**
     * Scope a query to only include deals visible to the given user based on their role.
     * Admin: no filter. Manager: own + team + unassigned. Agent: own only.
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        $ids = $user->visibleUserIds();
        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $user) {
            $q->whereIn('user_id', $ids);
            if ($user->isManager()) {
                $q->orWhereNull('user_id');
            }
        });
    }

    // ✅ Charges total accessor
    public function getChargesTotalAttribute(): float
    {
        return $this->charges->sum('calculated_amount');
    }

    // ✅ Final total accessor
    public function getFinalTotalAttribute(): float
    {
        return (float) $this->subtotal
            - (float) $this->discount_total
            + (float) $this->charges_total;
    }

}
