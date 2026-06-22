<?php

namespace App\Models;

use App\Traits\HasCompanyTimezone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\User;
use App\Support\CompanyCurrencyCache;

class Lead extends Model
{
    use HasCompanyTimezone, HasFactory;

    /**
     * Lead priority constants
     */
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    /**
     * Source platform constants
     */
    const PLATFORM_WHATSAPP = 'whatsapp';
    const PLATFORM_FACEBOOK = 'facebook';
    const PLATFORM_INSTAGRAM = 'instagram';
    const PLATFORM_META_ADS = 'meta_ads';
    const PLATFORM_GOOGLE_ADS = 'google_ads';
    const PLATFORM_TELEGRAM = 'telegram';
    const PLATFORM_PHONE = 'phone';
    const PLATFORM_SMS = 'sms';
    const PLATFORM_EMAIL = 'email';
    const PLATFORM_WEBSITE = 'website';
    const PLATFORM_API = 'api';
    const PLATFORM_OTHER = 'other';

    /**
     * Source type constants
     */
    const SOURCE_AI_AUTOMATION = 'ai_automation';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_WEB_FORM = 'web_form';
    const SOURCE_API = 'api';
    const SOURCE_IMPORT = 'import';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'funnel_id',
        'stage_id',
        'assigned_to',
        'external_id',
        'source_platform',
        'source_type',
        'first_name',
        'last_name',
        'email',
        'phone',
        'username',
        'priority',
        'currency',
        'contact_methods',
        'last_contact_at',
        'preferred_contact_method',
        'timezone',
        'language',
        'ai_score',
        'notes',
        'is_active',
        'is_qualified',
        'qualified_at',
        'qualified_by',
        'erp_budgets_ids',
        'address_line_1',
        'address_line_2',
        'neighborhood',
        'city',
        'state',
        'state_code',
        'postal_code',
        'country',
        'lead_company_id'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'ai_score' => 'decimal:2',
        'contact_methods' => 'array',
        'is_active' => 'boolean',
        'is_qualified' => 'boolean',
        'last_contact_at' => 'datetime',
        'qualified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            if (empty($lead->currency)) {
                $lead->currency = CompanyCurrencyCache::get((int) $lead->company_id);
            }

            if (empty($lead->priority)) {
                $lead->priority = self::PRIORITY_MEDIUM;
            }
        });

        static::deleting(function (Lead $lead) {
            foreach ($lead->inboxChats as $chat) {
                $chat->messages()->delete();
                $chat->delete();
            }
        });
    }

    /**
     * Get the company that owns the lead.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the company that owns the lead.
     */
    public function leadCompany(): BelongsTo
    {
        return $this->belongsTo(LeadCompany::class);
    }

    /**
     * Get the funnel this lead belongs to.
     */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /**
     * Get the current stage of the lead.
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * Get the user assigned to this lead.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get lead interested products.
     */
    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('quantity', 'unit_price', 'total_price')
            ->withTimestamps();
    }

    /**
     * Get lead sales.
     */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Get the tags associated with this lead.
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'lead_tag');
    }

    /**
     * Get lead observations.
     */
    public function observations()
    {
        return $this->hasMany(Observation::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get lead appointments.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderBy('scheduled_at');
    }

    /**
     * Get the user who qualified this lead.
     */
    public function qualifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    /**
     * Get all inbox chats (any channel) for this lead.
     */
    public function inboxChats(): HasMany
    {
        return $this->hasMany(InboxChat::class);
    }

    /**
     * Get the lead's WhatsApp inbox chat.
     */
    public function whatsappInboxChat(): HasOne
    {
        return $this->hasOne(InboxChat::class)->where('channel', InboxChat::CHANNEL_WHATSAPP);
    }

    /**
     * Get all chat sessions (any channel) for this lead.
     */
    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /**
     * Scope a query to only include active leads.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include leads from the same company.
     */
    public function scopeFromCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope a query to only include leads visible to the given user based on their role.
     * Admin: no filter. Manager: own + team + unassigned. Agent: own only.
     */
    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        $ids = $user->visibleUserIds();
        if ($ids === null) {
            return $query;
        }

        return $query->where(function ($q) use ($ids, $user) {
            $q->whereIn('assigned_to', $ids);
            if ($user->isManager()) {
                $q->orWhereNull('assigned_to');
            }
        });
    }

    /**
     * Scope a query to filter by priority.
     */
    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to filter by source platform.
     */
    public function scopeByPlatform(Builder $query, string $platform): Builder
    {
        return $query->where('source_platform', $platform);
    }

    /**
     * Scope a query to filter by assigned user.
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Scope a query to filter unassigned leads.
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    /**
     * Scope a query to filter qualified leads.
     */
    public function scopeQualified(Builder $query): Builder
    {
        return $query->where('is_qualified', true);
    }

    /**
     * Scope a query to filter unqualified leads.
     */
    public function scopeUnqualified(Builder $query): Builder
    {
        return $query->where('is_qualified', false);
    }

    /**
     * Scope a query to filter leads that need assessment.
     */
    public function scopeNeedsAssessment(Builder $query): Builder
    {
        return $query->whereNull('is_qualified');
    }

    /**
     * Scope a query to filter by AI score range.
     */
    public function scopeByAiScore(Builder $query, float $min, float $max = null): Builder
    {
        $query->where('ai_score', '>=', $min);
        if ($max !== null) {
            $query->where('ai_score', '<=', $max);
        }
        return $query;
    }

    /**
     * Scope a query to filter recent leads.
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Get the lead's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name) ?: $this->username ?: $this->email ?: 'Unknown';
    }

    /**
     * Get the lead's display name for UI.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return $this->full_name;
        }
        
        if ($this->username) {
            return '@' . $this->username;
        }
        
        if ($this->email) {
            return $this->email;
        }
        
        if ($this->phone) {
            return $this->phone;
        }
        
        return "Lead #{$this->id}";
    }

    /**
     * Check if lead is new (within last 24 hours).
     */
    public function isNew(): bool
    {
        return $this->created_at->isAfter(Carbon::now()->subDay());
    }

    /**
     * Check if lead is overdue for contact.
     */
    public function isOverdue(int $hours = 24): bool
    {
        if ($this->last_contact_at) {
            return $this->last_contact_at->isBefore(Carbon::now()->subHours($hours));
        }
        
        return $this->created_at->isBefore(Carbon::now()->subHours($hours));
    }

    /**
     * Move lead to next stage.
     */
    public function moveToNextStage(User $user = null): bool
    {
        $nextStage = $this->stage->nextStage();
        
        if ($nextStage) {
            $previousStage = $this->stage;
            $this->update(['stage_id' => $nextStage->id]);
            
            // Create record
            Record::createLeadStageChange($this, $previousStage, $nextStage, $user);
            
            return true;
        }
        
        return false;
    }

    /**
     * Move lead to previous stage.
     */
    public function moveToPreviousStage(User $user = null): bool
    {
        $previousStage = $this->stage->previousStage();
        
        if ($previousStage) {
            $currentStage = $this->stage;
            $this->update(['stage_id' => $previousStage->id]);
            
            // Create record
            Record::createLeadStageChange($this, $currentStage, $previousStage, $user);
            
            return true;
        }
        
        return false;
    }

    /**
     * Move lead to specific stage.
     */
    public function moveToStage(int $stageId, User $user = null): bool
    {
        $stage = Stage::where('id', $stageId)
            ->where('funnel_id', $this->funnel_id)
            ->first();
            
        if ($stage && $stage->id !== $this->stage_id) {
            $previousStage = $this->stage;
            $this->update(['stage_id' => $stageId]);
            
            // Create record
            Record::createLeadStageChange($this, $previousStage, $stage, $user);
            
            return true;
        }
        
        return false;
    }

    /**
     * Records relationship
     */
    public function records(): MorphMany
    {
        return $this->morphMany(Record::class, 'recordable');
    }

    /**
     * Tasks relationship
     */
    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Tasks relationship
     */
    public function reports()
    {
        return $this->hasMany(ConversationReport::class);
    }

    /**
     * Custom fields relationship
     */
    public function fields()
    {
        return $this->hasMany(LeadCustomField::class);
    }

    /**
     * Document relationship
     */
    public function docs()
    {
        return $this->hasMany(LeadDocument::class);
    }

    /**
     * Assign lead to a user.
     */
    public function assignTo(int $userId): bool
    {
        $user = User::where('id', $userId)
            ->where('company_id', $this->company_id)
            ->where('is_active', true)
            ->first();
            
        if ($user) {
            $this->update(['assigned_to' => $userId]);
            return true;
        }
        
        return false;
    }

    /**
     * Update contact timestamp.
     */
    public function updateLastContact(): bool
    {
        return $this->update([
            'last_contact_at' => now(),
        ]);
    }

    /**
     * Get contact information as array.
     */
    public function getContactInfo(): array
    {
        return array_filter([
            'email' => $this->email,
            'phone' => $this->phone,
            'username' => $this->username,
            'platform' => $this->source_platform,
        ]);
    }
    /**
     * Get available priority options.
     */
    public static function getPriorityOptions(): array
    {
        return [
            self::PRIORITY_LOW => 'Low',
            self::PRIORITY_MEDIUM => 'Medium',
            self::PRIORITY_HIGH => 'High',
            self::PRIORITY_URGENT => 'Urgent',
        ];
    }

    /**
     * Get available platform options.
     */
    public static function getPlatformOptions(): array
    {
        return [
            self::PLATFORM_WHATSAPP => 'WhatsApp',
            self::PLATFORM_FACEBOOK => 'Facebook',
            self::PLATFORM_INSTAGRAM => 'Instagram',
            self::PLATFORM_META_ADS => 'Meta Ads',
            self::PLATFORM_GOOGLE_ADS => 'Google Ads',
            self::PLATFORM_TELEGRAM => 'Telegram',
            self::PLATFORM_PHONE => 'Phone',
            self::PLATFORM_SMS => 'SMS',
            self::PLATFORM_EMAIL => 'Email',
            self::PLATFORM_WEBSITE => 'Website',
            self::PLATFORM_API => 'API',
            self::PLATFORM_OTHER => 'Other',
        ];
    }

    /**
     * Validation rules for lead creation.
     */
    public static function validationRules(): array
    {
        return [
            'company_id' => 'required|exists:companies,id',
            'funnel_id' => 'required|exists:funnels,id',
            'stage_id' => 'required|exists:stages,id',
            'assigned_to' => 'nullable|exists:users,id',
            'external_id' => 'nullable|string|max:255',
            'source_platform' => 'string|max:50',
            'source_type' => 'string|max:50',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:100',
            'priority' => 'in:low,medium,high,urgent',
            'currency' => 'string|size:3',
            'preferred_contact_method' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'language' => 'string|max:5',
            'ai_score' => 'nullable|numeric|between:0,100',
            'notes' => 'nullable|string',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:10',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ];
    }

    /**
     * Validation rules for lead update.
     */
    public static function updateValidationRules(): array
    {
        return [
            'assigned_to' => 'nullable|exists:users,id',
            'external_id' => 'nullable|string|max:255',
            'source_platform' => 'string|max:50',
            'source_type' => 'string|max:50',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:100',
            'priority' => 'in:low,medium,high,urgent',
            'currency' => 'string|size:3',
            'contact_methods' => 'nullable|array',
            'preferred_contact_method' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'language' => 'string|max:5',
            'ai_score' => 'nullable|numeric|between:0,100',
            'settings' => 'nullable|array',
            'notes' => 'nullable|string',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:10',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_active' => 'boolean',
            'is_qualified' => 'boolean',
            'lead_company_id' => 'nullable|exists:lead_companies,id',
        ];
    }
}