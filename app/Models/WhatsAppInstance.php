<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInstance extends Model
{
    protected $fillable = [
        'company_id',
        'whats_app_evo_integration_id',
        'instance_name',
        'label',
        'status',
        'metadata',
        'connected_at',
        'funnel_id',
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
