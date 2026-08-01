<?php

namespace App\Services\Widgets;

use App\Models\Funnel;
use App\Models\Stage;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Website Chat Widget.
 *
 * Config shape:
 *   theme          → primary_color, icon, position, launcher_text
 *   content        → header_title, header_subtitle, welcome_message,
 *                    online_text, offline_text, input_placeholder
 *   branding       → agent_avatar_url, company_logo_url (both optional)
 *   identification → mode (none|basic|full) + per-field required/optional/hidden
 *   mapping        → funnel_id / stage_id / assigned_user_id / assigned_team_id
 *   behavior       → auto_open_delay, remember_state
 *
 * Visitor traffic is handled by WebsiteChatService: this class only owns the
 * configuration contract, exactly as FormWidgetType does for forms.
 */
class ChatWidgetType implements WidgetType
{
    public const IDENTIFICATION_MODES = ['none', 'basic', 'full'];

    /** Fields a pre-chat form may ask for, and the states each can take. */
    public const IDENTITY_FIELDS = ['name', 'email', 'phone'];
    public const FIELD_STATES = ['required', 'optional', 'hidden'];

    /** Fields offered by each identification mode. */
    public const MODE_FIELDS = [
        'none' => [],
        'basic' => ['name', 'email'],
        'full' => ['name', 'email', 'phone'],
    ];

    public function key(): string
    {
        return 'chat';
    }

    public function defaultConfig(): array
    {
        return [
            'theme' => [
                'primary_color' => '#7C3AED',
                'icon' => 'message-circle',
                'position' => 'bottom-right',
                'launcher_text' => 'Chat with us',
            ],
            'content' => [
                'header_title' => 'Chat with us',
                'header_subtitle' => 'We usually reply in a few minutes',
                'welcome_message' => 'Hi there! 👋 How can we help you today?',
                'online_text' => 'Online',
                'offline_text' => 'We are away — leave a message and we will reply by email.',
                'input_placeholder' => 'Write a message…',
            ],
            'branding' => [
                'agent_avatar_url' => null,
                'company_logo_url' => null,
            ],
            'identification' => [
                'mode' => 'basic',
                'fields' => [
                    'name' => 'required',
                    'email' => 'required',
                    'phone' => 'hidden',
                ],
                'intro' => 'Tell us who you are so we can get back to you.',
                'submit_text' => 'Start chat',
            ],
            'mapping' => [
                'funnel_id' => null,
                'stage_id' => null,
                'assigned_user_id' => null,
                'assigned_team_id' => null,
            ],
            'behavior' => [
                'auto_open_delay' => 0,
                'remember_state' => true,
            ],
            'spam' => [
                'captcha' => ['provider' => 'none'],
            ],
        ];
    }

    public function normalizeConfig(array $config, int $companyId): array
    {
        $validator = Validator::make($config, [
            'theme.primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme.icon' => ['required', 'string', 'in:' . implode(',', FormWidgetType::ICONS)],
            'theme.position' => ['required', 'string', 'in:' . implode(',', FormWidgetType::POSITIONS)],
            'theme.launcher_text' => ['nullable', 'string', 'max:40'],

            'content.header_title' => ['required', 'string', 'max:60'],
            'content.header_subtitle' => ['nullable', 'string', 'max:120'],
            'content.welcome_message' => ['required', 'string', 'max:500'],
            'content.online_text' => ['nullable', 'string', 'max:60'],
            'content.offline_text' => ['nullable', 'string', 'max:200'],
            'content.input_placeholder' => ['nullable', 'string', 'max:80'],

            'branding.agent_avatar_url' => ['nullable', 'url', 'max:500'],
            'branding.company_logo_url' => ['nullable', 'url', 'max:500'],

            'identification.mode' => ['required', 'string', 'in:' . implode(',', self::IDENTIFICATION_MODES)],
            'identification.fields.name' => ['required', 'string', 'in:' . implode(',', self::FIELD_STATES)],
            'identification.fields.email' => ['required', 'string', 'in:' . implode(',', self::FIELD_STATES)],
            'identification.fields.phone' => ['required', 'string', 'in:' . implode(',', self::FIELD_STATES)],
            'identification.intro' => ['nullable', 'string', 'max:200'],
            'identification.submit_text' => ['nullable', 'string', 'max:40'],

            'mapping.funnel_id' => ['required', 'integer'],
            'mapping.stage_id' => ['required', 'integer'],
            'mapping.assigned_user_id' => ['nullable', 'integer'],
            'mapping.assigned_team_id' => ['nullable', 'integer'],

            'behavior.auto_open_delay' => ['nullable', 'integer', 'min:0', 'max:120'],
            'behavior.remember_state' => ['boolean'],
        ]);

        $validator->after(function ($validator) use ($config, $companyId) {
            $mode = Arr::get($config, 'identification.mode');
            $offered = self::MODE_FIELDS[$mode] ?? [];

            // At least one visible field, otherwise the pre-chat form is an
            // empty box the visitor can't get past.
            if ($mode !== 'none') {
                $visible = array_filter(
                    $offered,
                    fn ($field) => Arr::get($config, "identification.fields.{$field}") !== 'hidden'
                );

                if (empty($visible)) {
                    $validator->errors()->add(
                        'config.identification.fields',
                        'Show at least one field, or switch to "No identification".'
                    );
                }
            }

            $funnel = Funnel::where('id', Arr::get($config, 'mapping.funnel_id'))
                ->where('company_id', $companyId)
                ->first();

            if (!$funnel) {
                $validator->errors()->add('config.mapping.funnel_id', 'Select a funnel that belongs to your company.');
                return;
            }

            if (!Stage::where('id', Arr::get($config, 'mapping.stage_id'))->where('funnel_id', $funnel->id)->exists()) {
                $validator->errors()->add('config.mapping.stage_id', 'Select a stage that belongs to the chosen funnel.');
            }

            $assignedUserId = Arr::get($config, 'mapping.assigned_user_id');

            if ($assignedUserId && !User::where('id', $assignedUserId)->where('company_id', $companyId)->exists()) {
                $validator->errors()->add('config.mapping.assigned_user_id', 'Select a user from your company.');
            }
        });

        $validator->validate();

        $mode = $config['identification']['mode'];

        // Fields the chosen mode doesn't offer are forced hidden, so switching
        // modes can never leave a stale field showing on the website.
        $fields = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            $fields[$field] = in_array($field, self::MODE_FIELDS[$mode], true)
                ? $config['identification']['fields'][$field]
                : 'hidden';
        }

        return [
            'theme' => [
                'primary_color' => $config['theme']['primary_color'],
                'icon' => $config['theme']['icon'],
                'position' => $config['theme']['position'],
                'launcher_text' => Arr::get($config, 'theme.launcher_text') ?: 'Chat with us',
            ],
            'content' => [
                'header_title' => $config['content']['header_title'],
                'header_subtitle' => Arr::get($config, 'content.header_subtitle', ''),
                'welcome_message' => $config['content']['welcome_message'],
                'online_text' => Arr::get($config, 'content.online_text') ?: 'Online',
                'offline_text' => Arr::get($config, 'content.offline_text', ''),
                'input_placeholder' => Arr::get($config, 'content.input_placeholder') ?: 'Write a message…',
            ],
            'branding' => [
                'agent_avatar_url' => Arr::get($config, 'branding.agent_avatar_url') ?: null,
                'company_logo_url' => Arr::get($config, 'branding.company_logo_url') ?: null,
            ],
            'identification' => [
                'mode' => $mode,
                'fields' => $fields,
                'intro' => Arr::get($config, 'identification.intro', ''),
                'submit_text' => Arr::get($config, 'identification.submit_text') ?: 'Start chat',
            ],
            'mapping' => [
                'funnel_id' => (int) $config['mapping']['funnel_id'],
                'stage_id' => (int) $config['mapping']['stage_id'],
                'assigned_user_id' => Arr::get($config, 'mapping.assigned_user_id') ?: null,
                'assigned_team_id' => Arr::get($config, 'mapping.assigned_team_id') ?: null,
            ],
            'behavior' => [
                'auto_open_delay' => (int) Arr::get($config, 'behavior.auto_open_delay', 0),
                'remember_state' => (bool) Arr::get($config, 'behavior.remember_state', true),
            ],
            'spam' => [
                'captcha' => ['provider' => Arr::get($config, 'spam.captcha.provider', 'none')],
            ],
        ];
    }

    public function publicConfig(Widget $widget): array
    {
        $config = $widget->config;

        return [
            'public_id' => $widget->public_id,
            'type' => $widget->type,
            'theme' => $config['theme'] ?? [],
            'content' => $config['content'] ?? [],
            'branding' => $config['branding'] ?? [],
            'identification' => Arr::only($config['identification'] ?? [], ['mode', 'fields', 'intro', 'submit_text']),
            'behavior' => $config['behavior'] ?? ['auto_open_delay' => 0, 'remember_state' => true],
        ];
    }

    /**
     * Visitor traffic for chat widgets is richer than a single submit, so it
     * is served by dedicated endpoints (see PublicWidgetChatController) that
     * delegate to WebsiteChatService.
     */
    public function handleSubmission(Widget $widget, Request $request): array
    {
        return [
            'status' => 'invalid',
            'errors' => ['_' => 'Chat widgets use the /chat endpoints.'],
        ];
    }
}
