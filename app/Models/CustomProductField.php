<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomProductField extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected $fillable = [
        'company_id',
        'product_id',
        'group_id',
        'type',
        'field_type',
        'field_key',
        'field_value',
        'icon',
        'display_order',
    ];

    public function group()
    {
        return $this->belongsTo(CustomProductFieldGroup::class, 'group_id');
    }
}