<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlertSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_and_list_remain_independent_and_read_state_persists(): void
    {
        $user = User::factory()->role('admin')->create();
        $other = User::factory()->create();
        $alert = app(AlertService::class)->raise($user, 'test', 'Mine');
        $foreign = app(AlertService::class)->raise($other, 'test', 'Private');

        foreach (['/alerts', '/automation'] as $url) {
            $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
                ->where('alertBell.unread', 1)->has('alertBell.recent', 1)
                ->where('alertBell.recent.0.id', $alert->id)
                ->has('alerts.data', 1)->where('alerts.data.0.id', $alert->id));
        }

        $this->post(route('alerts.read', $foreign))->assertForbidden();
        $this->from('/alerts')->post(route('alerts.read', $alert))->assertRedirect('/alerts');
        $readAt = $alert->fresh()->read_at;
        $this->assertNotNull($readAt);
        $this->get('/alerts')->assertInertia(fn (Assert $page) => $page
            ->where('alertBell.unread', 0)->where('alertBell.recent.0.read', true));
        $this->post(route('alerts.read', $alert));
        $this->assertEquals($readAt, $alert->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_mark_all_read_only_updates_the_logged_in_users_alerts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = app(AlertService::class)->raise($user, 'test', 'Mine');
        $theirs = app(AlertService::class)->raise($other, 'test', 'Theirs');

        $this->actingAs($user)->from('/alerts')->post(route('alerts.read-all'))->assertRedirect('/alerts');

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
        $this->get('/alerts')->assertInertia(fn (Assert $page) => $page->where('alertBell.unread', 0));
    }

    public function test_alert_link_clears_conflicting_filters_and_handles_deleted_leads(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead($user);
        $alert = app(AlertService::class)->raise($user, 'lead_stuck', 'Stuck', lead: $lead);
        $this->actingAs($user)->withSession(['filters.leads' => ['stage' => 'lost']]);

        $response = $this->post(route('alerts.read', $alert));
        $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page
            ->has('leads.data', 1)->where('leads.data.0.id', $lead->id));

        $lead->delete();
        $this->post(route('alerts.read', $alert))->assertRedirect(route('alerts.index'));
        $this->assertModelExists($alert);
    }

    public function test_alerts_only_generates_due_conditions_without_queue_and_deduplicates(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 16)->setTime(11, 0));
        $user = User::factory()->create();
        $lead = $this->lead($user);
        $lead->update(['stage_changed_at' => now()->subDays(8)]);
        $todo = Todo::create(['lead_id' => $lead->id, 'assigned_to' => $user->id,
            'scheduled_at' => now()->subDays(4), 'type' => 'call', 'status' => 'pending']);
        config(['queue.default' => 'database']);
        Queue::fake();

        $this->artisan('automation:run --alerts-only')->assertSuccessful();
        $this->artisan('automation:run --alerts-only')->assertSuccessful();

        $this->assertDatabaseCount('alerts', 2);
        $this->assertDatabaseHas('alerts', ['type' => 'follow_up_overdue', 'user_id' => $user->id, 'read_at' => null]);
        $this->assertDatabaseHas('alerts', ['type' => 'lead_stuck', 'user_id' => $user->id]);
        Queue::assertNothingPushed();
        $this->travel(25)->hours();
        $this->artisan('automation:run --alerts-only')->assertSuccessful();
        $this->assertDatabaseCount('alerts', 4);
        $this->assertSame('pending', $todo->fresh()->status);
    }

    public function test_todo_due_today_does_not_raise_the_existing_three_day_overdue_alert(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead($user);
        Todo::create(['lead_id' => $lead->id, 'assigned_to' => $user->id,
            'scheduled_at' => now(), 'type' => 'call', 'status' => 'pending']);

        $this->artisan('automation:run --alerts-only')->assertSuccessful();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_hourly_scheduler_invokes_alert_generation(): void
    {
        $this->travelTo(now()->startOfHour());
        $user = User::factory()->create();
        $lead = $this->lead($user);
        $lead->update(['stage_changed_at' => now()->subDays(8)]);
        $event = collect(app(Schedule::class)->dueEvents($this->app))
            ->first(fn ($event) => str_contains($event->command, 'automation:run'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        // Keep the invocation in the test process so it uses the isolated database.
        $this->artisan(substr($event->command, strpos($event->command, 'automation:run')))->assertSuccessful();
        $this->assertDatabaseHas('alerts', ['type' => 'lead_stuck', 'user_id' => $user->id]);
    }

    public function test_project_alert_links_handle_missing_projects_and_user_alerts_open_users(): void
    {
        $admin = User::factory()->role('admin')->create();
        $project = Project::create(['name' => 'Unstaffed']);
        $alert = app(AlertService::class)->raise($admin, 'project_without_salespeople.'.$project->id,
            'Unstaffed', actionUrl: route('projects.show', $project));
        $userAlert = app(AlertService::class)->raise($admin, 'user_deactivated_open_leads.99',
            'Handover needed', actionUrl: route('users.index'));

        $this->actingAs($admin)->post(route('alerts.read', $alert))->assertRedirect(route('projects.show', $project));
        $this->get(route('projects.show', $project))->assertOk();
        $this->post(route('alerts.read', $userAlert))->assertRedirect(route('users.index'));
        $this->get(route('users.index'))->assertOk();
        $project->delete();
        $this->post(route('alerts.read', $alert))->assertRedirect(route('alerts.index'));
    }

    public function test_bell_partial_reload_returns_new_alerts_without_replacing_the_list(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/alerts');
        $alert = app(AlertService::class)->raise($user, 'test', 'Just arrived');

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Alerts/Index',
            'X-Inertia-Partial-Data' => 'alertBell',
            'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/alerts'))])->get('/alerts')
            ->assertJsonPath('props.alertBell.unread', 1)
            ->assertJsonPath('props.alertBell.recent.0.id', $alert->id)
            ->assertJsonMissingPath('props.alerts');
    }

    private function lead(User $user): Lead
    {
        return Lead::create(['first_name' => 'Test', 'last_name' => 'Lead',
            'project_id' => Project::create(['name' => 'Test Project'])->id,
            'mobile_number' => '9876543210', 'source' => 'walk_in', 'stage' => 'fresh',
            'assigned_to' => $user->id, 'assigned_role' => $user->role, 'stage_changed_at' => now()]);
    }
}
