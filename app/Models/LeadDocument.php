<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeadDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'title',
        'description',
        'path',
    ];

    /* ============================
     | Relationships
     ============================ */

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
