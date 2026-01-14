<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class N8nIntegration extends Model
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

    protected $casts = [
        'settings' => 'array',
        'sync_status' => 'array',
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    // protected $hidden = [
    //     'api_key', // Hide sensitive data from JSON responses
    // ];

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
        return !empty($this->webhook_url) && $this->is_active;
    }

    /**
     * Update sync status
     */
    public function updateSyncStatus(array $status): void
    {
        $this->update([
            'sync_status' => $status,
            'last_sync_at' => now(),
        ]);
    }

    /**
     * Get the last sync error if any
     */
    public function getLastSyncError(): ?string
    {
        if (!$this->sync_status || !isset($this->sync_status['error'])) {
            return null;
        }

        return $this->sync_status['error'];
    }

    /**
     * Check if last sync was successful
     */
    public function isLastSyncSuccessful(): bool
    {
        if (!$this->sync_status) {
            return false;
        }

        return isset($this->sync_status['success']) && $this->sync_status['success'] === true;
    }

    /**
     * Get formatted settings for display
     */
    public function getFormattedSettings(): array
    {
        return $this->settings ?? [];
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Ensure base_url has proper format if provided
            if ($model->base_url && !str_starts_with($model->base_url, 'http')) {
                $model->base_url = 'https://' . $model->base_url;
            }
        });

        static::updating(function ($model) {
            // Ensure base_url has proper format if provided
            if ($model->base_url && !str_starts_with($model->base_url, 'http')) {
                $model->base_url = 'https://' . $model->base_url;
            }
        });
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
            'webhook_url' => ['nullable', 'url', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'api_key'     => ['nullable', 'string', 'min:10', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}