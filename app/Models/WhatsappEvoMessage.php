<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappEvoMessage extends Model
{
    protected $fillable = [
        'whatsapp_evo_chat_id',
        'wa_message_id',
        'direction',
        'text',
        'type',
        'media_url',
        'media_mime',
        'media_size',
        'status',
        'sent_at',
    ];

    public function chat()
    {
        return $this->belongsTo(WhatsappEvoChat::class, 'whatsapp_evo_chat_id');
    }
}
