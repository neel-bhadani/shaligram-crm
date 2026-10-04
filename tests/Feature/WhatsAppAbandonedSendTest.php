<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Alert;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * A send whose worker died between claiming the row and 11za answering.
 *
 * It may have reached the customer, so it is never re-sent on its own: it
 * becomes `unknown`, admins are told once, and a person settles it after
 * looking it up in 11za's log.
 */
class WhatsAppAbandonedSendTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://api.11za.in/apis/template/sendTemplate';

    private User $admin;

    private Lead $lead;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-04 15:00', 'Asia/Kolkata'));

        $this->admin = User::create([
            'first_name' => 'Ann', 'last_name' => 'Admin', 'email' => 'ann@example.test',
            'mobile_number' => '9000000001', 'role' => 'admin', 'is_active' => true, 'password' => 'password',
        ]);

        $this->lead = Lead::create([
            'first_name' => 'Rahul', 'last_name' => 'Mehta', 'mobile_number' => '9876543210',
            'project_id' => Project::create(['name' => 'Skyline Residency'])->id,
            'source' => 'walk_in', 'stage' => 'fresh',
            'assigned_to' => $this->admin->id, 'assigned_role' => 'admin',
            'stage_changed_at' => now(), 'created_by' => $this->admin->id,
        ]);

        $this->template = MessageTemplate::create([
            'name' => 'Welcome', 'is_active' => true, 'placeholder_map' => ['first_name'],
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
        ]);

        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'auth_token' => '11za-live-token', 'origin_website' => 'https://shaligram.example',
            'api_enabled' => true, 'auto_send' => true,
        ]);
        $integration->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_sweeper_marks_an_abandoned_send_unknown_and_never_sends_it_again(): void
    {
        Http::fake();
        $abandoned = $this->message(['status' => 'sending', 'sending_started_at' => now()->subMinutes(6)]);
        $inProgress = $this->message(['status' => 'sending', 'sending_started_at' => now()->subMinute()]);

        $this->artisan('whatsapp:sweep-abandoned')->assertSuccessful();

        $this->assertSame('unknown', $abandoned->fresh()->status);
        $this->assertSame('sending', $inProgress->fresh()->status, 'a send still within its time is left alone');
        Http::assertNothingSent();

        // the queue hands the dead worker's job out again: it cannot claim the row
        SendWhatsAppMessage::dispatchSync($abandoned->id);
        Http::assertNothingSent();
        $this->assertSame('unknown', $abandoned->fresh()->status);
    }

    public function test_admins_are_alerted_about_newly_marked_sends_only(): void
    {
        $this->message(['status' => 'sending', 'sending_started_at' => now()->subMinutes(6)]);

        $this->artisan('whatsapp:sweep-abandoned');
        $this->assertSame(1, Alert::where('type', 'whatsapp_outcome_unknown')->count());

        // nothing new marked: nothing new raised, though the row is still unsettled
        Carbon::setTestNow(now()->addMinute());
        $this->artisan('whatsapp:sweep-abandoned');
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('whatsapp:sweep-abandoned');

        $this->assertSame(1, Alert::count());
    }

    public function test_a_send_left_unsettled_for_a_day_gets_one_reminder_a_day(): void
    {
        $this->message(['status' => 'unknown']);

        Carbon::setTestNow(now()->addHours(25));
        $this->artisan('whatsapp:sweep-abandoned');
        $this->artisan('whatsapp:sweep-abandoned');
        $this->assertSame(1, Alert::where('type', 'whatsapp_outcome_unknown_reminder')->count());

        Carbon::setTestNow(now()->addHours(25));
        $this->artisan('whatsapp:sweep-abandoned');
        $this->assertSame(2, Alert::where('type', 'whatsapp_outcome_unknown_reminder')->count());
    }

    public function test_a_job_that_gives_up_mid_send_records_unknown_not_failed(): void
    {
        $handedOver = $this->message(['status' => 'sending', 'sending_started_at' => now()]);
        $neverLeft = $this->message(['status' => 'queued']);

        (new SendWhatsAppMessage($handedOver->id))->failed(new RuntimeException('Job timed out'));
        (new SendWhatsAppMessage($neverLeft->id))->failed(new RuntimeException('Job timed out'));

        $this->assertSame('unknown', $handedOver->fresh()->status);
        $this->assertSame('failed', $neverLeft->fresh()->status);
    }

    public function test_the_claim_records_when_the_row_was_handed_to_11za(): void
    {
        Http::fake([self::SEND_URL => Http::response(['messageId' => 'M-1'])]);
        $message = $this->message(['status' => 'queued']);

        SendWhatsAppMessage::dispatchSync($message->id);

        $this->assertTrue(now()->equalTo($message->fresh()->sending_started_at));
    }

    public function test_i_checked_11za_it_delivered_records_it_sent_under_the_checkers_name(): void
    {
        Http::fake();
        $handedAt = now()->subMinutes(30);
        $message = $this->message(['status' => 'unknown', 'sending_started_at' => $handedAt]);

        $this->actingAs($this->admin)
            ->post(route('automation.messages.checked-delivered', $message))
            ->assertSessionHas('success');

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('delivered', $message->check_result);
        $this->assertSame($this->admin->id, $message->checked_by);
        $this->assertTrue($handedAt->equalTo($message->sent_at));
        $this->assertFalse($message->isUnconfirmed());
        $this->assertStringContainsString('Ann Admin found it in the 11za log', $message->outcome());
        Http::assertNothingSent();
    }

    public function test_i_checked_11za_it_never_went_sends_it_again_once(): void
    {
        Http::fake([self::SEND_URL => Http::response(['messageId' => 'M-2'])]);
        $message = $this->message(['status' => 'unknown', 'sending_started_at' => now()->subMinutes(30)]);

        $this->actingAs($this->admin)
            ->post(route('automation.messages.checked-not-sent', $message))
            ->assertSessionHas('success');

        Http::assertSentCount(1);
        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('not_sent', $message->check_result);
        $this->assertStringContainsString('sent again after Ann Admin found no trace', $message->outcome());

        // a second press settles nothing and sends nothing
        $this->actingAs($this->admin)
            ->post(route('automation.messages.checked-not-sent', $message))
            ->assertSessionHas('error');
        Http::assertSentCount(1);
    }

    public function test_it_never_went_is_refused_for_a_customer_who_opted_out_since(): void
    {
        Http::fake();
        $message = $this->message(['status' => 'unknown']);
        $this->lead->update(['whatsapp_opted_out_at' => now(), 'whatsapp_opt_out_source' => 'manual']);

        $this->actingAs($this->admin)
            ->post(route('automation.messages.checked-not-sent', $message))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame('unknown', $message->fresh()->status);
    }

    public function test_an_unknown_send_counts_as_received_so_a_rule_does_not_repeat_it(): void
    {
        Http::fake();
        $params = app(WhatsAppSender::class)->paramsFor($this->template, $this->lead);
        $this->message([
            'status' => 'unknown',
            'dedupe_key' => hash('sha256', '919876543210|'.$this->template->id.'|'.json_encode($params)),
        ]);

        $outcome = app(WhatsAppSender::class)->queueTemplate($this->lead, $this->template, dedupeMinutes: 60);

        $this->assertSame('skipped', $outcome['result']);
        Http::assertNothingSent();
    }

    public function test_every_unknown_send_is_on_the_queue_tab_however_old(): void
    {
        $old = $this->message(['status' => 'unknown']);
        config(['automation.queue_limit' => 1]);
        $this->message(['status' => 'sent', 'confirmed' => true]);

        $this->actingAs($this->admin)->get('/automation?tab=queue')
            ->assertInertia(fn (Assert $page) => $page
                ->has('queue', 1)
                ->has('needsChecking', 1)
                ->where('needsChecking.0.id', $old->id));
    }

    private function message(array $attrs): MessageLog
    {
        return MessageLog::create($attrs + [
            'lead_id' => $this->lead->id, 'template_id' => $this->template->id, 'user_id' => $this->admin->id,
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
            'mode' => 'api', 'to_number' => '919876543210', 'to_name' => 'Rahul Mehta',
            'body' => 'Hello Rahul.', 'params' => ['Rahul'],
        ]);
    }
}
