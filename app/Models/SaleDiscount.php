<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleDiscount extends Model
{
    protected $fillable = [
        'sale_id',
        'name',
        'type',
        'value',
        'calculated_amount',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
