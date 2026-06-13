<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasCompanyTimezone, HasFactory;

    /**
     * The attributes that are mass assignable.
     */ 

    protected $fillable = [
        'company_id',
        'name',
        'color',
    ];

    /**
     * Validation rules for tag creation.
     */

    public static function validationRules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'name' => 'nullable|string|max:100',
            'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
        ];
    }

    /**
     * Validation rules for tag updates.
     */
    public static function updateValidationRules(): array
    {
        return [
            'name' => 'nullable|string|max:100',
            'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
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

    /**
     * Get the leads tagged with this tag.
     */
    public function leads()
    {
        return $this->belongsToMany(Lead::class, 'lead_tag');
    }

    /**
     * Get the deals (sales) tagged with this tag.
     */
    public function sales()
    {
        return $this->belongsToMany(Sale::class, 'sale_tag');
    }
}
