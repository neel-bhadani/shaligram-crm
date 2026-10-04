<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Alert;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Filters live in the session, so the address bar can stay clean. These cover
 * the three things that buys and the one thing it costs: state survives a
 * refresh, Clear really wipes it, a parameter arriving on a link is still
 * honoured — and the session is user-controlled, so nothing reaches a query
 * builder unvalidated.
 */
class SessionFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $telecaller;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin');
        $this->telecaller = $this->user('telecaller');
        $this->project = Project::create(['name' => 'Green Acres']);
    }

    /* ---------------- leads ---------------- */

    public function test_filters_in_the_request_are_stored_and_survive_a_bare_visit(): void
    {
        $this->actingAs($this->admin)
            ->get('/leads?search=meera&stage=connected')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'meera')
                ->where('filters.stage', 'connected'))
            ->assertSessionHas('filters.leads', ['search' => 'meera', 'stage' => 'connected']);

        // the refresh: no query string at all, same filters back
        $this->actingAs($this->admin)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'meera')
                ->where('filters.stage', 'connected'));
    }

    public function test_an_empty_value_drops_that_filter_and_leaves_the_rest(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.leads' => ['search' => 'meera', 'stage' => 'connected']])
            ->get('/leads?search=&stage=connected')
            ->assertInertia(fn (Assert $page) => $page
                ->missing('filters.search')
                ->where('filters.stage', 'connected'));
    }

    /**
     * The shape the front end actually sends now: `reset=1` plus only the
     * filters that have a value. Emptying the search box has to drop it just
     * as `search=` did, and leave the rest of the row alone.
     */
    public function test_a_reset_visit_naming_only_the_set_filters_drops_the_others(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.leads' => ['search' => 'meera', 'stage' => 'connected']])
            ->get('/leads?reset=1&stage=connected')
            ->assertInertia(fn (Assert $page) => $page
                ->missing('filters.search')
                ->where('filters.stage', 'connected'))
            ->assertSessionHas('filters.leads', ['stage' => 'connected']);
    }

    /**
     * The session entry is emptied; the prop is not quite empty, and the
     * difference is the point.
     *
     * `range` is derived on the way out — it is the word the date control needs
     * for the state it is in, and '' is All time — but it is never stored, so
     * it cannot come back on the next visit as a filter nobody set. The session
     * assertion below is the one that matters.
     */
    public function test_reset_wipes_the_session_entry(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.leads' => ['search' => 'meera', 'stage' => 'connected']])
            ->get('/leads?reset=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['range' => ''])
                ->missing('filters.search')
                ->missing('filters.stage'))
            ->assertSessionHas('filters.leads', []);
    }

    public function test_a_poisoned_session_value_is_dropped_before_it_reaches_the_query(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.leads' => [
                'stage' => 'not-a-stage',
                'source' => 'not-a-source',
                'project_id' => 'DROP TABLE leads',
                'from' => '2026-02-30',
                'search' => 'meera',
            ]])
            ->get('/leads')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('filters.stage')
                ->missing('filters.source')
                ->missing('filters.project_id')
                ->missing('filters.from')
                // one bad key does not take the good ones with it
                ->where('filters.search', 'meera'));
    }

    public function test_the_leads_filters_still_filter(): void
    {
        $mine = $this->lead('Meera', 'connected', $this->telecaller);
        $theirs = $this->lead('Rahul', 'fresh', $this->admin);

        $this->actingAs($this->admin)
            ->get('/leads?stage=connected')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.id', $mine->id));

        $this->actingAs($this->admin)
            ->get('/leads?reset=1&search=rahul')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.id', $theirs->id));
    }

    public function test_a_telecaller_still_sees_only_their_own_leads(): void
    {
        $mine = $this->lead('Meera', 'fresh', $this->telecaller);
        $this->lead('Rahul', 'fresh', $this->admin);

        // even with a session that names the other owner
        $this->actingAs($this->telecaller)
            ->withSession(['filters.leads' => ['assigned_to' => $this->admin->id]])
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page->has('leads.data', 0));

        $this->actingAs($this->telecaller)
            ->get('/leads?reset=1')
            ->assertInertia(fn (Assert $page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.id', $mine->id));
    }

    /* ---------------- to-do ---------------- */

    public function test_the_todo_tab_defaults_to_today_and_then_sticks(): void
    {
        $this->actingAs($this->admin)
            ->get('/todos')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'today'));

        // the dashboard's follow-up panels link across with a tab parameter
        $this->actingAs($this->admin)
            ->get('/todos?tab=overdue')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'overdue'));

        $this->actingAs($this->admin)
            ->get('/todos')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'overdue'));
    }

    public function test_clearing_the_todo_filters_keeps_the_tab_the_user_is_on(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.todos' => ['tab' => 'completed', 'search' => 'meera', 'type' => 'call']])
            ->get('/todos?reset=1&tab=completed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'completed')
                ->missing('filters.search')
                ->missing('filters.type'))
            ->assertSessionHas('filters.todos', ['tab' => 'completed']);
    }

    public function test_a_todo_reset_visit_keeps_the_filters_it_names(): void
    {
        // the search box emptied while a type filter stays on
        $this->actingAs($this->admin)
            ->withSession(['filters.todos' => ['tab' => 'upcoming', 'search' => 'meera', 'type' => 'call']])
            ->get('/todos?reset=1&tab=upcoming&type=call')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'upcoming')
                ->missing('filters.search')
                ->where('filters.type', 'call'));
    }

    public function test_a_poisoned_todo_tab_falls_back_to_the_default(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.todos' => ['tab' => 'everything', 'type' => 'telepathy']])
            ->get('/todos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'today')
                ->missing('filters.type'));
    }

    public function test_a_telecaller_still_sees_only_their_own_todos(): void
    {
        $mine = $this->todo($this->telecaller);
        $theirs = $this->todo($this->admin);

        $this->actingAs($this->telecaller)
            ->withSession(['filters.todos' => ['assigned_to' => $this->admin->id]])
            ->get('/todos')
            ->assertInertia(fn (Assert $page) => $page->has('todos.data', 0));

        $this->actingAs($this->telecaller)
            ->get('/todos?reset=1')
            ->assertInertia(fn (Assert $page) => $page
                ->has('todos.data', 1)
                ->where('todos.data.0.id', $mine->id));

        $this->actingAs($this->admin)
            ->get('/todos?reset=1')
            ->assertInertia(fn (Assert $page) => $page->has('todos.data', 2));

        $this->assertNotSame($mine->id, $theirs->id);
    }

    /* ---------------- dashboard ---------------- */

    /**
     * Partial visits, asking only for `range`: the cards, charts, follow-up
     * panels and sign-in notice are closures Inertia would otherwise invoke,
     * and the notice in particular would count this as the one time a session
     * is told. The range resolution is what these are about.
     */
    public function test_the_dashboard_range_is_stored_and_survives_a_bare_visit(): void
    {
        $this->rangeVisit('/dashboard?range=7')
            ->assertJsonPath('props.range.key', '7');

        $this->rangeVisit('/dashboard')
            ->assertJsonPath('props.range.key', '7');
    }

    public function test_a_preset_clears_a_stored_custom_range(): void
    {
        $from = today()->subDays(10)->toDateString();
        $to = today()->toDateString();

        $this->rangeVisit("/dashboard?range=&from=$from&to=$to")
            ->assertJsonPath('props.range.key', 'custom')
            ->assertJsonPath('props.range.from', $from);

        // the preset sends the pair back as empty, which is how it wins
        $this->rangeVisit('/dashboard?range=30&from=&to=')
            ->assertJsonPath('props.range.key', '30')
            ->assertSessionHas('filters.dashboard', ['range' => '30']);
    }

    public function test_a_reset_preset_visit_also_clears_a_stored_custom_range(): void
    {
        $from = today()->subDays(10)->toDateString();
        $to = today()->toDateString();

        $this->rangeVisit("/dashboard?reset=1&from=$from&to=$to")
            ->assertJsonPath('props.range.key', 'custom');

        // the preset no longer sends the pair back as empty; reset does it
        $this->rangeVisit('/dashboard?reset=1&range=30')
            ->assertJsonPath('props.range.key', '30')
            ->assertSessionHas('filters.dashboard', ['range' => '30']);
    }

    public function test_an_impossible_stored_range_falls_back_to_thirty_days(): void
    {
        // a lone date, a future date, a mistyped one, a backwards pair —
        // none of them survive, and none of them throws
        foreach ([
            ['from' => today()->toDateString()],
            ['from' => today()->toDateString(), 'to' => today()->addYear()->toDateString()],
            ['from' => '2026-02-30', 'to' => today()->toDateString()],
            ['from' => today()->toDateString(), 'to' => today()->subYear()->toDateString()],
            ['from' => today()->subYears(5)->toDateString(), 'to' => today()->toDateString()],
        ] as $bad) {
            $this->rangeVisit('/dashboard', $bad)
                ->assertOk()
                ->assertJsonPath('props.range.key', '30');
        }
    }

    /* ---------------- alerts ---------------- */

    /**
     * Marking an alert read redirects back to the address bar, and the address
     * bar is bare /alerts — so the filters have to come back from the session.
     */
    public function test_the_alert_filters_survive_marking_an_alert_read(): void
    {
        $this->alert('urgent');
        $this->alert('urgent', read: true);
        $this->alert('info');

        $this->actingAs($this->admin)
            ->get('/alerts?status=unread&severity=urgent')
            ->assertInertia(fn (Assert $page) => $page->has('alerts.data', 1))
            ->assertSessionHas('filters.alerts', ['status' => 'unread', 'severity' => 'urgent']);

        $this->actingAs($this->admin)
            ->from('/alerts')
            ->post(route('alerts.read-all'))
            ->assertRedirect('/alerts');

        $this->actingAs($this->admin)
            ->get('/alerts')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['status' => 'unread', 'severity' => 'urgent'])
                // read-all emptied the unread list; the filter still applies
                ->has('alerts.data', 0));
    }

    public function test_the_automation_alerts_tab_keeps_its_own_filters(): void
    {
        // role:admin wants an active account, which this file's fixture is not
        $this->admin->update(['is_active' => true]);

        $this->actingAs($this->admin)
            ->get('/automation?tab=alerts&status=unread&severity=warning')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'alerts')
                ->where('filters', ['status' => 'unread', 'severity' => 'warning']));

        $this->actingAs($this->admin)
            ->get('/alerts')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['status' => 'all', 'severity' => 'all']));

        // the refresh: the tab falls back to Auto-send, the alert filters do not
        $this->actingAs($this->admin)
            ->get('/automation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'auto_send')
                ->where('filters', ['status' => 'unread', 'severity' => 'warning']));
    }

    public function test_a_poisoned_alert_filter_falls_back_to_all(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['filters.alerts' => ['status' => 'everything', 'severity' => 'urgent']])
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['status' => 'all', 'severity' => 'urgent']));
    }

    /* ---------------- fixtures ---------------- */

    private function alert(string $severity, bool $read = false): Alert
    {
        return Alert::create([
            'user_id' => $this->admin->id,
            'type' => 'test',
            'title' => 'Look',
            'severity' => $severity,
            'read_at' => $read ? now() : null,
        ]);
    }

    private function rangeVisit(string $url, ?array $stored = null)
    {
        $test = $this->actingAs($this->admin);

        if ($stored !== null) {
            $test = $test->withSession(['filters.dashboard' => $stored]);
        }

        return $test->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data' => 'range',
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get($url);
    }

    private function user(string $role): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'password' => 'password',
        ]);
    }

    private function lead(string $name, string $stage, User $owner): Lead
    {
        return Lead::create([
            'first_name' => $name,
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => $stage,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }

    private function todo(User $owner): Todo
    {
        return Todo::create([
            'lead_id' => $this->lead('Task', 'fresh', $owner)->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
            'scheduled_at' => today()->setTime(10, 0),
            'type' => 'call',
            'status' => 'pending',
        ]);
    }
}
