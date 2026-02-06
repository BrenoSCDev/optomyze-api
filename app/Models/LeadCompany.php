<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadCompany extends Model
{
    protected $fillable = [
        'company_id',

        // Identification
        'legal_name',
        'trade_name',
        'tax_id',
        'industry',
        'company_size',
        'website',

        // Contact
        'email',
        'phone',
        'secondary_phone',

        // Address
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'postal_code',
        'country',

        // CRM
        'source',
        'status',
        'notes',

        // Assets
        'logo_path',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
