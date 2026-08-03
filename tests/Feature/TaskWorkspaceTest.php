<?php

use App\Models\Company;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskSpace;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A company with a space, a list and its five default statuses. */
function makeWorkspace(string $slug = 'acme'): array
{
    $company = Company::create(['name' => ucfirst($slug), 'email' => "{$slug}-tasks@example.com"]);
    $user    = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);

    $space = TaskSpace::create([
        'company_id' => $company->id,
        'name'       => 'Sales',
        'created_by' => $user->id,
    ]);

    $list = TaskList::create([
        'company_id'    => $company->id,
        'task_space_id' => $space->id,
        'name'          => 'Prospecting',
        'created_by'    => $user->id,
    ]);
    $list->seedDefaultStatuses();

    return compact('company', 'user', 'space', 'list');
}

/*
|--------------------------------------------------------------------------
| Hierarchy
|--------------------------------------------------------------------------
*/

test('the space tree returns lists with an open task count', function () {
    $w = makeWorkspace();
    $todo = $w['list']->statuses()->where('type', 'not_started')->first();
    $done = $w['list']->statuses()->where('type', 'done')->first();

    foreach (range(1, 3) as $i) {
        Task::create([
            'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
            'task_status_id' => $todo->id, 'title' => "Open {$i}",
        ]);
    }
    Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $done->id, 'title' => 'Finished',
    ]);

    $res = $this->actingAs($w['user'])->getJson('/api/task-spaces');

    $res->assertOk();
    // The completed one must not be counted as outstanding work.
    expect($res->json('data.0.lists.0.open_tasks_count'))->toBe(3);
});

test('a new list is seeded with a working set of statuses', function () {
    $w = makeWorkspace();

    $res = $this->actingAs($w['user'])->postJson('/api/task-lists', [
        'task_space_id' => $w['space']->id,
        'name'          => 'Onboarding',
    ]);

    $res->assertCreated();
    expect($res->json('data.statuses'))->toHaveCount(5);
    // Exactly one landing column, or new tasks would have nowhere defined to go.
    expect(collect($res->json('data.statuses'))->where('is_default', true))->toHaveCount(1);
});

test('archiving a space archives its lists so none are left orphaned in the sidebar', function () {
    $w = makeWorkspace();

    $this->actingAs($w['user'])
        ->patchJson("/api/task-spaces/{$w['space']->id}/archive", ['archived' => true])
        ->assertOk();

    expect($w['list']->fresh()->archived_at)->not->toBeNull();
    expect($this->actingAs($w['user'])->getJson('/api/task-spaces')->json('data'))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Derived legacy status — the back-compat contract
|--------------------------------------------------------------------------
*/

test('moving a task into a done column mirrors the legacy enum and stamps completed_at', function () {
    $w = makeWorkspace();
    $todo = $w['list']->statuses()->where('type', 'not_started')->first();
    $done = $w['list']->statuses()->where('type', 'done')->first();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $todo->id, 'title' => 'Call back',
    ]);

    expect($task->status)->toBe('pending');

    $task->update(['task_status_id' => $done->id]);

    expect($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

test('dragging a task back out of done reopens it instead of leaving it finished', function () {
    $w = makeWorkspace();
    $done = $w['list']->statuses()->where('type', 'done')->first();
    $prog = $w['list']->statuses()->where('name', 'In Progress')->first();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $done->id, 'title' => 'Premature',
    ]);
    expect($task->fresh()->completed_at)->not->toBeNull();

    $task->update(['task_status_id' => $prog->id]);

    expect($task->fresh()->status)->toBe('in_progress')
        ->and($task->fresh()->completed_at)->toBeNull();
});

test('renaming a column keeps its semantics but retyping it resyncs the tasks inside', function () {
    $w = makeWorkspace();
    $todo = $w['list']->statuses()->where('type', 'not_started')->first();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $todo->id, 'title' => 'Shifting',
    ]);

    // Pure rename — the task stays pending.
    $this->actingAs($w['user'])
        ->putJson("/api/task-lists/{$w['list']->id}/statuses/{$todo->id}", ['name' => 'Backlog'])
        ->assertOk();
    expect($task->fresh()->status)->toBe('pending');

    // Retyping the column changes what it means for everything in it.
    $this->actingAs($w['user'])
        ->putJson("/api/task-lists/{$w['list']->id}/statuses/{$todo->id}", ['type' => 'done'])
        ->assertOk();
    expect($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

test('deleting a column rehomes its tasks rather than stranding them', function () {
    $w = makeWorkspace();
    $blocked = $w['list']->statuses()->where('name', 'Blocked')->first();
    $todo    = $w['list']->statuses()->where('type', 'not_started')->first();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $blocked->id, 'title' => 'Stuck',
    ]);

    $this->actingAs($w['user'])
        ->deleteJson("/api/task-lists/{$w['list']->id}/statuses/{$blocked->id}", ['reassign_to' => $todo->id])
        ->assertOk();

    expect($task->fresh()->task_status_id)->toBe($todo->id)
        ->and($task->fresh()->status)->toBe('pending');
});

test('the last remaining column cannot be deleted', function () {
    $w = makeWorkspace();
    $keep = $w['list']->statuses()->orderBy('order')->first();
    TaskStatus::where('task_list_id', $w['list']->id)->where('id', '!=', $keep->id)->delete();

    $this->actingAs($w['user'])
        ->deleteJson("/api/task-lists/{$w['list']->id}/statuses/{$keep->id}")
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Tenancy — the hole this rewrite closes
|--------------------------------------------------------------------------
*/

test('a task from another company is invisible, not editable and not deletable', function () {
    $mine    = makeWorkspace('mine');
    $theirs  = makeWorkspace('theirs');

    $foreign = Task::create([
        'company_id'     => $theirs['company']->id,
        'task_list_id'   => $theirs['list']->id,
        'task_status_id' => $theirs['list']->statuses()->first()->id,
        'title'          => 'Their secret deal',
    ]);

    $this->actingAs($mine['user'])->getJson("/api/tasks/{$foreign->id}")->assertNotFound();
    $this->actingAs($mine['user'])->putJson("/api/tasks/{$foreign->id}", ['title' => 'Hijacked'])->assertNotFound();
    $this->actingAs($mine['user'])->deleteJson("/api/tasks/{$foreign->id}")->assertNotFound();

    expect($foreign->fresh()->title)->toBe('Their secret deal');
});

test('a foreign space, list and status are all unreachable', function () {
    $mine   = makeWorkspace('mine');
    $theirs = makeWorkspace('theirs');
    $status = $theirs['list']->statuses()->first();

    $this->actingAs($mine['user'])->getJson("/api/task-spaces/{$theirs['space']->id}")->assertNotFound();
    $this->actingAs($mine['user'])->getJson("/api/task-lists/{$theirs['list']->id}")->assertNotFound();
    $this->actingAs($mine['user'])
        ->putJson("/api/task-lists/{$theirs['list']->id}/statuses/{$status->id}", ['name' => 'X'])
        ->assertNotFound();
});

test('a task cannot be assigned to a user from another company', function () {
    $mine   = makeWorkspace('mine');
    $theirs = makeWorkspace('theirs');

    $res = $this->actingAs($mine['user'])->postJson('/api/tasks', [
        'title'        => 'Cross-tenant assign',
        'task_list_id' => $mine['list']->id,
        'assignee_ids' => [$theirs['user']->id],
    ]);

    $res->assertCreated();
    // Silently dropped rather than accepted.
    expect(Task::find($res->json('data.id'))->assignees)->toHaveCount(0);
});

test('a task cannot be put into a column belonging to a different list', function () {
    $w = makeWorkspace();
    $other = TaskList::create([
        'company_id' => $w['company']->id, 'task_space_id' => $w['space']->id, 'name' => 'Other',
    ]);
    $other->seedDefaultStatuses();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => 'Fixed',
    ]);

    $this->actingAs($w['user'])
        ->putJson("/api/tasks/{$task->id}", ['task_status_id' => $other->statuses()->first()->id])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Creating, moving, filtering
|--------------------------------------------------------------------------
*/

test('a new task lands in the default column with assignees, tags and a primary assignee', function () {
    $w   = makeWorkspace();
    $tag = Tag::create(['company_id' => $w['company']->id, 'name' => 'VIP']);
    $mate = User::factory()->create(['company_id' => $w['company']->id]);

    $res = $this->actingAs($w['user'])->postJson('/api/tasks', [
        'title'        => 'Prepare quote',
        'task_list_id' => $w['list']->id,
        'priority'     => 'urgent',
        'assignee_ids' => [$mate->id, $w['user']->id],
        'tag_ids'      => [$tag->id],
    ]);

    $res->assertCreated();
    $task = Task::find($res->json('data.id'));

    expect($task->status)->toBe('pending')
        ->and($task->task_status_id)->toBe($w['list']->defaultStatus()->id)
        ->and($task->assignees)->toHaveCount(2)
        // assigned_to stays populated so every legacy consumer still sees an owner.
        ->and($task->assigned_to)->toBe($mate->id)
        ->and($task->tags)->toHaveCount(1);
});

test('subtasks nest one level deep and a subtask of a subtask is re-parented', function () {
    $w = makeWorkspace();

    $parent = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => 'Parent',
        'created_by' => $w['user']->id,
    ]);

    $child = $this->actingAs($w['user'])->postJson('/api/tasks', [
        'title' => 'Child', 'task_list_id' => $w['list']->id, 'parent_task_id' => $parent->id,
    ])->json('data.id');

    $grandchild = $this->actingAs($w['user'])->postJson('/api/tasks', [
        'title' => 'Grandchild', 'task_list_id' => $w['list']->id, 'parent_task_id' => $child,
    ])->json('data.id');

    expect(Task::find($grandchild)->parent_task_id)->toBe($parent->id);
});

test('the flat feed hides subtasks but the board keeps every column including empty ones', function () {
    $w = makeWorkspace();
    $status = $w['list']->statuses()->first();

    $parent = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $status->id, 'title' => 'Parent', 'created_by' => $w['user']->id,
    ]);
    Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $status->id, 'title' => 'Sub', 'parent_task_id' => $parent->id,
        'created_by' => $w['user']->id,
    ]);

    $feed = $this->actingAs($w['user'])->getJson("/api/tasks/feed?list_id={$w['list']->id}");
    expect($feed->json('meta.total'))->toBe(1);

    $board = $this->actingAs($w['user'])->getJson("/api/tasks/board?list_id={$w['list']->id}");
    // Empty columns are still valid drop targets, so all five must come back.
    expect($board->json('data.columns'))->toHaveCount(5);
    expect($board->json('data.columns.0.tasks'))->toHaveCount(1);
});

test('moving a task to another list re-homes it into that list default column', function () {
    $w = makeWorkspace();
    $other = TaskList::create([
        'company_id' => $w['company']->id, 'task_space_id' => $w['space']->id, 'name' => 'Other',
    ]);
    $other->seedDefaultStatuses();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => 'Travelling',
        'created_by' => $w['user']->id,
    ]);

    $this->actingAs($w['user'])
        ->patchJson("/api/tasks/{$task->id}/move", ['task_list_id' => $other->id])
        ->assertOk();

    expect($task->fresh()->task_list_id)->toBe($other->id)
        ->and($task->fresh()->task_status_id)->toBe($other->defaultStatus()->id);
});

test('reordering slots a task between its neighbours without touching them', function () {
    $w = makeWorkspace();
    $status = $w['list']->statuses()->first();

    // Created through the API so each gets a real spaced position; building
    // them straight off the model would leave every position at the column
    // default and make the midpoint assertion meaningless.
    $make = fn (string $t) => Task::find(
        $this->actingAs($w['user'])->postJson('/api/tasks', [
            'title' => $t, 'task_list_id' => $w['list']->id, 'task_status_id' => $status->id,
        ])->json('data.id')
    );

    $a = $make('A'); $b = $make('B'); $c = $make('C');
    [$posA, $posB] = [$a->position, $b->position];
    expect($posA)->toBeLessThan($posB);

    $this->actingAs($w['user'])
        ->patchJson("/api/tasks/{$c->id}/move", ['before_id' => $a->id, 'after_id' => $b->id])
        ->assertOk();

    expect($c->fresh()->position)->toBeGreaterThan($posA)->toBeLessThan($posB)
        // Neighbours untouched — the whole point of fractional positions.
        ->and($a->fresh()->position)->toBe($posA)
        ->and($b->fresh()->position)->toBe($posB);
});

test('archived tasks are hidden by default and returned only when asked for', function () {
    $w = makeWorkspace();
    $status = $w['list']->statuses()->first();

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $status->id, 'title' => 'Old news', 'created_by' => $w['user']->id,
    ]);

    $this->actingAs($w['user'])->patchJson("/api/tasks/{$task->id}/archive", ['archived' => true])->assertOk();

    expect($this->actingAs($w['user'])->getJson('/api/tasks/feed')->json('meta.total'))->toBe(0);
    expect($this->actingAs($w['user'])->getJson('/api/tasks/feed?archived=archived')->json('meta.total'))->toBe(1);
    expect($this->actingAs($w['user'])->getJson('/api/tasks/feed?archived=all')->json('meta.total'))->toBe(1);
});

test('the semantic status filter works regardless of what a company renamed its columns to', function () {
    $w = makeWorkspace();
    $todo = $w['list']->statuses()->where('type', 'not_started')->first();
    $done = $w['list']->statuses()->where('type', 'done')->first();
    $todo->update(['name' => 'Icebox']);

    Task::create(['company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $todo->id, 'title' => 'A', 'created_by' => $w['user']->id]);
    Task::create(['company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $done->id, 'title' => 'B', 'created_by' => $w['user']->id]);

    $res = $this->actingAs($w['user'])->getJson('/api/tasks/feed?status_type[]=not_started');

    expect($res->json('meta.total'))->toBe(1)
        ->and($res->json('data.0.title'))->toBe('A');
});

test('the calendar feed returns only dated tasks inside the window', function () {
    $w = makeWorkspace();
    $status = $w['list']->statuses()->first();

    $base = ['company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
             'task_status_id' => $status->id, 'created_by' => $w['user']->id];

    Task::create([...$base, 'title' => 'In window',  'due_date' => now()->addDays(2)]);
    Task::create([...$base, 'title' => 'Far future', 'due_date' => now()->addDays(60)]);
    Task::create([...$base, 'title' => 'Undated']);

    $res = $this->actingAs($w['user'])->getJson('/api/tasks/calendar?' . http_build_query([
        'start' => now()->startOfDay()->toDateTimeString(),
        'end'   => now()->addDays(7)->toDateTimeString(),
    ]));

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.title'))->toBe('In window');
});

/*
|--------------------------------------------------------------------------
| Sales linkage
|--------------------------------------------------------------------------
*/

test('a task can be linked to a lead but not to another company lead', function () {
    $mine   = makeWorkspace('mine');
    $theirs = makeWorkspace('theirs');

    $myLead = Lead::create([
        'company_id' => $mine['company']->id, 'first_name' => 'Ana', 'last_name' => 'Souza',
        'email' => 'ana@example.com', 'phone' => '5562911110000',
    ]);
    $theirLead = Lead::create([
        'company_id' => $theirs['company']->id, 'first_name' => 'Bob', 'last_name' => 'Other',
        'email' => 'bob@example.com', 'phone' => '5562911110001',
    ]);

    $this->actingAs($mine['user'])->postJson('/api/tasks', [
        'title' => 'Own lead', 'task_list_id' => $mine['list']->id, 'lead_id' => $myLead->id,
    ])->assertCreated();

    $this->actingAs($mine['user'])->postJson('/api/tasks', [
        'title' => 'Foreign lead', 'task_list_id' => $mine['list']->id, 'lead_id' => $theirLead->id,
    ])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Comments, checklist, bulk
|--------------------------------------------------------------------------
*/

test('commenting starts the author watching and only the author may edit', function () {
    $w    = makeWorkspace();
    $mate = User::factory()->create(['company_id' => $w['company']->id]);

    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => 'Discuss',
        'created_by' => $w['user']->id,
    ]);
    // Mate has to be able to *see* the task before "may they edit this comment?"
    // is even reachable — otherwise the lookup 404s first.
    $task->assignees()->attach($mate->id);

    $commentId = $this->actingAs($w['user'])
        ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Chasing this today'])
        ->assertCreated()->json('data.id');

    expect($task->fresh()->watchers)->toHaveCount(1);

    $this->actingAs($mate)
        ->putJson("/api/tasks/{$task->id}/comments/{$commentId}", ['body' => 'Not mine to edit'])
        ->assertForbidden();
});

test('a mention of a foreign user is dropped from the parsed mentions', function () {
    $mine   = makeWorkspace('mine');
    $theirs = makeWorkspace('theirs');

    $task = Task::create([
        'company_id' => $mine['company']->id, 'task_list_id' => $mine['list']->id,
        'task_status_id' => $mine['list']->statuses()->first()->id, 'title' => 'Mentions',
        'created_by' => $mine['user']->id,
    ]);

    $this->actingAs($mine['user'])->postJson("/api/tasks/{$task->id}/comments", [
        'body' => "cc @[{$theirs['user']->id}] and @[{$mine['user']->id}]",
    ])->assertCreated();

    $mentions = $task->comments()->first()->mentions;
    expect($mentions)->toBe([$mine['user']->id]);
});

test('ticking a checklist item records who completed it and clears on untick', function () {
    $w = makeWorkspace();
    $task = Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => 'Checklist host',
        'created_by' => $w['user']->id,
    ]);

    $itemId = $this->actingAs($w['user'])
        ->postJson("/api/tasks/{$task->id}/checklist", ['title' => 'Send the deck'])
        ->assertCreated()->json('data.id');

    $this->actingAs($w['user'])
        ->putJson("/api/tasks/{$task->id}/checklist/{$itemId}", ['is_done' => true])->assertOk();

    $item = $task->checklistItems()->find($itemId);
    expect($item->is_done)->toBeTrue()
        ->and($item->completed_by)->toBe($w['user']->id)
        ->and($item->completed_at)->not->toBeNull();

    $this->actingAs($w['user'])
        ->putJson("/api/tasks/{$task->id}/checklist/{$itemId}", ['is_done' => false])->assertOk();

    $item = $task->checklistItems()->find($itemId);
    expect($item->completed_at)->toBeNull()->and($item->completed_by)->toBeNull();
});

test('bulk actions apply only to tasks inside the caller company', function () {
    $mine   = makeWorkspace('mine');
    $theirs = makeWorkspace('theirs');

    $ours = Task::create([
        'company_id' => $mine['company']->id, 'task_list_id' => $mine['list']->id,
        'task_status_id' => $mine['list']->statuses()->first()->id, 'title' => 'Ours',
        'created_by' => $mine['user']->id,
    ]);
    $foreign = Task::create([
        'company_id' => $theirs['company']->id, 'task_list_id' => $theirs['list']->id,
        'task_status_id' => $theirs['list']->statuses()->first()->id, 'title' => 'Theirs',
    ]);

    $res = $this->actingAs($mine['user'])->postJson('/api/tasks/bulk', [
        'task_ids' => [$ours->id, $foreign->id],
        'action'   => 'archive',
    ]);

    $res->assertOk();
    expect($res->json('updated'))->toBe(1)
        ->and($ours->fresh()->archived_at)->not->toBeNull()
        ->and($foreign->fresh()->archived_at)->toBeNull();
});

test('bulk priority and bulk assign work across a selection', function () {
    $w    = makeWorkspace();
    $mate = User::factory()->create(['company_id' => $w['company']->id]);

    $ids = collect(range(1, 3))->map(fn ($i) => Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => "T{$i}",
        'created_by' => $w['user']->id,
    ])->id)->all();

    $this->actingAs($w['user'])->postJson('/api/tasks/bulk', [
        'task_ids' => $ids, 'action' => 'priority', 'priority' => 'urgent',
    ])->assertOk();

    $this->actingAs($w['user'])->postJson('/api/tasks/bulk', [
        'task_ids' => $ids, 'action' => 'assign', 'user_ids' => [$mate->id],
    ])->assertOk();

    expect(Task::whereIn('id', $ids)->where('priority', 'urgent')->count())->toBe(3)
        ->and(Task::whereIn('id', $ids)->where('assigned_to', $mate->id)->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Legacy contract
|--------------------------------------------------------------------------
*/

test('the legacy company tasks endpoint still returns an unpaginated data array', function () {
    $w = makeWorkspace();
    Task::create([
        'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
        'task_status_id' => $w['list']->statuses()->first()->id, 'title' => 'Legacy read',
        'created_by' => $w['user']->id,
    ]);

    $res = $this->actingAs($w['user'])->getJson('/api/tasks');

    $res->assertOk()->assertJsonStructure(['data' => [['id', 'title', 'status', 'priority']]]);
    expect($res->json('data'))->toHaveCount(1);
});

test('the summary counts open, overdue and due-today work for the current user', function () {
    $w = makeWorkspace();
    $status = $w['list']->statuses()->first();
    $base = ['company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
             'task_status_id' => $status->id, 'created_by' => $w['user']->id,
             'assigned_to' => $w['user']->id];

    Task::create([...$base, 'title' => 'Overdue',   'due_date' => now()->subDays(3)]);
    Task::create([...$base, 'title' => 'Today',     'due_date' => now()]);
    Task::create([...$base, 'title' => 'Unscheduled']);

    $res = $this->actingAs($w['user'])->getJson('/api/tasks/summary');

    $res->assertOk();
    expect($res->json('data.open'))->toBe(3)
        ->and($res->json('data.overdue'))->toBe(1)
        ->and($res->json('data.due_today'))->toBe(1)
        ->and($res->json('data.unscheduled'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Deal linkage
|--------------------------------------------------------------------------
*/

/** A deal in the given workspace. sales.funnel_id / stage_id are NOT NULL. */
function workspaceDeal(array $w): App\Models\Sale
{
    $lead = Lead::create([
        'company_id'      => $w['company']->id,
        'first_name'      => 'Nina',
        'source_platform' => 'website',
        'source_type'     => 'manual',
    ]);

    $funnel = App\Models\Funnel::create([
        'company_id' => $w['company']->id, 'name' => 'Sales', 'type' => 'deal',
        'is_active' => true, 'created_by' => $w['user']->id,
    ]);
    $stage = App\Models\Stage::create([
        'funnel_id' => $funnel->id, 'name' => 'Discovery',
        'type' => 'entry', 'order' => 1, 'is_active' => true,
    ]);

    return App\Models\Sale::create([
        'company_id'     => $w['company']->id,
        'funnel_id'      => $funnel->id,
        'stage_id'       => $stage->id,
        'lead_id'        => $lead->id,
        'user_id'        => $w['user']->id,
        'name'           => 'Annual licence',
        'status'         => 'pending',
        'pricing_model'  => 'product',
        'subtotal'       => 1000,
        'discount_total' => 0,
        'total'          => 1000,
        'currency'       => 'BRL',
    ]);
}

test('a task can be raised against a deal', function () {
    $w = makeWorkspace();
    $deal = workspaceDeal($w);

    $res = $this->actingAs($w['user'])->postJson('/api/tasks', [
        'title'        => 'Send the signed contract',
        'task_list_id' => $w['list']->id,
        'sale_id'      => $deal->id,
    ]);

    $res->assertCreated();
    expect(Task::firstOrFail()->sale_id)->toBe($deal->id);
});

test('the feed returns only the tasks of the deal asked for', function () {
    $w = makeWorkspace();
    $deal = workspaceDeal($w);
    $other = workspaceDeal($w);
    $todo = $w['list']->statuses()->where('type', 'not_started')->first();

    foreach ([[$deal, 'Mine'], [$other, 'Theirs'], [null, 'Unlinked']] as [$sale, $title]) {
        Task::create([
            'company_id' => $w['company']->id, 'task_list_id' => $w['list']->id,
            'task_status_id' => $todo->id, 'title' => $title,
            'sale_id' => $sale?->id,
        ]);
    }

    $res = $this->actingAs($w['user'])->getJson("/api/tasks/feed?sale_id={$deal->id}");

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.title'))->toBe('Mine');
});

test('a task cannot be linked to another company\'s deal', function () {
    $mine  = makeWorkspace('mine');
    $yours = makeWorkspace('yours');
    $deal  = workspaceDeal($yours);

    $this->actingAs($mine['user'])->postJson('/api/tasks', [
        'title'        => 'Peek',
        'task_list_id' => $mine['list']->id,
        'sale_id'      => $deal->id,
    ])->assertStatus(404);

    expect(Task::count())->toBe(0);
});

test('an existing task cannot be moved onto another company\'s deal', function () {
    $mine  = makeWorkspace('mine');
    $yours = makeWorkspace('yours');
    $deal  = workspaceDeal($yours);
    $todo  = $mine['list']->statuses()->where('type', 'not_started')->first();

    $task = Task::create([
        'company_id' => $mine['company']->id, 'task_list_id' => $mine['list']->id,
        'task_status_id' => $todo->id, 'title' => 'Mine',
    ]);

    $this->actingAs($mine['user'])->putJson("/api/tasks/{$task->id}", [
        'sale_id' => $deal->id,
    ])->assertStatus(404);

    expect($task->fresh()->sale_id)->toBeNull();
});
