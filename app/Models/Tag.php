<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */ 

    protected $fillable = [
        'company_id',
        'name',
    ];

    /**
     * Validation rules for tag creation.
     */

    public static function validationRules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'name' => 'nullable|string|max:100',
        ];
    }

    /**
     * Scope a query to only include tags from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Checks if a tag with the same name already exists for the company.
     */
    public static function existsForCompany(int $companyId, string $name): bool
    {
        return self::fromCompany($companyId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)]) // case-insensitive
            ->exists();
    }
}
