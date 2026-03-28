<?php
// app/Models/Event.php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected $fillable = [
        'company_id',
        'title',
        'description',
        'type',
        'start_at',
        'end_at',
        'all_day',
        'reminder'
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'all_day' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
