<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-defined static value usable in any template — CNPJ, registered
 * address, standard warranty period, governing jurisdiction.
 *
 * Only *static* values live here. Everything derived from CRM data
 * ({{lead.name}}, {{deal.total}}) is declared in code by VariableRegistry,
 * because those are computed from live records rather than typed once.
 */
class DocumentVariable extends Model
{
    protected $fillable = [
        'company_id',
        'key',
        'label',
        'value',
        'group',
        'description',
    ];

    protected $attributes = [
        'group' => 'custom',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function toPublicArray(): array
    {
        return [
            'id'          => $this->id,
            'key'         => $this->key,
            'label'       => $this->label,
            'value'       => $this->value,
            'group'       => $this->group,
            'description' => $this->description,
            // What an author types into a template body.
            'token'       => '{{custom.' . $this->key . '}}',
        ];
    }
}
