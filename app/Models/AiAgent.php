<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAgent extends Model
{
    use HasCompanyTimezone;

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    /*
     * Provider keys are the driver keys in config/llm.php — that registry is
     * the source of truth, so there is deliberately no enum of them here.
     */
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_CLAUDE = 'claude';

    public const MIN_RESPONSE_DELAY_SECONDS = 10;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'status',
        'provider',
        'model',
        'temperature',
        'max_tokens',
        'system_prompt',
        'enabled_tools',
        'response_delay_seconds',
        'handover_message',
        'settings',
        'execution_count',
        'last_executed_at',
    ];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'response_delay_seconds' => 'integer',
        'enabled_tools' => 'array',
        'settings' => 'array',
        'execution_count' => 'integer',
        'last_executed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function channelBindings(): HasMany
    {
        return $this->hasMany(AiAgentChannelBinding::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AiAgentExecution::class);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    public function recordExecution(): void
    {
        $this->increment('execution_count');
        $this->update(['last_executed_at' => now()]);
    }

    /**
     * Dot-path read into the extensible settings json.
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }
}
