<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FunnelManagerQueue extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
        'is_global',
        'current_index',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'is_global'     => 'boolean',
        'current_index' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'funnel_manager_queue_user')
            ->withPivot('order')
            ->withTimestamps()
            ->orderBy('funnel_manager_queue_user.order');
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function scopeFromCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * The company's global queue, if it has designated one.
     *
     * Deliberately does not filter on is_active. This answers "which queue is
     * the global one", a configuration question; whether it is currently
     * rotating is a separate one, and LeadQueueAssignmentService is the single
     * place that decides it — for the funnel's queue and this one alike.
     */
    public static function globalFor(int $companyId): ?self
    {
        return static::fromCompany($companyId)->where('is_global', true)->first();
    }

    /**
     * Make this the company's global queue, demoting whichever held it.
     *
     * There is only ever one. Enforced here rather than by a unique index
     * because "at most one row per company with is_global = true" is a partial
     * uniqueness constraint that MySQL cannot express; the transaction plus
     * lock is what actually keeps two concurrent saves from both winning.
     */
    public function makeGlobal(): void
    {
        DB::transaction(function () {
            static::fromCompany($this->company_id)
                ->where('is_global', true)
                ->whereKeyNot($this->getKey())
                ->lockForUpdate()
                ->update(['is_global' => false]);

            $this->forceFill(['is_global' => true])->save();
        });
    }
}
