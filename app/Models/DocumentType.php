<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What kind of document this is — Service Agreement, NDA, Rental, Consulting.
 *
 * Per-company and user-extensible, like the CRM's other taxonomies. It carries
 * policy as well as a label: whether documents of this kind need signing at
 * all, and whether they need internal approval before they can go out.
 */
class DocumentType extends Model
{
    public const CATEGORY_CONTRACT = 'contract';
    public const CATEGORY_PROPOSAL = 'proposal';
    public const CATEGORY_QUOTE    = 'quote';
    public const CATEGORY_NDA      = 'nda';
    public const CATEGORY_OTHER    = 'other';

    public const CATEGORIES = [
        self::CATEGORY_CONTRACT,
        self::CATEGORY_PROPOSAL,
        self::CATEGORY_QUOTE,
        self::CATEGORY_NDA,
        self::CATEGORY_OTHER,
    ];

    /**
     * Seeded for every company. `is_system` rows can be deactivated or
     * relabelled but not deleted, because documents already reference them.
     *
     * @var array<int, array{key: string, label: string, category: string}>
     */
    public const SYSTEM_TYPES = [
        ['key' => 'service_agreement', 'label' => 'Contrato de Prestação de Serviços', 'category' => self::CATEGORY_CONTRACT],
        ['key' => 'sales_agreement',   'label' => 'Contrato de Venda',                 'category' => self::CATEGORY_CONTRACT],
        ['key' => 'nda',               'label' => 'Acordo de Confidencialidade (NDA)', 'category' => self::CATEGORY_NDA],
        ['key' => 'employment',        'label' => 'Contrato de Trabalho',              'category' => self::CATEGORY_CONTRACT],
        ['key' => 'rental',            'label' => 'Contrato de Locação',               'category' => self::CATEGORY_CONTRACT],
        ['key' => 'maintenance',       'label' => 'Contrato de Manutenção',            'category' => self::CATEGORY_CONTRACT],
        ['key' => 'consulting',        'label' => 'Contrato de Consultoria',           'category' => self::CATEGORY_CONTRACT],
        ['key' => 'subscription',      'label' => 'Contrato de Assinatura',            'category' => self::CATEGORY_CONTRACT],
        ['key' => 'proposal',          'label' => 'Proposta Comercial',                'category' => self::CATEGORY_PROPOSAL],
    ];

    protected $fillable = [
        'company_id',
        'key',
        'label',
        'description',
        'category',
        'is_system',
        'requires_signature',
        'requires_approval',
        'default_template_id',
        'is_active',
        'position',
    ];

    protected $casts = [
        'is_system'          => 'boolean',
        'requires_signature' => 'boolean',
        'requires_approval'  => 'boolean',
        'is_active'          => 'boolean',
        'position'           => 'integer',
    ];

    protected $attributes = [
        'category'           => self::CATEGORY_CONTRACT,
        'is_system'          => false,
        'requires_signature' => true,
        'requires_approval'  => false,
        'is_active'          => true,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class);
    }

    public function defaultTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'default_template_id');
    }

    public function scopeFromCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Create the seeded types for a company that has none yet. Idempotent, so
     * it is safe to call on every visit to the Documents module.
     */
    public static function ensureSystemTypes(int $companyId): void
    {
        foreach (self::SYSTEM_TYPES as $position => $type) {
            self::firstOrCreate(
                ['company_id' => $companyId, 'key' => $type['key']],
                [
                    'label'     => $type['label'],
                    'category'  => $type['category'],
                    'is_system' => true,
                    'position'  => $position,
                ]
            );
        }
    }

    public function toPublicArray(): array
    {
        return [
            'id'                  => $this->id,
            'key'                 => $this->key,
            'label'               => $this->label,
            'description'         => $this->description,
            'category'            => $this->category,
            'is_system'           => $this->is_system,
            'requires_signature'  => $this->requires_signature,
            'requires_approval'   => $this->requires_approval,
            'default_template_id' => $this->default_template_id,
            'is_active'           => $this->is_active,
            'position'            => $this->position,
        ];
    }
}
