<?php

namespace App\Services;

use App\Events\LeadCreatedNotification;
use App\Jobs\SendExternalNotification;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Dispatch a notification event through all enabled channels.
     *
     * @param string $eventKey   Key from notification_defaults.php events (e.g. 'lead_created')
     * @param int    $companyId  Company this notification belongs to
     * @param array  $context    Contextual data:
     *   'lead_owner_id' => ?int    — assigned_to of the lead
     *   'deal_owner_id' => ?int    — user_id of the sale
     *   'actor_name'    => ?string — display name of who triggered the event
     *   'stage_name'    => ?string — destination stage name (stage-change events)
     *   'lead'          => ['id' => int, 'name' => string]
     *   'sale'          => ['id' => int, 'name' => string, 'total' => float]
     *   '_lead_model'   => Lead   — for in-app broadcast only
     *   '_actor_model'  => User   — for in-app broadcast only
     */
    public function dispatch(string $eventKey, int $companyId, array $context = []): void
    {
        $settings = NotificationSetting::where('company_id', $companyId)->first();

        if (!$settings) {
            Log::debug("[Notification] No settings for company {$companyId} — skipping '{$eventKey}'.");
            return;
        }

        // 1. Check if the event is enabled
        $events = $settings->events ?? [];
        if (!($events[$eventKey]['enabled'] ?? false)) {
            Log::debug("[Notification] Event '{$eventKey}' is disabled for company {$companyId}.");
            return;
        }

        // 2. Enforce quiet hours
        if ($this->isQuietHours($settings->quiet_hours ?? [])) {
            Log::debug("[Notification] Quiet hours active for company {$companyId} — skipping '{$eventKey}'.");
            return;
        }

        $channels = $settings->channels ?? [];

        // 3. In-app channel: company-wide broadcast (fired once per event, not per recipient)
        if ($channels['in_app'] ?? false) {
            $this->sendInApp($eventKey, $context, $companyId);
        }

        // 4. External channels (email + WhatsApp) require recipient resolution
        $needsExternal = ($channels['email'] ?? false) || ($channels['whatsapp'] ?? false);

        if ($needsExternal) {
            $recipients = $this->resolveRecipients($settings, $companyId, $context);

            if ($recipients->isEmpty()) {
                Log::debug("[Notification] No eligible recipients for '{$eventKey}' in company {$companyId}.");
                return;
            }

            $message = $this->buildMessage($eventKey, $context);

            foreach ($recipients as $recipient) {
                if ($channels['email'] ?? false) {
                    $this->sendEmail($recipient, $eventKey, $message);
                }

                if ($channels['whatsapp'] ?? false) {
                    $this->sendWhatsApp($recipient, $eventKey, $message);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Recipient resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the set of users who should receive this notification.
     *
     * Reads the company's `default_recipients` settings and maps each enabled
     * key to actual user IDs supplied in the event context:
     *
     *   lead_owner   → the user currently assigned to the lead  (context.lead_owner_id)
     *   deal_owner   → the user who owns the sale              (context.deal_owner_id)
     *   team_manager → the manager(s) of the team(s) the lead/deal owner belongs to
     *                  (resolved via teams.manager_user_id, not by user role)
     *
     * Results are deduplicated so the same user is never notified twice.
     */
    private function resolveRecipients(
        NotificationSetting $settings,
        int $companyId,
        array $context
    ): Collection {
        $defaults = $settings->default_recipients ?? [];
        $ids      = collect();

        // lead_owner — the user assigned to the lead
        if (($defaults['lead_owner'] ?? false) && !empty($context['lead_owner_id'])) {
            $ids->push((int) $context['lead_owner_id']);
        }

        // deal_owner — the user who owns the sale/deal
        if (($defaults['deal_owner'] ?? false) && !empty($context['deal_owner_id'])) {
            $ids->push((int) $context['deal_owner_id']);
        }

        // team_manager — the manager(s) of whichever team(s) the lead/deal owner belongs to.
        // We look up teams that contain either the lead_owner or deal_owner, then collect
        // their manager_user_id. This avoids querying by role and instead follows the actual
        // team structure configured inside the company.
        if ($defaults['team_manager'] ?? false) {
            $ownerIds = collect([
                $context['lead_owner_id'] ?? null,
                $context['deal_owner_id'] ?? null,
            ])->filter()->unique()->values();

            if ($ownerIds->isNotEmpty()) {
                $managerIds = Team::where('company_id', $companyId)
                    ->whereHas('users', fn ($q) => $q->whereIn('users.id', $ownerIds))
                    ->pluck('manager_user_id')
                    ->filter();

                $ids = $ids->merge($managerIds);
            }
        }

        $uniqueIds = $ids->unique()->filter()->values();

        if ($uniqueIds->isEmpty()) {
            return collect();
        }

        // Final load — enforce is_active as a safety net even if the IDs came from above
        return User::whereIn('id', $uniqueIds)
            ->where('is_active', true)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Quiet hours
    // -------------------------------------------------------------------------

    /**
     * Returns true if the current time falls within the configured quiet-hours window.
     *
     * Supports overnight ranges (e.g. 22:00 → 08:00).
     */
    private function isQuietHours(array $quietHours): bool
    {
        if (!($quietHours['enabled'] ?? false)) {
            return false;
        }

        $from = $quietHours['from'] ?? null;
        $to   = $quietHours['to']   ?? null;

        if (!$from || !$to) {
            return false;
        }

        $now   = Carbon::now();
        $start = Carbon::createFromFormat('H:i', $from)->setDateFrom($now);
        $end   = Carbon::createFromFormat('H:i', $to)->setDateFrom($now);

        // Overnight range (e.g. 22:00 → 08:00 the next day)
        if ($start->gt($end)) {
            return $now->gte($start) || $now->lte($end);
        }

        return $now->between($start, $end);
    }

    // -------------------------------------------------------------------------
    // Channel dispatch
    // -------------------------------------------------------------------------

    /**
     * Fire the appropriate in-app broadcast for the given event.
     *
     * For lead_created: fires the existing LeadCreatedNotification broadcast to the
     * company private channel. For deal events, the SaleTransactionNotification model
     * hook already broadcasts to the feed — no duplicate dispatch needed here.
     */
    private function sendInApp(string $eventKey, array $context, int $companyId): void
    {
        try {
            if (
                $eventKey === 'lead_created'
                && isset($context['_lead_model'])
                && isset($context['_actor_model'])
            ) {
                broadcast(new LeadCreatedNotification(
                    $context['_lead_model'],
                    $context['_actor_model']
                ))->toOthers();
            }
            // For deal_created, deal_won, deal_lost, deal_stage_changed, lead_assigned:
            // SaleTransactionNotification / LeadTransactionNotification model hooks already
            // broadcast these events to the company feed channel — they are the in-app signal.

            Log::debug("[Notification] in_app dispatched for '{$eventKey}' (company {$companyId}).");
        } catch (\Throwable $e) {
            Log::error("[Notification] in_app broadcast failed for '{$eventKey}': {$e->getMessage()}");
        }
    }

    /**
     * Enqueue an email notification via the n8n webhook.
     */
    private function sendEmail(User $recipient, string $eventKey, string $message): void
    {
        if (!filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning("[Notification] Invalid email for user {$recipient->id}: '{$recipient->email}' — skipping.");
            return;
        }

        SendExternalNotification::dispatch('email', [
            'name'    => $recipient->name,
            'email'   => $recipient->email,
            'message' => $message,
        ], $eventKey, $recipient->id);
    }

    /**
     * Enqueue a WhatsApp notification via the n8n webhook.
     */
    private function sendWhatsApp(User $recipient, string $eventKey, string $message): void
    {
        if (empty($recipient->phone)) {
            Log::debug("[Notification] User {$recipient->id} has no phone — skipping WhatsApp for '{$eventKey}'.");
            return;
        }

        // Strip non-digit characters; keep only digits
        $phone = preg_replace('/\D/', '', $recipient->phone);

        if (strlen($phone) < 10) {
            Log::warning("[Notification] Invalid phone for user {$recipient->id}: '{$recipient->phone}' — skipping.");
            return;
        }

        SendExternalNotification::dispatch('whatsapp', [
            'number'  => $phone,
            'message' => $message,
        ], $eventKey, $recipient->id);
    }

    // -------------------------------------------------------------------------
    // Message builder
    // -------------------------------------------------------------------------

    /**
     * Build a professional, human-readable notification message.
     *
     * Each event produces a multi-line string. Null lines (optional context
     * that wasn't provided) are stripped so the message is never padded with
     * blank placeholders.
     */
    private function buildMessage(string $eventKey, array $context): string
    {
        $leadName  = $context['lead']['name']  ?? null;
        $saleName  = $context['sale']['name']  ?? null;
        $actorName = $context['actor_name']    ?? null;
        $stageName = $context['stage_name']    ?? null;
        $saleTotal = isset($context['sale']['total'])
            ? 'R$ ' . number_format((float) $context['sale']['total'], 2, ',', '.')
            : null;

        $lines = match ($eventKey) {

            'lead_created' => [
                '[Optomyze CRM] New Lead Registered',
                '',
                $leadName  ? "Lead: {$leadName}"       : null,
                $actorName ? "Registered by: {$actorName}" : null,
                '',
                'Log in to the CRM to review and take action.',
            ],

            'lead_assigned' => [
                '[Optomyze CRM] Lead Assigned to You',
                '',
                $leadName  ? "Lead: {$leadName}"       : null,
                $actorName ? "Assigned by: {$actorName}" : null,
                '',
                'Please access the CRM to review the lead details.',
            ],

            'deal_created' => [
                '[Optomyze CRM] New Deal Opened',
                '',
                $saleName  ? "Deal: {$saleName}"       : null,
                $leadName  ? "Client: {$leadName}"     : null,
                $saleTotal ? "Value: {$saleTotal}"     : null,
                $actorName ? "Created by: {$actorName}" : null,
                '',
                'Access the CRM to manage this deal.',
            ],

            'deal_stage_changed' => [
                '[Optomyze CRM] Deal Stage Updated',
                '',
                $saleName  ? "Deal: {$saleName}"         : null,
                $stageName ? "New stage: {$stageName}"   : null,
                $actorName ? "Updated by: {$actorName}"  : null,
                '',
                'Log in to the CRM to view the full deal progress.',
            ],

            'deal_won' => [
                '[Optomyze CRM] Deal Closed — WON',
                '',
                $saleName  ? "Deal: {$saleName}"       : null,
                $saleTotal ? "Value: {$saleTotal}"     : null,
                $actorName ? "Closed by: {$actorName}" : null,
                '',
                'Congratulations to the team!',
            ],

            'deal_lost' => [
                '[Optomyze CRM] Deal Closed — LOST',
                '',
                $saleName  ? "Deal: {$saleName}"       : null,
                $leadName  ? "Client: {$leadName}"     : null,
                $saleTotal ? "Value: {$saleTotal}"     : null,
                $actorName ? "Closed by: {$actorName}" : null,
                '',
                'Review the deal in the CRM to record a lost reason.',
            ],

            'product_added' => [
                '[Optomyze CRM] Product Added to Deal',
                '',
                $saleName  ? "Deal: {$saleName}"      : null,
                $actorName ? "Added by: {$actorName}" : null,
                '',
                'Access the CRM to review the updated deal.',
            ],

            default => [
                '[Optomyze CRM] Notification',
                '',
                "Event: {$eventKey}",
            ],
        };

        // Remove null-only lines but keep intentional empty-string spacers
        return implode("\n", array_filter($lines, fn($line) => $line !== null));
    }
}
