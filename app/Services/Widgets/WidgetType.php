<?php

namespace App\Services\Widgets;

use App\Models\Widget;
use Illuminate\Http\Request;

/**
 * Contract every embeddable widget type implements.
 *
 * Adding a new widget (chat, booking, survey, …) means adding one class that
 * implements this interface and registering it in WidgetTypeRegistry — the
 * table, controllers, embed loader and CRM shell stay untouched.
 */
interface WidgetType
{
    /** Stable key stored in widgets.type. */
    public function key(): string;

    /** Config used when a widget of this type is first created. */
    public function defaultConfig(): array;

    /**
     * Validate + normalize an incoming config payload.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function normalizeConfig(array $config, int $companyId): array;

    /**
     * The subset of the config that is safe to expose to any visitor's browser
     * — never CRM internals such as funnel or stage ids.
     */
    public function publicConfig(Widget $widget): array;

    /**
     * Handle a visitor interaction (a form submit, a chat message, …).
     *
     * @return array{status: string, message?: string, lead_id?: int|null, errors?: array}
     */
    public function handleSubmission(Widget $widget, Request $request): array;
}
