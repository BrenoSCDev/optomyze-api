<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class WhatsappEvoChat extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'whatsapp_instance_id',
        'lead_id',
        'remote_jid',
        'phone_number',
        'is_group',
        'is_archived',
        'last_message_at',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function messages()
    {
        return $this->hasMany(WhatsappEvoMessage::class);
    }

    public function instance()
    {
        return $this->belongsTo(WhatsAppInstance::class, 'whatsapp_instance_id');
    }
}
