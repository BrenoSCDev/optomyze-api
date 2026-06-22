<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WelcomeMessageConfig extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'channelable_type',
        'channelable_id',
        'channel',
        'is_enabled',
        'message',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function channelable(): MorphTo
    {
        return $this->morphTo();
    }
}
