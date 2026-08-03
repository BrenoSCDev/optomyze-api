<?php

namespace App\Models;

use App\Services\Storage\CompanyStorage;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'storage_path',
        'filename',
        'display_order',
        'is_primary',
        'alt_text',
        'file_size',
        'mime_type',
        'width',
        'height',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    protected $appends = ['url'];

    // Relationships
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Scopes
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // Accessors
    public function getUrlAttribute()
    {
        return $this->storage_path;
    }

    // Methods
    public function deleteFile()
    {
        if (Storage::disk('local')->exists($this->storage_path)) {
            Storage::disk('local')->delete($this->storage_path);
            CompanyStorage::forget($this->storage_path);
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($image) {
            $image->deleteFile();
        });
    }
}