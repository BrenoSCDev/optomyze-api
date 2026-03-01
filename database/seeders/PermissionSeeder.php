<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Upsert all permissions ─────────────────────────────────────
        $permissions = $this->allPermissions();

        foreach (array_chunk($permissions, 50) as $chunk) {
            Permission::upsert($chunk, ['key'], ['group', 'description']);
        }

        // ── 2. Seed default role templates per company ────────────────────
        $companyIds = DB::table('companies')->pluck('id');

        foreach ($companyIds as $companyId) {
            RolePermission::seedDefaultsForCompany($companyId);
        }
    }

    private function allPermissions(): array
    {
        return [
            // ── menu ──────────────────────────────────────────────────────
            ['key' => 'menu.dashboards',                      'group' => 'menu', 'description' => 'Access Dashboards section'],
            ['key' => 'menu.dashboards.optomyze_metrics',     'group' => 'menu', 'description' => 'View Optomyze Metrics dashboard'],
            ['key' => 'menu.dashboards.plan_usage',           'group' => 'menu', 'description' => 'View Plan Usage dashboard'],
            ['key' => 'menu.dashboards.meta_ads',             'group' => 'menu', 'description' => 'View Meta Ads dashboard'],
            ['key' => 'menu.dashboards.google_ads',           'group' => 'menu', 'description' => 'View Google Ads dashboard'],
            ['key' => 'menu.dashboards.google_analytics',     'group' => 'menu', 'description' => 'View Google Analytics dashboard'],
            ['key' => 'menu.leads',                           'group' => 'menu', 'description' => 'Access Leads section'],
            ['key' => 'menu.deals',                           'group' => 'menu', 'description' => 'Access Deals section'],
            ['key' => 'menu.products',                        'group' => 'menu', 'description' => 'Access Products section'],
            ['key' => 'menu.chat',                            'group' => 'menu', 'description' => 'Access Chat/Inbox section'],
            ['key' => 'menu.chat.whatsapp_inbox',             'group' => 'menu', 'description' => 'Access WhatsApp Inbox'],
            ['key' => 'menu.chat.optomyze_inbox',             'group' => 'menu', 'description' => 'Access Optomyze Internal Inbox'],
            ['key' => 'menu.calendars',                       'group' => 'menu', 'description' => 'Access Calendars section'],
            ['key' => 'menu.reports',                         'group' => 'menu', 'description' => 'Access Reports section'],
            ['key' => 'menu.reports.lead_activity',           'group' => 'menu', 'description' => 'View Lead Activity report'],
            ['key' => 'menu.reports.deal_activity',           'group' => 'menu', 'description' => 'View Deal Activity report'],
            ['key' => 'menu.reports.tasks',                   'group' => 'menu', 'description' => 'View Tasks report'],
            ['key' => 'menu.settings',                        'group' => 'menu', 'description' => 'Access Settings section'],
            ['key' => 'menu.settings.crm_panel',              'group' => 'menu', 'description' => 'Access CRM Panel settings'],
            ['key' => 'menu.settings.funnels',                'group' => 'menu', 'description' => 'Access Funnel settings'],
            ['key' => 'menu.settings.companies',              'group' => 'menu', 'description' => 'Access Companies settings'],
            ['key' => 'menu.settings.tags',                   'group' => 'menu', 'description' => 'Access Tags settings'],
            ['key' => 'menu.settings.preferences',            'group' => 'menu', 'description' => 'Access Preferences settings'],
            ['key' => 'menu.organization',                    'group' => 'menu', 'description' => 'Access Organization section'],
            ['key' => 'menu.organization.users',              'group' => 'menu', 'description' => 'Manage Users page'],
            ['key' => 'menu.organization.teams',              'group' => 'menu', 'description' => 'Manage Teams page'],
            ['key' => 'menu.organization.roles',              'group' => 'menu', 'description' => 'Manage Roles page'],
            ['key' => 'menu.integrations',                    'group' => 'menu', 'description' => 'Access Integrations section'],
            ['key' => 'menu.integrations.apps',               'group' => 'menu', 'description' => 'Access Integration Apps'],
            ['key' => 'menu.integrations.panel',              'group' => 'menu', 'description' => 'Access Integration Panel'],
            ['key' => 'menu.api',                             'group' => 'menu', 'description' => 'Access API section'],
            ['key' => 'menu.api.tokens',                      'group' => 'menu', 'description' => 'Access API Tokens'],
            ['key' => 'menu.api.setup',                       'group' => 'menu', 'description' => 'Access API Setup'],
            ['key' => 'menu.api.playground',                  'group' => 'menu', 'description' => 'Access API Playground'],
            ['key' => 'menu.support',                         'group' => 'menu', 'description' => 'Access Support section'],
            ['key' => 'menu.support.open_ticket',             'group' => 'menu', 'description' => 'Open Support Tickets'],
            ['key' => 'menu.support.talk_to_optus',           'group' => 'menu', 'description' => 'Access Optus AI Chat'],

            // ── leads ─────────────────────────────────────────────────────
            ['key' => 'leads.view',                 'group' => 'leads', 'description' => 'View/read leads'],
            ['key' => 'leads.create',               'group' => 'leads', 'description' => 'Create new leads'],
            ['key' => 'leads.edit',                 'group' => 'leads', 'description' => 'Edit existing leads'],
            ['key' => 'leads.delete',               'group' => 'leads', 'description' => 'Delete leads'],
            ['key' => 'leads.move_stage',           'group' => 'leads', 'description' => 'Move leads between stages'],
            ['key' => 'leads.assign_user',          'group' => 'leads', 'description' => 'Assign a user/owner to a lead'],
            ['key' => 'leads.add_products',         'group' => 'leads', 'description' => 'Add products of interest to a lead'],
            ['key' => 'leads.remove_products',      'group' => 'leads', 'description' => 'Remove products from a lead'],
            ['key' => 'leads.add_observations',     'group' => 'leads', 'description' => 'Add observations/notes to a lead'],
            ['key' => 'leads.upload_documents',     'group' => 'leads', 'description' => 'Upload documents to a lead'],
            ['key' => 'leads.delete_documents',     'group' => 'leads', 'description' => 'Delete documents from a lead'],
            ['key' => 'leads.create_tasks',         'group' => 'leads', 'description' => 'Create tasks on a lead'],
            ['key' => 'leads.edit_tasks',           'group' => 'leads', 'description' => 'Edit tasks on a lead'],
            ['key' => 'leads.delete_tasks',         'group' => 'leads', 'description' => 'Delete tasks on a lead'],
            ['key' => 'leads.manage_tags',          'group' => 'leads', 'description' => 'Add/remove tags on leads'],
            ['key' => 'leads.start_purchase',       'group' => 'leads', 'description' => 'Start a purchase/sale from a lead'],
            ['key' => 'leads.create_quote',         'group' => 'leads', 'description' => 'Create quotes for a lead'],
            ['key' => 'leads.export',               'group' => 'leads', 'description' => 'Export leads to Excel'],
            ['key' => 'leads.view_budget',          'group' => 'leads', 'description' => 'View budget/financial info on leads'],
            ['key' => 'leads.mark_lost',            'group' => 'leads', 'description' => 'Mark a lead as lost (with reason)'],

            // ── deals ─────────────────────────────────────────────────────
            ['key' => 'deals.view',         'group' => 'deals', 'description' => 'View/read deals'],
            ['key' => 'deals.create',       'group' => 'deals', 'description' => 'Create new deals'],
            ['key' => 'deals.edit',         'group' => 'deals', 'description' => 'Edit existing deals'],
            ['key' => 'deals.delete',       'group' => 'deals', 'description' => 'Delete deals'],
            ['key' => 'deals.move_stage',   'group' => 'deals', 'description' => 'Move deals between pipeline stages'],
            ['key' => 'deals.manage_tags',  'group' => 'deals', 'description' => 'Add/remove tags on deals'],
            ['key' => 'deals.export',       'group' => 'deals', 'description' => 'Export deals to Excel'],

            // ── products ──────────────────────────────────────────────────
            ['key' => 'products.view',                  'group' => 'products', 'description' => 'View products'],
            ['key' => 'products.create',                'group' => 'products', 'description' => 'Create new products'],
            ['key' => 'products.edit',                  'group' => 'products', 'description' => 'Edit existing products'],
            ['key' => 'products.delete',                'group' => 'products', 'description' => 'Delete products'],
            ['key' => 'products.toggle_active',         'group' => 'products', 'description' => 'Activate/deactivate products'],
            ['key' => 'products.manage_images',         'group' => 'products', 'description' => 'Upload/delete product images'],
            ['key' => 'products.manage_custom_fields',  'group' => 'products', 'description' => 'Manage custom product fields'],

            // ── users ─────────────────────────────────────────────────────
            ['key' => 'users.view',   'group' => 'users', 'description' => 'View users list'],
            ['key' => 'users.create', 'group' => 'users', 'description' => 'Create new users'],
            ['key' => 'users.edit',   'group' => 'users', 'description' => 'Edit user profiles'],
            ['key' => 'users.delete', 'group' => 'users', 'description' => 'Delete/deactivate users'],

            // ── teams ─────────────────────────────────────────────────────
            ['key' => 'teams.view',   'group' => 'teams', 'description' => 'View teams'],
            ['key' => 'teams.create', 'group' => 'teams', 'description' => 'Create new teams'],
            ['key' => 'teams.edit',   'group' => 'teams', 'description' => 'Edit teams'],
            ['key' => 'teams.delete', 'group' => 'teams', 'description' => 'Delete teams'],

            // ── roles ─────────────────────────────────────────────────────
            ['key' => 'roles.view', 'group' => 'roles', 'description' => 'View roles'],
            ['key' => 'roles.edit', 'group' => 'roles', 'description' => 'Edit role permissions'],

            // ── funnels ───────────────────────────────────────────────────
            ['key' => 'funnels.view',                'group' => 'funnels', 'description' => 'View funnels'],
            ['key' => 'funnels.create',              'group' => 'funnels', 'description' => 'Create new funnels'],
            ['key' => 'funnels.edit',                'group' => 'funnels', 'description' => 'Edit funnel structure/stages'],
            ['key' => 'funnels.delete',              'group' => 'funnels', 'description' => 'Delete funnels'],
            ['key' => 'funnels.manage_stages',       'group' => 'funnels', 'description' => 'Add/edit/reorder stages'],
            ['key' => 'funnels.manage_entry_rules',  'group' => 'funnels', 'description' => 'Configure stage entry requirements'],
            ['key' => 'funnels.manage_stage_actions','group' => 'funnels', 'description' => 'Configure stage actions'],

            // ── tags ──────────────────────────────────────────────────────
            ['key' => 'tags.view',   'group' => 'tags', 'description' => 'View tags'],
            ['key' => 'tags.create', 'group' => 'tags', 'description' => 'Create new tags'],
            ['key' => 'tags.edit',   'group' => 'tags', 'description' => 'Edit tags'],
            ['key' => 'tags.delete', 'group' => 'tags', 'description' => 'Delete tags'],

            // ── companies ─────────────────────────────────────────────────
            ['key' => 'companies.view',   'group' => 'companies', 'description' => 'View lead companies'],
            ['key' => 'companies.create', 'group' => 'companies', 'description' => 'Create lead companies'],
            ['key' => 'companies.edit',   'group' => 'companies', 'description' => 'Edit lead companies'],
            ['key' => 'companies.delete', 'group' => 'companies', 'description' => 'Delete lead companies'],

            // ── integrations ──────────────────────────────────────────────
            ['key' => 'integrations.view',              'group' => 'integrations', 'description' => 'View integrations'],
            ['key' => 'integrations.configure',         'group' => 'integrations', 'description' => 'Configure/install integrations'],
            ['key' => 'integrations.manage_whatsapp',   'group' => 'integrations', 'description' => 'Manage WhatsApp instances'],
            ['key' => 'integrations.manage_n8n',        'group' => 'integrations', 'description' => 'Configure N8N agents'],
            ['key' => 'integrations.manage_meta_ads',   'group' => 'integrations', 'description' => 'Configure Meta Ads'],
            ['key' => 'integrations.manage_google_ads', 'group' => 'integrations', 'description' => 'Configure Google Ads'],

            // ── calendar ──────────────────────────────────────────────────
            ['key' => 'calendar.view',   'group' => 'calendar', 'description' => 'View calendar events'],
            ['key' => 'calendar.create', 'group' => 'calendar', 'description' => 'Create events'],
            ['key' => 'calendar.edit',   'group' => 'calendar', 'description' => 'Edit events'],
            ['key' => 'calendar.delete', 'group' => 'calendar', 'description' => 'Delete events'],

            // ── chat ──────────────────────────────────────────────────────
            ['key' => 'chat.view_whatsapp',  'group' => 'chat', 'description' => 'View WhatsApp conversations'],
            ['key' => 'chat.send_whatsapp',  'group' => 'chat', 'description' => 'Send WhatsApp messages'],
            ['key' => 'chat.view_inbox',     'group' => 'chat', 'description' => 'View internal inbox'],
            ['key' => 'chat.send_inbox',     'group' => 'chat', 'description' => 'Send internal messages'],
            ['key' => 'chat.create_groups',  'group' => 'chat', 'description' => 'Create chat groups'],
            ['key' => 'chat.manage_groups',  'group' => 'chat', 'description' => 'Edit/delete chat groups'],

            // ── reports ───────────────────────────────────────────────────
            ['key' => 'reports.view_lead_activity', 'group' => 'reports', 'description' => 'View lead activity reports'],
            ['key' => 'reports.view_deal_activity', 'group' => 'reports', 'description' => 'View deal activity reports'],
            ['key' => 'reports.view_tasks',         'group' => 'reports', 'description' => 'View task reports'],

            // ── sales ─────────────────────────────────────────────────────
            ['key' => 'sales.manage_pipelines',      'group' => 'sales', 'description' => 'Configure sales pipelines'],
            ['key' => 'sales.manage_commissions',    'group' => 'sales', 'description' => 'Configure commissions'],
            ['key' => 'sales.manage_automations',    'group' => 'sales', 'description' => 'Configure sales automations'],
            ['key' => 'sales.manage_pricing_rules',  'group' => 'sales', 'description' => 'Configure product pricing rules'],
            ['key' => 'sales.manage_notifications',  'group' => 'sales', 'description' => 'Configure notification settings'],

            // ── search ────────────────────────────────────────────────────
            ['key' => 'search.leads',    'group' => 'search', 'description' => 'Search leads globally'],
            ['key' => 'search.deals',    'group' => 'search', 'description' => 'Search deals globally'],
            ['key' => 'search.products', 'group' => 'search', 'description' => 'Search products globally'],
            ['key' => 'search.tasks',    'group' => 'search', 'description' => 'Search tasks globally'],
            ['key' => 'search.users',    'group' => 'search', 'description' => 'Search users globally'],
        ];
    }
}
