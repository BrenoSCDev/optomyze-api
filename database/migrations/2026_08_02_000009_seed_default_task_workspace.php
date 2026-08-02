<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every existing company a usable workspace on day one and rehomes the
 * tasks they already have, so nobody logs in to an empty module.
 *
 * Legacy enum -> seeded status, by semantic type:
 *   pending -> To Do | in_progress -> In Progress
 *   completed -> Done | cancelled -> Cancelled
 *
 * Two properties this migration has to hold, because MySQL gives migrations no
 * transaction (Grammar::$transactions is false for everything but Postgres) and
 * a failure part-way through leaves the `migrations` row unwritten:
 *
 *  1. **Re-runnable.** Every insert is guarded on what already exists, so a
 *     re-run after a partial failure resumes instead of giving already-processed
 *     companies a second "Sales" space.
 *  2. **Bounded query count.** Work is batched per company rather than per task;
 *     a tenant with 50k tasks costs the same number of queries as one with 10.
 */
return new class extends Migration
{
    /** name, color, type, is_default */
    private const DEFAULT_STATUSES = [
        ['To Do',       '#94a3b8', 'not_started', true],
        ['In Progress', '#3b82f6', 'active',      false],
        ['Blocked',     '#f59e0b', 'active',      false],
        ['Done',        '#10b981', 'done',        false],
        ['Cancelled',   '#64748b', 'closed',      false],
    ];

    private const LEGACY_TO_TYPE = [
        'pending'     => 'not_started',
        'in_progress' => 'active',
        'completed'   => 'done',
        'cancelled'   => 'closed',
    ];

    private const SPACE_NAME = 'Sales';
    private const LIST_NAME  = 'General';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $spaceId = $this->ensureSpace($companyId, $now);
            $listId  = $this->ensureList($companyId, $spaceId, $now);

            $statusIdByType = $this->ensureStatuses($companyId, $listId, $now);

            $this->rehomeTasks($companyId, $listId, $statusIdByType);
        }
    }

    /**
     * Deliberately does nothing.
     *
     * The previous version cleared task_list_id/task_status_id on *every* task
     * and emptied task_spaces, task_lists and task_statuses outright — which
     * would have destroyed every space, list and custom column a company had
     * created since, not just the scaffolding seeded here. There is no reliable
     * way to tell the two apart after the fact: users rename and recolour these.
     *
     * Nothing is lost by the no-op. Rolling back far enough to actually undo
     * this feature passes through the create_task_* migrations, whose
     * dropIfExists removes the tables and their contents wholesale.
     */
    public function down(): void
    {
        // Intentionally empty — see the docblock above.
    }

    /* ------------------------------------------------------------ helpers */

    /** Reuse an existing seeded space so a re-run does not duplicate it. */
    private function ensureSpace(int $companyId, $now): int
    {
        $existing = DB::table('task_spaces')
            ->where('company_id', $companyId)
            ->where('name', self::SPACE_NAME)
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return DB::table('task_spaces')->insertGetId([
            'company_id'  => $companyId,
            'name'        => self::SPACE_NAME,
            'description' => 'Day-to-day sales execution',
            'color'       => '#6366f1',
            'icon'        => 'Target',
            'order'       => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    private function ensureList(int $companyId, int $spaceId, $now): int
    {
        $existing = DB::table('task_lists')
            ->where('company_id', $companyId)
            ->where('task_space_id', $spaceId)
            ->where('name', self::LIST_NAME)
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return DB::table('task_lists')->insertGetId([
            'company_id'    => $companyId,
            'task_space_id' => $spaceId,
            'name'          => self::LIST_NAME,
            'description'   => 'Tasks that came across from the previous task list',
            'color'         => '#6366f1',
            'order'         => 0,
            'default_view'  => 'list',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /**
     * @return array<string,int> semantic type => status id
     */
    private function ensureStatuses(int $companyId, int $listId, $now): array
    {
        // One read, then insert only what is missing.
        $existing = DB::table('task_statuses')
            ->where('task_list_id', $listId)
            ->get(['id', 'name', 'type']);

        $byName = $existing->keyBy('name');
        $idByType = [];

        foreach (self::DEFAULT_STATUSES as $i => [$name, $color, $type, $isDefault]) {
            $row = $byName->get($name);

            $statusId = $row->id ?? DB::table('task_statuses')->insertGetId([
                'company_id'   => $companyId,
                'task_list_id' => $listId,
                'name'         => $name,
                'color'        => $color,
                'type'         => $type,
                'order'        => $i,
                'is_default'   => $isDefault,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            // "Blocked" is also `active`; first one wins as the mapping target.
            $idByType[$type] ??= (int) $statusId;
        }

        return $idByType;
    }

    /**
     * File this company's unfiled tasks into the seeded list.
     *
     * One UPDATE per legacy status value (four) rather than one per task. The
     * previous version issued a query per row, which on a large tenant meant
     * tens of thousands of round trips inside a migration.
     *
     * `position` is derived from the primary key rather than a running counter:
     * it keeps every task distinct and stably ordered without needing a
     * per-row write, and the board is drag-reorderable afterwards anyway.
     */
    private function rehomeTasks(int $companyId, int $listId, array $statusIdByType): void
    {
        foreach (self::LEGACY_TO_TYPE as $legacyStatus => $type) {
            $statusId = $statusIdByType[$type] ?? null;
            if (!$statusId) {
                continue;
            }

            DB::table('tasks')
                ->where('company_id', $companyId)
                ->whereNull('task_list_id')
                ->where('status', $legacyStatus)
                ->update([
                    'task_list_id'   => $listId,
                    'task_status_id' => $statusId,
                    'position'       => DB::raw('id * 1000'),
                ]);
        }

        // Anything with an unrecognised legacy status still needs a home, or it
        // would be stranded outside every list.
        $fallback = $statusIdByType['not_started'] ?? null;
        if ($fallback) {
            DB::table('tasks')
                ->where('company_id', $companyId)
                ->whereNull('task_list_id')
                ->update([
                    'task_list_id'   => $listId,
                    'task_status_id' => $fallback,
                    'position'       => DB::raw('id * 1000'),
                ]);
        }
    }
};
