<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProviderTemplate;
use App\Models\User;
use App\Services\WhatsApp\ElevenZaClient;
use App\Services\WhatsApp\WhatsAppSender;
use Closure;
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

    /**
     * getTemplatesAll's answer from production, October 2026, as it came —
     * except the status timeline, which was elided when it was shared.
     */
    private const PRODUCTION_SAMPLE = '{"Message":"template list","Data":{"docs":[{"_id":"69e74fbc24358fd1da81a8dc","group":"Shaligram वनम्","name":"vanam_won","category":"MARKETING","carouselSameBodyContent":false,"localizations":[{"status":"APPROVED","language":"en","components":[{"type":"BODY","text":"🎉 Welcome to the *Shaligram Family!* ..."},{"type":"FOOTER","text":"Shaligram Group"}]}],"timestamp":1776766908794,"showOnChat":true,"variables":[{"localization":"en","headerDynamics":[],"bodyDynamics":[],"carouselDynamics":[]}],"templateStatusTimeline":[],"buttonComponents":{},"headerComponents":null,"templateId":"1281501838372763","id":"69e74fbc24358fd1da81a8dc","dynamicValues":[{"language":"en","bodyDynamic":0}]}],"totalDocs":1,"limit":100,"totalPages":1,"page":1,"pagingCounter":1,"hasPrevPage":false,"hasNextPage":false,"prevPage":null,"nextPage":null},"Status":200,"IsSuccess":true}';

    public function test_productions_answer_is_read_with_status_category_count_and_wording(): void
    {
        $tag = MessageTemplate::create([
            'name' => 'Welcome to the family', 'is_active' => true,
            'provider_template_name' => 'vanam_won', 'provider_template_language' => 'en', 'placeholder_map' => [],
        ]);

        Http::fake([self::LIST_URL => Http::response(self::PRODUCTION_SAMPLE)]);

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('raw', null)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('templates', [[
                'name' => 'vanam_won', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                'variables' => 0, 'extra_variables' => 0, 'body' => '🎉 Welcome to the *Shaligram Family!* ...',
            ]])
            ->assertJsonPath('message', '11za lists 1 template. Read 1 page of up to 100 (asked for 1000; 11za sends at most 100 a page).');

        Http::assertSent(fn ($request) => $request->url() === self::LIST_URL && $request['authToken'] === self::TOKEN && $request['page'] === 1);

        // the BODY's wording onto the tag; the footer is not the body
        $this->assertSame('🎉 Welcome to the *Shaligram Family!* ...', $tag->fresh()->provider_body);

        $settings = Integration::forProvider('whatsapp')->settings;
        $this->assertArrayNotHasKey('template_list', $settings);
        $this->assertSame(['at', 'total', 'page_size', 'failed_at'], array_keys($settings['template_list_read']));
        $this->assertSame(100, $settings['template_list_read']['page_size']);
    }

    public function test_each_language_has_its_own_status_count_and_wording(): void
    {
        $english = $this->message();
        $hindi = MessageTemplate::create([
            'name' => 'Welcome (Hindi)', 'is_active' => true,
            'provider_template_name' => 'welcome', 'provider_template_language' => 'hi', 'placeholder_map' => ['first_name'],
        ]);

        Http::fake([self::LIST_URL => $this->serve([
            $this->doc('welcome', [
                'en' => ['count' => 2, 'status' => 'APPROVED', 'body' => 'Hi {{1}}, about {{2}}.'],
                'hi' => ['count' => 1, 'status' => 'PENDING', 'body' => 'नमस्ते {{1}}'],
            ], ['en' => ['headerDynamics' => ['x']]], 'UTILITY'),
            // no dynamicValues: the count comes from that language's bodyDynamics
            ['name' => 'site_visit', 'localizations' => [['language' => 'en']],
                'variables' => [['localization' => 'en', 'bodyDynamics' => ['a', 'b', 'c']]]],
            // nor those: from the highest {{n}} in the wording
            ['name' => 'callback', 'localizations' => [['language' => 'en',
                'components' => [['type' => 'BODY', 'text' => 'Call {{1}} on {{2}}']]]]],
        ])]);

        $this->readList()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('templates', [
                ['name' => 'welcome', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'variables' => 2, 'extra_variables' => 1, 'body' => 'Hi {{1}}, about {{2}}.'],
                ['name' => 'welcome', 'language' => 'hi', 'status' => 'PENDING', 'category' => 'UTILITY', 'variables' => 1, 'extra_variables' => 0, 'body' => 'नमस्ते {{1}}'],
                ['name' => 'site_visit', 'language' => 'en', 'status' => null, 'category' => null, 'variables' => 3, 'extra_variables' => 0, 'body' => null],
                ['name' => 'callback', 'language' => 'en', 'status' => null, 'category' => null, 'variables' => 2, 'extra_variables' => null, 'body' => 'Call {{1}} on {{2}}'],
            ]);

        $this->assertSame('Hi {{1}}, about {{2}}.', $english->fresh()->provider_body);
        $this->assertSame('नमस्ते {{1}}', $hindi->fresh()->provider_body);
    }

    public function test_a_tag_says_when_its_template_is_not_approved_or_is_marketing(): void
    {
        $pending = $this->message();
        $rejected = MessageTemplate::create(['name' => 'Rejected one', 'is_active' => true,
            'provider_template_name' => 'old_offer', 'provider_template_language' => 'en', 'placeholder_map' => []]);
        $missing = MessageTemplate::create(['name' => 'Gone', 'is_active' => true,
            'provider_template_name' => 'deleted_in_11za', 'provider_template_language' => 'en', 'placeholder_map' => []]);
        $fine = MessageTemplate::create(['name' => 'Fine', 'is_active' => true,
            'provider_template_name' => 'vanam_won', 'provider_template_language' => 'en', 'placeholder_map' => []]);

        Http::fake([self::LIST_URL => $this->serve([
            $this->doc('welcome', ['en' => ['count' => 2, 'status' => 'PENDING', 'body' => 'Hi {{1}}, about {{2}}.']]),
            $this->doc('old_offer', ['en' => ['count' => 0, 'status' => 'REJECTED']]),
            $this->doc('vanam_won', ['en' => ['count' => 0, 'status' => 'APPROVED', 'body' => 'Welcome!']], [], 'MARKETING'),
        ])]);
        $this->readList()->assertJsonPath('ok', true);

        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(function (Assert $page) use ($pending, $rejected, $missing, $fine) {
                $tags = collect($page->toArray()['props']['templates'])->keyBy('id');

                $this->assertSame('PENDING', $tags[$pending->id]['status']);
                $this->assertStringStartsWith('Meta has not approved this template yet', $tags[$pending->id]['approval_warning']);
                $this->assertStringStartsWith('Meta rejected this template', $tags[$rejected->id]['approval_warning']);
                $this->assertStringStartsWith("11za's list has no template \"deleted_in_11za\"", $tags[$missing->id]['approval_warning']);
                $this->assertNull($tags[$fine->id]['approval_warning']);
                $this->assertTrue($tags[$fine->id]['marketing']);
                $this->assertFalse($tags[$pending->id]['marketing']);
                // the preview is 11za's wording for the example customer
                $this->assertSame('Hi Rahul, about Skyline Residency.', $tags[$pending->id]['preview']);
                $this->assertNull($tags[$missing->id]['preview'], 'no stand-in wording');
            });
    }

    public function test_the_leads_whatsapp_section_shows_only_11zas_wording_and_the_warnings(): void
    {
        $known = $this->message();
        $unknown = MessageTemplate::create(['name' => 'Not read yet', 'is_active' => true, 'body' => 'Old CRM wording {first_name}',
            'provider_template_name' => 'callback', 'provider_template_language' => 'en', 'placeholder_map' => ['first_name']]);

        Http::fake([self::LIST_URL => $this->serve([
            $this->doc('welcome', ['en' => ['count' => 2, 'status' => 'PENDING', 'body' => 'Hi {{1}}, about {{2}}.']], [], 'MARKETING'),
        ])]);
        $this->readList();

        $project = Project::create(['name' => 'Skyline Residency']);
        $lead = Lead::create([
            'first_name' => 'Asha', 'last_name' => 'Rao', 'mobile_number' => '9876543210', 'project_id' => $project->id,
            'source' => 'walk_in', 'stage' => 'fresh', 'assigned_to' => $this->admin->id, 'assigned_role' => 'admin',
            'stage_changed_at' => now(), 'created_by' => $this->admin->id,
        ]);

        $tags = collect($this->actingAs($this->admin)->getJson(route('leads.whatsapp.show', $lead))->assertOk()->json('templates'))->keyBy('id');

        $this->assertSame('Hi Asha, about Skyline Residency.', $tags[$known->id]['preview']);
        $this->assertStringStartsWith('Meta has not approved', $tags[$known->id]['approval_warning']);
        $this->assertTrue($tags[$known->id]['marketing']);
        $this->assertNull($tags[$unknown->id]['preview'], 'the old CRM wording is not shown as the template');
    }

    public function test_a_template_missing_fields_is_kept_with_nulls_and_a_nameless_one_is_dropped(): void
    {
        Http::fake([self::LIST_URL => $this->serve([
            ['name' => 'bare'],
            ['name' => 'odd', 'localizations' => 'en', 'variables' => null],
            ['localizations' => [['language' => 'en']]],
            'not an object',
        ])]);

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('templates', [
                ['name' => 'bare', 'language' => null, 'status' => null, 'category' => null, 'variables' => null, 'extra_variables' => null, 'body' => null],
                ['name' => 'odd', 'language' => null, 'status' => null, 'category' => null, 'variables' => null, 'extra_variables' => null, 'body' => null],
            ]);
    }

    public function test_an_empty_list_is_a_real_answer(): void
    {
        ProviderTemplate::create(['name' => 'deleted_in_11za', 'language' => 'en', 'variables' => 0]);

        Http::fake([self::LIST_URL => $this->serve([])]);

        $this->readList()->assertJsonPath('ok', true)->assertJsonPath('templates', [])
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, '11za lists 0 templates.'));

        $this->assertSame(0, ProviderTemplate::count());
    }

    public function test_every_page_is_read_at_the_size_11za_uses(): void
    {
        Http::fake([self::LIST_URL => $this->serve(range(1, 250), maxPerPage: 100)]);

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total', 250)
            ->assertJsonCount(250, 'templates')
            ->assertJsonPath('message', '11za lists 250 templates. Read 3 pages of up to 100 (asked for 1000; 11za sends at most 100 a page).');

        Http::assertSentCount(3);
    }

    public function test_a_failed_later_page_changes_nothing(): void
    {
        ProviderTemplate::create(['name' => 'kept', 'language' => 'en', 'variables' => 1]);

        $serve = $this->serve(range(1, 250), maxPerPage: 100);
        Http::fake([self::LIST_URL => fn ($request) => $request['page'] === 2 ? Http::response('busy', 503) : $serve($request)]);

        $this->readList()->assertJsonPath('ok', false)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Could not read page 2 of the template list'));

        $this->assertSame(['kept'], ProviderTemplate::pluck('name')->all());
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

        Http::fake([self::LIST_URL => $this->serve([$this->doc('welcome')])]);

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
        Http::fake([self::LIST_URL => $this->serve(range(1, 501), maxPerPage: 100)]);

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total', 501)
            ->assertJsonCount(500, 'templates')
            ->assertJsonPath('message', 'Showing the first 500 of 501 templates from 11za. Type the name for any other. '
                .'Read 5 pages of up to 100 (asked for 1000; 11za sends at most 100 a page).');

        // the sixth page is never asked for
        Http::assertSentCount(5);
        $this->assertSame(500, ProviderTemplate::count());

        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page
                ->where('providerTemplates.total', 501)
                ->has('providerTemplates.templates', 500));
    }

    public function test_a_refused_page_size_falls_back_to_the_next_and_says_which_worked(): void
    {
        $serve = $this->serve([$this->doc('welcome')]);
        Http::fake(fn ($request) => $request['limit'] === 1000
            ? Http::response(['Message' => 'limit too large'], 400)
            : $serve($request));

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page_size', 500)
            ->assertJsonPath('message', '11za lists 1 template. Read 1 page of up to 500, after 1000 was refused.');

        Http::assertSentCount(2);

        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.page_size', 500));
    }

    public function test_an_answer_with_no_templates_also_falls_back(): void
    {
        $serve = $this->serve([$this->doc('welcome')]);
        Http::fake(fn ($request) => $request['limit'] === 100
            ? $serve($request)
            : Http::response(['IsSuccess' => false, 'Message' => 'invalid limit']));

        $this->readList()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page_size', 100)
            ->assertJsonPath('message', fn (string $m) => str_ends_with($m, 'Read 1 page of up to 100, after 1000 and 500 were refused.'));
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
        $serve = $this->serve([$this->doc('welcome')]);
        Http::fake(function ($request) use (&$up, $serve) {
            return $up ? $serve($request) : Http::response('down', 503);
        });

        $this->readList();
        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.failed', true));

        $up = true;
        $this->readList()->assertJsonPath('ok', true);
        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.failed', false));
    }

    public function test_a_new_tag_takes_11zas_wording_from_the_list_at_once(): void
    {
        ProviderTemplate::create(['name' => 'site_visit', 'language' => 'en', 'status' => 'APPROVED',
            'variables' => 1, 'body' => 'Thanks for visiting, {{1}}.']);

        $this->actingAs($this->admin)->post(route('automation.templates.store'), [
            'name' => 'Site visit thanks', 'is_active' => true,
            'provider_template_name' => 'site_visit', 'provider_template_language' => 'en',
            'placeholder_map' => ['first_name'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Thanks for visiting, {{1}}.', MessageTemplate::sole()->provider_body);

        // and the form has it while picking
        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('providerTemplates.templates.0.body', 'Thanks for visiting, {{1}}.'));
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
            [
                ['name' => 'welcome', 'language' => 'en', 'status' => null, 'category' => null, 'variables' => 1, 'extra_variables' => null, 'body' => null],
                ['name' => 'site_visit', 'language' => null, 'status' => null, 'category' => null, 'variables' => null, 'extra_variables' => null, 'body' => null],
            ],
            ProviderTemplate::orderBy('id')->get()->map->toListEntry()->all(),
        );

        $settings = Integration::forProvider('whatsapp')->settings;
        $this->assertArrayNotHasKey('template_list', $settings);
        $this->assertSame(self::TOKEN, $settings['auth_token']);
        $this->assertSame('2026-10-04T10:00:00+05:30', $settings['template_list_read']['at']);
    }

    /**
     * 11za's list endpoint, in its real shape, paged. `limit` is what was
     * asked for, capped at what 11za would actually send.
     *
     * @param  list<int|array|string>  $templates  numbers become t1, t2…
     */
    private function serve(array $templates, ?int $maxPerPage = null): Closure
    {
        $docs = array_map(fn ($t) => is_int($t) ? $this->doc("t{$t}") : $t, $templates);

        return function ($request) use ($docs, $maxPerPage) {
            $limit = min((int) $request['limit'], $maxPerPage ?? PHP_INT_MAX);
            $page = (int) $request['page'];
            $pages = max(1, (int) ceil(count($docs) / $limit));

            return Http::response([
                'Message' => 'template list',
                'Data' => [
                    'docs' => array_slice($docs, ($page - 1) * $limit, $limit),
                    'totalDocs' => count($docs), 'limit' => $limit, 'totalPages' => $pages, 'page' => $page,
                    'hasPrevPage' => $page > 1, 'hasNextPage' => $page < $pages,
                ],
                'Status' => 200,
                'IsSuccess' => true,
            ]);
        };
    }

    /**
     * One template in getTemplatesAll's shape.
     *
     * @param  array<string, array{count?: int, status?: string, body?: string}>  $languages
     * @param  array<string, array<string, list<string>>>  $dynamics  language => extra *Dynamics arrays
     */
    private function doc(string $name, array $languages = ['en' => []], array $dynamics = [], string $category = 'UTILITY'): array
    {
        return [
            '_id' => md5($name),
            'name' => $name,
            'category' => $category,
            'localizations' => array_map(fn ($lang, $l) => [
                'status' => $l['status'] ?? 'APPROVED',
                'language' => $lang,
                'components' => isset($l['body']) ? [['type' => 'BODY', 'text' => $l['body']], ['type' => 'FOOTER', 'text' => 'Shaligram Group']] : [],
            ], array_keys($languages), $languages),
            'variables' => array_map(fn ($lang) => array_merge(['localization' => $lang, 'headerDynamics' => [], 'bodyDynamics' => [],
                'carouselDynamics' => []], $dynamics[$lang] ?? []), array_keys($languages)),
            'dynamicValues' => array_map(fn ($lang, $l) => ['language' => $lang, 'bodyDynamic' => $l['count'] ?? 0],
                array_keys($languages), $languages),
        ];
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
