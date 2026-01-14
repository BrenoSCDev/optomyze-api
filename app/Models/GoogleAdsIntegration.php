<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GoogleAdsIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'customer_id',
        'manager_id',
        'developer_token',
        'webhook_url',
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
            'customer_id' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', 'string', 'max:255'],
            'developer_token' => ['nullable', 'string', 'min:10', 'max:255'],
            'webhook_url' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
