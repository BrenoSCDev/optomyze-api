<?php

namespace App\Models;

use App\Events\RecordCreated;
use App\Services\RecordTranslator;
use App\Support\CompanyLanguageCache;
use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Record extends Model
{
    use HasCompanyTimezone, HasFactory;

    protected static function booted(): void
    {
        static::created(function (Record $record) {
            broadcast(new RecordCreated($record));
        });
    }

    /**
     * Record type constants (union of legacy LeadTransaction/SaleTransaction types).
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
    const TYPE_CHAT_SESSION = 'chat_session';
    const TYPE_LOST = 'lost';
    const TYPE_TASK = 'task';
    const TYPE_APPOINTMENT = 'appointment';
    const TYPE_OBSERVATION = 'observation';
    const TYPE_DOCUMENT = 'document';
    const TYPE_SIGNATURE = 'signature';

    /**
     * Record action constants.
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
    const ACTION_STARTED = 'started';
    const ACTION_LOST = 'lost';
    const ACTION_WON = 'won';
    const ACTION_CLOSED = 'closed';
    const ACTION_COMPLETED = 'completed';
    const ACTION_SENT = 'sent';
    const ACTION_REFUSED = 'refused';
    const ACTION_CANCELED = 'canceled';

    /**
     * Source constants.
     */
    const SOURCE_MANUAL = 'manual';
    const SOURCE_SYSTEM = 'system';
    const SOURCE_AUTOMATION = 'automation';
    const SOURCE_API = 'api';
    const SOURCE_WEBHOOK = 'webhook';
    const SOURCE_IMPORT = 'import';

    /**
     * Polymorphic recordable type aliases (see morph map in AppServiceProvider).
     */
    const RECORDABLE_LEAD = 'Lead';
    const RECORDABLE_SALE = 'Sale';

    protected $fillable = [
        'company_id',
        'recordable_type',
        'recordable_id',
        'user_id',
        'type',
        'action',
        'title',
        'description',
        'metadata',
        'source',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function recordable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a lead creation record.
     */
    public static function createLeadCreation(
        Lead $lead,
        ?User $user = null,
        string $source = self::SOURCE_MANUAL,
        array $metadata = [],
        ?string $variant = null
    ): self {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $user,
            self::TYPE_CREATION,
            self::ACTION_CREATED,
            $variant,
            array_merge(['leadName' => self::leadDisplayName($lead)], $metadata),
            $source
        );
    }

    /**
     * Create a WhatsApp chat started record.
     */
    public static function createWhatsAppChatStarted(
        Lead $lead,
        InboxChat $chat,
        WhatsAppInstance $instance
    ): self {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            null,
            self::TYPE_WHATSAPP_CHAT,
            self::ACTION_CHAT_STARTED,
            null,
            [
                'instanceName' => $instance->instance_name,
                'chat_id' => $chat->id,
                'instance_id' => $instance->id,
                'phone_number' => $chat->external_contact_id,
            ],
            self::SOURCE_WEBHOOK
        );
    }

    /**
     * Create an Instagram chat started record.
     */
    public static function createInstagramChatStarted(
        Lead $lead,
        InboxChat $chat,
        InstagramIntegration $integration
    ): self {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            null,
            self::TYPE_INSTAGRAM_CHAT,
            self::ACTION_CHAT_STARTED,
            null,
            [
                'username' => $integration->username,
                'chat_id' => $chat->id,
                'integration_id' => $integration->id,
                'igsid' => $chat->external_contact_id,
            ],
            self::SOURCE_WEBHOOK
        );
    }

    /**
     * Create a chat session started record.
     */
    public static function createChatSessionStarted(Lead $lead, ChatSession $session): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            null,
            self::TYPE_CHAT_SESSION,
            self::ACTION_STARTED,
            null,
            [
                'chat_session_id' => $session->id,
                'channel' => $session->channel,
            ],
            self::SOURCE_SYSTEM
        );
    }

    /**
     * Create a chat session closed record.
     */
    public static function createChatSessionEnded(
        Lead $lead,
        ChatSession $session,
        ClosingReason $reason,
        ?User $actor = null
    ): self {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_CHAT_SESSION,
            self::ACTION_CLOSED,
            null,
            [
                'chat_session_id' => $session->id,
                'channel' => $session->channel,
                'closingReason' => $reason->name,
                'closing_reason_id' => $reason->id,
                'durationMinutes' => (int) round($session->duration_seconds / 60),
            ],
            self::SOURCE_MANUAL
        );
    }

    /**
     * Create a lead-lost record with the selected reason.
     */
    public static function createLeadLost(Lead $lead, LostReason $reason, ?User $user = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $user,
            self::TYPE_LOST,
            self::ACTION_LOST,
            null,
            ['reason' => $reason->description, 'lost_reason_id' => $reason->id],
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Create a lead stage-change record.
     */
    public static function createLeadStageChange(
        Lead $lead,
        ?Stage $fromStage,
        Stage $toStage,
        ?User $user = null,
        array $metadata = []
    ): self {
        $params = array_merge([
            'leadName' => self::leadDisplayName($lead),
            'fromStage' => $fromStage?->name,
            'toStage' => $toStage->name,
            'from_stage_id' => $fromStage?->id,
            'to_stage_id' => $toStage->id,
        ], $metadata);

        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $user,
            self::TYPE_STAGE_CHANGE,
            self::ACTION_MOVED,
            $fromStage ? 'with_from' : 'no_from',
            $params,
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a lead being moved to a different funnel (and its entry stage).
     */
    public static function createLeadFunnelChange(
        Lead $lead,
        Funnel $funnel,
        ?Stage $fromStage,
        Stage $toStage,
        ?User $user = null
    ): self {
        $params = [
            'leadName' => self::leadDisplayName($lead),
            'funnelName' => $funnel->name,
            'fromStage' => $fromStage?->name,
            'toStage' => $toStage->name,
            'from_stage_id' => $fromStage?->id,
            'to_stage_id' => $toStage->id,
            'from_funnel_id' => $fromStage?->funnel_id,
            'to_funnel_id' => $funnel->id,
            'to_funnel_name' => $funnel->name,
        ];

        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $user,
            self::TYPE_STAGE_CHANGE,
            self::ACTION_MOVED,
            'to_funnel',
            $params,
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Create a deal creation record.
     */
    public static function createDealCreation(Sale $sale, ?User $user = null): self
    {
        $lead = $sale->lead;
        $leadName = $lead ? self::leadDisplayName($lead) : null;

        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $user,
            self::TYPE_CREATION,
            self::ACTION_CREATED,
            $leadName ? 'with_lead' : 'no_lead',
            ['dealName' => $sale->name, 'leadName' => $leadName],
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Create a deal deletion record. Call before the sale is deleted.
     */
    public static function createDealDeletion(Sale $sale, ?User $user = null): self
    {
        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $user,
            self::TYPE_DELETION,
            self::ACTION_DELETED,
            null,
            ['dealName' => $sale->name],
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a deal stage movement. Automatically uses 'won' or 'lost' actions
     * when the destination stage type is 'won' or 'lost'.
     */
    public static function createDealStageChange(
        Sale $sale,
        ?Stage $fromStage,
        Stage $toStage,
        ?User $user = null,
        array $metadata = []
    ): self {
        if ($toStage->type === 'won') {
            $action = self::ACTION_WON;
            $variant = null;
            $params = self::newSaleParams($sale);
        } elseif ($toStage->type === 'lost') {
            $action = self::ACTION_LOST;
            $variant = $fromStage ? 'with_from' : 'no_from';
            $params = [
                'dealName' => $sale->name,
                'fromStage' => $fromStage?->name,
                'toStage' => $toStage->name,
            ];
        } else {
            $action = self::ACTION_MOVED;
            $variant = $fromStage ? 'with_from' : 'no_from';
            $params = [
                'dealName' => $sale->name,
                'fromStage' => $fromStage?->name,
                'toStage' => $toStage->name,
            ];
        }

        $params = array_merge($params, $metadata, [
            'from_stage_id' => $fromStage?->id,
            'to_stage_id' => $toStage->id,
        ]);

        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $user,
            self::TYPE_STAGE_CHANGE,
            $action,
            $variant,
            $params,
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a manual deal status change (via updateStatus endpoint).
     */
    public static function createDealStatusChange(
        Sale $sale,
        string $fromStatus,
        string $toStatus,
        ?User $user = null
    ): self {
        $action = $toStatus === 'closed' ? self::ACTION_CLOSED : self::ACTION_LOST;
        $params = $toStatus === 'closed'
            ? self::newSaleParams($sale)
            : ['dealName' => $sale->name];

        $params['from_status'] = $fromStatus;
        $params['to_status'] = $toStatus;

        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $user,
            self::TYPE_STATUS_CHANGE,
            $action,
            null,
            $params,
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a manual deal field update (via the deal update endpoint).
     */
    public static function createDealUpdate(Sale $sale, ?User $user = null): self
    {
        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $user,
            self::TYPE_UPDATE,
            self::ACTION_UPDATED,
            null,
            ['dealName' => $sale->name, 'userName' => $user?->name],
            $user ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a user being assigned (or unassigned) from a lead.
     */
    public static function createLeadAssignment(
        Lead $lead,
        ?User $oldUser,
        ?User $newUser,
        ?User $actor = null
    ): self {
        $action = $newUser === null ? self::ACTION_UNASSIGNED : self::ACTION_ASSIGNED;
        $variant = $action === self::ACTION_ASSIGNED ? ($oldUser ? 'reassigned' : 'initial') : null;

        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_ASSIGNMENT,
            $action,
            $variant,
            [
                'leadName' => self::leadDisplayName($lead),
                'oldUserName' => $oldUser?->name,
                'newUserName' => $newUser?->name,
            ],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a user being assigned (or unassigned) as a deal's owner.
     */
    public static function createDealAssignment(
        Sale $sale,
        ?User $oldUser,
        ?User $newUser,
        ?User $actor = null
    ): self {
        $action = $newUser === null ? self::ACTION_UNASSIGNED : self::ACTION_ASSIGNED;
        $variant = $action === self::ACTION_ASSIGNED ? ($oldUser ? 'reassigned' : 'initial') : null;

        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $actor,
            self::TYPE_ASSIGNMENT,
            $action,
            $variant,
            [
                'dealName' => $sale->name,
                'oldUserName' => $oldUser?->name,
                'newUserName' => $newUser?->name,
            ],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a task being created for a lead.
     */
    public static function createTaskCreated(Task $task, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_TASK,
            self::ACTION_CREATED,
            null,
            [
                'leadName' => self::leadDisplayName($lead),
                'taskTitle' => $task->title,
                'assigneeName' => $task->assignee?->name,
            ],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a task being updated (any field other than a completion transition).
     */
    public static function createTaskUpdated(Task $task, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_TASK,
            self::ACTION_UPDATED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'taskTitle' => $task->title],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a task being marked as completed.
     */
    public static function createTaskCompleted(Task $task, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_TASK,
            self::ACTION_COMPLETED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'taskTitle' => $task->title],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a tag being added to or removed from a lead.
     */
    public static function createLeadTagChange(Lead $lead, Tag $tag, bool $added, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_TAG_CHANGE,
            $added ? self::ACTION_ADDED : self::ACTION_REMOVED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'tagName' => $tag->name],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a tag being added to or removed from a deal.
     */
    public static function createDealTagChange(Sale $sale, Tag $tag, bool $added, ?User $actor = null): self
    {
        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $actor,
            self::TYPE_TAG_CHANGE,
            $added ? self::ACTION_ADDED : self::ACTION_REMOVED,
            null,
            ['dealName' => $sale->name, 'tagName' => $tag->name],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log an appointment being created for a lead.
     */
    public static function createAppointmentCreated(Appointment $appointment, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_APPOINTMENT,
            self::ACTION_CREATED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'appointmentTitle' => $appointment->title],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log an appointment being updated (any change other than completion).
     */
    public static function createAppointmentUpdated(Appointment $appointment, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_APPOINTMENT,
            self::ACTION_UPDATED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'appointmentTitle' => $appointment->title],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log an appointment being marked as completed.
     */
    public static function createAppointmentCompleted(Appointment $appointment, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_APPOINTMENT,
            self::ACTION_COMPLETED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'appointmentTitle' => $appointment->title],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log an observation (note) being added to a lead.
     */
    public static function createObservationCreated(Observation $observation, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_OBSERVATION,
            self::ACTION_CREATED,
            null,
            ['leadName' => self::leadDisplayName($lead)],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a document being attached to a lead.
     */
    public static function createLeadDocumentAdded(LeadDocument $document, Lead $lead, ?User $actor = null): self
    {
        return self::make(
            $lead->company_id,
            self::RECORDABLE_LEAD,
            $lead->id,
            $actor,
            self::TYPE_DOCUMENT,
            self::ACTION_ADDED,
            null,
            ['leadName' => self::leadDisplayName($lead), 'documentTitle' => $document->title],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a document being attached to a deal.
     */
    public static function createDealDocumentAdded(SaleDoc $doc, Sale $sale, ?User $actor = null): self
    {
        return self::make(
            $sale->company_id,
            self::RECORDABLE_SALE,
            $sale->id,
            $actor,
            self::TYPE_DOCUMENT,
            self::ACTION_ADDED,
            null,
            ['dealName' => $sale->name, 'documentTitle' => $doc->filename],
            $actor ? self::SOURCE_MANUAL : self::SOURCE_SYSTEM
        );
    }

    /**
     * Log a step in a document's lifecycle onto the lead and/or deal it
     * belongs to.
     *
     * Provider-neutral wording: the timeline says a contract was sent or
     * signed, never which vendor carried it. A document can be attached to
     * both a lead and a deal, and both timelines should show it — a contract
     * is news on the opportunity *and* on the person.
     *
     * @return array<int, self>
     */
    public static function createDocumentLifecycle(
        Document $document,
        string $action,
        ?User $actor = null
    ): array {
        $signerCount = $document->relationLoaded('signatureRequest')
            ? ($document->signatureRequest?->signers()->count() ?? 0)
            : SignatureSigner::whereIn(
                'signature_request_id',
                SignatureRequest::where('document_id', $document->id)->select('id')
            )->count();

        $written = [];

        if ($document->sale) {
            $written[] = self::make(
                $document->company_id,
                self::RECORDABLE_SALE,
                $document->sale_id,
                $actor,
                self::TYPE_SIGNATURE,
                $action,
                null,
                [
                    'documentTitle' => $document->title,
                    'signerCount'   => $signerCount,
                    'dealName'      => $document->sale->name,
                ],
                $actor ? self::SOURCE_MANUAL : self::SOURCE_WEBHOOK
            );
        }

        if ($document->lead) {
            $written[] = self::make(
                $document->company_id,
                self::RECORDABLE_LEAD,
                $document->lead_id,
                $actor,
                self::TYPE_SIGNATURE,
                $action,
                null,
                [
                    'documentTitle' => $document->title,
                    'signerCount'   => $signerCount,
                    'leadName'      => self::leadDisplayName($document->lead),
                ],
                $actor ? self::SOURCE_MANUAL : self::SOURCE_WEBHOOK
            );
        }

        return $written;
    }

    /**
     * Create, translate and persist a Record.
     */
    protected static function make(
        int $companyId,
        string $recordableType,
        ?int $recordableId,
        ?User $user,
        string $type,
        string $action,
        ?string $variant,
        array $params,
        string $source
    ): self {
        $language = CompanyLanguageCache::get($companyId);
        $rendered = RecordTranslator::translate($language, $recordableType, $type, $action, $variant, $params);

        return self::create([
            'company_id' => $companyId,
            'recordable_type' => $recordableType,
            'recordable_id' => $recordableId,
            'user_id' => $user?->id,
            'type' => $type,
            'action' => $action,
            'title' => $rendered['title'],
            'description' => $rendered['description'],
            'metadata' => $params,
            'source' => $source,
        ]);
    }

    private static function leadDisplayName(Lead $lead): string
    {
        $name = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));

        return $name !== '' ? $name : "Lead #{$lead->id}";
    }

    /**
     * Shared params for "new sale" descriptions (won / closed).
     */
    private static function newSaleParams(Sale $sale): array
    {
        $lead = $sale->lead;
        $leadName = $lead ? self::leadDisplayName($lead) : null;
        $ownerName = $sale->user?->name;

        return [
            'dealName' => $sale->name,
            'leadName' => $leadName,
            'ownerName' => $ownerName,
            'leadPart' => $leadName ? " · {$leadName}" : '',
            'ownerPart' => $ownerName ? " · {$ownerName}" : '',
        ];
    }
}
