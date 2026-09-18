<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The follow-up calendar.
 *
 * The rules worth locking down:
 *
 *   - placement. A follow-up appears on the calendar on its `scheduled_at`
 *     date — the same date the To-do page's pending tabs and the follow-ups
 *     report measure against — completed rows included, cancelled rows
 *     nowhere.
 *
 *   - the window. The month is padded out to the whole weeks that contain
 *     it (Sun-to-Sat), so the grid is always whole rows. Events in the pad
 *     are shown, dimmed, rather than silently dropped.
 *
 *   - privacy. Todo::forUser() decides who sees what, exactly as on the
 *     To-do page: an admin sees the office's follow-ups, a telecaller sees
 *     only their own, and a soft-deleted lead takes its rows away with it.
 *
 *   - the summary. Today / Upcoming / Waiting longer are the Todo model's
 *     own scopes over the filtered set, global rather than month-scoped, so
 *     a number here is the same number as on the To-do page's tab badges.
 */
class CalendarPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00'));

        $this->admin = $this->user('admin', 'Admin');
        $this->alice = $this->user('telecaller', 'Alice');
        $this->bob = $this->user('telecaller', 'Bob');

        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->beta = Project::create(['name' => 'Beta']);
    }

    /* ---------------- access ---------------- */

    public function test_a_guest_cannot_open_the_calendar(): void
    {
        $this->get('/calendar')->assertRedirect(route('login'));
    }

    public function test_every_signed_in_role_can_open_the_calendar(): void
    {
        foreach (['admin', 'telecaller', 'salesperson'] as $role) {
            $this->actingAs($this->user($role, ucfirst($role)))
                ->get('/calendar')
                ->assertOk();
        }
    }

    /* ---------------- the month and its window ---------------- */

    public function test_it_lands_on_the_current_month_by_default(): void
    {
        $props = $this->page('/calendar');

        $this->assertSame('2026-09', $props['month']);
        $this->assertSame('September 2026', $props['monthLabel']);
    }

    public function test_an_invalid_month_falls_back_to_the_current_month(): void
    {
        $props = $this->page('/calendar', ['month' => '2026-99']);

        $this->assertSame('2026-09', $props['month']);
    }

    public function test_the_visible_window_is_the_whole_weeks_around_the_month(): void
    {
        $range = $this->page('/calendar', ['month' => '2026-09'])['range'];

        $this->assertSame('2026-08-30', $range['start'], 'the Sunday before the first of September');
        $this->assertSame('2026-10-03', $range['end'], 'the Saturday after the last of September');

        // whole weeks, so the grid draws complete rows of seven
        $days = Carbon::parse($range['start'])->diffInDays(Carbon::parse($range['end'])) + 1;
        $this->assertSame(0, $days % 7, 'a pad that is not whole weeks draws ragged rows');
    }

    /* ---------------- placement ---------------- */

    public function test_a_follow_up_lands_on_its_scheduled_date(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);
        $scheduled = $this->todo($lead, '2026-09-17 14:30', 'pending');

        $event = $this->page('/calendar', ['month' => '2026-09'])['events'][0];

        $this->assertSame('2026-09-17', $event['date']);
        $this->assertSame('14:30', $event['time']);
        $this->assertSame($scheduled->id, $event['id']);
        $this->assertSame($lead->id, $event['lead']['id']);
    }

    public function test_event_state_is_completed_for_a_done_follow_up(): void
    {
        $this->todo($this->lead($this->alice, $this->alpha), '2026-09-15 10:00', 'completed', '2026-09-15 11:00');

        $this->assertSame('completed', $this->page('/calendar', ['month' => '2026-09'])['events'][0]['state']);
    }

    public function test_event_state_is_overdue_for_a_pending_one_in_the_past(): void
    {
        $this->todo($this->lead($this->alice, $this->alpha), '2026-09-05 10:00', 'pending');

        $this->assertSame('overdue', $this->page('/calendar', ['month' => '2026-09'])['events'][0]['state']);
    }

    public function test_event_state_is_pending_for_one_due_today(): void
    {
        $this->todo($this->lead($this->alice, $this->alpha), '2026-09-06 10:00', 'pending');

        $this->assertSame('pending', $this->page('/calendar', ['month' => '2026-09'])['events'][0]['state']);
    }

    public function test_event_state_is_upcoming_for_a_pending_one_in_the_future(): void
    {
        $this->todo($this->lead($this->alice, $this->alpha), '2026-09-17 14:30', 'pending');

        $this->assertSame('upcoming', $this->page('/calendar', ['month' => '2026-09'])['events'][0]['state']);
    }

    public function test_events_sitting_in_the_spillover_cells_are_shipped(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        // the grid's first cell (August, dimmed) and its last cell (October, dimmed)
        $this->todo($lead, '2026-08-30 09:00', 'pending');
        $this->todo($lead, '2026-10-03 09:00', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09'])['events'];

        $this->assertCount(2, $events);
        $this->assertSame(['2026-08-30', '2026-10-03'], array_column($events, 'date'));
    }

    public function test_events_come_ordered_by_scheduled_time(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $later = $this->todo($lead, '2026-09-10 14:00', 'pending')->id;
        $earlier = $this->todo($lead, '2026-09-10 09:00', 'pending')->id;

        $ids = array_column($this->page('/calendar', ['month' => '2026-09'])['events'], 'id');

        $this->assertSame([$earlier, $later], $ids);
    }

    public function test_an_event_carries_everything_a_cell_draws(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);
        $todo = $this->todo($lead, '2026-09-17 14:30', 'pending', null, 'meeting', 'Bring the brochure');

        $event = $this->page('/calendar', ['month' => '2026-09'])['events'][0];

        $this->assertSame($todo->id, $event['id']);
        $this->assertSame('pending', $event['status']);
        $this->assertSame('meeting', $event['type']);
        $this->assertSame('Bring the brochure', $event['remark']);
        $this->assertSame($lead->id, $event['lead']['id']);
        $this->assertSame($lead->full_name, $event['lead']['name']);
        $this->assertSame($lead->stage, $event['lead']['stage']);
        $this->assertSame($this->alice->id, $event['assignee']['id']);
        $this->assertSame($this->alpha->id, $event['project']['id']);
        $this->assertSame($this->alpha->name, $event['project']['name']);
        $this->assertSame(route('leads.show', $lead->id), $event['url']);
    }

    /* ---------------- visibility ---------------- */

    public function test_an_admin_sees_everyones_follow_ups(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha);
        $leadB = $this->lead($this->bob, $this->beta);

        $this->todo($leadA, '2026-09-10 10:00', 'pending');
        $this->todo($leadB, '2026-09-11 10:00', 'pending');

        $this->assertCount(2, $this->page('/calendar', ['month' => '2026-09'])['events']);
    }

    public function test_a_telecaller_sees_only_their_own_follow_ups(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha);
        $leadB = $this->lead($this->bob, $this->beta);

        $this->todo($leadA, '2026-09-10 10:00', 'pending');
        $this->todo($leadB, '2026-09-11 10:00', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09'], $this->alice)['events'];

        $this->assertCount(1, $events);
        $this->assertSame($leadA->id, $events[0]['lead']['id']);
    }

    public function test_a_deleted_leads_follow_ups_leave_the_calendar(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-10 10:00', 'pending');

        $lead->delete();

        $props = $this->page('/calendar', ['month' => '2026-09']);

        $this->assertCount(0, $props['events']);
        $this->assertSame(0, $props['summary']['upcoming'], 'the summary reads the same rows the grid does');
    }

    /* ---------------- the filters ---------------- */

    public function test_status_filter_overdue_keeps_what_is_overdue(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-05 10:00', 'pending');
        $this->todo($lead, '2026-09-17 14:30', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09', 'status' => 'overdue'])['events'];

        $this->assertCount(1, $events);
        $this->assertSame('overdue', $events[0]['state']);
    }

    public function test_status_filter_completed_keeps_what_is_done(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-15 10:00', 'completed', '2026-09-15 11:00');
        $this->todo($lead, '2026-09-17 14:30', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09', 'status' => 'completed'])['events'];

        $this->assertCount(1, $events);
        $this->assertSame('completed', $events[0]['state']);
    }

    public function test_status_filter_pending_keeps_every_pending_except_overdue(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-05 10:00', 'pending');   // overdue
        $this->todo($lead, '2026-09-06 10:00', 'pending');   // due today
        $this->todo($lead, '2026-09-17 14:30', 'pending');   // upcoming

        $events = $this->page('/calendar', ['month' => '2026-09', 'status' => 'pending'])['events'];

        $this->assertCount(2, $events);
        $this->assertSame(['pending', 'upcoming'], array_column($events, 'state'));
    }

    public function test_cancelled_follow_ups_are_nowhere_by_default(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-10 10:00', 'cancelled');

        $props = $this->page('/calendar', ['month' => '2026-09']);

        $this->assertCount(0, $props['events']);
        $this->assertSame(0, $props['summary']['upcoming']);
    }

    public function test_assigned_to_narrows_the_calendar(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha);
        $leadB = $this->lead($this->bob, $this->beta);

        $this->todo($leadA, '2026-09-10 10:00', 'pending');
        $this->todo($leadB, '2026-09-11 10:00', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09', 'assigned_to' => $this->alice->id])['events'];

        $this->assertCount(1, $events);
        $this->assertSame($leadA->id, $events[0]['lead']['id']);
    }

    public function test_project_narrows_the_calendar(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha);
        $leadB = $this->lead($this->alice, $this->beta);

        $this->todo($leadA, '2026-09-10 10:00', 'pending');
        $this->todo($leadB, '2026-09-11 10:00', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09', 'project_id' => $this->alpha->id])['events'];

        $this->assertCount(1, $events);
        $this->assertSame($leadA->id, $events[0]['lead']['id']);
    }

    public function test_stage_narrows_the_calendar(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha, ['stage' => 'in_discussion']);
        $leadB = $this->lead($this->alice, $this->alpha, ['stage' => 'fresh']);

        $this->todo($leadA, '2026-09-10 10:00', 'pending');
        $this->todo($leadB, '2026-09-11 10:00', 'pending');

        $events = $this->page('/calendar', ['month' => '2026-09', 'stage' => 'fresh'])['events'];

        $this->assertCount(1, $events);
        $this->assertSame($leadB->id, $events[0]['lead']['id']);
    }

    public function test_the_assignee_control_is_admin_only(): void
    {
        $adminOptions = $this->page('/calendar')['options'];
        $ids = array_column($adminOptions['users'], 'id');

        $this->assertContains($this->alice->id, $ids);
        $this->assertContains($this->bob->id, $ids);
        $this->assertNotContains($this->admin->id, $ids, 'only staff an admin may assign to');

        $telecallerOptions = $this->page('/calendar', [], $this->alice)['options'];
        $this->assertSame([], $telecallerOptions['users'], 'a telecaller is the only person ever on their calendar');
    }

    public function test_options_carry_what_complete_task_modal_needs(): void
    {
        $options = $this->page('/calendar')['options'];

        $this->assertContains('connected', $options['activeStages']);
        $this->assertIsArray($options['terminalStages']);
        $this->assertNotEmpty($options['handoverStage']);
        $this->assertNotEmpty($options['handoverRole']);
    }

    /* ---------------- the summary cards ---------------- */

    public function test_summary_matches_the_todo_pages_buckets(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-05 10:00', 'pending'); // overdue
        $this->todo($lead, '2026-09-06 10:00', 'pending'); // due today
        $this->todo($lead, '2026-09-17 14:30', 'pending'); // upcoming
        $this->todo($lead, '2026-09-15 10:00', 'completed', '2026-09-15 11:00');

        $summary = $this->page('/calendar', ['month' => '2026-09'])['summary'];

        $this->assertSame(Todo::forUser($this->admin)->hasLead()->overdue()->count(), $summary['overdue']);
        $this->assertSame(Todo::forUser($this->admin)->hasLead()->dueToday()->count(), $summary['today']);
        $this->assertSame(Todo::forUser($this->admin)->hasLead()->upcoming()->count(), $summary['upcoming']);
    }

    public function test_summary_is_global_while_the_grid_is_the_month(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        // waiting since July — nowhere on this month's grid, still a waiting one
        $this->todo($lead, '2026-07-02 10:00', 'pending');

        $props = $this->page('/calendar', ['month' => '2026-09']);

        $this->assertSame(1, $props['summary']['overdue']);
        $this->assertCount(0, $props['events']);
    }

    public function test_the_filters_narrow_the_summary_too(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha);
        $leadB = $this->lead($this->bob, $this->beta);

        $this->todo($leadA, '2026-09-05 10:00', 'pending');
        $this->todo($leadB, '2026-09-06 10:00', 'pending');

        $overdueOnly = $this->page('/calendar', ['month' => '2026-09', 'assigned_to' => $this->alice->id, 'status' => 'overdue'])['summary'];
        $this->assertSame(1, $overdueOnly['overdue']);
        $this->assertSame(0, $overdueOnly['today']);

        $aliceOnly = $this->page('/calendar', ['month' => '2026-09', 'assigned_to' => $this->alice->id])['summary'];
        $this->assertSame(1, $aliceOnly['overdue']);
        $this->assertSame(0, $aliceOnly['today']);
    }

    /* ---------------- every event in the window is shipped ---------------- */

    public function test_every_follow_up_in_the_visible_window_is_shipped(): void
    {
        $leadA = $this->lead($this->alice, $this->alpha);
        $leadB = $this->lead($this->bob, $this->beta);

        $this->todo($leadA, '2026-09-02 10:00', 'pending');
        $this->todo($leadB, '2026-09-15 11:00', 'pending');
        $this->todo($leadA, '2026-09-21 09:00', 'completed', '2026-09-21 10:00');
        $this->todo($leadB, '2026-09-28 12:00', 'completed', '2026-09-28 12:30');

        $events = $this->page('/calendar', ['month' => '2026-09'])['events'];

        $expected = Todo::forUser($this->admin)->hasLead()
            ->whereIn('status', ['pending', 'completed'])
            ->whereBetween('scheduled_at', [
                Carbon::parse('2026-08-30 00:00'),
                Carbon::parse('2026-10-03 23:59'),
            ])
            ->count();

        $this->assertSame($expected, count($events), 'the grid shows every follow-up the window holds');
    }

    public function test_another_month_is_a_different_view(): void
    {
        $lead = $this->lead($this->alice, $this->alpha);

        $this->todo($lead, '2026-09-10 10:00', 'pending');
        $this->todo($lead, '2026-08-12 10:00', 'pending');

        $this->assertCount(1, $this->page('/calendar', ['month' => '2026-09'])['events']);
        $this->assertCount(1, $this->page('/calendar', ['month' => '2026-08'])['events']);
    }

    /* ---------------- helpers ---------------- */

    private function page(string $url, array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)
            ->get($url.'?'.http_build_query($query + ['reset' => 1]));

        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'first_name' => $name, 'last_name' => 'Test',
            'email' => strtolower($name).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function lead(?User $owner, Project $project, array $attributes = []): Lead
    {
        return Lead::create($attributes + [
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $project->id, 'source' => 'walk_in',
            'stage' => 'in_discussion', 'assigned_to' => $owner?->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function todo(
        Lead $lead,
        string $scheduledAt,
        string $status,
        ?string $completedAt = null,
        string $type = 'call',
        ?string $remarks = null,
    ): Todo {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $this->admin->id,
            'scheduled_at' => $scheduledAt,
            'type' => $type,
            'status' => $status,
            'remarks' => $remarks,
            'completed_at' => $completedAt,
        ]);
    }
}
