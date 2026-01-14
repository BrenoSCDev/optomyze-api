<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasFactory;

    /**
     * Lead status constants
     */
    const STATUS_NEW = 'new';
    const STATUS_CONTACTED = 'contacted';
    const STATUS_QUALIFIED = 'qualified';
    const STATUS_UNQUALIFIED = 'unqualified';
    const STATUS_CONVERTED = 'converted';
    const STATUS_LOST = 'lost';

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
    const PLATFORM_INSTAGRAM = 'instagram';
    const PLATFORM_TELEGRAM = 'telegram';
    const PLATFORM_FACEBOOK = 'facebook';
    const PLATFORM_WEBSITE = 'website';
    const PLATFORM_EMAIL = 'email';
    const PLATFORM_PHONE = 'phone';
    const PLATFORM_API = 'api';

    /**
     * Source type constants
     */
    const SOURCE_AI_AUTOMATION = 'ai_automation';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_WEB_FORM = 'web_form';
    const SOURCE_API = 'api';
    const SOURCE_IMPORT = 'import';
    const SOURCE_WEBHOOK = 'webhook';

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
        'workflow_id',
        'automation_name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'ddi',
        'username',
        'platform_user_id',
        'status',
        'priority',
        'estimated_value',
        'currency',
        'contact_methods',
        'last_contact_at',
        'preferred_contact_method',
        'timezone',
        'language',
        'ai_data',
        'conversation_data',
        'platform_data',
        'initial_message',
        'ai_score',
        'tags',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'referrer',
        'ip_address',
        'user_agent',
        'custom_fields',
        'settings',
        'notes',
        'is_active',
        'is_qualified',
        'qualified_at',
        'qualified_by',
        'webhook_data',
        'webhook_source',
        'last_sync_at',
        'sync_enabled',
        'erp_budgets_ids',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'estimated_value' => 'decimal:2',
        'ai_score' => 'decimal:2',
        'contact_methods' => 'array',
        'ai_data' => 'array',
        'conversation_data' => 'array',
        'platform_data' => 'array',
        'tags' => 'array',
        'custom_fields' => 'array',
        'settings' => 'array',
        'webhook_data' => 'array',
        'is_active' => 'boolean',
        'is_qualified' => 'boolean',
        'sync_enabled' => 'boolean',
        'last_contact_at' => 'datetime',
        'qualified_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Get the company that owns the lead.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
     * Get lead observations.
     */
    public function observations()
    {
        return $this->hasMany(Observation::class)->orderBy('created_at', 'desc');
    }
    

    /**
     * Get the user who qualified this lead.
     */
    public function qualifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    /**
     * Get the lead WhatsApp Evolution Chat
     */
    public function whatsAppEvoChat(): HasOne
    {
        return $this->hasOne(WhatsappEvoChat::class);
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
     * Scope a query to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
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
     * Check if lead is high value based on AI score or estimated value.
     */
    public function isHighValue(): bool
    {
        return $this->ai_score >= 80 || 
               $this->estimated_value >= 10000 || 
               $this->priority === self::PRIORITY_HIGH ||
               $this->priority === self::PRIORITY_URGENT;
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
            
            // Create transaction
            LeadTransaction::createStageChange($this, $previousStage, $nextStage, $user);
            
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
            
            // Create transaction
            LeadTransaction::createStageChange($this, $currentStage, $previousStage, $user);
            
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
            
            // Create transaction
            LeadTransaction::createStageChange($this, $previousStage, $stage, $user);
            
            return true;
        }
        
        return false;
    }

    /**
     * Transactions relationship
     */
    public function transactions()
    {
        return $this->hasMany(LeadTransaction::class);
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
     * Mark lead as qualified.
     */
    public function markAsQualified(int $userId): bool
    {
        return $this->update([
            'is_qualified' => true,
            'qualified_at' => now(),
            'qualified_by' => $userId,
            'status' => self::STATUS_QUALIFIED,
        ]);
    }

    /**
     * Mark lead as unqualified.
     */
    public function markAsUnqualified(int $userId): bool
    {
        return $this->update([
            'is_qualified' => false,
            'qualified_at' => now(),
            'qualified_by' => $userId,
            'status' => self::STATUS_UNQUALIFIED,
        ]);
    }

    /**
     * Update contact timestamp.
     */
    public function updateLastContact(): bool
    {
        return $this->update([
            'last_contact_at' => now(),
            'status' => self::STATUS_CONTACTED,
        ]);
    }

    /**
     * Add tags to the lead.
     */
    public function addTags(array $tags): bool
    {
        $currentTags = $this->tags ?? [];
        $newTags = array_unique(array_merge($currentTags, $tags));
        
        return $this->update(['tags' => $newTags]);
    }

    /**
     * Remove tags from the lead.
     */
    public function removeTags(array $tags): bool
    {
        $currentTags = $this->tags ?? [];
        $newTags = array_diff($currentTags, $tags);
        
        return $this->update(['tags' => array_values($newTags)]);
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
     * Sync with external platform.
     */
    public function syncWithPlatform(): bool
    {
        // This would be implemented based on specific platform APIs
        $this->update(['last_sync_at' => now()]);
        return true;
    }

    /**
     * Get available status options.
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_NEW => 'New',
            self::STATUS_CONTACTED => 'Contacted',
            self::STATUS_QUALIFIED => 'Qualified',
            self::STATUS_UNQUALIFIED => 'Unqualified',
            self::STATUS_CONVERTED => 'Converted',
            self::STATUS_LOST => 'Lost',
        ];
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
            self::PLATFORM_INSTAGRAM => 'Instagram',
            self::PLATFORM_TELEGRAM => 'Telegram',
            self::PLATFORM_FACEBOOK => 'Facebook',
            self::PLATFORM_WEBSITE => 'Website',
            self::PLATFORM_EMAIL => 'Email',
            self::PLATFORM_PHONE => 'Phone',
            self::PLATFORM_API => 'API',
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
            'workflow_id' => 'nullable|string|max:255',
            'automation_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'ddi' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:100',
            'platform_user_id' => 'nullable|string|max:255',
            'status' => 'in:new,contacted,qualified,unqualified,converted,lost',
            'priority' => 'in:low,medium,high,urgent',
            'estimated_value' => 'nullable|numeric|min:0',
            'currency' => 'string|size:3',
            'contact_methods' => 'nullable|array',
            'preferred_contact_method' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'language' => 'string|max:5',
            'ai_data' => 'nullable|array',
            'conversation_data' => 'nullable|array',
            'platform_data' => 'nullable|array',
            'initial_message' => 'nullable|string',
            'ai_score' => 'nullable|numeric|between:0,100',
            'tags' => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'settings' => 'nullable|array',
            'notes' => 'nullable|string',
            'webhook_data' => 'nullable|array',
            'webhook_source' => 'nullable|string|max:100',
            'sync_enabled' => 'boolean',
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
            'workflow_id' => 'nullable|string|max:255',
            'automation_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'ddi' => 'nullable|string|max:20',
            'username' => 'nullable|string|max:100',
            'platform_user_id' => 'nullable|string|max:255',
            'status' => 'in:new,contacted,qualified,unqualified,converted,lost',
            'priority' => 'in:low,medium,high,urgent',
            'estimated_value' => 'nullable|numeric|min:0',
            'currency' => 'string|size:3',
            'contact_methods' => 'nullable|array',
            'preferred_contact_method' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'language' => 'string|max:5',
            'ai_data' => 'nullable|array',
            'conversation_data' => 'nullable|array',
            'platform_data' => 'nullable|array',
            'initial_message' => 'nullable|string',
            'ai_score' => 'nullable|numeric|between:0,100',
            'tags' => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'settings' => 'nullable|array',
            'notes' => 'nullable|string',
            'webhook_data' => 'nullable|array',
            'webhook_source' => 'nullable|string|max:100',
            'sync_enabled' => 'boolean',
        ];
    }
}