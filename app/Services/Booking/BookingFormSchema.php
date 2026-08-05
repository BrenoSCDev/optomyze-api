<?php

namespace App\Services\Booking;

use App\Models\Lead;
use Illuminate\Support\Arr;

/**
 * The form a booking page collects before confirming a slot.
 *
 * Intentionally the same shape as the form widget's `fields` array — same keys,
 * same `source: lead|custom` split, same option and validation structure — so
 * the two builders stay interchangeable and a future shared field editor can
 * drive both. What differs is only the default set: a booking always needs a
 * name and an email to send a confirmation to.
 */
class BookingFormSchema
{
    /** Built-in lead fields a booking form can collect. */
    public const LEAD_FIELDS = ['name', 'first_name', 'last_name', 'email', 'phone', 'company', 'notes'];

    /** Input types a field may use. Mirrors FormWidgetType::CUSTOM_FIELD_TYPES. */
    public const FIELD_TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'number', 'date'];

    /**
     * The starting form. Name and email are required and cannot be disabled in
     * practice — a booking with neither is an appointment nobody can be told
     * about — but they are ordinary rows so the builder needs no special case.
     */
    public static function defaultFields(): array
    {
        return [
            [
                'key' => 'name', 'source' => 'lead', 'type' => 'text',
                'label' => 'Full name', 'placeholder' => 'Your full name',
                'required' => true, 'enabled' => true,
            ],
            [
                'key' => 'email', 'source' => 'lead', 'type' => 'text',
                'label' => 'Email', 'placeholder' => 'you@company.com',
                'required' => true, 'enabled' => true,
            ],
            [
                'key' => 'phone', 'source' => 'lead', 'type' => 'text',
                'label' => 'Phone', 'placeholder' => '+1 555 000 0000',
                'required' => false, 'enabled' => true,
            ],
            [
                'key' => 'notes', 'source' => 'lead', 'type' => 'textarea',
                'label' => 'What would you like to discuss?', 'placeholder' => '',
                'required' => false, 'enabled' => true,
            ],
        ];
    }

    /** Validation rules for the stored field definitions. */
    public static function definitionRules(): array
    {
        return [
            'form_fields' => ['sometimes', 'array', 'max:40'],
            'form_fields.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'form_fields.*.source' => ['required', 'in:lead,custom'],
            'form_fields.*.type' => ['required', 'in:' . implode(',', self::FIELD_TYPES)],
            'form_fields.*.label' => ['required', 'string', 'max:120'],
            'form_fields.*.placeholder' => ['nullable', 'string', 'max:120'],
            'form_fields.*.help_text' => ['nullable', 'string', 'max:200'],
            'form_fields.*.required' => ['boolean'],
            'form_fields.*.enabled' => ['boolean'],
            'form_fields.*.options' => ['nullable', 'array', 'max:50'],
            'form_fields.*.options.*.label' => ['required_with:form_fields.*.options', 'string', 'max:120'],
            'form_fields.*.options.*.value' => ['required_with:form_fields.*.options', 'string', 'max:120'],
            'form_fields.*.validation.min' => ['nullable', 'numeric'],
            'form_fields.*.validation.max' => ['nullable', 'numeric'],
            'form_fields.*.validation.max_length' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }

    /** Only the enabled fields, which is all the public page ever sees. */
    public static function enabledFields(?array $fields): array
    {
        return array_values(array_filter(
            $fields ?: self::defaultFields(),
            fn ($field) => (bool) ($field['enabled'] ?? true)
        ));
    }

    /**
     * Laravel rules for an incoming submission, derived from the definitions.
     *
     * Built from the stored config rather than hardcoded so a field the owner
     * marked optional cannot be enforced as required by a stale client, and
     * vice versa — the server decides.
     */
    public static function submissionRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $key = 'answers.' . $field['key'];
            $set = [($field['required'] ?? false) ? 'required' : 'nullable'];

            switch ($field['type'] ?? 'text') {
                case 'number':
                    $set[] = 'numeric';
                    if (($min = Arr::get($field, 'validation.min')) !== null) $set[] = 'min:' . $min;
                    if (($max = Arr::get($field, 'validation.max')) !== null) $set[] = 'max:' . $max;
                    break;
                case 'date':
                    $set[] = 'date';
                    break;
                case 'checkbox':
                    $set[] = 'boolean';
                    break;
                case 'select':
                case 'radio':
                    $values = collect($field['options'] ?? [])->pluck('value')->filter()->all();
                    if ($values) $set[] = 'in:' . implode(',', $values);
                    break;
                default:
                    $set[] = 'string';
                    $set[] = 'max:' . (Arr::get($field, 'validation.max_length') ?: 2000);
            }

            // Email is validated as one wherever it is placed in the form.
            if (($field['key'] ?? null) === 'email') {
                $set[] = 'email:filter';
            }

            $rules[$key] = $set;
        }

        return $rules;
    }

    /**
     * Split the submitted answers into the lead columns they map onto and the
     * remainder, which is kept verbatim as custom data.
     */
    public static function splitAnswers(array $fields, array $answers): array
    {
        $lead = [];
        $extra = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (!$key || !array_key_exists($key, $answers)) {
                continue;
            }

            $value = $answers[$key];

            if (($field['source'] ?? 'custom') === 'lead' && in_array($key, self::LEAD_FIELDS, true)) {
                $lead[$key] = $value;
            } else {
                $extra[$key] = ['label' => $field['label'] ?? $key, 'value' => $value];
            }
        }

        return [$lead, $extra];
    }

    /** "Ada Lovelace" → ['Ada', 'Lovelace']; a single word keeps an empty surname. */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (!$parts || $parts[0] === '') {
            return ['Booking', null];
        }

        $first = array_shift($parts);

        return [$first, $parts ? implode(' ', $parts) : null];
    }

    /** The lead source every booking carries. */
    public static function leadSource(): array
    {
        return [
            'source_platform' => Lead::PLATFORM_CALENDAR,
            'source_type'     => Lead::SOURCE_BOOKING,
        ];
    }
}
