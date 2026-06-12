<?php

namespace App\Models;

use App\Events\LeadTransactionNotification;
use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use App\Models\LostReason;
use App\Models\User;
use App\Models\InboxChat;
use App\Models\WhatsAppInstance;

class LeadTransaction extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected static function booted(): void
    {
        static::created(function (LeadTransaction $transaction) {
            broadcast(new LeadTransactionNotification($transaction));
        });
    }

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
    const TYPE_WHATSAPP_CHAT = 'whatsapp_chat';
    const TYPE_INSTAGRAM_CHAT = 'instagram_chat';
    const TYPE_LOST = 'lost';

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
    const ACTION_CHAT_STARTED = 'chat_started';
    const ACTION_LOST = 'lost';

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
     * Create a lead creation transaction.
     */
    public static function createLeadCreation(
        Lead $lead,
        User $user = null,
        string $source = self::SOURCE_MANUAL,
        array $metadata = []
    ): self {
        return self::create([
            'lead_id'     => $lead->id,
            'company_id'  => $lead->company_id,
            'user_id'     => $user?->id,
            'type'        => self::TYPE_CREATION,
            'action'      => self::ACTION_CREATED,
            'description' => 'Lead created',
            'source'      => $source,
            'metadata'    => $metadata,
            'is_important' => true,
        ]);
    }

    /**
     * Create a WhatsApp chat started transaction.
     */
    public static function createWhatsAppChatStarted(
        Lead $lead,
        InboxChat $chat,
        WhatsAppInstance $instance
    ): self {
        return self::create([
            'lead_id'     => $lead->id,
            'company_id'  => $lead->company_id,
            'user_id'     => null,
            'type'        => self::TYPE_WHATSAPP_CHAT,
            'action'      => self::ACTION_CHAT_STARTED,
            'description' => "New WhatsApp conversation started via instance '{$instance->instance_name}'",
            'source'      => self::SOURCE_WEBHOOK,
            'metadata'    => [
                'chat_id'       => $chat->id,
                'instance_id'   => $instance->id,
                'instance_name' => $instance->instance_name,
                'phone_number'  => $chat->external_contact_id,
            ],
            'is_important' => true,
        ]);
    }

    /**
     * Create an Instagram chat started transaction.
     */
    public static function createInstagramChatStarted(
        Lead $lead,
        InboxChat $chat,
        InstagramIntegration $integration
    ): self {
        return self::create([
            'lead_id'     => $lead->id,
            'company_id'  => $lead->company_id,
            'user_id'     => null,
            'type'        => self::TYPE_INSTAGRAM_CHAT,
            'action'      => self::ACTION_CHAT_STARTED,
            'description' => "New Instagram conversation started via @{$integration->username}",
            'source'      => self::SOURCE_WEBHOOK,
            'metadata'    => [
                'chat_id'        => $chat->id,
                'integration_id' => $integration->id,
                'igsid'          => $chat->external_contact_id,
            ],
            'is_important' => true,
        ]);
    }

    /**
     * Create a lost lead transaction with the selected reason.
     */
    public static function createLeadLost(
        Lead $lead,
        LostReason $reason,
        User $user = null
    ): self {
        return self::create([
            'lead_id'      => $lead->id,
            'company_id'   => $lead->company_id,
            'user_id'      => $user?->id,
            'type'         => self::TYPE_LOST,
            'action'       => self::ACTION_LOST,
            'description'  => $reason->description,
            'source'       => $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM,
            'metadata'     => ['lost_reason_id' => $reason->id],
            'is_important' => true,
        ]);
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
        $leadName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) ?: "Lead #{$lead->id}";
        $description = $fromStage
            ? "{$leadName} was moved from '{$fromStage->name}' to '{$toStage->name}'"
            : "{$leadName} was moved to '{$toStage->name}'";

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