<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSetting extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'welcome_messages_enabled',
        'closing_messages_enabled',
    ];

    protected $casts = [
        'welcome_messages_enabled' => 'boolean',
        'closing_messages_enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
