<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'n8n_agent_id',
        'platform',
        'content',
    ];

    /**
     * Validation rules for creating/updating reports.
     */
    public static function validationRules(): array
    {
        return [
            'lead_id'     => 'required|exists:leads,id',
            'workflow_id' => 'nullable|exists:n8n_agents,workflow_id',
            'platform'    => 'nullable|string|max:255',
            'content'     => 'required|string',
        ];
    }

    /**
     * Relationships
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent()
    {
        return $this->belongsTo(N8nAgent::class, 'n8n_agent_id');
    }

    /**
     * Create a new ConversationReport.
     */
    public static function createReport(array $data): self
    {
        $validated = validator($data, self::validationRules())->validate();

        // If workflow_id is given, map it to an agent_id
        if (!empty($validated['workflow_id'])) {
            $agent = N8nAgent::where('workflow_id', $validated['workflow_id'])->first();
            if ($agent) {
                $validated['n8n_agent_id'] = $agent->id;
            }
            unset($validated['workflow_id']);
        }

        return self::create($validated);
    }

    /**
     * Get all reports for a specific lead.
     */
    public static function forLead(int $leadId)
    {
        return self::where('lead_id', $leadId)->get();
    }

    /**
     * Get all reports for a specific agent.
     */
    public static function forAgent(int $agentId)
    {
        return self::where('n8n_agent_id', $agentId)->get();
    }
}
