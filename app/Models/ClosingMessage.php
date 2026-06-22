<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClosingMessage extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'message',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function closingReasons(): BelongsToMany
    {
        return $this->belongsToMany(ClosingReason::class);
    }
}
