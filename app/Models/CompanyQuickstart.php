<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * COMPANY QUICKSTART CHECKLIST
 *
 * The onboarding script a company works through when it first signs up. The
 * steps live here rather than in a table: they are a fixed sequence the
 * product defines, so adding or retiring one should be a code change, not a
 * data migration. The row records only what a company has done.
 *
 * Where each step takes the user is deliberately *not* stored here — that is
 * the frontend's routing concern, and duplicating it server-side would mean
 * two places to update when a screen moves.
 */
class CompanyQuickstart extends Model
{
    /**
     * The checklist, in the order it is shown. Keys are the contract with the
     * frontend, which maps each to a destination and a translated label.
     */
    public const STEPS = [
        'create_funnel',
        'connect_messaging',
        'welcome_message',
        'add_team_member',
        'first_appointment',
        'chat_widget',
    ];

    protected $fillable = [
        'company_id',
        'completed_steps',
        'dismissed_at',
    ];

    protected $casts = [
        'completed_steps' => 'array',
        'dismissed_at'    => 'datetime',
    ];

    protected $attributes = [
        'completed_steps' => '[]',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Completed steps, filtered to those the product still defines and put
     * back in checklist order. A key retired from STEPS stays in the column
     * but stops being reported, so removing a step needs no backfill.
     */
    public function completedSteps(): array
    {
        $stored = $this->completed_steps ?? [];

        return array_values(array_intersect(self::STEPS, $stored));
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isComplete(): bool
    {
        return count($this->completedSteps()) === count(self::STEPS);
    }

    /** Marks a step done or undone; unknown keys are rejected by the caller. */
    public function setStepCompleted(string $step, bool $completed): void
    {
        $steps = $this->completedSteps();

        $steps = $completed
            ? array_unique([...$steps, $step])
            : array_diff($steps, [$step]);

        $this->update([
            'completed_steps' => array_values(array_intersect(self::STEPS, $steps)),
        ]);
    }

    /**
     * The shape the frontend consumes. `steps` is always the full checklist in
     * order, so the client never has to know the sequence itself.
     */
    public function toPayload(): array
    {
        $completed = $this->completedSteps();

        return [
            'id'              => $this->id,
            'company_id'      => $this->company_id,
            'steps'           => self::STEPS,
            'completed_steps' => $completed,
            'completed_count' => count($completed),
            'total_steps'     => count(self::STEPS),
            'is_complete'     => $this->isComplete(),
            'dismissed'       => $this->isDismissed(),
            'dismissed_at'    => $this->dismissed_at?->toIso8601String(),
        ];
    }
}
