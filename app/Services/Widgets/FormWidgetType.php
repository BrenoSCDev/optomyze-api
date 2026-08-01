<?php

namespace App\Services\Widgets;

use App\Events\LeadLiveUpdate;
use App\Jobs\FireIntegrationWebhook;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\Record;
use App\Models\Stage;
use App\Models\Widget;
use App\Models\WidgetSubmission;
use App\Services\LeadQueueAssignmentService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Website Form Widget.
 *
 * Config shape:
 *   theme    → primary_color, icon
 *   content  → title, description, submit_text, success_message
 *   fields[] → the form structure (built-in lead fields + custom fields)
 *   mapping  → funnel_id / stage_id the created lead lands in
 *   spam     → honeypot, min_submit_seconds, captcha (reserved)
 */
class FormWidgetType implements WidgetType
{
    /** Built-in lead fields a form can collect, and where they land on the Lead. */
    public const LEAD_FIELDS = ['name', 'email', 'phone'];

    /** Input types a custom field may use. */
    public const CUSTOM_FIELD_TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'number', 'date'];

    /** How the widget presents itself on the customer's page. */
    public const DISPLAY_MODES = ['inline', 'collapsible', 'floating'];

    /** Screen corner used by the floating launcher. */
    public const POSITIONS = ['bottom-right', 'bottom-left'];

    /** Icons the embed script knows how to draw. */
    public const ICONS = [
        'message-circle', 'mail', 'user', 'phone', 'calendar',
        'star', 'rocket', 'heart', 'sparkles', 'briefcase',
    ];

    public function key(): string
    {
        return 'form';
    }

    public function defaultConfig(): array
    {
        return [
            'theme' => [
                'primary_color' => '#7C3AED',
                'icon' => 'message-circle',
                'display' => 'inline',
                'launcher_text' => 'Contact us',
                'position' => 'bottom-right',
            ],
            'content' => [
                'title' => 'Get in touch',
                'description' => 'Leave your details and we will get back to you shortly.',
                'submit_text' => 'Send',
                'success_message' => 'Thanks! We have received your message and will be in touch soon.',
            ],
            'fields' => [
                [
                    'key' => 'name',
                    'source' => 'lead',
                    'type' => 'text',
                    'label' => 'Name',
                    'placeholder' => 'Your full name',
                    'required' => true,
                    'enabled' => true,
                ],
                [
                    'key' => 'email',
                    'source' => 'lead',
                    'type' => 'text',
                    'label' => 'Email',
                    'placeholder' => 'you@company.com',
                    'required' => true,
                    'enabled' => true,
                ],
                [
                    'key' => 'phone',
                    'source' => 'lead',
                    'type' => 'text',
                    'label' => 'Phone',
                    'placeholder' => '+1 555 000 0000',
                    'required' => false,
                    'enabled' => true,
                ],
            ],
            'mapping' => [
                'funnel_id' => null,
                'stage_id' => null,
            ],
            'spam' => [
                'honeypot' => true,
                'min_submit_seconds' => 2,
                'captcha' => ['provider' => 'none'],
            ],
        ];
    }

    public function normalizeConfig(array $config, int $companyId): array
    {
        $validator = Validator::make($config, [
            'theme.primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.icon' => ['required', 'string', 'in:' . implode(',', self::ICONS)],
            'theme.display' => ['nullable', 'string', 'in:' . implode(',', self::DISPLAY_MODES)],
            'theme.launcher_text' => ['nullable', 'string', 'max:40'],
            'theme.position' => ['nullable', 'string', 'in:' . implode(',', self::POSITIONS)],

            'content.title' => ['nullable', 'string', 'max:120'],
            'content.description' => ['nullable', 'string', 'max:500'],
            'content.submit_text' => ['required', 'string', 'max:60'],
            'content.success_message' => ['required', 'string', 'max:500'],

            'fields' => ['required', 'array', 'min:1'],
            'fields.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.source' => ['required', 'in:lead,custom'],
            'fields.*.type' => ['required', 'in:' . implode(',', self::CUSTOM_FIELD_TYPES)],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:120'],
            'fields.*.help_text' => ['nullable', 'string', 'max:200'],
            'fields.*.required' => ['boolean'],
            'fields.*.enabled' => ['boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*.label' => ['required_with:fields.*.options', 'string', 'max:120'],
            'fields.*.options.*.value' => ['required_with:fields.*.options', 'string', 'max:120'],
            'fields.*.validation.min' => ['nullable', 'numeric'],
            'fields.*.validation.max' => ['nullable', 'numeric'],
            'fields.*.validation.max_length' => ['nullable', 'integer', 'min:1', 'max:5000'],

            'mapping.funnel_id' => ['required', 'integer'],
            'mapping.stage_id' => ['required', 'integer'],

            'spam.honeypot' => ['boolean'],
            'spam.min_submit_seconds' => ['integer', 'min:0', 'max:60'],
        ]);

        $validator->after(function ($validator) use ($config, $companyId) {
            $fields = $config['fields'] ?? [];

            // Field keys become the CRM custom-field keys, so they must be unique.
            $keys = array_column($fields, 'key');
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('config.fields', 'Field names must be unique.');
            }

            foreach ($fields as $index => $field) {
                if (($field['source'] ?? null) === 'lead' && !in_array($field['key'] ?? '', self::LEAD_FIELDS, true)) {
                    $validator->errors()->add("config.fields.{$index}.key", 'Unknown built-in lead field.');
                }

                if (in_array($field['type'] ?? '', ['select', 'radio'], true) && empty($field['options'])) {
                    $validator->errors()->add("config.fields.{$index}.options", 'This field type needs at least one option.');
                }
            }

            // A form must still collect something identifiable.
            $enabledLeadKeys = collect($fields)
                ->filter(fn ($f) => ($f['source'] ?? null) === 'lead' && ($f['enabled'] ?? true))
                ->pluck('key')
                ->all();

            if (!in_array('name', $enabledLeadKeys, true)) {
                $validator->errors()->add('config.fields', 'The Name field is required to create a lead.');
            }

            if (!array_intersect(['email', 'phone'], $enabledLeadKeys)) {
                $validator->errors()->add('config.fields', 'Enable at least an Email or Phone field so leads are contactable.');
            }

            // The funnel/stage pair must belong to this company.
            $funnelId = Arr::get($config, 'mapping.funnel_id');
            $stageId = Arr::get($config, 'mapping.stage_id');

            $funnel = Funnel::where('id', $funnelId)->where('company_id', $companyId)->first();

            if (!$funnel) {
                $validator->errors()->add('config.mapping.funnel_id', 'Select a funnel that belongs to your company.');
                return;
            }

            if (!Stage::where('id', $stageId)->where('funnel_id', $funnel->id)->exists()) {
                $validator->errors()->add('config.mapping.stage_id', 'Select a stage that belongs to the chosen funnel.');
            }
        });

        $validator->validate();

        return $this->sanitizeConfig($config);
    }

    /**
     * Keep only known keys (and in a stable order) so stored configs stay
     * predictable no matter what the client sends.
     */
    private function sanitizeConfig(array $config): array
    {
        $fields = [];

        foreach (array_values($config['fields']) as $order => $field) {
            $normalized = [
                'key' => $field['key'],
                'source' => $field['source'],
                'type' => $field['type'],
                'label' => $field['label'],
                'placeholder' => $field['placeholder'] ?? '',
                'help_text' => $field['help_text'] ?? '',
                'required' => (bool) ($field['required'] ?? false),
                'enabled' => (bool) ($field['enabled'] ?? true),
                'order' => $order,
            ];

            if (in_array($field['type'], ['select', 'radio'], true)) {
                $normalized['options'] = array_values(array_map(
                    fn ($option) => ['label' => $option['label'], 'value' => $option['value']],
                    $field['options'] ?? []
                ));
            }

            $validation = array_filter([
                'min' => Arr::get($field, 'validation.min'),
                'max' => Arr::get($field, 'validation.max'),
                'max_length' => Arr::get($field, 'validation.max_length'),
            ], fn ($value) => $value !== null && $value !== '');

            if (!empty($validation)) {
                $normalized['validation'] = $validation;
            }

            $fields[] = $normalized;
        }

        return [
            'theme' => [
                'primary_color' => $config['theme']['primary_color'],
                'icon' => $config['theme']['icon'],
                'display' => Arr::get($config, 'theme.display', 'inline'),
                'launcher_text' => Arr::get($config, 'theme.launcher_text') ?: 'Contact us',
                'position' => Arr::get($config, 'theme.position', 'bottom-right'),
            ],
            'content' => [
                'title' => $config['content']['title'] ?? '',
                'description' => $config['content']['description'] ?? '',
                'submit_text' => $config['content']['submit_text'],
                'success_message' => $config['content']['success_message'],
            ],
            'fields' => $fields,
            'mapping' => [
                'funnel_id' => (int) $config['mapping']['funnel_id'],
                'stage_id' => (int) $config['mapping']['stage_id'],
            ],
            'spam' => [
                'honeypot' => (bool) Arr::get($config, 'spam.honeypot', true),
                'min_submit_seconds' => (int) Arr::get($config, 'spam.min_submit_seconds', 2),
                'captcha' => ['provider' => Arr::get($config, 'spam.captcha.provider', 'none')],
            ],
        ];
    }

    public function publicConfig(Widget $widget): array
    {
        $config = $widget->config;

        $fields = collect($config['fields'] ?? [])
            ->filter(fn ($field) => $field['enabled'] ?? true)
            ->sortBy('order')
            ->map(fn ($field) => Arr::only($field, [
                'key', 'type', 'label', 'placeholder', 'help_text', 'required', 'options', 'validation',
            ]))
            ->values()
            ->all();

        return [
            'public_id' => $widget->public_id,
            'type' => $widget->type,
            'theme' => array_merge(
                ['display' => 'inline', 'launcher_text' => 'Contact us', 'position' => 'bottom-right'],
                $config['theme'] ?? []
            ),
            'content' => $config['content'] ?? [],
            'fields' => $fields,
            'spam' => [
                'honeypot' => (bool) Arr::get($config, 'spam.honeypot', true),
                'min_submit_seconds' => (int) Arr::get($config, 'spam.min_submit_seconds', 2),
                'captcha' => Arr::get($config, 'spam.captcha', ['provider' => 'none']),
            ],
        ];
    }

    public function handleSubmission(Widget $widget, Request $request): array
    {
        $config = $widget->config;
        $fields = collect($config['fields'] ?? [])->filter(fn ($f) => $f['enabled'] ?? true)->sortBy('order');
        $payload = (array) $request->input('data', []);

        // ─── Spam gates ──────────────────────────────────────────────────
        // Bots get a success-looking response so they don't learn to adapt,
        // but nothing is written to the CRM.
        if (Arr::get($config, 'spam.honeypot', true) && filled($request->input('hp'))) {
            $this->recordSubmission($widget, $request, null, WidgetSubmission::STATUS_REJECTED, 'honeypot');

            return ['status' => 'ok', 'message' => Arr::get($config, 'content.success_message', 'Thank you!')];
        }

        $minSeconds = (int) Arr::get($config, 'spam.min_submit_seconds', 0);
        $elapsed = (int) $request->input('elapsed_seconds', PHP_INT_MAX);

        if ($minSeconds > 0 && $elapsed < $minSeconds) {
            $this->recordSubmission($widget, $request, null, WidgetSubmission::STATUS_REJECTED, 'too_fast');

            return ['status' => 'ok', 'message' => Arr::get($config, 'content.success_message', 'Thank you!')];
        }

        // ─── Validate against the configured form structure ──────────────
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $key = "data.{$field['key']}";
            $attributes[$key] = $field['label'];
            $rules[$key] = $this->rulesForField($field);
        }

        $validator = Validator::make(['data' => $payload], $rules, [], $attributes);

        if ($validator->fails()) {
            $this->recordSubmission($widget, $request, null, WidgetSubmission::STATUS_REJECTED, 'validation');

            return [
                'status' => 'invalid',
                'errors' => collect($validator->errors()->messages())
                    ->mapWithKeys(fn ($messages, $key) => [Str::after($key, 'data.') => $messages[0]])
                    ->all(),
            ];
        }

        $clean = $this->sanitizeValues($fields, $payload);

        // ─── Create (or reuse) the lead ──────────────────────────────────
        [$firstName, $lastName] = $this->splitName((string) ($clean['name'] ?? ''));
        $email = $clean['email'] ?? null;
        $phone = $clean['phone'] ?? null;

        $existingLead = $this->findExistingLead($widget->company_id, $email, $phone);
        $isDuplicate = $existingLead !== null;

        if ($isDuplicate) {
            $lead = $existingLead;
        } else {
            $lead = Lead::create([
                'company_id' => $widget->company_id,
                'funnel_id' => Arr::get($config, 'mapping.funnel_id'),
                'stage_id' => Arr::get($config, 'mapping.stage_id'),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'source_platform' => Lead::PLATFORM_WEBSITE,
                'source_type' => Lead::SOURCE_WEB_FORM,
            ]);

            LeadQueueAssignmentService::assign($lead);

            Record::createLeadCreation(
                $lead,
                null,
                Record::SOURCE_WEBHOOK,
                ['widgetName' => $widget->name, 'widget_id' => $widget->id],
                'via_form_widget'
            );

            broadcast(new LeadLiveUpdate('created', $lead));

            FireIntegrationWebhook::dispatch('lead_created', [
                'id' => $lead->id,
                'company_id' => $lead->company_id,
                'funnel_id' => $lead->funnel_id,
                'stage_id' => $lead->stage_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'source' => $lead->source_type,
                'created_at' => $lead->created_at?->toISOString(),
            ]);
        }

        $this->storeCustomFields($widget, $lead, $fields, $clean);

        $this->recordSubmission(
            $widget,
            $request,
            $lead,
            $isDuplicate ? WidgetSubmission::STATUS_DUPLICATE : WidgetSubmission::STATUS_CREATED
        );

        $widget->forceFill([
            'submissions_count' => $widget->submissions_count + 1,
            'last_submission_at' => now(),
        ])->save();

        return [
            'status' => 'ok',
            'message' => Arr::get($config, 'content.success_message', 'Thank you!'),
            'lead_id' => $lead->id,
        ];
    }

    /** Laravel validation rules for one configured field. */
    private function rulesForField(array $field): array
    {
        $rules = [($field['required'] ?? false) ? 'required' : 'nullable'];

        switch ($field['type']) {
            case 'number':
                $rules[] = 'numeric';
                if (($min = Arr::get($field, 'validation.min')) !== null) {
                    $rules[] = 'min:' . $min;
                }
                if (($max = Arr::get($field, 'validation.max')) !== null) {
                    $rules[] = 'max:' . $max;
                }
                break;

            case 'date':
                $rules[] = 'date';
                break;

            case 'checkbox':
                $rules[] = 'boolean';
                break;

            case 'select':
            case 'radio':
                $rules[] = 'string';
                $values = array_column($field['options'] ?? [], 'value');
                if (!empty($values)) {
                    $rules[] = 'in:' . implode(',', $values);
                }
                break;

            default:
                $rules[] = 'string';
                $rules[] = 'max:' . (Arr::get($field, 'validation.max_length') ?: ($field['type'] === 'textarea' ? 5000 : 255));

                if ($field['key'] === 'email' && ($field['source'] ?? null) === 'lead') {
                    $rules[] = 'email';
                }
                break;
        }

        return $rules;
    }

    /** Trim and coerce submitted values to the type the field declares. */
    private function sanitizeValues($fields, array $payload): array
    {
        $clean = [];

        foreach ($fields as $field) {
            $value = $payload[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$field['key']] = match ($field['type']) {
                'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($value) ? $value + 0 : null,
                default => trim(strip_tags((string) $value)),
            };
        }

        return $clean;
    }

    /**
     * A returning visitor shouldn't spawn a second lead: match on email or
     * phone within the company, exactly as the public API does.
     */
    private function findExistingLead(int $companyId, ?string $email, ?string $phone): ?Lead
    {
        $digits = $phone ? PhoneNormalizer::normalize($phone) : null;
        $variants = $digits ? PhoneNormalizer::variants($digits) : null;

        if (!$email && !$variants) {
            return null;
        }

        return Lead::where('company_id', $companyId)
            ->where(function ($query) use ($email, $variants) {
                if ($email) {
                    $query->orWhere('email', $email);
                }
                if ($variants) {
                    $query->orWhereIn('phone', $variants);
                }
            })
            ->first();
    }

    /** Persist every custom field value against the lead. */
    private function storeCustomFields(Widget $widget, Lead $lead, $fields, array $clean): void
    {
        foreach ($fields as $field) {
            if (($field['source'] ?? null) !== 'custom') {
                continue;
            }

            $value = $clean[$field['key']] ?? null;

            if ($value === null) {
                continue;
            }

            LeadCustomField::updateOrCreate(
                [
                    'company_id' => $widget->company_id,
                    'lead_id' => $lead->id,
                    'field_key' => $field['key'],
                    'type' => 'lead',
                ],
                [
                    'field_type' => $this->customFieldType($field['type']),
                    'field_value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                ]
            );
        }
    }

    /** Map a form input type onto the lead_custom_fields storage type. */
    private function customFieldType(string $type): string
    {
        return match ($type) {
            'number' => 'number',
            'checkbox' => 'boolean',
            'date' => 'date',
            default => 'text',
        };
    }

    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            return ['Unknown', null];
        }

        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private function recordSubmission(
        Widget $widget,
        Request $request,
        ?Lead $lead,
        string $status,
        ?string $rejectionReason = null
    ): void {
        WidgetSubmission::create([
            'widget_id' => $widget->id,
            'company_id' => $widget->company_id,
            'lead_id' => $lead?->id,
            'status' => $status,
            'rejection_reason' => $rejectionReason,
            'payload' => (array) $request->input('data', []),
            'origin' => $request->header('Origin') ?: $request->header('Referer'),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }
}
