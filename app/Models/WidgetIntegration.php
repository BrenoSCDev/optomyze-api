<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a widget type as installed for a company.
 *
 * Mirrors the other integration models (company_id + is_active + the same
 * scopes) so IntegrationController@available can treat it like the rest.
 */
class WidgetIntegration extends Model
{
    use HasFactory;

    protected $table = 'widget_integrations';

    /** The widget types that can be installed — matches widgets.type. */
    public const TYPES = ['form', 'chat'];

    protected $fillable = [
        'company_id',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /** 'form-widget' → 'form'. The integration key the frontend card uses. */
    public static function typeFromIntegrationKey(string $key): ?string
    {
        $type = str_replace('-widget', '', $key);

        return in_array($type, self::TYPES, true) ? $type : null;
    }
}
