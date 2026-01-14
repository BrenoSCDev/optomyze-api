<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomProductField extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'product_id',
        'type',
        'field_type',
        'field_key',
        'field_value',
    ];
}