<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappEvoMessage extends Model
{
    protected $fillable = [
        'whatsapp_evo_chat_id',
        'user_id',
        'wa_message_id',
        'direction',
        'message_mode',
        'text',
        'type',
        'media_url',
        'media_mime',
        'media_size',
        'latitude',
        'longitude',
        'metadata',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
        'metadata'  => 'array',
    ];

    public function chat()
    {
        return $this->belongsTo(WhatsappEvoChat::class, 'whatsapp_evo_chat_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
