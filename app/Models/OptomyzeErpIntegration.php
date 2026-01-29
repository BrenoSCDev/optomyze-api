<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OptomyzeErpIntegration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'webhook_url',
        'api_key',
        'base_url',
        'settings',
        'is_active',
        'last_sync_at',
        'sync_status',
    ];

    /**
     * Get the company that owns this integration
     */
    public function company(): BelongsTo
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
     * Check if integration is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->api_key) && $this->is_active;
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
            'api_key'     => ['nullable', 'string', 'min:10', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
