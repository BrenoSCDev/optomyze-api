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

    protected $casts = [
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
