<?php

// app/Models/Sale.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
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
    ];

    protected $appends = [
        'charges_total',
        'final_total',
    ];

    protected $casts = [
    'total' => 'decimal:2',
];


    public function company():BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function charges()
    {
        return $this->hasMany(SaleCharge::class);
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
