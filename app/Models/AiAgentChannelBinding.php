<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiAgentChannelBinding extends Model
{
    protected $fillable = [
        'company_id',
        'ai_agent_id',
        'channelable_type',
        'channelable_id',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /**
     * The bound channel account (WhatsAppInstance | InstagramIntegration),
     * mirroring inbox_chats.channelable.
     */
    public function channelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
