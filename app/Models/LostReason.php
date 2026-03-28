<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostReason extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'description',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeFromCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
