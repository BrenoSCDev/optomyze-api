<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVariable;
use App\Models\Lead;
use App\Models\LeadCompany;
use App\Models\LeadCustomField;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;

/**
 * Builds the frozen context a template renders against.
 *
 * "Frozen" is the important word. Everything a template can reach is read from
 * the database once, here, and handed over as a plain array. The renderer
 * cannot lazily load a relation, call a model method, or reach anything that
 * was not deliberately exposed — which is both a security boundary and the
 * reason a generated version can be explained years later: the resolved values
 * are stored alongside it.
 *
 * Numbers stay numbers. `deal.total` is `15000.0` so `{% if deal.total > 10000 %}`
 * works; `deal.total_formatted` is `R$ 15.000,00` for printing. Formatting a
 * value that a condition then compares is the classic way this kind of engine
 * silently breaks.
 */
class VariableResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(Document $document): array
    {
        $document->loadMissing([
            'company', 'type', 'owner',
            'lead.leadCompany', 'leadCompany',
            'sale.items', 'sale.discounts', 'sale.charges',
        ]);

        $company  = $document->company;
        $currency = $document->currency
            ?? $document->sale?->currency
            ?? $company?->currency
            ?? 'BRL';

        $language = strtoupper((string) ($company?->language ?? 'PT'));

        return [
            'company'     => $this->company($document),
            'lead'        => $this->lead($document->lead),
            'customer'    => $this->customer($document->leadCompany ?? $document->lead?->leadCompany),
            'deal'        => $this->deal($document->sale, $currency, $language),
            'salesperson' => $this->user($document->owner ?? $document->creator),
            'document'    => $this->document($document, $currency, $language),
            'custom'      => $this->customVariables($document->company_id),
            'today'       => $this->formatDate(Carbon::now($company?->timezone ?? config('app.timezone')), $language),
            'now'         => $this->formatDateTime(Carbon::now($company?->timezone ?? config('app.timezone')), $language),
        ];
    }

    /* ============================================================
     | Sections
     ============================================================ */

    /**
     * The company issuing the document.
     *
     * Address and tax id are not columns on `companies` — they exist nowhere
     * in the CRM's own schema. They are exactly the "company-defined static
     * value" that `document_variables` was created to hold, so they resolve
     * from there and simply appear under the `company.` namespace, which is
     * where an author looks for them.
     *
     * Custom values cannot shadow a real column: the CRM's own record of the
     * company name wins over anything typed into a settings screen, so a
     * stale custom entry cannot quietly rename the company on a contract.
     */
    private function company(Document $document): array
    {
        $company = $document->company;
        $custom  = $this->customVariables($document->company_id);

        $fromCustom = fn (string ...$keys) => (string) collect($keys)
            ->map(fn ($key) => $custom[$key] ?? null)
            ->first(fn ($value) => filled($value)) ?? '';

        return [
            'name'      => $company?->name ?? '',
            'email'     => $company?->email ?? '',
            'phone'     => $company?->phone ?? '',
            'website'   => $company?->website ?? '',
            'currency'  => $company?->currency ?? '',
            'address'   => $fromCustom('company_address', 'address', 'endereco'),
            'tax_id'    => $fromCustom('company_tax_id', 'cnpj', 'tax_id'),
            'legal_name' => $fromCustom('company_legal_name', 'razao_social') ?: ($company?->name ?? ''),
        ];
    }

    private function lead(?Lead $lead): array
    {
        if (!$lead) {
            return [];
        }

        $name = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));

        return [
            'name'        => $name !== '' ? $name : ($lead->username ?? ''),
            'first_name'  => $lead->first_name ?? '',
            'last_name'   => $lead->last_name ?? '',
            'email'       => $lead->email ?? '',
            'phone'       => $lead->phone ?? '',
            'address'     => $this->joinAddress([
                $lead->address_line_1,
                $lead->address_line_2,
                $lead->neighborhood,
                $lead->city,
                $lead->state_code ?: $lead->state,
                $lead->postal_code,
                $lead->country,
            ]),
            'city'        => $lead->city ?? '',
            'state'       => $lead->state ?? '',
            'postal_code' => $lead->postal_code ?? '',
            'country'     => $lead->country ?? '',
            'custom'      => $this->leadCustomFields($lead),
        ];
    }

    /**
     * Custom fields are EAV rows keyed by `field_key`, so they arrive as
     * `lead.custom.<key>`. Keys are used verbatim — an author picks them from
     * the same list the CRM shows on the contact.
     */
    private function leadCustomFields(Lead $lead): array
    {
        return LeadCustomField::where('lead_id', $lead->id)
            ->pluck('field_value', 'field_key')
            ->all();
    }

    private function customer(?LeadCompany $customer): array
    {
        if (!$customer) {
            return [];
        }

        return [
            'legal_name' => $customer->legal_name ?? '',
            'trade_name' => $customer->trade_name ?? '',
            'tax_id'     => $customer->tax_id ?? '',
            'industry'   => $customer->industry ?? '',
            'email'      => $customer->email ?? '',
            'phone'      => $customer->phone ?? '',
            'website'    => $customer->website ?? '',
            'address'    => $this->joinAddress([
                $customer->street,
                $customer->number,
                $customer->complement,
                $customer->neighborhood,
                $customer->city,
                $customer->state,
                $customer->postal_code,
                $customer->country,
            ]),
            'city'        => $customer->city ?? '',
            'state'       => $customer->state ?? '',
            'postal_code' => $customer->postal_code ?? '',
            'country'     => $customer->country ?? '',
        ];
    }

    /**
     * The deal, plus its line items shaped for a product table.
     *
     * Totals come from the Sale's own accessors where they exist, so a
     * contract quotes exactly the figure the deal screen shows — recomputing
     * them here would be a second source of truth for money.
     */
    private function deal(?Sale $sale, string $currency, string $language): array
    {
        if (!$sale) {
            return [];
        }

        $subtotal      = (float) ($sale->subtotal ?? 0);
        $discountTotal = (float) ($sale->discount_total ?? 0);
        $total         = (float) ($sale->total ?? 0);
        $finalTotal    = (float) ($sale->final_total ?? $total);
        $value         = (float) ($sale->reference_value ?? $total);

        return [
            'name'                 => $sale->name ?? '',
            'status'               => $sale->status ?? '',
            'currency'             => $sale->currency ?? $currency,

            'value'                => $value,
            'value_formatted'      => $this->money($value, $currency, $language),
            'subtotal'             => $subtotal,
            'subtotal_formatted'   => $this->money($subtotal, $currency, $language),
            'discount_total'       => $discountTotal,
            'discount_formatted'   => $this->money($discountTotal, $currency, $language),
            'total'                => $total,
            'total_formatted'      => $this->money($total, $currency, $language),
            'final_total'          => $finalTotal,
            'final_total_formatted' => $this->money($finalTotal, $currency, $language),

            'items' => $sale->items->map(fn ($item) => [
                'product_name'           => $item->product_name ?? '',
                'quantity'               => (float) $item->quantity,
                'unit_price'             => (float) $item->unit_price,
                'unit_price_formatted'   => $this->money((float) $item->unit_price, $currency, $language),
                'total_price'            => (float) $item->total_price,
                'total_price_formatted'  => $this->money((float) $item->total_price, $currency, $language),
            ])->values()->all(),

            'item_count' => $sale->items->count(),
        ];
    }

    private function user(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'name'  => $user->name ?? '',
            'email' => $user->email ?? '',
        ];
    }

    private function document(Document $document, string $currency, string $language): array
    {
        $value = $document->value !== null ? (float) $document->value : null;

        return [
            'number'          => $document->number ?? '',
            'title'           => $document->title ?? '',
            'type'            => $document->type?->label ?? '',
            'value'           => $value,
            'value_formatted' => $value !== null ? $this->money($value, $currency, $language) : '',
            'currency'        => $currency,
            'start_date'      => $document->start_date ? $this->formatDate($document->start_date, $language) : '',
            'end_date'        => $document->end_date ? $this->formatDate($document->end_date, $language) : '',
            'renewal_date'    => $document->renewal_date ? $this->formatDate($document->renewal_date, $language) : '',
        ];
    }

    private function customVariables(int $companyId): array
    {
        return DocumentVariable::fromCompany($companyId)->pluck('value', 'key')->all();
    }

    /* ============================================================
     | Formatting
     ============================================================ */

    private function joinAddress(array $parts): string
    {
        return implode(', ', array_filter(array_map('trim', array_map('strval', $parts))));
    }

    /**
     * Money follows its **currency's** convention, not the reader's language.
     *
     * This is deliberate and it is the one place the two rules diverge. A BRL
     * amount printed English-style reads as `R$ 2,650.00`, which a Brazilian
     * counterparty parses as two reais and sixty-five centavos — a
     * thousand-fold error inside a legally binding number. Tying the
     * separators to the currency makes the amount unambiguous to whoever the
     * money is actually denominated for, which on a contract matters more
     * than matching the rest of the page's locale.
     *
     * Dates still follow the reader's language; a misread date is recoverable
     * in a way a misread price is not.
     */
    private function money(float $amount, string $currency, string $language): string
    {
        $currency = strtoupper($currency);

        $symbols = ['BRL' => 'R$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol  = $symbols[$currency] ?? $currency;

        // Currencies conventionally written with a comma decimal separator.
        $commaDecimal = ['BRL', 'EUR', 'ARS', 'CLP', 'COP', 'PYG', 'UYU'];

        $formatted = in_array($currency, $commaDecimal, true)
            ? number_format($amount, 2, ',', '.')
            : number_format($amount, 2, '.', ',');

        return trim("{$symbol} {$formatted}");
    }

    private function formatDate(Carbon|\DateTimeInterface $date, string $language): string
    {
        $date = $date instanceof Carbon ? $date : Carbon::instance($date);

        return match ($language) {
            'EN'    => $date->format('m/d/Y'),
            'DE'    => $date->format('d.m.Y'),
            default => $date->format('d/m/Y'),
        };
    }

    private function formatDateTime(Carbon $date, string $language): string
    {
        return $this->formatDate($date, $language) . ' ' . $date->format('H:i');
    }
}
