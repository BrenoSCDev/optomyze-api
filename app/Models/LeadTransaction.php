<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class LeadTransaction extends Model
{
    use HasFactory;

    /**
     * Transaction type constants
     */
    const TYPE_STAGE_CHANGE = 'stage_change';
    const TYPE_ASSIGNMENT = 'assignment';
    const TYPE_QUALIFICATION = 'qualification';
    const TYPE_CONTACT = 'contact';
    const TYPE_NOTE = 'note';
    const TYPE_STATUS_CHANGE = 'status_change';
    const TYPE_PRIORITY_CHANGE = 'priority_change';
    const TYPE_VALUE_CHANGE = 'value_change';
    const TYPE_SCORE_CHANGE = 'score_change';
    const TYPE_CREATION = 'creation';
    const TYPE_UPDATE = 'update';
    const TYPE_DELETION = 'deletion';
    const TYPE_RESTORATION = 'restoration';
    const TYPE_TAG_CHANGE = 'tag_change';
    const TYPE_FIELD_CHANGE = 'field_change';

    /**
     * Transaction action constants
     */
    const ACTION_MOVED = 'moved';
    const ACTION_ASSIGNED = 'assigned';
    const ACTION_UNASSIGNED = 'unassigned';
    const ACTION_QUALIFIED = 'qualified';
    const ACTION_UNQUALIFIED = 'unqualified';
    const ACTION_CONTACTED = 'contacted';
    const ACTION_NOTED = 'noted';
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';
    const ACTION_RESTORED = 'restored';
    const ACTION_CHANGED = 'changed';
    const ACTION_ADDED = 'added';
    const ACTION_REMOVED = 'removed';

    /**
     * Communication direction constants
     */
    const DIRECTION_INBOUND = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    /**
     * Source constants
     */
    const SOURCE_MANUAL = 'manual';
    const SOURCE_SYSTEM = 'system';
    const SOURCE_AUTOMATION = 'automation';
    const SOURCE_API = 'api';
    const SOURCE_WEBHOOK = 'webhook';
    const SOURCE_IMPORT = 'import';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'lead_id',
        'company_id',
        'user_id',
        'type',
        'action',
        'description',
        'previous_data',
        'current_data',
        'metadata',
        'from_stage_id',
        'to_stage_id',
        'assigned_from',
        'assigned_to',
        'contact_method',
        'message',
        'communication_direction',
        'communication_data',
        'previous_status',
        'current_status',
        'previous_priority',
        'current_priority',
        'previous_qualification',
        'current_qualification',
        'qualification_reason',
        'previous_estimated_value',
        'current_estimated_value',
        'previous_ai_score',
        'current_ai_score',
        'source',
        'trigger',
        'is_automated',
        'automation_id',
        'ip_address',
        'user_agent',
        'session_id',
        'context',
        'is_visible',
        'is_important',
        'notifications_sent',
        'notification_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'previous_data' => 'array',
        'current_data' => 'array',
        'metadata' => 'array',
        'communication_data' => 'array',
        'context' => 'array',
        'notification_data' => 'array',
        'previous_qualification' => 'boolean',
        'current_qualification' => 'boolean',
        'previous_estimated_value' => 'decimal:2',
        'current_estimated_value' => 'decimal:2',
        'previous_ai_score' => 'decimal:2',
        'current_ai_score' => 'decimal:2',
        'is_automated' => 'boolean',
        'is_visible' => 'boolean',
        'is_important' => 'boolean',
        'notifications_sent' => 'boolean',
    ];

    /**
     * Get the lead this transaction belongs to.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Get the company this transaction belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who performed this transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the stage the lead was moved from.
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'from_stage_id');
    }

    /**
     * Get the stage the lead was moved to.
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'to_stage_id');
    }

    /**
     * Get the user the lead was assigned from.
     */
    public function assignedFromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_from');
    }

    /**
     * Get the user the lead was assigned to.
     */
    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Scope a query to only include visible transactions.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope a query to only include important transactions.
     */
    public function scopeImportant(Builder $query): Builder
    {
        return $query->where('is_important', true);
    }

    /**
     * Scope a query to filter by transaction type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to filter by source.
     */
    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Scope a query to only include manual transactions.
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('is_automated', false);
    }

    /**
     * Scope a query to only include automated transactions.
     */
    public function scopeAutomated(Builder $query): Builder
    {
        return $query->where('is_automated', true);
    }

    /**
     * Scope a query to filter transactions within date range.
     */
    public function scopeWithinDateRange(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope a query to filter recent transactions.
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Check if this transaction represents a stage change.
     */
    public function isStageChange(): bool
    {
        return $this->type === self::TYPE_STAGE_CHANGE;
    }

    /**
     * Check if this transaction represents an assignment change.
     */
    public function isAssignmentChange(): bool
    {
        return $this->type === self::TYPE_ASSIGNMENT;
    }

    /**
     * Check if this transaction represents a qualification change.
     */
    public function isQualificationChange(): bool
    {
        return $this->type === self::TYPE_QUALIFICATION;
    }

    /**
     * Check if this transaction represents a contact interaction.
     */
    public function isContactInteraction(): bool
    {
        return $this->type === self::TYPE_CONTACT;
    }

    /**
     * Get human-readable time difference.
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get formatted description with user context.
     */
    public function getFormattedDescriptionAttribute(): string
    {
        $userName = $this->user ? $this->user->name : 'System';
        return "{$userName} {$this->description}";
    }

    /**
     * Create a stage change transaction.
     */
    public static function createStageChange(
        Lead $lead,
        Stage $fromStage = null,
        Stage $toStage,
        User $user = null,
        array $metadata = []
    ): self {
        $description = $fromStage 
            ? "moved lead from '{$fromStage->name}' to '{$toStage->name}'"
            : "moved lead to '{$toStage->name}'";

        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user?->id,
            'type' => self::TYPE_STAGE_CHANGE,
            'action' => self::ACTION_MOVED,
            'description' => $description,
            'from_stage_id' => $fromStage?->id,
            'to_stage_id' => $toStage->id,
            'metadata' => $metadata,
            'is_important' => true,
            'source' => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Create an assignment transaction.
     */
    public static function createAssignment(
        Lead $lead,
        User $assignedTo = null,
        User $assignedFrom = null,
        User $user = null,
        array $metadata = []
    ): self {
        if ($assignedTo) {
            $description = $assignedFrom 
                ? "reassigned lead from {$assignedFrom->name} to {$assignedTo->name}"
                : "assigned lead to {$assignedTo->name}";
            $action = self::ACTION_ASSIGNED;
        } else {
            $description = "unassigned lead" . ($assignedFrom ? " from {$assignedFrom->name}" : "");
            $action = self::ACTION_UNASSIGNED;
        }

        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user?->id,
            'type' => self::TYPE_ASSIGNMENT,
            'action' => $action,
            'description' => $description,
            'assigned_from' => $assignedFrom?->id,
            'assigned_to' => $assignedTo?->id,
            'metadata' => $metadata,
            'is_important' => true,
            'source' => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Create a qualification transaction.
     */
    public static function createQualification(
        Lead $lead,
        bool $isQualified,
        User $user,
        string $reason = null,
        array $metadata = []
    ): self {
        $action = $isQualified ? self::ACTION_QUALIFIED : self::ACTION_UNQUALIFIED;
        $description = $isQualified ? "qualified the lead" : "marked lead as unqualified";
        
        if ($reason) {
            $description .= ": {$reason}";
        }

        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user->id,
            'type' => self::TYPE_QUALIFICATION,
            'action' => $action,
            'description' => $description,
            'current_qualification' => $isQualified,
            'qualification_reason' => $reason,
            'metadata' => $metadata,
            'is_important' => true,
            'source' => self::SOURCE_MANUAL,
        ]);
    }

    /**
     * Create a contact transaction.
     */
    public static function createContact(
        Lead $lead,
        string $method,
        string $direction = self::DIRECTION_OUTBOUND,
        string $message = null,
        User $user = null,
        array $metadata = []
    ): self {
        $description = $direction === self::DIRECTION_INBOUND 
            ? "received contact via {$method}"
            : "contacted lead via {$method}";

        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user?->id,
            'type' => self::TYPE_CONTACT,
            'action' => self::ACTION_CONTACTED,
            'description' => $description,
            'contact_method' => $method,
            'communication_direction' => $direction,
            'message' => $message,
            'metadata' => $metadata,
            'is_important' => true,
            'source' => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Create a status change transaction.
     */
    public static function createStatusChange(
        Lead $lead,
        string $previousStatus,
        string $currentStatus,
        User $user = null,
        array $metadata = []
    ): self {
        $description = "changed status from '{$previousStatus}' to '{$currentStatus}'";

        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user?->id,
            'type' => self::TYPE_STATUS_CHANGE,
            'action' => self::ACTION_CHANGED,
            'description' => $description,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
            'metadata' => $metadata,
            'source' => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * Create a note transaction.
     */
    public static function createNote(
        Lead $lead,
        string $note,
        User $user,
        array $metadata = []
    ): self {
        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user->id,
            'type' => self::TYPE_NOTE,
            'action' => self::ACTION_NOTED,
            'description' => 'added a note',
            'message' => $note,
            'metadata' => $metadata,
            'source' => self::SOURCE_MANUAL,
        ]);
    }

    /**
     * Create a lead creation transaction.
     */
    public static function createLeadCreation(
        Lead $lead,
        User $user = null,
        string $source = self::SOURCE_MANUAL,
        array $metadata = []
    ): self {
        $description = "created the lead";
        if ($lead->source_platform) {
            $description .= " from {$lead->source_platform}";
        }

        return self::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'user_id' => $user?->id,
            'type' => self::TYPE_CREATION,
            'action' => self::ACTION_CREATED,
            'description' => $description,
            'current_data' => $lead->toArray(),
            'metadata' => $metadata,
            'is_important' => true,
            'source' => $source,
            'is_automated' => !$user,
        ]);
    }

    /**
     * Get available transaction types.
     */
    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_STAGE_CHANGE => 'Stage Change',
            self::TYPE_ASSIGNMENT => 'Assignment',
            self::TYPE_QUALIFICATION => 'Qualification',
            self::TYPE_CONTACT => 'Contact',
            self::TYPE_NOTE => 'Note',
            self::TYPE_STATUS_CHANGE => 'Status Change',
            self::TYPE_PRIORITY_CHANGE => 'Priority Change',
            self::TYPE_VALUE_CHANGE => 'Value Change',
            self::TYPE_SCORE_CHANGE => 'Score Change',
            self::TYPE_CREATION => 'Creation',
            self::TYPE_UPDATE => 'Update',
            self::TYPE_TAG_CHANGE => 'Tag Change',
            self::TYPE_FIELD_CHANGE => 'Field Change',
        ];
    }
}