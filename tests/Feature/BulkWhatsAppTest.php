<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageBatch;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\WhatsApp\BulkSender;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Bulk WhatsApp: one tag to many leads, admin only, with every guard rail —
 * exclusions with reasons, the cap after exclusions, the confirmed numbers,
 * pacing, stop, and the 429 and failure-streak holds.
 *
 * @see BulkSender
 */
class BulkWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://api.11za.in/apis/template/sendTemplate';

    private User $admin;

    private User $tele;

    private Project $project;

    private MessageTemplate $tag;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00', 'Asia/Kolkata'));
        Queue::fake();

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Skyline Residency']);
        $this->tag = MessageTemplate::create([
            'name' => 'Site visit thanks', 'is_active' => true,
            'provider_template_name' => 'site_visit', 'provider_template_language' => 'en',
            'placeholder_map' => ['first_name'],
        ]);

        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'auth_token' => '11za-live-token/abc+123',
            'origin_website' => 'https://shaligram.example',
            'api_enabled' => true,
            'auto_send' => true,
        ]);
        $integration->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ================= the confirm screen ================= */

    public function test_it_is_admin_only(): void
    {
        $lead = $this->lead('Rahul', '9876543210');

        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.bulk.preview'), $this->picked([$lead]))
            ->assertForbidden();
        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.bulk.start'), $this->picked([$lead]) + ['expect_selected' => 1, 'expect_recipients' => 1])
            ->assertForbidden();

        $this->assertSame(0, MessageLog::count());
    }

    public function test_the_preview_shows_selected_and_receiving_with_every_left_out_lead_and_why(): void
    {
        $rahul = $this->lead('Rahul', '9876543210');
        $opted = $this->lead('Omar', '9876543211', ['whatsapp_opted_out_at' => now()]);
        $nonumber = $this->lead('Nina', '12345');
        $twin = $this->lead('Tom', '9876543210', ['project_id' => Project::create(['name' => 'Vanam'])->id]);
        $recent = $this->lead('Riya', '9876543212');
        MessageLog::create(['lead_id' => $recent->id, 'template_id' => $this->tag->id, 'mode' => 'api', 'status' => 'sent',
            'to_number' => '919876543212', 'body' => 'x', 'created_at' => now()->subHours(3)]);

        $response = $this->preview([$rahul, $opted, $nonumber, $twin, $recent])
            ->assertJsonPath('selected', 5)
            ->assertJsonPath('recipients', 1)
            ->assertJsonPath('over_cap', false)
            ->assertJsonPath('sample.name', 'Rahul User')
            ->assertJsonPath('sample.values.0.value', 'Rahul');

        $reasons = collect($response->json('excluded'))->mapWithKeys(fn ($g) => [$g['reason'] => collect($g['leads'])->pluck('name')->all()]);

        $this->assertSame([
            'Opted out of WhatsApp' => ['Omar User'],
            'No usable mobile number' => ['Nina User'],
            'Same number as another lead in this send' => ['Tom User'],
            'Sent this tag in the last 24 hours' => ['Riya User'],
        ], $reasons->all());

        $this->assertSame(0, MessageLog::where('status', '!=', 'sent')->count(), 'a preview writes nothing');
    }

    public function test_the_cap_counts_after_exclusions_and_refuses_rather_than_cuts(): void
    {
        config(['automation.whatsapp.bulk.max_recipients' => 2]);

        $a = $this->lead('Asha', '9876500001');
        $b = $this->lead('Bina', '9876500002');
        $opted = $this->lead('Omar', '9876500003', ['whatsapp_opted_out_at' => now()]);

        // three selected, two receive: inside the cap
        $this->preview([$a, $b, $opted])->assertJsonPath('recipients', 2)->assertJsonPath('over_cap', false);

        // three receive: over it, and nothing is written
        $c = $this->lead('Chirag', '9876500004');
        $this->preview([$a, $b, $c])->assertJsonPath('over_cap', true);

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.bulk.start'), $this->picked([$a, $b, $c]) + ['expect_selected' => 3, 'expect_recipients' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', '3 would receive it, and a bulk send is limited to 2. Nothing was sent. Narrow the selection.');

        $this->assertSame(0, MessageBatch::count());
        $this->assertSame(0, MessageLog::count());
    }

    public function test_it_is_refused_when_the_numbers_moved_since_the_admin_confirmed_them(): void
    {
        $a = $this->lead('Asha', '9876500001');
        $b = $this->lead('Bina', '9876500002');

        $b->update(['whatsapp_opted_out_at' => now()]);   // after the admin looked

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.bulk.start'), $this->picked([$a, $b]) + ['expect_selected' => 2, 'expect_recipients' => 2])
            ->assertStatus(422)
            ->assertJsonPath('recipients', 1)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'The list changed since you checked it: now 2 selected, 1 will receive it.'));

        $this->assertSame(0, MessageBatch::count());
    }

    public function test_click_to_send_only_tags_cannot_be_sent_in_bulk(): void
    {
        $this->tag->update(['provider_template_name' => null]);

        $this->preview([$this->lead('Asha', '9876500001')], 422)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Bulk sending needs API sending, and this tag cannot go by API'));
    }

    public function test_everything_matching_the_list_filter_is_the_list_as_it_shows(): void
    {
        $vanam = Project::create(['name' => 'Vanam']);
        $in = $this->lead('Asha', '9876500001', ['project_id' => $vanam->id]);
        $this->lead('Bina', '9876500002');

        // the leads page keeps its filter in the session
        $this->actingAs($this->admin)->get(route('leads.index', ['reset' => 1, 'project_id' => $vanam->id]))->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.bulk.preview'), ['template_id' => $this->tag->id, 'mode' => 'filter'])
            ->assertOk()
            ->assertJsonPath('selected', 1)
            ->assertJsonPath('sample.name', $in->full_name);
    }

    /* ================= starting ================= */

    public function test_starting_writes_one_batch_one_row_per_lead_and_paces_the_jobs(): void
    {
        $leads = [$this->lead('Asha', '9876500001'), $this->lead('Bina', '9876500002'), $this->lead('Chirag', '9876500003')];
        $opted = $this->lead('Omar', '9876500004', ['whatsapp_opted_out_at' => now()]);

        $this->start([...$leads, $opted], 4, 3)
            ->assertJsonPath('url', route('automation.index', ['tab' => 'queue']));

        $batch = MessageBatch::sole();
        $this->assertSame([4, 1, 3], [$batch->selected_count, $batch->excluded_count, $batch->recipient_count]);
        $this->assertSame($this->admin->id, $batch->created_by);

        $queued = $batch->messages()->where('status', 'queued')->orderBy('id')->get();
        $this->assertCount(3, $queued);

        // 20 a minute: one every three seconds
        $this->assertSame([0, 3, 6], $queued->map(fn ($m) => (int) now()->diffInSeconds($m->send_at))->all());
        Queue::assertPushed(SendWhatsAppMessage::class, 3);

        $leftOut = $batch->messages()->where('status', 'skipped')->sole();
        $this->assertSame($opted->id, $leftOut->lead_id);
        $this->assertSame('Opted out of WhatsApp', $leftOut->error);

        // one row on the Queue, not four in its log
        $this->actingAs($this->admin)->get('/automation?tab=queue')
            ->assertInertia(fn (Assert $page) => $page
                ->has('queue', 0)
                ->has('scheduled', 0)
                ->has('batches', 1)
                ->where('batches.0.counts.queued', 3)
                ->where('batches.0.counts.skipped', 1)
                ->where('batches.0.created_by', 'Ann User'));
    }

    public function test_a_bulk_send_can_be_sent_later(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.bulk.start'), $this->picked([$this->lead('Asha', '9876500001')])
                + ['expect_selected' => 1, 'expect_recipients' => 1, 'send_at' => '2026-10-06T09:00'])
            ->assertOk();

        $this->assertTrue(MessageLog::sole()->send_at->equalTo(Carbon::parse('2026-10-06 09:00', 'Asia/Kolkata')));
        $this->assertSame('scheduled', MessageBatch::sole()->state());
    }

    /* ================= while it runs ================= */

    public function test_stopping_cancels_what_is_waiting_and_keeps_who_started_it(): void
    {
        Http::fake([self::SEND_URL => Http::response(['data' => ['messageId' => '11za-1']])]);
        $this->start([$this->lead('Asha', '9876500001'), $this->lead('Bina', '9876500002'), $this->lead('Chirag', '9876500003')], 3, 3);

        [$first, $second, $third] = MessageLog::orderBy('id')->get()->all();
        $this->runAt('2026-10-05 11:00:00', $first);
        $this->assertSame('sent', $first->fresh()->status);

        $other = $this->user('admin', 'Sam');
        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00:02', 'Asia/Kolkata'));
        $this->actingAs($other)->post(route('automation.batches.stop', MessageBatch::sole()))->assertSessionHas('success');

        $batch = MessageBatch::sole();
        $this->assertSame('stopped', $batch->status);
        $this->assertSame($this->admin->id, $batch->created_by);
        $this->assertSame($other->id, $batch->stopped_by);
        $this->assertSame(['cancelled', 'cancelled'], [$second->fresh()->status, $third->fresh()->status]);
        $this->assertSame($other->id, $second->fresh()->cancelled_by);
        $this->assertSame($this->admin->id, $second->fresh()->user_id);

        // their turns come round and nothing goes
        $this->runAt('2026-10-05 11:00:06', $third);
        Http::assertSentCount(1);
    }

    public function test_11za_saying_429_holds_the_send_and_nothing_more_goes(): void
    {
        Http::fake([self::SEND_URL => Http::response(['message' => 'too many'], 429)]);
        $this->start([$this->lead('Asha', '9876500001'), $this->lead('Bina', '9876500002')], 2, 2);

        [$first, $second] = MessageLog::orderBy('id')->get()->all();
        $this->runAt('2026-10-05 11:00:00', $first);

        $batch = MessageBatch::sole();
        $this->assertSame('held', $batch->status);
        $this->assertStringContainsString('HTTP 429', $batch->held_reason);
        $this->assertSame('held', $first->fresh()->status);
        $this->assertStringStartsWith('Rate limited', $first->fresh()->outcome());
        $this->assertSame('held', $second->fresh()->status);

        $this->runAt('2026-10-05 11:00:03', $second);
        Http::assertSentCount(1);
    }

    public function test_a_run_of_failures_holds_the_send_and_a_success_resets_the_run(): void
    {
        config(['automation.whatsapp.bulk.failure_streak' => 2]);

        Http::fakeSequence(self::SEND_URL)
            ->push(['message' => 'bad'], 400)
            ->push(['data' => ['messageId' => '11za-2']])
            ->push(['message' => 'bad'], 400)
            ->push(['message' => 'bad'], 400);

        $leads = collect(range(1, 5))->map(fn ($i) => $this->lead("Lead{$i}", '987650000'.$i))->all();
        $this->start($leads, 5, 5);
        $rows = MessageLog::orderBy('id')->get();

        $this->runAt('2026-10-05 11:00:00', $rows[0]);   // failed: 1 in a row
        $this->runAt('2026-10-05 11:00:03', $rows[1]);   // sent: the run is over
        $this->assertSame(0, MessageBatch::sole()->failure_streak);

        $this->runAt('2026-10-05 11:00:06', $rows[2]);   // failed: 1
        $this->assertSame('running', MessageBatch::sole()->status);
        $this->runAt('2026-10-05 11:00:09', $rows[3]);   // failed: 2 — held

        $batch = MessageBatch::sole();
        $this->assertSame('held', $batch->status);
        $this->assertStringStartsWith('Held: 2 sends in a row failed. The last error: 11za answered HTTP 400.', $batch->held_reason);
        $this->assertSame('held', $rows[4]->fresh()->status);
    }

    public function test_a_send_in_flight_when_the_batch_is_held_is_held_too_not_retried(): void
    {
        $this->start([$this->lead('Asha', '9876500001'), $this->lead('Bina', '9876500002')], 2, 2);
        $first = MessageLog::orderBy('id')->first();

        // the batch is held by something else while this one is with 11za
        Http::fake([self::SEND_URL => function () {
            MessageBatch::sole()->hold('Held by a test');

            return Http::response('busy', 503);
        }]);

        $this->runAt('2026-10-05 11:00:00', $first);

        $this->assertSame('held', $first->fresh()->status);
        $this->assertSame('Held by a test', MessageBatch::sole()->held_reason);
    }

    public function test_after_resume_a_retry_from_before_the_hold_does_nothing(): void
    {
        config(['automation.whatsapp.bulk.failure_streak' => 99]);

        Http::fakeSequence(self::SEND_URL)
            ->push('busy', 503)
            ->push(['data' => ['messageId' => '11za-1']]);

        $this->start([$this->lead('Asha', '9876500001')], 1, 1);
        $row = MessageLog::sole();

        $this->runAt('2026-10-05 11:00:00', $row);   // retryable: back to queued, a retry pending
        $this->assertSame('queued', $row->fresh()->status);

        $batch = MessageBatch::sole();
        $batch->hold('Held by an admin');
        $this->actingAs($this->admin)->post(route('automation.batches.resume', $batch))->assertSessionHas('success');
        $this->assertSame(1, $row->fresh()->dispatch);

        // the old retry wakes: a different dispatch number, so nothing
        $this->runAt('2026-10-05 11:01:00', $row, dispatch: 0);
        Http::assertSentCount(1);
        $this->assertSame('queued', $row->fresh()->status);

        // the job Resume queued sends it
        $this->runAt('2026-10-05 11:01:00', $row, dispatch: 1);
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame($this->admin->id, MessageBatch::sole()->resumed_by);
    }

    public function test_the_same_tag_sent_meanwhile_is_skipped_when_its_turn_comes(): void
    {
        Http::fake([self::SEND_URL => Http::response(['data' => ['messageId' => '11za-1']])]);
        $lead = $this->lead('Asha', '9876500001');
        $this->start([$lead], 1, 1);
        $row = MessageLog::sole();

        // somebody sent it by hand from the lead before its turn
        MessageLog::create(['lead_id' => $lead->id, 'template_id' => $this->tag->id, 'mode' => 'api', 'status' => 'sent',
            'to_number' => '919876500001', 'body' => 'x']);

        $this->runAt('2026-10-05 11:00:00', $row);

        $this->assertSame('skipped', $row->fresh()->status);
        $this->assertStringStartsWith('This tag already went to this lead or number', $row->fresh()->error);
        Http::assertNothingSent();
    }

    /* ================= helpers ================= */

    /** @param list<Lead> $leads */
    private function picked(array $leads): array
    {
        return ['template_id' => $this->tag->id, 'mode' => 'selected', 'lead_ids' => array_map(fn (Lead $l) => $l->id, $leads)];
    }

    /** @param list<Lead> $leads */
    private function preview(array $leads, int $status = 200): TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.bulk.preview'), $this->picked($leads))
            ->assertStatus($status);
    }

    /** @param list<Lead> $leads */
    private function start(array $leads, int $selected, int $recipients): TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.bulk.start'), $this->picked($leads)
                + ['expect_selected' => $selected, 'expect_recipients' => $recipients])
            ->assertOk();
    }

    /** A row's job, run as the worker would at this India time. */
    private function runAt(string $time, MessageLog $message, int $dispatch = 0): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Kolkata'));

        (new SendWhatsAppMessage($message->id, $dispatch))->handle(app(WhatsAppSender::class));
    }

    private function lead(string $first, string $mobile, array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'first_name' => $first,
            'last_name' => 'User',
            'mobile_number' => $mobile,
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_to' => $this->tele->id,
            'assigned_role' => 'telecaller',
            'stage_changed_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'User',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
