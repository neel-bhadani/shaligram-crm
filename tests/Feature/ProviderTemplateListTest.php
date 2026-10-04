<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\MessageTemplate;
use App\Models\ProviderTemplate;
use App\Models\User;
use App\Services\WhatsApp\ElevenZaClient;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The Tags tab's 11za template list: kept in its own table as name,
 * language and variable count, never with a raw answer or wording, and read
 * by a refresh that always answers.
 *
 * @see WhatsAppSender::refreshProviderTemplates()
 */
class ProviderTemplateListTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_URL = 'https://api.11za.in/apis/template/getTemplatesAll';

    private const TOKEN = '11za-live-token/abc+123';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ann',
            'last_name' => 'User',
            'email' => 'ann@example.test',
            'mobile_number' => '9876500001',
            'role' => 'admin',
            'is_active' => true,
            'password' => 'password',
        ]);

        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'auth_token' => self::TOKEN,
            'origin_website' => 'https://shaligram.example',
            'api_enabled' => true,
        ]);
        $integration->save();
    }

    public function test_the_list_is_stored_as_name_language_and_count_and_the_wording_copied_onto_the_message(): void
    {
        $template = $this->message();

        Http::fake([self::LIST_URL => Http::response(['Data' => [
            ['name' => 'welcome', 'language' => 'en', 'body' => 'Hi {{1}}, about {{2}}.'],
            ['name' => 'site_visit', 'language' => 'en'],
        ], 'IsSuccess' => true])]);

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('raw', null)
            ->assertJsonPath('failed', false)
            ->assertJsonPath('templates', [
                ['name' => 'welcome', 'language' => 'en', 'variables' => 2],
                ['name' => 'site_visit', 'language' => 'en', 'variables' => null],
            ]);

        Http::assertSent(fn ($request) => $request->url() === self::LIST_URL && $request['authToken'] === self::TOKEN);

        $this->assertSame(['welcome', 'site_visit'], ProviderTemplate::orderBy('id')->pluck('name')->all());
        $this->assertSame('Hi {{1}}, about {{2}}.', $template->fresh()->provider_body);

        $settings = Integration::forProvider('whatsapp')->settings;
        $this->assertArrayNotHasKey('template_list', $settings);
        $this->assertSame(['at', 'total', 'page_size', 'failed_at'], array_keys($settings['template_list_read']));
    }

    public function test_a_refused_read_shows_11zas_answer_without_the_token_and_stores_none_of_it(): void
    {
        ProviderTemplate::create(['name' => 'kept', 'language' => 'en', 'variables' => 1]);

        Http::fake([self::LIST_URL => Http::response(['Message' => 'bad token '.self::TOKEN, 'IsSuccess' => false], 400)]);

        $response = $this->readList()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('failed', true)
            ->assertJsonPath('templates.0.name', 'kept');

        $this->assertStringContainsString('[redacted]', $response->json('raw'));
        $this->assertStringNotContainsString('11za-live-token', $response->getContent());
        $this->assertSame(['kept'], ProviderTemplate::pluck('name')->all());
        $this->assertStringNotContainsString('bad token', json_encode(Integration::forProvider('whatsapp')->settings));
    }

    public function test_an_unrecognisable_answer_is_shown_trimmed_and_without_the_token(): void
    {
        config(['automation.whatsapp.api.list_raw_excerpt' => 100]);

        Http::fake([self::LIST_URL => Http::response(['echo' => ['authToken' => self::TOKEN], 'note' => str_repeat('x', 500)])]);

        $response = $this->readList()->assertJsonPath('ok', false)->assertJsonPath('failed', true);

        $this->assertStringNotContainsString('11za-live-token', $response->getContent());
        $this->assertSame(101, mb_strlen($response->json('raw')));
        $this->assertSame(0, ProviderTemplate::count());
    }

    public function test_a_timeout_is_a_plain_answer_not_an_error_page(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->readList()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('failed', true)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Operation timed out'));
    }

    public function test_a_database_error_while_saving_leaves_the_previous_list_and_answers_plainly(): void
    {
        ProviderTemplate::create(['name' => 'kept', 'language' => 'en', 'variables' => 1]);

        Http::fake([self::LIST_URL => Http::response(['Data' => [['name' => 'welcome', 'language' => 'en']]])]);

        DB::connection()->beforeExecuting(function (string $query) {
            if (str_starts_with($query, 'insert into `provider_templates`')) {
                throw new RuntimeException('disk full');
            }
        });

        $this->readList()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'could not be saved') && ! str_contains($m, 'disk full'));

        $this->assertSame(['kept'], ProviderTemplate::pluck('name')->all());
    }

    public function test_an_unexpected_failure_is_still_a_plain_answer(): void
    {
        $this->mock(ElevenZaClient::class, function ($mock) {
            $mock->shouldReceive('listTemplates')->andThrow(new \Error('something odd '.self::TOKEN));
            $mock->shouldReceive('redact')->andReturnUsing(fn (string $text) => str_replace(self::TOKEN, '[redacted]', $text));
        });

        $response = $this->readList()->assertJsonPath('ok', false)->assertJsonPath('failed', true);

        $this->assertStringNotContainsString('11za-live-token', $response->getContent());
    }

    public function test_more_than_500_are_cut_at_500_and_say_so(): void
    {
        Http::fake([self::LIST_URL => Http::response(['Data' => collect(range(1, 501))
            ->map(fn (int $i) => ['name' => "t{$i}", 'language' => 'en'])->all()])]);

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total', 501)
            ->assertJsonCount(500, 'templates')
            ->assertJsonPath('message', 'Showing the first 500 of 501 templates from 11za. Type the name for any other. Read with page size 1000.');

        $this->assertSame(500, ProviderTemplate::count());

        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page
                ->where('providerTemplates.total', 501)
                ->has('providerTemplates.templates', 500));
    }

    public function test_a_refused_page_size_falls_back_to_the_next_and_says_which_worked(): void
    {
        Http::fake(fn ($request) => $request['limit'] === 1000
            ? Http::response(['Message' => 'limit too large'], 400)
            : Http::response(['Data' => [['name' => 'welcome', 'language' => 'en']]]));

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page_size', 500)
            ->assertJsonPath('message', '11za lists 1 template. Read with page size 500, after 1000 was refused.');

        Http::assertSentCount(2);

        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.page_size', 500));
    }

    public function test_an_answer_with_no_templates_also_falls_back(): void
    {
        Http::fake(fn ($request) => $request['limit'] === 100
            ? Http::response(['Data' => [['name' => 'welcome', 'language' => 'en']]])
            : Http::response(['IsSuccess' => false, 'Message' => 'invalid limit']));

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page_size', 100)
            ->assertJsonPath('message', fn (string $m) => str_ends_with($m, 'Read with page size 100, after 1000 and 500 were refused.'));
    }

    public function test_when_every_size_is_refused_the_last_answer_is_shown_and_nothing_changes(): void
    {
        ProviderTemplate::create(['name' => 'kept', 'language' => 'en', 'variables' => 1]);

        Http::fake(fn ($request) => Http::response(['Message' => "no ({$request['limit']})"], 400));

        $response = $this->readList()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('failed', true)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Tried page sizes 1000, 500, 100'));

        Http::assertSentCount(3);
        $this->assertStringContainsString('no (100)', $response->json('raw'));
        $this->assertSame(['kept'], ProviderTemplate::pluck('name')->all());
    }

    public function test_a_timeout_does_not_try_smaller_sizes(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->readList()->assertJsonPath('ok', false);

        Http::assertSentCount(0);
    }

    public function test_the_page_says_the_last_read_failed_until_a_read_succeeds(): void
    {
        $up = false;
        Http::fake(function () use (&$up) {
            return $up
                ? Http::response(['Data' => [['name' => 'welcome', 'language' => 'en']]])
                : Http::response('down', 503);
        });

        $this->readList();
        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.failed', true));

        $up = true;
        $this->readList()->assertJsonPath('ok', true);
        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.failed', false));
    }

    public function test_saving_a_message_keeps_11zas_wording_only_while_it_is_the_same_template(): void
    {
        $template = $this->message();
        $template->update(['provider_body' => 'Hi {{1}}, about {{2}}.']);

        $payload = [
            'name' => 'Welcome again', 'is_active' => true,
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
            'placeholder_map' => ['first_name', 'project'],
        ];

        $this->actingAs($this->admin)->put(route('automation.templates.update', $template), $payload)->assertSessionHasNoErrors();
        $this->assertSame('Hi {{1}}, about {{2}}.', $template->fresh()->provider_body);

        $this->actingAs($this->admin)->put(route('automation.templates.update', $template),
            ['provider_template_name' => 'site_visit'] + $payload)->assertSessionHasNoErrors();
        $this->assertNull($template->fresh()->provider_body);
    }

    public function test_the_migration_moves_the_old_settings_list_into_the_table_and_drops_the_key(): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings(['template_list' => [
            'templates' => [
                ['name' => 'welcome', 'language' => 'en', 'body' => 'Hi {{1}}', 'variables' => 1],
                ['name' => 'site_visit', 'language' => null, 'body' => null, 'variables' => null],
            ],
            'raw' => '{"Data":[]}',
            'at' => '2026-10-04T10:00:00+05:30',
        ]]);
        $integration->save();

        // the data half only: DDL would end the test's transaction
        (require database_path('migrations/2026_10_04_145322_create_provider_templates_table.php'))->moveOldList();

        $this->assertSame(
            [['name' => 'welcome', 'language' => 'en', 'variables' => 1], ['name' => 'site_visit', 'language' => null, 'variables' => null]],
            ProviderTemplate::orderBy('id')->get()->map->toListEntry()->all(),
        );

        $settings = Integration::forProvider('whatsapp')->settings;
        $this->assertArrayNotHasKey('template_list', $settings);
        $this->assertSame(self::TOKEN, $settings['auth_token']);
        $this->assertSame('2026-10-04T10:00:00+05:30', $settings['template_list_read']['at']);
    }

    private function readList(): TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson(route('automation.templates.provider'))
            ->assertOk();
    }

    private function message(): MessageTemplate
    {
        return MessageTemplate::create([
            'name' => 'Welcome', 'is_active' => true,
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
            'placeholder_map' => ['first_name', 'project'],
        ]);
    }
}
