<?php

namespace Tests\Feature;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordSearchTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('partnerTerms')]
    public function test_partner_list_and_export_find_the_same_advertised_fields(string $term, int $count): void
    {
        $admin = User::factory()->role('admin')->create();
        $partner = $this->partner();
        $this->partner(['name' => 'Unrelated Agency', 'contact_person' => 'Someone Else', 'phone' => '1112223333', 'email' => null]);

        $this->actingAs($admin)->get('/channel-partners?'.http_build_query(['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('partners.total', $count)
                ->where('partners.data', fn ($rows) => collect($rows)->pluck('id')->all() === ($count ? [$partner->id] : [])));
        $this->postJson('/export-data/count', ['data_type' => 'channel_partners', 'search' => $term])
            ->assertOk()->assertJsonPath('count', $count);

        $this->assertDatabaseHas('channel_partners', ['id' => $partner->id, 'phone' => '+91 98765 43210']);
    }

    public static function partnerTerms(): array
    {
        return array_map(fn ($term) => [$term, $term === 'nonexistent' ? 0 : 1], array_combine(
            ['company', 'company partial', 'contact first', 'contact middle', 'contact last', 'contact combined', 'phone full', 'phone prefix', 'phone suffix', 'email full', 'email partial', 'case', 'email case', 'trim', 'zero', 'no results'],
            ['Shaligram', 'Properties', 'Rahul', 'Kumar', 'Shah', 'Rahul Shah', '9876543210', '98765', '43210', 'rahul@example.com', 'example.com', 'RAHUL', 'RAHUL@EXAMPLE.COM', '  Shaligram  ', '0', 'nonexistent'],
        ));
    }

    #[DataProvider('leadTerms')]
    public function test_leads_search_all_name_components_mobile_and_email(string $term, int $count): void
    {
        $admin = User::factory()->role('admin')->create();
        $lead = $this->lead();

        $this->actingAs($admin)->get('/leads?'.http_build_query(['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('leads.total', $count)
                ->where('leads.data', fn ($rows) => collect($rows)->pluck('id')->all() === ($count ? [$lead->id] : [])));
    }

    public static function leadTerms(): array
    {
        return array_map(fn ($term) => [$term, $term === 'nonexistent' ? 0 : 1], array_combine(
            ['first', 'middle', 'last', 'combined', 'reversed', 'mobile', 'mobile partial', 'formatted input', 'case', 'email', 'email partial', 'trim', 'no results'],
            ['Yatin', 'Kumar', 'Desai', 'Yatin Desai', 'Desai Yatin', '9898989898', '98989', '(98989) 89898', 'YATIN', 'YATIN@EXAMPLE.COM', 'example.com', '  Yatin  ', 'nonexistent'],
        ));
    }

    #[DataProvider('followUpTerms')]
    public function test_follow_ups_search_related_lead_names_and_formatted_mobile(string $term, int $count): void
    {
        $this->freezeTime();
        $admin = User::factory()->role('admin')->create();
        $lead = $this->lead(['mobile_number' => '+91 (98989)-89898']);
        $todo = $this->todo($lead, $admin);

        $this->actingAs($admin)->get('/todos?'.http_build_query(['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('todos.total', $count)
                ->where('todos.data', fn ($rows) => collect($rows)->pluck('id')->all() === ($count ? [$todo->id] : [])));
    }

    public static function followUpTerms(): array
    {
        return [
            'first' => ['yatin', 1], 'middle' => ['Kumar', 1], 'last' => ['DESAI', 1],
            'combined' => ['Yatin Desai', 1], 'mobile' => ['9898989898', 1],
            'partial' => ['98989', 1], 'trim' => ['  Yatin  ', 1],
            'email is not promised' => ['yatin@example.com', 0], 'no results' => ['nonexistent', 0],
        ];
    }

    #[DataProvider('userTerms')]
    public function test_users_search_names_email_and_mobile(string $term, int $count): void
    {
        $admin = User::factory()->role('admin')->create(['first_name' => 'Administrator', 'email' => 'admin@crm.test', 'mobile_number' => '1112223333']);
        $user = User::factory()->create(['first_name' => 'Rahul', 'last_name' => 'Shah', 'email' => 'rahul@example.com', 'mobile_number' => '+91 98765 43210']);

        $this->actingAs($admin)->get('/users?'.http_build_query(['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('users.total', $count)
                ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->all() === ($count ? [$user->id] : [])));
    }

    public static function userTerms(): array
    {
        return [
            'first' => ['RAHUL', 1], 'last' => ['shah', 1], 'combined' => ['Rahul Shah', 1],
            'email' => ['RAHUL@EXAMPLE.COM', 1], 'email partial' => ['example.com', 1],
            'phone' => ['9876543210', 1], 'partial' => ['43210', 1],
            'trim' => ['  Rahul  ', 1], 'no results' => ['nonexistent', 0],
        ];
    }

    #[DataProvider('specialTerms')]
    public function test_search_treats_wildcards_backslashes_and_quotes_literally(string $term): void
    {
        $admin = User::factory()->role('admin')->create();
        $target = Project::create(['name' => 'Vanam '.$term.' Heights']);
        Project::create(['name' => 'Vanam Ordinary Heights']);

        $this->actingAs($admin)->get('/projects?'.http_build_query(['search' => $term]))
            ->assertInertia(fn (Assert $page) => $page->where('projects.total', 1)->where('projects.data.0.id', $target->id));
    }

    public static function specialTerms(): array
    {
        return ['percent' => ['%'], 'underscore' => ['_'], 'backslash' => ['\\'],
            'apostrophe' => ["'"], 'quote' => ['"'], 'escape character' => ['!'],
            'escaped-looking input' => ['!%_\\'], 'injection text' => ["' OR 1=1 --"]];
    }

    public function test_case_insensitive_search_does_not_depend_on_sqlite_like_default(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific case-sensitive LIKE setting.');
        }

        $admin = User::factory()->role('admin')->create();
        $target = Project::create(['name' => 'Vanam Heights']);
        DB::statement('PRAGMA case_sensitive_like = ON');

        try {
            $this->actingAs($admin)->get('/projects?search=VANAM')
                ->assertInertia(fn (Assert $page) => $page->where('projects.total', 1)->where('projects.data.0.id', $target->id));
        } finally {
            DB::statement('PRAGMA case_sensitive_like = OFF');
        }
    }

    public function test_lead_search_stays_inside_project_stage_and_visibility_filters(): void
    {
        $owner = User::factory()->role('telecaller')->create();
        $other = User::factory()->role('telecaller')->create();
        $project = Project::create(['name' => 'Vanam']);
        $target = $this->lead(['project_id' => $project->id, 'assigned_to' => $owner->id]);
        $this->lead(['project_id' => $project->id, 'assigned_to' => $owner->id, 'stage' => 'connected', 'mobile_number' => '1111111111']);
        $this->lead(['project_id' => $project->id, 'assigned_to' => $other->id, 'mobile_number' => '2222222222']);
        $this->lead(['assigned_to' => $owner->id]);

        $this->actingAs($owner)->get('/leads?'.http_build_query(['search' => 'Yatin Desai', 'stage' => 'fresh', 'project_id' => $project->id]))
            ->assertInertia(fn (Assert $page) => $page->where('leads.total', 1)->where('leads.data.0.id', $target->id));
    }

    public function test_partner_search_preserves_type_status_parent_and_export_filters(): void
    {
        $admin = User::factory()->role('admin')->create();
        $parent = $this->partner(['name' => 'Unique Parent']);
        $target = $this->partner(['name' => 'Target Broker', 'type' => 'broker', 'parent_id' => $parent->id]);
        $this->partner(['name' => 'Inactive Broker', 'type' => 'broker', 'parent_id' => $parent->id, 'is_active' => false]);
        $this->partner(['name' => 'Other Broker', 'type' => 'broker']);

        $this->actingAs($admin)->get('/channel-partners?'.http_build_query(['search' => 'UNIQUE PARENT', 'type' => 'broker', 'status' => 'active', 'parent_id' => $parent->id]))
            ->assertInertia(fn (Assert $page) => $page->where('partners.total', 1)->where('partners.data.0.id', $target->id));
        $this->postJson('/export-data/count', ['data_type' => 'channel_partners', 'search' => 'UNIQUE PARENT', 'type' => 'broker', 'status' => 'active'])
            ->assertOk()->assertJsonPath('count', 1);
        $response = $this->post('/export-data/download', ['data_type' => 'channel_partners', 'format' => 'csv', 'search' => 'UNIQUE PARENT', 'type' => 'broker', 'status' => 'active']);
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Target Broker', $csv);
        $this->assertStringNotContainsString('Inactive Broker', $csv);
        $this->assertStringNotContainsString('Other Broker', $csv);
    }

    public function test_partner_alternate_phone_is_searchable(): void
    {
        $admin = User::factory()->role('admin')->create();
        $target = $this->partner(['alt_phone' => '+91 (87654) 32109']);

        $this->actingAs($admin)->get('/channel-partners?search=8765432109')
            ->assertInertia(fn (Assert $page) => $page->where('partners.total', 1)->where('partners.data.0.id', $target->id));
    }

    public function test_follow_up_search_cannot_bypass_owner_type_tab_or_deleted_lead_filters(): void
    {
        $this->freezeTime();
        $owner = User::factory()->role('telecaller')->create();
        $other = User::factory()->role('telecaller')->create();
        $target = $this->todo($this->lead(), $owner);
        $this->todo($this->lead(), $other);
        $this->todo($this->lead(), $owner, ['type' => 'site_visit']);
        $this->todo($this->lead(), $owner, ['scheduled_at' => now()->addDay()]);
        $deleted = $this->lead();
        $this->todo($deleted, $owner);
        $deleted->delete();

        $this->actingAs($owner)->get('/todos?search=Desai&type=call&tab=today')
            ->assertInertia(fn (Assert $page) => $page->where('todos.total', 1)->where('todos.data.0.id', $target->id));
    }

    public function test_user_search_cannot_bypass_role_or_status_filters(): void
    {
        $admin = User::factory()->role('admin')->create();
        $target = User::factory()->role('salesperson')->create(['first_name' => 'Rahul']);
        User::factory()->role('telecaller')->create(['first_name' => 'Rahul']);
        User::factory()->role('salesperson')->inactive()->create(['first_name' => 'Rahul']);

        $this->actingAs($admin)->get('/users?search=rahul&role=salesperson&status=active')
            ->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.id', $target->id));
    }

    public function test_project_search_cannot_bypass_status_filters(): void
    {
        $admin = User::factory()->role('admin')->create();
        $target = Project::create(['name' => 'Vanam Heights', 'is_active' => true]);
        Project::create(['name' => 'Vanam Gardens', 'is_active' => false]);

        $this->actingAs($admin)->get('/projects?search=VANAM&status=active')
            ->assertInertia(fn (Assert $page) => $page->where('projects.total', 1)->where('projects.data.0.id', $target->id));
    }

    #[DataProvider('listPages')]
    public function test_search_runs_before_pagination_retains_filters_and_clears(string $path, string $prop): void
    {
        $this->freezeTime();
        $admin = User::factory()->role('admin')->create(['first_name' => 'Administrator']);
        $project = Project::create(['name' => 'Vanam']);
        $owner = User::factory()->role('telecaller')->create();
        $filters = match ($path) {
            '/leads' => ['stage' => 'fresh', 'project_id' => $project->id],
            '/todos' => ['tab' => 'today', 'type' => 'call', 'assigned_to' => $owner->id],
            '/channel-partners' => ['status' => 'active', 'type' => 'firm'],
            '/users' => ['status' => 'active', 'role' => 'salesperson'],
            '/projects' => ['status' => 'active'],
        };

        for ($i = 0; $i < 32; $i++) {
            $name = $i < 16 ? 'Aaa Ordinary '.$i : 'Zzz Needle '.$i;
            $attributes = ['first_name' => $name, 'middle_name' => null, 'last_name' => 'Record', 'mobile_number' => (string) (9000000000 + $i), 'project_id' => $project->id, 'created_at' => now()->subMinutes($i)];
            $record = match ($path) {
                '/leads' => $this->lead($attributes),
                '/todos' => $this->todo($this->lead($attributes), $owner, ['scheduled_at' => today()->addMinutes($i)]),
                '/channel-partners' => $this->partner(['name' => $name]),
                '/users' => User::factory()->role('salesperson')->create(['first_name' => $name]),
                '/projects' => Project::create(['name' => $name]),
            };
            if ($i === 31) {
                $targetId = $record->id;
            }
        }

        $this->actingAs($admin)->get($path.'?'.http_build_query($filters))
            ->assertInertia(fn (Assert $page) => $page->where($prop.'.current_page', 1)
                ->where($prop.'.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($targetId)));
        $this->get($path.'?'.http_build_query(['reset' => 1, 'search' => 'needle 31'] + $filters))
            ->assertInertia(fn (Assert $page) => $page->where($prop.'.total', 1)->where($prop.'.data.0.id', $targetId)->where($prop.'.current_page', 1));
        $response = $this->get($path.'?'.http_build_query(['reset' => 1, 'search' => 'needle'] + $filters))
            ->assertInertia(fn (Assert $page) => $page->where($prop.'.total', 16));
        $nextPage = $response->viewData('page')['props'][$prop]['next_page_url'];
        $this->get($path.'?reset=1&search=another-tab');
        $this->get($nextPage)
            ->assertInertia(fn (Assert $page) => $page->where($prop.'.total', 16)->has($prop.'.data', 1)->where('filters.search', 'needle'));
        $this->get($path.'?page=2')
            ->assertInertia(fn (Assert $page) => $page->where($prop.'.total', 16)->has($prop.'.data', 1)->where('filters.search', 'needle'));
        $this->get($path.'?'.http_build_query(['reset' => 1, 'search' => 'nonexistent'] + $filters))
            ->assertInertia(fn (Assert $page) => $page->where($prop.'.total', 0)->has($prop.'.data', 0));
        $this->get($path.'?'.http_build_query(['reset' => 1] + $filters))
            ->assertInertia(fn (Assert $page) => $page->missing('filters.search')->where($prop.'.total', $path === '/projects' ? 33 : 32));
        $this->get($path.'?'.http_build_query(['search' => '   '] + $filters))
            ->assertInertia(fn (Assert $page) => $page->missing('filters.search')->where($prop.'.total', $path === '/projects' ? 33 : 32));
    }

    public static function listPages(): array
    {
        return ['leads' => ['/leads', 'leads'], 'follow-ups' => ['/todos', 'todos'],
            'partners' => ['/channel-partners', 'partners'], 'users' => ['/users', 'users'], 'projects' => ['/projects', 'projects']];
    }

    /** @param array<string, mixed> $attributes */
    private function partner(array $attributes = []): ChannelPartner
    {
        return ChannelPartner::create(array_replace(['name' => 'Shaligram Properties', 'type' => 'firm', 'contact_person' => 'Rahul Kumar Shah', 'phone' => '+91 98765 43210', 'email' => 'rahul@example.com', 'is_active' => true], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_replace(['first_name' => 'Yatin', 'middle_name' => 'Kumar', 'last_name' => 'Desai', 'mobile_number' => '9898989898', 'email' => 'yatin@example.com', 'source' => 'website', 'stage' => 'fresh', 'project_id' => $attributes['project_id'] ?? Project::create(['name' => 'Other Project'])->id], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function todo(Lead $lead, User $owner, array $attributes = []): Todo
    {
        return Todo::create(array_replace(['lead_id' => $lead->id, 'assigned_to' => $owner->id, 'scheduled_at' => now(), 'type' => 'call', 'status' => 'pending'], $attributes));
    }
}
