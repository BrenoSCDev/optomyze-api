<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-company, per-prefix, per-year counter behind document numbers.
 *
 * Written only through DocumentNumberGenerator, which takes a row lock. Never
 * increment this directly — a contract number appears in correspondence and in
 * court, and two documents sharing one is not a cosmetic bug.
 */
class DocumentNumberSequence extends Model
{
    protected $fillable = [
        'company_id',
        'prefix',
        'year',
        'last_number',
    ];

    protected $casts = [
        'year'        => 'integer',
        'last_number' => 'integer',
    ];
}
