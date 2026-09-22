<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marking an alert read must leave the reader on their own origin, whatever
 * host the destination was stored under.
 *
 * `action_url` is written as an absolute `route()` url when the alert is
 * raised, pinned to whichever host the raising process was wired up to.
 * Locally that is the stored `APP_URL` (`http://127.0.0.1:8000`) — even when
 * the reader stands on `http://localhost:8000`. The front end follows the
 * mark-read redirect through an XHR; a Location pointing at a different host
 * is a cross-origin request the browser refuses outright (`net::ERR_FAILED`).
 *
 * The controller therefore hands Laravel only the stored path + query and
 * lets it re-root the redirect on whatever host the reading request actually
 * arrived on — the reader's origin — never the stored one)Skip, whether that is a
 * production `APP_URL` domain, a `127.0.0.1` written by a local run, or a
 * `localhost` from the app's own routing.
 */
class AlertReadRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_read_marks_read_and_repeating_it_is_idempotent(): void
    {
        $user = User::factory()->create();
        $alert = app(AlertService::class)->raise($user, 'test', 'Read me');

        $first = $this->actingAs($user)->post(route('alerts.read', $alert));
        $first->assertRedirect();
        $readAt = $alert->fresh()->read_at;
        $this->assertNotNull($readAt);

        $second = $this->actingAs($user)->post(route('alerts.read', $alert));
        $second->assertRedirect();
        $this->assertSame($readAt->getTimestamp(), $alert->fresh()->read_at->getTimestamp(),
            "re-reading is not an error; it must not move the timestamp");
    }

    public function test_production_style_stored_host_is_stripped_and_the_redirect_stays_on_the_reader_origin(): void
    {
        $user = User::factory()->create();
        $alert = app(AlertService::class)->raise($user, 'user_deactivated_open_leads.7', 'Handover needed',
            actionUrl: 'https://crm.example.com/users?reset=1&status=pending');

        $response = $this->actingAs($user)->post(route('alerts.read', $alert));
        $response->assertRedirect(app('url')->to('/users?reset=1&status=pending'));

        $location = $response->headers->get('Location') ?? '';
        $this->assertSame('/users', parse_url($location, PHP_URL_PATH));
        $this->assertStringNotContainsString('crm.example.com', $location,
            "a production APP_URL host must never leak into the reader's redirect");
    }

    public function test_lead_alert_redirect_keeps_the_search_query_and_drops_the_host(): void
    {
        $user = User::factory()->create();
        $alert = app(AlertService::class)->raise($user, 'lead_stuck', 'Stuck',
            lead: $this->lead($user));

        $response = $this->actingAs($user)->post(route('alerts.read', $alert));
        $response->assertRedirect(app('url')->to('/leads?search=9876543210'));

        $location = $response->headers->get('Location') ?? '';
        $this->assertSame('/leads', parse_url($location, PHP_URL_PATH));
    }

    public function test_stored_localhost_destination_is_re_rooted_so_localhost_and_127_0_0_1_never_meet(): void
    {
        $user = User::factory()->create();
        $alert = app(AlertService::class)->raise($user, 'user_deactivated_open_leads.8', 'Handover needed',
            actionUrl: 'http://localhost:8000/users?status=pending');

        $response = $this->actingAs($user)->post(route('alerts.read', $alert));
        $response->assertRedirect(app('url')->to('/users?status=pending'));

        $location = $response->headers->get('Location') ?? '';
        $this->assertStringNotContainsString('localhost', $location);
    }

    public function test_alert_without_a_destination_falls_back_to_the_page_it_was_read_from(): void
    {
        $user = User::factory()->create();
        $alert = app(AlertService::class)->raise($user, 'test', 'No destination');

        $this->assertNull($alert->action_url);
        $this->actingAs($user)->from('/alerts')->post(route('alerts.read', $alert))
            ->assertRedirect('/alerts');
    }

    public function test_reading_someone_elses_alert_is_forbidden_and_leaves_it_unread(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $alert = app(AlertService::class)->raise($owner, 'test', 'Private');

        $this->actingAs($stranger)->post(route('alerts.read', $alert))->assertForbidden();
        $this->assertNull($alert->fresh()->read_at);
    }

    private function lead(User $user): Lead
    {
        return Lead::create([
            'first_name' => 'Test', 'last_name' => 'Lead',
            'project_id' => Project::create(['name' => 'Test Project'])->id,
            'mobile_number' => '9876543210', 'source' => 'walk_in', 'stage' => 'fresh',
            'assigned_to' => $user->id, 'assigned_role' => $user->role, 'stage_changed_at' => now(),
        ]);
    }
}
