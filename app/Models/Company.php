<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'website',
        'subscription_plan',
        'is_active',
        'settings',
        'leads_limit',
        'users_limit',
        'storage_limit_gb',
        'active_deals_limit',
        'tasks_limit',
        'integrations_limit',
        'products_limit',
        'product_module',
        'currency' => 'string|size:3',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Get the users for the company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the funnels for the company.
     */
    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    /**
     * Get active users for the company.
     */
    public function activeUsers(): HasMany
    {
        return $this->users()->where('is_active', true);
    }

    /**
     * Get active funnels for the company.
     */
    public function activeFunnels(): HasMany
    {
        return $this->funnels()->where('is_active', true);
    }

    /**
     * Get funnel tags for the company.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Get n8n integrations for the company.
     */
    public function n8nIntegrations(): HasMany
    {
        return $this->hasMany(N8nIntegration::class);
    }

    /**
     * Scope a query to only include active companies.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if company has a specific subscription plan.
     */
    public function hasSubscriptionPlan(string $plan): bool
    {
        return $this->subscription_plan === $plan;
    }

    /**
     * Check if company is on premium or enterprise plan.
     */
    public function isPremium(): bool
    {
        return in_array($this->subscription_plan, ['premium', 'enterprise']);
    }

    /**
     * Get company admins.
     */
    public function admins(): HasMany
    {
        return $this->users()->where('role', 'admin');
    }

    /**
     * Validation rules for company creation.
     */
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:companies,email',
            'phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'subscription_plan' => 'required|in:basic,premium,enterprise',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ];
    }

    /**
     * Validation rules for company update.
     */
    public static function updateValidationRules($id): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:companies,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'subscription_plan' => 'required|in:basic,premium,enterprise',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ];
    }
}