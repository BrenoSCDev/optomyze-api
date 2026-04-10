<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomProductFieldGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'icon',
        'is_default',
        'display_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function fields()
    {
        return $this->hasMany(CustomProductField::class, 'group_id')->orderBy('display_order');
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeModelLevel($query)
    {
        return $query->whereNull('product_id');
    }
}
