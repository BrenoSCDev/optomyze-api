<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class N8nAgent extends Model
{
    use HasFactory;

    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_ERROR = 'error';

    const PLATFORM_WHATSAPP = 'whatsapp';
    const PLATFORM_TELEGRAM = 'telegram';
    const PLATFORM_EMAIL = 'email';
    const PLATFORM_SMS = 'sms';
    const PLATFORM_SLACK = 'slack';
    const PLATFORM_DISCORD = 'discord';
    const PLATFORM_WEB = 'web';

    protected $fillable = [
        'company_id',
        'n8n_integration_id',
        'workflow_id',
        'workflow_name',
        'platform',
        'agent_name',
        'description',
        'configuration',
        'webhook_data',
        'status',
        'last_execution_at',
        'last_execution_result',
        'execution_count',
    ];

    protected $casts = [
        'configuration' => 'array',
        'webhook_data' => 'array',
        'last_execution_result' => 'array',
        'last_execution_at' => 'datetime',
        'execution_count' => 'integer',
    ];

    /**
     * Get the N8N integration that owns this agent
     */
    public function n8nIntegration(): BelongsTo
    {
        return $this->belongsTo(N8nIntegration::class);
    }

    /**
     * Get the company through the N8N integration
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')
                    ->through('n8nIntegration');
    }

    /**
     * Return conversation reports
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ConversationReport::class);
    }

    /**
     * Scope to get only active agents
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to filter by platform
     */
    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope to get agents with errors
     */
    public function scopeWithErrors($query)
    {
        return $query->where('status', self::STATUS_ERROR);
    }

    /**
     * Get all available platforms
     */
    public static function getAvailablePlatforms(): array
    {
        return [
            self::PLATFORM_WHATSAPP => 'WhatsApp',
            self::PLATFORM_TELEGRAM => 'Telegram',
            self::PLATFORM_EMAIL => 'Email',
            self::PLATFORM_SMS => 'SMS',
            self::PLATFORM_SLACK => 'Slack',
            self::PLATFORM_DISCORD => 'Discord',
            self::PLATFORM_WEB => 'Web',
        ];
    }

    /**
     * Get all available statuses
     */
    public static function getAvailableStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_ERROR => 'Error',
        ];
    }

    /**
     * Check if agent is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if agent has errors
     */
    public function hasErrors(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Update execution result and increment counter
     */
    public function recordExecution(array $result): void
    {
        $this->update([
            'last_execution_at' => now(),
            'last_execution_result' => $result,
            'execution_count' => $this->execution_count + 1,
            'status' => isset($result['error']) ? self::STATUS_ERROR : self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Store webhook data received from N8N
     */
    public function storeWebhookData(array $data): void
    {
        $this->update([
            'webhook_data' => $data,
            'last_execution_at' => now(),
        ]);
    }

    /**
     * Get the last execution error if any
     */
    public function getLastExecutionError(): ?string
    {
        if (!$this->last_execution_result || !isset($this->last_execution_result['error'])) {
            return null;
        }

        return $this->last_execution_result['error'];
    }

    /**
     * Check if last execution was successful
     */
    public function isLastExecutionSuccessful(): bool
    {
        if (!$this->last_execution_result) {
            return false;
        }

        return !isset($this->last_execution_result['error']);
    }

    /**
     * Get platform display name
     */
    public function getPlatformDisplayName(): string
    {
        $platforms = self::getAvailablePlatforms();
        return $platforms[$this->platform] ?? ucfirst($this->platform);
    }

    /**
     * Get status display name
     */
    public function getStatusDisplayName(): string
    {
        $statuses = self::getAvailableStatuses();
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get configuration value by key
     */
    public function getConfigValue(string $key, $default = null)
    {
        return data_get($this->configuration, $key, $default);
    }

    /**
     * Set configuration value by key
     */
    public function setConfigValue(string $key, $value): void
    {
        $config = $this->configuration ?? [];
        data_set($config, $key, $value);
        $this->update(['configuration' => $config]);
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Set default status if not provided
            if (empty($model->status)) {
                $model->status = self::STATUS_ACTIVE;
            }
        });
    }
}