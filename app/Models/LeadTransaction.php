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
        'source',
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
}