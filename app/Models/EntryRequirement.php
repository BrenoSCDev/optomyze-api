<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntryRequirement extends Model
{
    protected $fillable = [
        'stage_id',
        'require_assigned_owner',
        'require_contact_email',
        'require_phone_number',
        'require_at_least_one_product',
        'require_deal_value',
        'require_recent_activity',
    ];

    protected $casts = [
        'require_assigned_owner' => 'boolean',
        'require_contact_email' => 'boolean',
        'require_phone_number' => 'boolean',
        'require_at_least_one_product' => 'boolean',
        'require_deal_value' => 'boolean',
        'require_recent_activity' => 'boolean',
    ];

    public function stage()
    {
        return $this->belongsTo(Stage::class);
    }
}
