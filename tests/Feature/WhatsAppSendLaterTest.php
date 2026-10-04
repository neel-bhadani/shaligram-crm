<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Send later: a tag sent by a person at a chosen time, India time, by API
 * only, through the existing queue worker as a delayed job. Cancellable in
 * the Queue until the worker claims it; the lead is read again when it goes.
 */
class WhatsAppSendLaterTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://api.11za.in/apis/template/sendTemplate';

    private User $admin;

    private User $tele;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Skyline Residency']);
        $this->configureApi();

        Http::fake([self::SEND_URL => Http::response(['status' => 'success', 'data' => ['messageId' => '11za-1']])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_tag_is_scheduled_from_the_lead_at_the_india_time_typed(): void
    {
        Queue::fake();
        $lead = $this->lead();

        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.send', $lead), ['template_id' => $this->tag()->id, 'send_at' => '2026-10-05T15:30'])
            ->assertOk()
            ->assertJsonPath('message', 'Scheduled for 5 Oct, 3:30 pm (India time). Until then it can be cancelled, from this lead or the Queue.');

        $message = MessageLog::sole();
        $this->assertSame('queued', $message->status);
        $this->assertSame('api', $message->mode);
        $this->assertTrue($message->send_at->equalTo(Carbon::parse('2026-10-05 15:30', 'Asia/Kolkata')));
        $this->assertTrue($message->isScheduled());

        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->messageId === $message->id
            && Carbon::instance($job->delay)->equalTo($message->send_at));
        Http::assertNothingSent();
    }

    public function test_a_time_already_gone_is_refused_and_nothing_is_written(): void
    {
        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.send', $this->lead()), ['template_id' => $this->tag()->id, 'send_at' => '2026-10-05T10:59'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a time in the future (India time).');

        $this->assertSame(0, MessageLog::count());
    }

    public function test_click_to_send_cannot_be_scheduled_and_says_why(): void
    {
        $this->configureApi(apiEnabled: false);

        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.send', $this->lead()), ['template_id' => $this->tag()->id, 'send_at' => '2026-10-05T15:30'])
            ->assertStatus(422)
            ->assertJsonPath('message', WhatsAppSender::NOT_SCHEDULABLE);

        $this->assertSame(0, MessageLog::count());
    }

    public function test_the_lead_is_read_again_when_it_goes(): void
    {
        $lead = $this->lead();
        $message = $this->schedule($lead);

        $lead->update(['first_name' => 'Rohan', 'mobile_number' => '9123456780']);

        $this->runAt('2026-10-05 15:30', $message);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('919123456780', $message->to_number);
        $this->assertSame(['Rohan'], $message->params);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), '919123456780')
            && str_contains($request->body(), 'Rohan'));
    }

    public function test_a_lead_deleted_before_the_time_is_dropped_with_the_reason(): void
    {
        $lead = $this->lead();
        $message = $this->schedule($lead);
        $lead->delete();

        $this->runAt('2026-10-05 15:30', $message);

        $this->assertSame('skipped', $message->fresh()->status);
        $this->assertSame('The lead was deleted before the scheduled time, so it was not sent.', $message->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_a_lead_left_without_a_number_is_dropped_never_sent_blank(): void
    {
        $lead = $this->lead();
        $message = $this->schedule($lead);
        $lead->update(['mobile_number' => '12345']);

        $this->runAt('2026-10-05 15:30', $message);

        $this->assertSame('skipped', $message->fresh()->status);
        $this->assertStringStartsWith('By the scheduled time the lead had no usable mobile number', $message->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_a_customer_who_opts_out_after_scheduling_is_not_sent_to(): void
    {
        $lead = $this->lead();
        $message = $this->schedule($lead);

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Asia/Kolkata'));
        $lead->update(['whatsapp_opted_out_at' => now(), 'whatsapp_opt_out_source' => 'manual']);

        $this->runAt('2026-10-05 15:30', $message);

        $this->assertSame('skipped', $message->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_worker_woken_early_does_not_send(): void
    {
        $message = $this->schedule($this->lead());

        $this->runAt('2026-10-05 15:29', $message);

        $this->assertSame('queued', $message->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_it_is_listed_and_cancellable_in_the_queue_until_it_goes_and_never_after(): void
    {
        $message = $this->schedule($this->lead());

        $this->actingAs($this->admin)->get('/automation?tab=queue')
            ->assertInertia(fn (Assert $page) => $page
                ->has('scheduled', 1)
                ->where('scheduled.0.id', $message->id)
                ->where('scheduled.0.scheduled', true));

        Carbon::setTestNow(Carbon::parse('2026-10-05 14:10', 'Asia/Kolkata'));
        $this->actingAs($this->admin)->post(route('automation.messages.cancel', $message))->assertSessionHas('success');

        // who scheduled it and who stopped it, both kept
        $message->refresh();
        $this->assertSame('cancelled', $message->status);
        $this->assertSame($this->tele->id, $message->user_id);
        $this->assertSame($this->admin->id, $message->cancelled_by);
        $this->assertSame(
            'Cancelled by Ann User on 5 Oct, 2:10 pm. Scheduled by Tara User on 5 Oct, 11:00 am for 5 Oct, 3:30 pm',
            $message->outcome(),
        );

        // the delayed job wakes later and finds nothing to send
        $this->runAt('2026-10-05 15:30', $message);
        Http::assertNothingSent();

        // once the worker has it, cancel cannot land on it
        $claimed = $this->schedule($this->lead(['mobile_number' => '9876500011']));
        $claimed->update(['status' => 'sending']);

        $this->actingAs($this->admin)->post(route('automation.messages.cancel', $claimed))
            ->assertSessionHas('error', 'That message has already left the queue.');
        $this->assertSame('sending', $claimed->fresh()->status);
    }

    public function test_whoever_scheduled_it_can_cancel_it_from_the_lead_and_an_admin_can_cancel_any(): void
    {
        $lead = $this->lead();
        $mine = $this->schedule($lead);

        $this->actingAs($this->tele)->getJson(route('leads.whatsapp.show', $lead))
            ->assertJsonPath('history.0.can_cancel', true);
        $this->actingAs($this->admin)->getJson(route('leads.whatsapp.show', $lead))
            ->assertJsonPath('history.0.can_cancel', true);

        // the telecaller can open this lead, but not cancel what somebody else scheduled on it
        $adminsOwn = $this->schedule($lead, $this->admin);
        $this->actingAs($this->tele)->getJson(route('leads.whatsapp.show', $lead))
            ->assertJsonPath('history.0.id', $adminsOwn->id)
            ->assertJsonPath('history.0.can_cancel', false);
        $this->actingAs($this->tele)->postJson(route('leads.whatsapp.cancel', [$lead, $adminsOwn]))->assertForbidden();
        $this->assertSame('queued', $adminsOwn->fresh()->status);

        $this->actingAs($this->tele)->postJson(route('leads.whatsapp.cancel', [$lead, $mine]))
            ->assertOk()
            ->assertJsonPath('message', 'Cancelled. It will not be sent.');
        $this->assertSame('cancelled', $mine->fresh()->status);
        $this->assertSame($this->tele->id, $mine->fresh()->cancelled_by);

        $theirs = $this->schedule($this->lead(['mobile_number' => '9876500022']));
        $this->actingAs($this->admin)->postJson(route('leads.whatsapp.cancel', [$theirs->lead_id, $theirs]))->assertOk();
        $this->assertSame('cancelled', $theirs->fresh()->status);
        $this->assertSame($this->tele->id, $theirs->fresh()->user_id);
        $this->assertSame($this->admin->id, $theirs->fresh()->cancelled_by);
    }

    public function test_cancelling_from_the_lead_is_refused_once_it_is_on_its_way(): void
    {
        $lead = $this->lead();
        $message = $this->schedule($lead);
        $message->update(['status' => 'sending']);

        $this->actingAs($this->tele)->postJson(route('leads.whatsapp.cancel', [$lead, $message]))->assertForbidden();
        $this->assertSame('sending', $message->fresh()->status);

        // nor can a message on another lead be cancelled through this one
        $other = $this->schedule($this->lead(['mobile_number' => '9876500033']));
        $this->actingAs($this->tele)->postJson(route('leads.whatsapp.cancel', [$lead, $other]))->assertForbidden();
    }

    public function test_a_queued_message_can_be_scheduled_from_the_queue(): void
    {
        Queue::fake();
        $this->configureApi(autoSend: false);

        $outcome = app(WhatsAppSender::class)->queueTemplate($this->lead(), $this->tag());
        $this->assertSame('queued', $outcome['result']);

        $this->actingAs($this->admin)
            ->post(route('automation.messages.send', $outcome['message']), ['send_at' => '2026-10-06T09:00'])
            ->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'Scheduled for 6 Oct, 9:00 am'));

        $this->assertTrue($outcome['message']->fresh()->isScheduled());
        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->delay !== null);

        // a second press does not send it another way
        $this->actingAs($this->admin)->post(route('automation.messages.send', $outcome['message']))
            ->assertSessionHas('error', 'That message is already scheduled. Cancel it first to send it another way.');
    }

    /* ================= helpers ================= */

    private function schedule(Lead $lead, ?User $by = null): MessageLog
    {
        $outcome = app(WhatsAppSender::class)->queueTemplate(
            $lead, MessageTemplate::first() ?? $this->tag(), user: $by ?? $this->tele,
            sendAt: Carbon::parse('2026-10-05 15:30', 'Asia/Kolkata'),
        );

        $this->assertSame('scheduled', $outcome['result']);

        return $outcome['message'];
    }

    /** The delayed job, run as the worker would run it at this India time. */
    private function runAt(string $time, MessageLog $message): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Kolkata'));

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppSender::class));
    }

    private function tag(): MessageTemplate
    {
        return MessageTemplate::create([
            'name' => 'Site visit thanks', 'is_active' => true,
            'provider_template_name' => 'site_visit', 'provider_template_language' => 'en',
            'placeholder_map' => ['first_name'],
        ]);
    }

    private function configureApi(bool $apiEnabled = true, bool $autoSend = true): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'auth_token' => '11za-live-token/abc+123',
            'origin_website' => 'https://shaligram.example',
            'api_enabled' => $apiEnabled,
            'auto_send' => $autoSend,
        ]);
        $integration->save();
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'first_name' => 'Rahul',
            'last_name' => 'Mehta',
            'mobile_number' => '9876543210',
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
