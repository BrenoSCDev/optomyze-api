<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Stage extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'funnel_id',
        'name',
        'description',
        'type',
        'order',
        'color',
        'is_active',
        'settings',
        'template_type',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'order' => 'integer',
    ];

    /**
     * Get the funnel that owns the stage.
     */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /**
     * Get the company through the funnel relationship.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')->through('funnel');
    }

    /**
     * Scope a query to only include active stages.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include stages from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->whereHas('funnel', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        });
    }

    /**
     * Scope a query to order stages by their order field.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }

    /**
     * Check if this is the first stage in the funnel.
     */
    public function isFirst(): bool
    {
        return $this->order === 1;
    }

    /**
     * Check if this is the last stage in the funnel.
     */
    public function isLast(): bool
    {
        $maxOrder = $this->funnel->stages()->max('order');
        return $this->order === $maxOrder;
    }

    /**
     * Get the next stage in the funnel.
     */
    public function nextStage(): ?Stage
    {
        return $this->funnel->stages()
            ->where('order', '>', $this->order)
            ->ordered()
            ->first();
    }

    /**
     * Get the previous stage in the funnel.
     */
    public function previousStage(): ?Stage
    {
        return $this->funnel->stages()
            ->where('order', '<', $this->order)
            ->orderBy('order', 'desc')
            ->first();
    }

    /**
     * Move stage to a new order position.
     */
    public function moveToOrder(int $newOrder): bool
    {
        if ($newOrder === $this->order) {
            return true;
        }

        $maxOrder = $this->funnel->stages()->max('order') ?? 0;
        
        if ($newOrder < 1 || $newOrder > $maxOrder) {
            return false;
        }

        // Start transaction to ensure data consistency
        return DB::transaction(function () use ($newOrder) {
            $oldOrder = $this->order;
            
            if ($newOrder < $oldOrder) {
                // Moving up - shift other stages down
                $this->funnel->stages()
                    ->where('order', '>=', $newOrder)
                    ->where('order', '<', $oldOrder)
                    ->increment('order');
            } else {
                // Moving down - shift other stages up
                $this->funnel->stages()
                    ->where('order', '>', $oldOrder)
                    ->where('order', '<=', $newOrder)
                    ->decrement('order');
            }
            
            // Update this stage's order
            $this->order = $newOrder;
            return $this->save();
        });
    }

    /**
     * Get stages that come after this stage.
     */
    public function subsequentStages()
    {
        return $this->funnel->stages()
            ->where('order', '>', $this->order)
            ->ordered();
    }

    /**
     * Get stages that come before this stage.
     */
    public function precedingStages()
    {
        return $this->funnel->stages()
            ->where('order', '<', $this->order)
            ->ordered();
    }

    /**
     * Get all leads in this stage.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Get all entry requirements in this stage.
     */
    public function entryRequirement()
    {
        return $this->hasOne(EntryRequirement::class);
    }
    
    /**
     * Get active leads in this stage.
     */
    public function activeLeads(): HasMany
    {
        return $this->leads()->where('is_active', true);
    }

    /**
     * Validation rules for stage creation.
     */
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:entry,normal,service,proposition,qualified,conversion,lost',
            'color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/'
            ],
            'template_type' => 'nullable|in:stage,model',
            'settings' => 'nullable|array',
            'settings.sla_hours' => 'nullable|integer|min:1',
            'settings.auto_assign' => 'nullable|boolean',
            'settings.notifications_enabled' => 'nullable|boolean',
            'settings.required_fields' => 'nullable|array',
        ];
    }

    /**
     * Validation rules for stage update.
     */
    public static function updateValidationRules(): array
    {
        return [
            'name' => 'string|max:100',
            'description' => 'nullable|string|max:1000',
            'type' => 'in:entry,normal,service,proposition,qualified,conversion,lost',
            'color' => [
                'nullable',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/'
            ],
            'is_active' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        // Automatically set order if not provided
        static::creating(function (Stage $stage) {
            if (!$stage->order) {
                $maxOrder = Stage::where('funnel_id', $stage->funnel_id)->max('order') ?? 0;
                $stage->order = $maxOrder + 1;
            }
        });

        // Reorder remaining stages when a stage is deleted
        static::deleted(function (Stage $stage) {
            if ($stage->isForceDeleting()) {
                // Permanent delete - reorder remaining stages
                Stage::where('funnel_id', $stage->funnel_id)
                    ->where('order', '>', $stage->order)
                    ->decrement('order');
            }
        });

        // Handle restoration
        static::restored(function (Stage $stage) {
            // When restoring, place at the end to avoid conflicts
            $maxOrder = Stage::where('funnel_id', $stage->funnel_id)->max('order') ?? 0;
            $stage->order = $maxOrder + 1;
            $stage->save();
        });
    }
}