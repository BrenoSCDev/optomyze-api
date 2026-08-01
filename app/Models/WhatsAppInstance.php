<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInstance extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'whats_app_evo_integration_id',
        'instance_name',
        'label',
        'profile_name',
        'profile_pic_url',
        'phone_number',
        'owner_jid',
        'status',
        'metadata',
        'connected_at',
        'status_checked_at',
        'funnel_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'connected_at' => 'datetime',
        'status_checked_at' => 'datetime',
    ];

    /**
     * Get the integration that owns this instance
     */
    public function whatsappEvoIntegration(): BelongsTo
    {
        return $this->belongsTo(WhatsAppEvoIntegration::class);
    }

    /**
     * Get the funnel that owns the instance.
     */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }
}
