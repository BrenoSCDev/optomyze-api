<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInstance extends Model
{
    /**
     * Prefix for the generated account identifier. Short and readable, because
     * this is the string a customer reads out to support when they have three
     * WhatsApp numbers connected and one of them has stopped working.
     */
    private const IDENTIFIER_PREFIX = 'acct_';

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
     * Mint the identifier a new account is provisioned under.
     *
     * Doubles as the Evolution instance name, which the user never sees or
     * chooses — they used to type one, and a name like "sales" told them
     * nothing while colliding with the same name in another tenant.
     *
     * Uniqueness is checked across every company, not just this one: all
     * tenants now share a single Evolution server, so its namespace is global
     * even though our own unique index used to be company-scoped.
     */
    public static function generateIdentifier(): string
    {
        do {
            $identifier = self::IDENTIFIER_PREFIX . strtoupper(bin2hex(random_bytes(3)));
        } while (static::where('instance_name', $identifier)->exists());

        return $identifier;
    }

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
