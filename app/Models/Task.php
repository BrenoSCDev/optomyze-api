<?php

namespace App\Models;

use App\Support\CompanyTimezoneCache;
use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasCompanyTimezone;

    /**
     * Task has no company_id column — timezone is resolved through its Lead.
     *
     * Resolution order:
     *  1. The `lead` relation is already loaded — delegate to Lead::getCompanyTimezone().
     *  2. lead_id is present — query Lead for company_id, then cache and resolve.
     *  3. Fall back to the application timezone.
     */
    public function getCompanyTimezone(): string
    {
        // 1. Lead relation already loaded — delegate (uses Lead's own cache chain).
        if ($this->relationLoaded('lead') && $this->lead instanceof Lead) {
            return $this->lead->getCompanyTimezone();
        }

        // 2. Resolve via lead_id with per-request cache.
        if ($this->lead_id) {
            $companyId = Lead::where('id', $this->lead_id)->value('company_id');

            if ($companyId) {
                $cached = CompanyTimezoneCache::get((int) $companyId);
                if ($cached !== null) {
                    return $cached;
                }

                $tz = \App\Models\Company::where('id', $companyId)->value('timezone')
                    ?: config('app.timezone');

                CompanyTimezoneCache::set((int) $companyId, $tz);
                return $tz;
            }
        }

        return config('app.timezone');
    }

    protected $fillable = [
        'lead_id',
        'title',
        'description',
        'status',
        'priority',
        'reminder',
        'due_date',
        'completed_at',
        'assigned_to',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relacionamento com Lead
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Usuário responsável pela tarefa
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Usuário que criou a tarefa
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scopes úteis
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }

    /**
     * Marcar como concluída
     */
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
