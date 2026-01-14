<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleDoc extends Model
{
    protected $fillable = [
        'sale_id',
        'filename',
        'storage_path',
        'mime_type',
        'file_size',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}