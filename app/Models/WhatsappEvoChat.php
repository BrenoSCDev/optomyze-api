<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappEvoChat extends Model
{
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

    // WhatsappEvoChat.php
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
