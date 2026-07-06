<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentExecution extends Model
{
    use HasCompanyTimezone;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_HANDOVER = 'handover';

    protected $fillable = [
        'company_id',
        'ai_agent_id',
        'inbox_chat_id',
        'chat_session_id',
        'status',
        'last_inbound_message_id',
        'tool_calls',
        'response_text',
        'error',
        'prompt_tokens',
        'completion_tokens',
        'duration_ms',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'last_inbound_message_id' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(InboxChat::class, 'inbox_chat_id');
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
