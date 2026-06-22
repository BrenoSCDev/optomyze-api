<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;

class MessageTemplateRenderer
{
    /**
     * Render a user-authored message template, substituting {lead_name},
     * {company_name} and {agent_name} placeholders.
     */
    public static function render(string $template, Lead $lead, ?User $agent = null): string
    {
        $company = $lead->relationLoaded('company') ? $lead->company : Company::find($lead->company_id);

        $leadName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));

        $replacements = [
            '{lead_name}' => $leadName !== '' ? $leadName : "Lead #{$lead->id}",
            '{company_name}' => $company?->name ?? '',
            '{agent_name}' => $agent?->name ?? '',
        ];

        return strtr($template, $replacements);
    }
}
