<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'channels',
        'default_recipients',
        'events',
        'quiet_hours',
    ];

    protected $casts = [
        'channels' => 'array',
        'default_recipients' => 'array',
        'events' => 'array',
        'quiet_hours' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
