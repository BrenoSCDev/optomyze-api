<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'role',
        'permission_key',
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_key', 'key');
    }

    /**
     * Get all permission keys for a given role within a company.
     */
    public static function keysForRole(string $role, int $companyId): array
    {
        return static::where('company_id', $companyId)
            ->where('role', $role)
            ->pluck('permission_key')
            ->toArray();
    }

    /**
     * Seed the three default role templates for a newly created company.
     */
    public static function seedDefaultsForCompany(int $companyId): void
    {
        $allKeys = Permission::pluck('key')->toArray();

        $managerExcluded = [
            'roles.edit',
            'integrations.configure',
            'menu.api', 'menu.api.tokens', 'menu.api.setup', 'menu.api.playground',
            'sales.manage_pipelines',
        ];

        $agentKeys = [
            'menu.leads',
            'leads.view', 'leads.create', 'leads.edit', 'leads.move_stage',
            'leads.assign_user', 'leads.add_observations', 'leads.create_tasks',
            'leads.edit_tasks', 'leads.manage_tags', 'leads.mark_lost',
            'menu.deals', 'deals.view',
            'menu.chat', 'menu.chat.whatsapp_inbox', 'menu.chat.optomyze_inbox',
            'chat.view_whatsapp', 'chat.send_whatsapp', 'chat.view_inbox', 'chat.send_inbox',
            'menu.calendars', 'calendar.view', 'calendar.create', 'calendar.edit',
            'menu.settings', 'menu.settings.preferences',
            'menu.support', 'menu.support.open_ticket', 'menu.support.talk_to_optus',
            'search.leads', 'search.tasks',
            'reports.view_tasks',
        ];

        $roleTemplates = [
            'admin'   => $allKeys,
            'manager' => array_values(array_diff($allKeys, $managerExcluded)),
            'agent'   => $agentKeys,
        ];

        foreach ($roleTemplates as $role => $keys) {
            $rows = array_map(
                fn ($key) => ['company_id' => $companyId, 'role' => $role, 'permission_key' => $key],
                $keys
            );

            foreach (array_chunk($rows, 100) as $chunk) {
                static::insertOrIgnore($chunk);
            }
        }
    }
}
