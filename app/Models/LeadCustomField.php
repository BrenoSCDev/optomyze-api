<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadCustomField extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'type',
        'field_type',
        'field_key',
        'field_value',
    ];
}
