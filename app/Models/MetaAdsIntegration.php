<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MetaAdsIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'act_id',
        'account_id',
        'access_token',
        'is_active',
        'last_sync_at',
        'sync_status',
    ];

    /**
     * Get the company that owns this integration
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to get only active integrations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to find n8n integration from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
    
    /**
     * Validate configuration input
     */
    public static function validateConfig(array $data)
    {
        $validator = Validator::make($data, [
            'account_id' => ['nullable', 'string', 'max:255'],
            'access_token'     => ['nullable', 'string', 'min:10', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
