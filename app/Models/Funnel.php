<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Funnel extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_active',
        'created_by',
        'settings',
        'type',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Get the company that owns the funnel.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who created the funnel.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the stages for this funnel.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('order');
    }

    /**
     * Get the instances for this funnel.
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WhatsAppInstance::class)->orderBy('order');
    }

    /**
     * Get total leads count across all stages in the funnel.
     */
    public function totalLeadsCount(): int
    {
        return $this->stages()
            ->withCount('leads')
            ->get()
            ->sum('leads_count');
    }

    /**
     * Get only active stages for this funnel.
     */
    public function activeStages(): HasMany
    {
        return $this->stages()->where('is_active', true);
    }

    /**
     * Scope a query to only include active funnels.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include funnels from the same company.
     */
    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get the number of stages in the funnel.
     */
    public function getStageCountAttribute(): int
    {
        return $this->stages()->count();
    }

    /**
     * Get stage names as an array.
     */
    public function getStageNamesAttribute(): array
    {
        return $this->stages()->pluck('name')->toArray();
    }

    /**
     * Get the first stage of the funnel.
     */
    public function firstStage(): ?Stage
    {
        return $this->stages()->where('order', 1)->first();
    }

    /**
     * Get the last stage of the funnel.
     */
    public function lastStage(): ?Stage
    {
        return $this->stages()->orderBy('order', 'desc')->first();
    }

    /**
     * Check if funnel has a specific stage.
     */
    public function hasStage(string $stageName): bool
    {
        return $this->stages()->where('name', $stageName)->exists();
    }

    /**
     * Get stage by name.
     */
    public function getStageByName(string $stageName): ?Stage
    {
        return $this->stages()->where('name', $stageName)->first();
    }

    /**
     * Get stage by order.
     */
    public function getStageByOrder(int $order): ?Stage
    {
        return $this->stages()->where('order', $order)->first();
    }

    /**
     * Add a new stage to the funnel.
     */
    public function addStage(array $stageData): Stage
    {
        return $this->stages()->create($stageData);
    }

    /**
     * Remove a stage from the funnel.
     */
    public function removeStage(Stage $stage): bool
    {
        if ($stage->funnel_id !== $this->id) {
            return false;
        }

        return $stage->delete();
    }

    /**
     * Reorder all stages in the funnel.
     */
    public function reorderStages(array $stageOrderMap): bool
    {
        return DB::transaction(function () use ($stageOrderMap) {
            foreach ($stageOrderMap as $stageId => $newOrder) {
                $stage = $this->stages()->find($stageId);
                if ($stage) {
                    $stage->update(['order' => $newOrder]);
                }
            }
            return true;
        });
    }

    /**
     * Duplicate this funnel with all its stages.
     */
    public function duplicate(string $newName, int $createdBy): Funnel
    {
        return DB::transaction(function () use ($newName, $createdBy) {
            // Create new funnel
            $newFunnel = $this->replicate();
            $newFunnel->name = $newName;
            $newFunnel->created_by = $createdBy;
            $newFunnel->save();

            // Duplicate all stages
            foreach ($this->stages as $stage) {
                $newStage = $stage->replicate();
                $newStage->funnel_id = $newFunnel->id;
                $newStage->save();
            }

            return $newFunnel;
        });
    }

    /**
     * Get funnel completion statistics.
     */
    public function getCompletionStats(): array
    {
        $stages = $this->stages()->withCount('deals')->get(); // Assuming you'll have deals later
        $totalDeals = $stages->sum('deals_count');

        return [
            'total_stages' => $stages->count(),
            'total_deals' => $totalDeals,
            'stages_breakdown' => $stages->map(function ($stage) use ($totalDeals) {
                return [
                    'stage_id' => $stage->id,
                    'name' => $stage->name,
                    'deals_count' => $stage->deals_count ?? 0,
                    'percentage' => $totalDeals > 0 ? round(($stage->deals_count ?? 0) / $totalDeals * 100, 2) : 0,
                ];
            }),
        ];
    }

    /**
     * Validation rules for funnel creation.
     */
    public static function validationRules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'created_by' => 'required|exists:users,id',
            'settings' => 'nullable|array',
        ];
    }

    /**
     * Validation rules for funnel update.
     */
    public static function updateValidationRules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        // When deleting a funnel, also delete its stages
        static::deleting(function (Funnel $funnel) {
            if ($funnel->isForceDeleting()) {
                // Permanent delete - force delete all stages
                $funnel->stages()->forceDelete();
            } else {
                // Soft delete - soft delete all stages
                $funnel->stages()->delete();
            }
        });

        // When restoring a funnel, also restore its stages
        static::restored(function (Funnel $funnel) {
            $funnel->stages()->restore();
        });
    }
}