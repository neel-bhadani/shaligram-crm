<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncLeadAssignees extends Command
{
    protected $signature = 'leads:sync-assignees {--dry-run : Preview without updating assignments}';

    protected $description = 'Align target lead and today pending todo assignees with stage/project rules';

    private const STAGES = ['fresh', 'not_connected', 'site_visit_scheduled', 'site_visit_done', 'in_discussion'];

    private const PROJECT_USERS = ['Vanam' => 'Mayur Patel', 'SkyDeck' => 'Ravi Patel', 'Felicity' => 'Dhanashri Meshram'];

    public function handle(): int
    {
        try {
            [$leadCount, $todoCount] = DB::transaction(function (): array {
                $day = now(config('app.timezone'))->toDateString();
                $users = [];
                foreach (['Riya Gandhi', ...array_values(self::PROJECT_USERS)] as $name) {
                    [$first, $last] = explode(' ', $name, 2);
                    $matches = User::where('first_name', $first)->where('last_name', $last)->get()
                        ->filter(fn (User $user) => $user->first_name === $first && $user->last_name === $last);
                    if ($matches->count() !== 1) {
                        throw new RuntimeException("Required user {$name}: expected exactly one existing record, found {$matches->count()}.");
                    }
                    $user = $matches->first();
                    $role = $name === 'Riya Gandhi' ? 'telecaller' : 'salesperson';
                    if ($user->role !== $role) {
                        throw new RuntimeException("Required user {$name} must have role {$role}.");
                    }
                    $users[$name] = $user->id;
                }
                $projects = [];
                foreach (self::PROJECT_USERS as $name => $user) {
                    $matches = Project::where('name', $name)->get()->filter(fn (Project $project) => $project->name === $name);
                    if ($matches->count() !== 1) {
                        throw new RuntimeException("Required project {$name}: expected exactly one existing record, found {$matches->count()}.");
                    }
                    $projects[$name] = $matches->first()->id;
                }
                foreach (self::STAGES as $stage) {
                    if (! LeadStage::where('key', $stage)->where('is_terminal', false)->exists()) {
                        throw new RuntimeException("Required non-terminal stage {$stage} is missing.");
                    }
                }

                // Stable row locks serialize concurrent corrections. Read-only dry runs
                // take no write locks. Soft-deleted leads are excluded by the model.
                $leads = Lead::whereIn('stage', self::STAGES)->orderBy('id')
                    ->when(! $this->option('dry-run'), fn ($query) => $query->lockForUpdate())
                    ->get(['id', 'stage', 'project_id', 'assigned_to']);
                $todos = Todo::pending()->whereDate('scheduled_at', $day)
                    ->whereIn('lead_id', $leads->modelKeys())->orderBy('id')
                    ->when(! $this->option('dry-run'), fn ($query) => $query->lockForUpdate())
                    ->get(['id', 'lead_id', 'assigned_to'])->groupBy('lead_id');
                $groups = array_fill_keys(['fresh', 'not_connected', ...array_keys(self::PROJECT_USERS)], [0, 0, 0]);
                $leadUpdates = $todoUpdates = [];
                $skipped = $correctTodos = $missing = 0;
                foreach ($leads as $lead) {
                    if (in_array($lead->stage, ['fresh', 'not_connected'], true)) {
                        $group = $lead->stage;
                        $assignee = $users['Riya Gandhi'];
                    } else {
                        $group = array_search($lead->project_id, $projects, true);
                        if ($group === false) {
                            $skipped++;

                            continue;
                        }
                        $assignee = $users[self::PROJECT_USERS[$group]];
                    }
                    $groups[$group][0]++;
                    if ($lead->assigned_to === $assignee) {
                        $groups[$group][1]++;
                    } else {
                        $groups[$group][2]++;
                        $leadUpdates[$assignee][] = $lead->id;
                    }
                    $pending = $todos->get($lead->id, collect());
                    if ($pending->isEmpty()) {
                        $missing++;
                    }
                    // Preserve every existing todo, including any pre-existing duplicates.
                    foreach ($pending as $todo) {
                        if ($todo->assigned_to === $assignee) {
                            $correctTodos++;
                        } else {
                            $todoUpdates[$assignee][] = $todo->id;
                        }
                    }
                }
                $leadCount = array_sum(array_map('count', $leadUpdates));
                $todoCount = array_sum(array_map('count', $todoUpdates));
                $this->info("Assignments for {$day} (".config('app.timezone').'); environment: '.app()->environment());
                $this->line('All four required users and all three required projects: PASS');
                $this->line('Total target leads: '.$leads->count());
                $rows = [];
                foreach ($groups as $name => $counts) {
                    $rows[] = [$name, ...$counts];
                }
                $this->table(['Group', 'Total', 'Already correct', 'Need change'], $rows);
                $this->line("Unmapped project leads skipped: {$skipped}");
                $this->line("Today's todos already correctly assigned: {$correctTodos}");
                $this->line("Lead assignments needing change: {$leadCount}");
                $this->line("Todo assignments needing change: {$todoCount}");
                $this->line("Qualifying leads missing today's pending todo: {$missing}");
                if ($missing > 0) {
                    throw new RuntimeException("{$missing} qualifying leads have no pending todo today. Run followups:create-today --dry-run to inspect before creating the missing follow-ups.");
                }
                if ($this->option('dry-run')) {
                    return [0, 0];
                }
                // This one-time correction must not call LeadFollowUpService::assign:
                // it rewrites other fields, all pending todos, history and automation.
                // Query-builder updates change ONLY assigned_to, not even updated_at
                // or the separate assigned_role metadata, per this command's scope.
                foreach (['leads' => $leadUpdates, 'todos' => $todoUpdates] as $table => $updates) {
                    foreach ($updates as $assignee => $ids) {
                        foreach (array_chunk($ids, 500) as $chunk) {
                            DB::table($table)->whereIn('id', $chunk)->update(['assigned_to' => $assignee]);
                        }
                    }
                }

                return [$leadCount, $todoCount];
            });
            $this->info($this->option('dry-run') ? 'Dry run: no data changed.' : "Updated {$leadCount} leads and {$todoCount} todos.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('No assignments changed; transaction rolled back. '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
