<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunnelManagerQueue extends Model
{
    use HasCompanyTimezone;

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
        'current_index',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
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
}
