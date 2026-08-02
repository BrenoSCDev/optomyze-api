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
        'scopes',
    ];

    protected $casts = [
        'scopes' => 'array',
    ];

    /**
     * What a tag can be attached to. Each value maps to one pivot table:
     * lead -> lead_tag, deal -> sale_tag, task -> task_tag.
     */
    public const SCOPES = ['lead', 'deal', 'task'];

    /**
     * Validation rules for tag creation.
     */

    public static function validationRules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'name' => 'nullable|string|max:100',
            'color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'scopes' => 'nullable|array',
            'scopes.*' => 'in:' . implode(',', self::SCOPES),
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
            'scopes' => 'nullable|array',
            'scopes.*' => 'in:' . implode(',', self::SCOPES),
        ];
    }

    /**
     * Restrict to tags usable on a given entity.
     *
     * A null `scopes` means "everywhere" — that is what tags meant before they
     * were scoped, and treating null as an empty set would make a tag created
     * by an older client vanish from every picker.
     */
    public function scopeForUsage($query, ?string $scope)
    {
        if (!$scope || !in_array($scope, self::SCOPES, true)) {
            return $query;
        }

        return $query->where(function ($q) use ($scope) {
            $q->whereNull('scopes')
              ->orWhereJsonContains('scopes', $scope);
        });
    }

    /** True when this tag may be attached to the given entity. */
    public function appliesTo(string $scope): bool
    {
        return $this->scopes === null || in_array($scope, $this->scopes, true);
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

    /**
     * Get the tasks tagged with this tag.
     */
    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_tag');
    }
}
