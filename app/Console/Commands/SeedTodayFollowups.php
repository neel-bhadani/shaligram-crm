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

class SeedTodayFollowups extends Command
{
    protected $signature = 'followups:create-today {--dry-run : Preview without inserting todos}';

    protected $description = 'Create missing pending follow-ups for today using the requested stage/project assignments';

    private const STAGES = ['fresh', 'not_connected', 'site_visit_scheduled', 'site_visit_done', 'in_discussion'];

    private const PROJECT_USERS = ['Vanam' => 'Mayur Patel', 'SkyDeck' => 'Ravi Patel', 'Felicity' => 'Dhanashri Meshram'];

    public function handle(): int
    {
        try {
            $created = DB::transaction(function (): int {
                $when = now(config('app.timezone'));
                $day = $when->toDateString();
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

                // Lock in a stable order so concurrent command runs serialize before
                // checking todos. Never save these leads or invoke the routing service.
                $leads = Lead::whereIn('stage', self::STAGES)->orderBy('id')
                    ->when(! $this->option('dry-run'), fn ($query) => $query->lockForUpdate())
                    ->get(['id', 'stage', 'project_id']);
                $pending = Todo::pending()->whereDate('scheduled_at', $day)
                    ->when(! $this->option('dry-run'), fn ($query) => $query->lockForUpdate())
                    ->get(['lead_id', 'assigned_to'])->groupBy('lead_id');
                $assignments = [];
                $already = $skipped = $mismatched = 0;
                $salesAssignments = [];
                foreach (self::PROJECT_USERS as $project => $user) {
                    $salesAssignments[$projects[$project]] = $users[$user];
                }
                foreach ($leads as $lead) {
                    $assignee = in_array($lead->stage, ['fresh', 'not_connected'], true)
                        ? $users['Riya Gandhi'] : ($salesAssignments[$lead->project_id] ?? null);
                    if ($assignee === null) {
                        $skipped++;

                        continue;
                    }
                    if ($pending->has($lead->id)) {
                        $already++;
                        if (! $pending[$lead->id]->contains('assigned_to', $assignee)) {
                            $mismatched++;
                        }

                        continue;
                    }
                    $assignments[$lead->id] = $assignee;
                }
                $this->info("Follow-ups for {$day} ({$when->timezoneName}); environment: ".app()->environment());
                $rows = [['Target-stage leads', $leads->count()]];
                foreach (self::STAGES as $stage) {
                    $rows[] = [$stage, $leads->where('stage', $stage)->count()];
                }
                foreach ($projects as $name => $id) {
                    $rows[] = ["Project: {$name} (target stages)", $leads->where('project_id', $id)->count()];
                }
                $rows[] = ['Qualifying leads (mapped or telecaller stages)', $leads->count() - $skipped];
                $rows[] = ['Already had today pending follow-up', $already];
                $rows[] = ['New todos planned', count($assignments)];
                $rows[] = ['Skipped unmapped project', $skipped];
                $rows[] = ['Existing today follow-ups with different assignee (preserved)', $mismatched];
                $this->table(['Metric', 'Count'], $rows);
                if ($this->option('dry-run')) {
                    return 0;
                }
                foreach (array_chunk($assignments, 500, true) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $leadId => $assignee) {
                        $rows[] = [
                            'lead_id' => $leadId,
                            'assigned_to' => $assignee,
                            'created_by' => null,
                            'scheduled_at' => $when,
                            'type' => 'call',
                            'status' => 'pending',
                            'remarks' => "Today's follow-up",
                            'completed_at' => null,
                            'created_at' => $when,
                            'updated_at' => $when,
                        ];
                    }
                    // A direct insert intentionally avoids side effects on lead history,
                    // ownership, automation, and existing todos.
                    DB::table('todos')->insert($rows);
                }

                return count($assignments);
            });
            $this->info($this->option('dry-run') ? 'Dry run: no data changed.' : "Created {$created} follow-ups.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('No follow-ups inserted; transaction rolled back. '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
