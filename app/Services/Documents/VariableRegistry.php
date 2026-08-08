<?php

namespace App\Services\Documents;

use App\Models\DocumentVariable;

/**
 * The catalogue of variables an author may use.
 *
 * Two sources, deliberately separated:
 *
 *   - **CRM-derived** variables are declared here in code, because they are
 *     computed from live records. A row in a table could not describe
 *     "the sum of this deal's line items".
 *   - **Static** company values (CNPJ, registered address) live in
 *     `document_variables`, because they are typed once and change rarely.
 *
 * This drives the template editor's variable picker, so a declaration here is
 * what makes a variable discoverable. A variable that resolves but is not
 * declared works and is invisible; one declared but not resolved would be a
 * lie, so the two are kept in step by `DocumentEngineTest`.
 *
 * `requires` is what lets the UI warn "this template needs a Deal" before
 * someone attaches it to a lead-only document and gets blanks.
 */
class VariableRegistry
{
    /**
     * @return array<int, array{
     *   group: string,
     *   label: string,
     *   requires: ?string,
     *   variables: array<int, array{token: string, label: string, example?: string}>
     * }>
     */
    public function catalog(?int $companyId = null): array
    {
        $groups = [
            [
                'group'    => 'company',
                'label'    => 'Sua empresa',
                'requires' => null,
                'variables' => [
                    ['token' => 'company.name',     'label' => 'Nome da empresa'],
                    ['token' => 'company.email',    'label' => 'E-mail'],
                    ['token' => 'company.phone',    'label' => 'Telefone'],
                    ['token' => 'company.website',  'label' => 'Site'],
                    // Resolved from the company's own document variables —
                    // there is no address or tax id column on `companies`.
                    // Blank until someone fills them in under Settings.
                    ['token' => 'company.legal_name', 'label' => 'Razão social'],
                    ['token' => 'company.address',    'label' => 'Endereço'],
                    ['token' => 'company.tax_id',     'label' => 'CNPJ'],
                ],
            ],
            [
                'group'    => 'lead',
                'label'    => 'Contato',
                'requires' => 'lead',
                'variables' => [
                    ['token' => 'lead.name',        'label' => 'Nome completo'],
                    ['token' => 'lead.first_name',  'label' => 'Primeiro nome'],
                    ['token' => 'lead.last_name',   'label' => 'Sobrenome'],
                    ['token' => 'lead.email',       'label' => 'E-mail'],
                    ['token' => 'lead.phone',       'label' => 'Telefone'],
                    ['token' => 'lead.address',     'label' => 'Endereço completo'],
                    ['token' => 'lead.city',        'label' => 'Cidade'],
                    ['token' => 'lead.state',       'label' => 'Estado'],
                    ['token' => 'lead.postal_code', 'label' => 'CEP'],
                    ['token' => 'lead.country',     'label' => 'País'],
                    ['token' => 'lead.custom.CHAVE', 'label' => 'Campo personalizado do contato'],
                ],
            ],
            [
                'group'    => 'customer',
                'label'    => 'Empresa do cliente',
                'requires' => 'lead_company',
                'variables' => [
                    ['token' => 'customer.legal_name', 'label' => 'Razão social'],
                    ['token' => 'customer.trade_name', 'label' => 'Nome fantasia'],
                    ['token' => 'customer.tax_id',     'label' => 'CNPJ'],
                    ['token' => 'customer.industry',   'label' => 'Setor'],
                    ['token' => 'customer.email',      'label' => 'E-mail'],
                    ['token' => 'customer.phone',      'label' => 'Telefone'],
                    ['token' => 'customer.address',    'label' => 'Endereço completo'],
                ],
            ],
            [
                'group'    => 'deal',
                'label'    => 'Negociação',
                'requires' => 'sale',
                'variables' => [
                    ['token' => 'deal.name',                'label' => 'Nome da negociação'],
                    ['token' => 'deal.value',               'label' => 'Valor (número, para comparações)'],
                    ['token' => 'deal.value_formatted',     'label' => 'Valor (formatado)'],
                    ['token' => 'deal.subtotal',            'label' => 'Subtotal (número)'],
                    ['token' => 'deal.subtotal_formatted',  'label' => 'Subtotal (formatado)'],
                    ['token' => 'deal.discount_total',      'label' => 'Descontos (número)'],
                    ['token' => 'deal.discount_formatted',  'label' => 'Descontos (formatado)'],
                    ['token' => 'deal.total',               'label' => 'Total (número)'],
                    ['token' => 'deal.total_formatted',     'label' => 'Total (formatado)'],
                    ['token' => 'deal.currency',            'label' => 'Moeda'],
                    ['token' => 'deal.items',               'label' => 'Itens — use em {% for item in deal.items %}'],
                ],
            ],
            [
                'group'    => 'item',
                'label'    => 'Item da negociação (dentro do laço)',
                'requires' => 'sale',
                'variables' => [
                    ['token' => 'item.product_name',      'label' => 'Produto'],
                    ['token' => 'item.quantity',          'label' => 'Quantidade'],
                    ['token' => 'item.unit_price',        'label' => 'Preço unitário (número)'],
                    ['token' => 'item.unit_price_formatted', 'label' => 'Preço unitário (formatado)'],
                    ['token' => 'item.total_price',       'label' => 'Subtotal (número)'],
                    ['token' => 'item.total_price_formatted', 'label' => 'Subtotal (formatado)'],
                    ['token' => 'loop.index',             'label' => 'Número da linha'],
                ],
            ],
            [
                'group'    => 'salesperson',
                'label'    => 'Responsável',
                'requires' => null,
                'variables' => [
                    ['token' => 'salesperson.name',  'label' => 'Nome'],
                    ['token' => 'salesperson.email', 'label' => 'E-mail'],
                ],
            ],
            [
                'group'    => 'document',
                'label'    => 'Documento',
                'requires' => null,
                'variables' => [
                    ['token' => 'document.number',     'label' => 'Número do documento'],
                    ['token' => 'document.title',      'label' => 'Título'],
                    ['token' => 'document.type',       'label' => 'Tipo'],
                    ['token' => 'document.value',      'label' => 'Valor (número)'],
                    ['token' => 'document.value_formatted', 'label' => 'Valor (formatado)'],
                    ['token' => 'document.start_date', 'label' => 'Início da vigência'],
                    ['token' => 'document.end_date',   'label' => 'Fim da vigência'],
                    ['token' => 'today',               'label' => 'Data de hoje'],
                    ['token' => 'now',                 'label' => 'Data e hora'],
                ],
            ],
        ];

        if ($companyId) {
            $custom = DocumentVariable::fromCompany($companyId)->orderBy('key')->get();

            if ($custom->isNotEmpty()) {
                $groups[] = [
                    'group'     => 'custom',
                    'label'     => 'Personalizadas',
                    'requires'  => null,
                    'variables' => $custom->map(fn (DocumentVariable $variable) => [
                        'token' => 'custom.' . $variable->key,
                        'label' => $variable->label,
                    ])->values()->all(),
                ];
            }
        }

        return $groups;
    }

    /**
     * Which CRM records a template needs, from the variables it references.
     * `lead.custom.*` counts as needing a lead.
     *
     * @param string[] $referenced
     * @return string[] e.g. ['sale', 'lead']
     */
    public function requirementsFor(array $referenced): array
    {
        $requirements = [];

        foreach ($referenced as $token) {
            $root = explode('.', $token)[0] ?? '';

            $requirement = match ($root) {
                'lead'     => 'lead',
                'customer' => 'lead_company',
                'deal', 'item' => 'sale',
                default    => null,
            };

            if ($requirement) {
                $requirements[$requirement] = true;
            }
        }

        return array_keys($requirements);
    }
}
