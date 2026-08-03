<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasCompanyTimezone, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'website',
        'logo_path',
        'subscription_plan',
        'is_active',
        'settings',
        'product_module',
        'contact_pipeline_enabled',
        'leads_limit',
        'users_limit',
        'storage_limit_gb',
        'storage_used_bytes',
        'active_deals_limit',
        'tasks_limit',
        'integrations_limit',
        'products_limit',
        'sales_funnels_limit',
        'deal_funnels_limit',
        'companies_limit',
        'messages_limit',
        'api_tokens_limit',
        'api_requests_limit',
        'api_requests_count',
        'api_requests_reset_at',
        'timezone',
        'currency',
        'language',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active'              => 'boolean',
        'contact_pipeline_enabled' => 'boolean',
        'settings'               => 'array',
        'api_requests_reset_at'  => 'datetime',
    ];

    /**
     * Give every new company its Quickstart checklist.
     *
     * On the model rather than in the registration controller because
     * companies are also created by seeders, factories and the admin path;
     * a hook here covers all of them.
     */
    protected static function booted(): void
    {
        static::created(function (Company $company) {
            $company->quickstart()->create();

            // Every company always has exactly one default calendar. Created
            // here so seeders, factories and the admin path all get one.
            $company->calendars()->create([
                'name'        => 'Company Calendar',
                'description' => 'The default calendar for ' . $company->name . '.',
                'type'        => 'company',
                'visibility'  => 'company',
                'timezone'    => $company->timezone,
                'is_default'  => true,
                'is_active'   => true,
            ]);
        });
    }

    public function quickstart(): HasOne
    {
        return $this->hasOne(CompanyQuickstart::class);
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    /** Ledger of every file stored for this company. */
    public function files(): HasMany
    {
        return $this->hasMany(CompanyFile::class);
    }

    /** This company's default calendar; created if it has gone missing. */
    public function defaultCalendar(): Calendar
    {
        return Calendar::defaultFor($this->id);
    }

    public static function planLimits(string $plan): array
    {
        return match ($plan) {
            'basic' => [
                'leads_limit'            => 2000,
                'users_limit'            => 2,
                'storage_limit_gb'       => 5,
                'active_deals_limit'     => 50,
                'tasks_limit'            => 1000,
                'integrations_limit'     => 2,
                'products_limit'         => 50,
                'sales_funnels_limit'    => 1,
                'deal_funnels_limit'     => 1,
                'companies_limit'        => 100,
                'messages_limit'         => 10000,
                'api_tokens_limit'       => 2,
                'api_requests_limit'     => 1000,
                'api_token_rate_limit_per_minute' => 60,
            ],

            'premium' => [
                'leads_limit'            => 25000,
                'users_limit'            => 10,
                'storage_limit_gb'       => 50,
                'active_deals_limit'     => 500,
                'tasks_limit'            => 25000,
                'integrations_limit'     => 10,
                'products_limit'         => 500,
                'sales_funnels_limit'    => 5,
                'deal_funnels_limit'     => 5,
                'companies_limit'        => 1000,
                'messages_limit'         => 50000,
                'api_tokens_limit'       => 10,
                'api_requests_limit'     => 10000,
                'api_token_rate_limit_per_minute' => 300,
            ],

            'enterprise' => [
                // null = unlimited
                'leads_limit'            => null,
                'users_limit'            => null,
                'storage_limit_gb'       => 500,
                'active_deals_limit'     => null,
                'tasks_limit'            => null,
                'integrations_limit'     => null,
                'products_limit'         => null,
                'sales_funnels_limit'    => null,
                'deal_funnels_limit'     => null,
                'companies_limit'        => null,
                'messages_limit'         => null,
                'api_tokens_limit'       => null,
                'api_requests_limit'     => null,
                'api_token_rate_limit_per_minute' => null,
            ],
        };
    }


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
            'language' => 'nullable|in:EN,PT,ES,DE',
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
            'language' => 'nullable|in:EN,PT,ES,DE',
        ];
    }
}