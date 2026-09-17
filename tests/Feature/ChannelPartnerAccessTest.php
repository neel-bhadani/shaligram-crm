<?php

namespace Tests\Feature;

use App\Models\ChannelPartner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reading, editing and merging the roster vs deleting it.
 *
 * Three routes widened, and exactly three. GET /channel-partners (index), PUT
 * /channel-partners/{partner} (update) and POST /channel-partners/{partner}
 * /merge moved out of the `role:admin` group and onto the outer `auth` group,
 * so every signed-in role can open the roster, search it, filter it, page
 * through it, fix an existing row through the page's Edit button, and put a
 * duplicate back together through the page's Merge button — the same rows the
 * lead form's broker picker offers and the report's "By channel partner"
 * grouping reads. Delete stays behind `role:admin`, and destroy() is
 * double-locked through ChannelPartnerPolicy::delete, so a DELETE typed by
 * hand gets a 403 whoever presses send.
 *
 * @see \App\Http\Controllers\ChannelPartnerController
 * @see \App\Policies\ChannelPartnerPolicy
 */
class ChannelPartnerAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $alice;
    private User $suresh;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:00'));

        $this->admin   = $this->user('admin', 'Ann');
        $this->alice   = $this->user('telecaller', 'Alice');
        $this->suresh  = $this->user('salesperson', 'Suresh');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- reading ---------------- */

    public function test_an_admin_can_open_the_channel_partners_page(): void
    {
        $this->actingAs($this->admin)->get('/channel-partners')->assertOk();
    }

    public function test_a_telecaller_and_a_salesperson_can_open_the_channel_partners_page(): void
    {
        foreach ([$this->alice, $this->suresh] as $role => $user) {
            $this->actingAs($user)->get('/channel-partners')->assertOk();
        }
    }

    public function test_an_unauthenticated_user_is_sent_to_the_login_screen(): void
    {
        $this->get('/channel-partners')->assertRedirect(route('login'));
    }

    /**
     * The read page answers identically for every role: same rows from the same
     * query, whatever the sidebar says about who is signed in.
     */
    public function test_a_non_admin_sees_the_same_roster_as_an_admin(): void
    {
        $firm   = $this->partner('firm', 'Shreeji Realty');
        $broker = $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        $admin = $this->page('/channel-partners', [], $this->admin);
        $staff = $this->page('/channel-partners', [], $this->alice);

        $this->assertSame(
            collect($admin['partners']['data'])->pluck('id')->all(),
            collect($staff['partners']['data'])->pluck('id')->all(),
        );
        $this->assertSame(2, $staff['partners']['total']);
        $this->assertSame('Ravi Kumar — Shreeji Realty', $staff['partners']['data'][1]['display_label']);
    }

    public function test_a_non_admin_can_search_and_filter_like_an_admin(): void
    {
        $shreeji = $this->partner('firm', 'Shreeji Realty');
        $this->partner('firm', 'Other Realty');
        $this->partner('broker', 'Ravi Kumar', ['parent_id' => $shreeji->id]);
        $this->partner('broker', 'Sunil');

        // a search, a status filter and the brokers-of-a-firm filter all compose
        // for a telecaller exactly as they do for an admin
        $searched = collect(
            $this->page('/channel-partners', ['search' => 'shreeji'], $this->alice)['partners']['data'],
        )->pluck('name')->all();

        $this->assertSame(['Shreeji Realty', 'Ravi Kumar'], $searched, 'a firm matches by name and its broker by parent');

        $pinned = collect(
            $this->page('/channel-partners', ['parent_id' => $shreeji->id], $this->suresh)['partners']['data'],
        )->pluck('name')->all();

        $this->assertSame(['Ravi Kumar'], $pinned);

        $inactive = $this->partner('broker', 'Dormant Broker', ['is_active' => false]);
        $filtered = collect(
            $this->page('/channel-partners', ['status' => 'inactive'], $this->alice)['partners']['data'],
        )->pluck('name')->all();

        $this->assertSame(['Dormant Broker'], $filtered);
    }

    public function test_a_non_admin_can_page_through_the_roster(): void
    {
        foreach (range(1, 17) as $i) {
            $this->partner('broker', "Broker {$i}");
        }

        $first = $this->page('/channel-partners', [], $this->alice);
        $next  = $this->page('/channel-partners', ['page' => 2], $this->alice);

        $this->assertSame(17, $first['partners']['total']);
        $this->assertSame(15, $first['partners']['per_page']);
        $this->assertCount(15, $first['partners']['data']);
        $this->assertCount(2, $next['partners']['data'], 'page two holds the remainder');
        $this->assertNotSame(
            collect($first['partners']['data'])->pluck('id')->all(),
            collect($next['partners']['data'])->pluck('id')->all(),
        );
    }

    /* ---------------- editing & updating ---------------- */

    public function test_a_non_admin_can_update_a_channel_partner(): void
    {
        foreach ([$this->alice, $this->suresh] as $user) {
            $broker = $this->partner('broker', "Ravi Kumar {$user->id}");

            $this->actingAs($user)
                ->put("/channel-partners/{$broker->id}", [
                    'name'           => "Ravi Kumar {$user->id}",
                    'type'           => 'broker',
                    'parent_id'      => '',
                    'phone'          => '9876501234',
                    'contact_person' => 'Ravi',
                    'is_active'      => true,
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame('Ravi', $broker->fresh()->contact_person);
            $this->assertNull($broker->fresh()->parent_id);
        }
    }

    public function test_an_unauthenticated_user_cannot_update_a_channel_partner(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $this->put("/channel-partners/{$broker->id}", [
            'name'      => 'Ravi Kumar',
            'type'      => 'broker',
            'parent_id' => '',
            'phone'     => '9876501234',
        ])->assertRedirect(route('login'));

        $this->assertNull($broker->fresh()->contact_person);
    }

    /**
     * The route is open, not the rules. A non-admin's edit is validated the
     * way an admin's is — the modal's form is the same one, and typing a bad
     * value should be refused rather than silently saved.
     */
    public function test_validation_still_guards_a_non_admin_update(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');
        $before = $broker->fresh();

        $this->actingAs($this->alice)
            ->put("/channel-partners/{$broker->id}", [
                'name'      => '',
                'type'      => 'broker',
                'parent_id' => '',
                'phone'     => '9876501234',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame($before->name, $broker->fresh()->name);
    }

    public function test_a_non_admin_can_merge_a_channel_partner(): void
    {
        foreach ([$this->alice, $this->suresh] as $user) {
            $source = $this->partner('broker', "Source {$user->id}");
            $target = $this->partner('firm', "Target {$user->id}");

            $this->actingAs($user)
                ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
                ->assertSessionHasNoErrors();

            $this->assertSoftDeleted('channel_partners', ['id' => $source->id]);
            $this->assertNotSoftDeleted('channel_partners', ['id' => $target->id]);
        }
    }

    public function test_an_unauthenticated_user_cannot_merge_a_channel_partner(): void
    {
        $source = $this->partner('broker', 'Source');
        $target = $this->partner('firm', 'Target');

        $this->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertRedirect(route('login'));

        $this->assertNotSoftDeleted('channel_partners', ['id' => $source->id]);
    }

    /**
     * The route is open, not the rules. A non-admin's merge is validated the
     * way an admin's is — the same request, the same shape rules — so a merge
     * into itself is refused rather than carried out.
     */
    public function test_merge_validation_still_guards_a_non_admin(): void
    {
        $partner = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->alice)
            ->post("/channel-partners/{$partner->id}/merge", ['target_id' => $partner->id])
            ->assertSessionHasErrors('target_id');

        $this->assertNotSoftDeleted('channel_partners', ['id' => $partner->id]);
    }

    /* ---------------- deleting ---------------- */

    public function test_an_admin_can_delete_a_channel_partner(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $this->actingAs($this->admin)
            ->delete("/channel-partners/{$broker->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('channel_partners', ['id' => $broker->id]);
    }

    public function test_a_non_admin_cannot_delete_even_by_a_direct_request(): void
    {
        foreach ([$this->alice, $this->suresh] as $user) {
            $broker = $this->partner('broker', "Ravi Kumar {$user->id}");

            $this->actingAs($user)
                ->delete("/channel-partners/{$broker->id}")
                ->assertForbidden();

            $this->assertNotSoftDeleted('channel_partners', ['id' => $broker->id]);
        }
    }

    /* ---------------- helpers ---------------- */

    /** One page visit, as Inertia, returning the props. */
    private function page(string $url, array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)
            ->get($url . '?' . http_build_query($query + ['reset' => 1]));

        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function partner(string $type, string $name, array $attributes = []): ChannelPartner
    {
        return ChannelPartner::create($attributes + [
            'name'  => $name,
            'type'  => $type,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'first_name'    => $name,
            'last_name'     => 'Test',
            'email'         => strtolower($name) . '@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'          => $role,
            'is_active'     => true,
            'password'      => 'password',
        ]);
    }
}