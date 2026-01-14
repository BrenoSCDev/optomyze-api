<?php

namespace App\Models;

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
        'image_type',
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

    public function scopeByType($query, $type)
    {
        return $query->where('image_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // Accessors
    public function getUrlAttribute()
    {
        return Storage::url($this->storage_path);
    }

    public function getFullUrlAttribute()
    {
        return url(Storage::url($this->storage_path));
    }

    // Methods
    public function deleteFile()
    {
        if (Storage::exists($this->storage_path)) {
            Storage::delete($this->storage_path);
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