<?php

namespace Tests\Feature;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Channel partners: the roster, the link from a lead, and the report.
 *
 * Five things are being protected here and they are not the same thing:
 *
 *   the door       READING, EDITING and MERGING are open to every signed-in
 *                  role — GET (index), PUT (update) and POST /merge sit on the
 *                  `auth` group, not the admin one — while DELETE stays
 *                  admin-only: its route is behind `role:admin`, and destroy()
 *                  says it again through ChannelPartnerPolicy::delete. Typing
 *                  the URL is neither a way in nor a way around.
 *
 *   the shapes     one level of nesting, always. A broker's parent is a firm, a
 *                  firm has no parent, and nothing is its own parent. None of
 *                  that can be a database constraint, so it is all validation
 *                  and all of it is asserted.
 *
 *   the history    `leads.broker_name` was kept, not backfilled. A lead created
 *                  before this feature still reads the way it always did, and
 *                  an edit made after it does not quietly blank the column.
 *
 *   the arithmetic the report's channel-partner grouping has to sum to the same
 *                  dashboard cards every other grouping sums to, or one of the
 *                  two is lying.
 *
 * The read-for-everyone part has its own home in ChannelPartnerAccessTest.
 *
 * @see \App\Http\Controllers\ChannelPartnerController
 * @see \App\Http\Requests\ChannelPartnerRequest
 */
class ChannelPartnerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $alice;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:00'));

        $this->admin   = $this->user('admin', 'Ann');
        $this->alice   = $this->user('telecaller', 'Alice');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- the door ---------------- */

    public function test_every_role_can_read_edit_and_merge_but_not_delete(): void
    {
        foreach (['telecaller', 'salesperson'] as $role) {
            $staff  = $this->user($role, ucfirst($role) . 'X');
            $broker = $this->partner('broker', "Ravi {$role}");
            $firm   = $this->partner('firm', "Target Firm {$role}");

            // reading, editing and merging are open to every signed-in role
            $this->actingAs($staff)->get('/channel-partners')->assertOk();
            $this->actingAs($staff)
                ->put("/channel-partners/{$broker->id}", $this->payload([
                    'type'           => 'broker',
                    'name'           => "Ravi {$role}",
                    'contact_person' => $role,
                ]))
                ->assertSessionHasNoErrors();
            $this->actingAs($staff)
                ->post("/channel-partners/{$broker->id}/merge", ['target_id' => $firm->id])
                ->assertSessionHasNoErrors();

            $this->assertSoftDeleted('channel_partners', ['id' => $broker->id]);
            $this->assertNotSoftDeleted('channel_partners', ['id' => $firm->id]);

            // deleting stays admin-only: no DELETE gets past it
            $this->actingAs($staff)->delete("/channel-partners/{$firm->id}")->assertForbidden();
            $this->assertNotSoftDeleted('channel_partners', ['id' => $firm->id]);
        }
    }

    /**
     * The page manages partners and, since the Add button landed, makes them:
     * POST /channel-partners is the store the Add modal submits to, open to
     * every signed-in role with the same rule-set as the quick door.
     */
    public function test_a_partner_can_be_created_from_the_page(): void
    {
        $this->actingAs($this->admin)
            ->post('/channel-partners', $this->payload([
                'type'           => 'firm',
                'name'           => 'Orchid Estates',
                'contact_person' => 'Nita',
                'email'          => 'nita@orchid.example.test',
                'address'        => 'MG Road',
            ]))
            ->assertRedirect(route('channel-partners.index'))
            ->assertSessionHasNoErrors();

        $partner = ChannelPartner::firstOrFail();

        $this->assertSame('Orchid Estates', $partner->name);
        $this->assertSame('firm', $partner->type);
        $this->assertSame('Nita', $partner->contact_person);
        $this->assertSame('nita@orchid.example.test', $partner->email);
        $this->assertSame('MG Road', $partner->address);
        $this->assertTrue($partner->is_active);
    }

    public function test_a_deactivated_admin_can_read_edit_and_merge_but_not_delete(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');
        $firm   = $this->partner('firm', 'Shreeji Realty');
        $this->admin->update(['is_active' => false]);

        // reading, editing and merging live on the `auth` group now: all three
        // stay open to a live session, exactly as a deactivated telecaller
        // keeps reading /todos or /leads. Deleting is still admin-only, and
        // role:admin re-checks is_active, so it cannot get past it.
        $this->actingAs($this->admin)->get('/channel-partners')->assertOk();
        $this->actingAs($this->admin)
            ->put("/channel-partners/{$broker->id}", $this->payload([
                'type' => 'broker',
                'name' => 'Ravi Kumar',
            ]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post("/channel-partners/{$broker->id}/merge", ['target_id' => $firm->id])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('channel_partners', ['id' => $broker->id]);
        $this->assertNotSoftDeleted('channel_partners', ['id' => $firm->id]);

        $this->actingAs($this->admin)->delete("/channel-partners/{$firm->id}")->assertForbidden();
        $this->assertNotSoftDeleted('channel_partners', ['id' => $firm->id]);
    }

    /* ---------------- the three cases ---------------- */

    public function test_an_individual_broker_saves_with_no_parent(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', $this->payload(['type' => 'broker', 'name' => 'Ravi Kumar']))
            ->assertCreated();

        $partner = ChannelPartner::firstOrFail();

        $this->assertSame('broker', $partner->type);
        $this->assertNull($partner->parent_id);
        $this->assertSame('Ravi Kumar', $partner->display_label, 'no firm, so no suffix');
    }

    public function test_a_firm_saves_and_a_lead_can_point_straight_at_it(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'firm', 'name' => 'Shreeji Realty', 'contact_person' => 'Nita',
            ]))
            ->assertCreated();

        $firm = ChannelPartner::firstOrFail();

        $this->assertSame('firm', $firm->type);
        $this->assertNull($firm->parent_id);

        $lead = $this->lead(['channel_partner_id' => $firm->id, 'source' => 'broker']);

        $this->assertSame('Shreeji Realty', $lead->fresh()->broker_label);
    }

    public function test_a_broker_under_a_firm_saves_and_reads_as_broker_then_firm(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'broker', 'name' => 'Ravi Kumar', 'parent_id' => $firm->id,
            ]))
            ->assertCreated();

        $broker = ChannelPartner::where('name', 'Ravi Kumar')->firstOrFail();

        $this->assertSame($firm->id, $broker->parent_id);
        $this->assertSame('Ravi Kumar — Shreeji Realty', $broker->display_label);

        $lead = $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);

        $this->assertSame(
            'Ravi Kumar — Shreeji Realty',
            $lead->fresh()->broker_label,
            'the firm is what tells two brokers called Ravi apart',
        );
    }

    /* ---------------- the shapes that must not exist ---------------- */

    public function test_a_firm_cannot_carry_a_parent(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'firm', 'name' => 'Another Firm', 'parent_id' => $firm->id,
            ]))
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_brokers_parent_must_be_a_firm(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'broker', 'name' => 'Sunil', 'parent_id' => $broker->id,
            ]))
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_an_inactive_firm_is_not_offered_as_a_parent(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty', ['is_active' => false]);

        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', $this->payload([
                'type' => 'broker', 'name' => 'Ravi', 'parent_id' => $firm->id,
            ]))
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_partner_cannot_be_its_own_parent(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $this->actingAs($this->admin)
            ->put("/channel-partners/{$broker->id}", $this->payload([
                'type' => 'broker', 'name' => 'Ravi Kumar', 'parent_id' => $broker->id,
            ]))
            ->assertSessionHasErrors('parent_id');
    }

    public function test_a_firm_holding_brokers_cannot_be_demoted_to_a_broker(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');
        $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        $this->actingAs($this->admin)
            ->put("/channel-partners/{$firm->id}", $this->payload([
                'type' => 'broker', 'name' => 'Shreeji Realty',
            ]))
            ->assertSessionHasErrors('type');

        $this->assertSame('firm', $firm->fresh()->type);
    }

    public function test_switching_a_broker_to_a_firm_clears_the_stale_parent(): void
    {
        $firm   = $this->partner('firm', 'Shreeji Realty');
        $broker = $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        // the modal empties the field on screen; the type is what says so here
        $this->actingAs($this->admin)
            ->put("/channel-partners/{$broker->id}", $this->payload([
                'type' => 'firm', 'name' => 'Ravi Kumar & Co',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($broker->fresh()->parent_id, 'a firm never keeps a parent');
    }

    /* ---------------- delete ---------------- */

    public function test_deleting_a_firm_with_active_brokers_is_blocked(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');
        $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        $this->actingAs($this->admin)
            ->delete("/channel-partners/{$firm->id}")
            ->assertSessionHasErrors('partner');

        $this->assertNotSoftDeleted('channel_partners', ['id' => $firm->id]);
    }

    public function test_deactivating_the_brokers_unblocks_the_firm(): void
    {
        $firm   = $this->partner('firm', 'Shreeji Realty');
        $broker = $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        $broker->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->delete("/channel-partners/{$firm->id}")
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('channel_partners', ['id' => $firm->id]);
    }

    public function test_a_delete_is_soft_and_the_leads_keep_pointing_at_it(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');
        $lead   = $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);

        $this->actingAs($this->admin)->delete("/channel-partners/{$broker->id}");

        $this->assertSoftDeleted('channel_partners', ['id' => $broker->id]);
        $this->assertSame($broker->id, $lead->fresh()->channel_partner_id, 'never nulled');
    }

    /* ---------------- the list ---------------- */

    public function test_the_list_carries_the_lead_count_and_the_broker_counts(): void
    {
        $firm   = $this->partner('firm', 'Shreeji Realty');
        $broker = $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);
        $this->partner('broker', 'Sunil', ['parent_id' => $firm->id, 'is_active' => false]);

        $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);
        $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);
        $this->lead(['channel_partner_id' => $firm->id, 'source' => 'broker']);

        $rows = collect($this->page('/channel-partners')['partners']['data'])->keyBy('name');

        $this->assertSame(1, $rows['Shreeji Realty']['leads_count'], 'the lead that came through the firm itself');
        $this->assertSame(2, $rows['Shreeji Realty']['brokers_count']);
        $this->assertSame(1, $rows['Shreeji Realty']['active_brokers_count'], 'Sunil is switched off');

        $this->assertSame(2, $rows['Ravi Kumar']['leads_count']);
        $this->assertSame('Shreeji Realty', $rows['Ravi Kumar']['parent_name']);
    }

    public function test_the_brokers_of_this_firm_filter_returns_only_that_firms_brokers(): void
    {
        $shreeji = $this->partner('firm', 'Shreeji Realty');
        $other   = $this->partner('firm', 'Other Realty');

        $this->partner('broker', 'Ravi Kumar', ['parent_id' => $shreeji->id]);
        $this->partner('broker', 'Sunil', ['parent_id' => $other->id]);
        $this->partner('broker', 'Independent Ravi');

        $names = collect($this->page('/channel-partners', ['parent_id' => $shreeji->id])['partners']['data'])
            ->pluck('name')->all();

        $this->assertSame(['Ravi Kumar'], $names);
    }

    /* ---------------- the lead link ---------------- */

    public function test_a_broker_lead_must_name_a_partner(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload(['source' => 'broker']))
            ->assertSessionHasErrors('channel_partner_id');
    }

    public function test_a_non_broker_lead_never_keeps_a_partner_id(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        // a stale tab posting the partner alongside a source that is not broker
        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload([
                'source' => 'walk_in', 'channel_partner_id' => $broker->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Lead::firstOrFail()->channel_partner_id);
    }

    public function test_an_inactive_partner_cannot_be_chosen_for_a_new_lead(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar', ['is_active' => false]);

        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload([
                'source' => 'broker', 'channel_partner_id' => $broker->id,
            ]))
            ->assertSessionHasErrors('channel_partner_id');
    }

    public function test_a_lead_already_on_a_partner_can_be_saved_after_that_partner_is_switched_off(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');
        $lead   = $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);

        $broker->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->leadPayload([
                'source' => 'broker',
                'channel_partner_id' => $broker->id,
                'mobile_number' => $lead->mobile_number,
                'stage' => $lead->stage,
            ]))
            ->assertSessionHasNoErrors();
    }

    /* ---------------- the old column ---------------- */

    public function test_a_lead_from_before_this_change_still_shows_its_broker_name(): void
    {
        $legacy = $this->lead(['source' => 'broker', 'broker_name' => 'Shreeji Realty']);

        $this->assertNull($legacy->channel_partner_id);
        $this->assertSame('Shreeji Realty', $legacy->broker_label);

        // and the Leads page ships it, so the Source column can print it
        $row = collect($this->page('/leads')['leads']['data'])->firstWhere('id', $legacy->id);

        $this->assertSame('Shreeji Realty', $row['broker_name']);
        $this->assertNull($row['channel_partner']);
    }

    public function test_editing_a_legacy_lead_neither_demands_a_partner_nor_wipes_the_text(): void
    {
        $legacy = $this->lead(['source' => 'broker', 'broker_name' => 'Shreeji Realty']);

        // the form no longer has the field, so it no longer sends the key
        $this->actingAs($this->admin)
            ->put("/leads/{$legacy->id}", $this->leadPayload([
                'source'        => 'broker',
                'first_name'    => 'Meerah',
                'mobile_number' => $legacy->mobile_number,
                'stage'         => $legacy->stage,
            ]))
            ->assertSessionHasNoErrors();

        $legacy->refresh();

        $this->assertSame('Meerah', $legacy->first_name, 'the edit went through');
        $this->assertSame('Shreeji Realty', $legacy->broker_name, 'and the history is untouched');
        $this->assertNull($legacy->channel_partner_id, 'nothing guessed a match for it');
    }

    public function test_attributing_a_legacy_lead_to_a_partner_prefers_the_partner(): void
    {
        $legacy = $this->lead(['source' => 'broker', 'broker_name' => 'Shreeji Realty']);
        $firm   = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->admin)
            ->put("/leads/{$legacy->id}", $this->leadPayload([
                'source'             => 'broker',
                'channel_partner_id' => $firm->id,
                'mobile_number'      => $legacy->mobile_number,
                'stage'              => $legacy->stage,
            ]))
            ->assertSessionHasNoErrors();

        $legacy->refresh();

        $this->assertSame($firm->id, $legacy->channel_partner_id);
        $this->assertSame('Shreeji Realty', $legacy->broker_name, 'the text is still there underneath');
        $this->assertSame('Shreeji Realty', $legacy->broker_label);
    }

    /* ---------------- the report ---------------- */

    public function test_the_report_groups_by_partner_and_keeps_the_unattributed_leads_visible(): void
    {
        $firm   = $this->partner('firm', 'Shreeji Realty');
        $broker = $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);

        $attributed = $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);
        $this->lead(['channel_partner_id' => $firm->id, 'source' => 'broker']);
        // a lead from before the feature: text, no row
        $this->lead(['source' => 'broker', 'broker_name' => 'Shreeji Realty']);
        $this->lead(['source' => 'walk_in']);

        $this->history($attributed, 'booking_done', now());

        $rows = $this->reportRows('channel_partner');

        $this->assertSame('Ravi Kumar — Shreeji Realty', $rows[$broker->id]['label']);
        $this->assertSame(1, $rows[$broker->id]['total']);
        $this->assertSame(1, $rows[$broker->id]['booked']);
        $this->assertSame(100.0, $rows[$broker->id]['conversion']);

        $this->assertSame(1, $rows[$firm->id]['total'], 'the lead that came through the firm itself');
        // a real denominator with nothing on top of it: 0% is the answer here,
        // and the dash is reserved for the group with no leads to divide by
        $this->assertSame(0.0, $rows[$firm->id]['conversion']);

        $this->assertSame(
            2,
            $rows['__none__']['total'],
            'the legacy broker lead and the walk-in, neither of them attributed to a row',
        );
        $this->assertSame('No channel partner', $rows['__none__']['label']);
        $this->assertFalse($rows['__none__']['drillable'], 'there is no id to hand the Leads page');
    }

    public function test_a_group_with_no_leads_reads_a_dash_and_never_zero_percent(): void
    {
        $this->partner('broker', 'Ravi Kumar');

        $rows = $this->reportRows('channel_partner');
        $row  = collect($rows)->firstWhere('label', 'Ravi Kumar');

        $this->assertSame(0, $row['total']);
        $this->assertNull($row['conversion'], 'no leads is nothing to divide by');
        $this->assertNull($row['share']);
    }

    public function test_a_soft_deleted_partner_keeps_its_row_marked_removed(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');
        $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker']);

        $broker->delete();

        $rows = $this->reportRows('channel_partner');

        $this->assertSame('Ravi Kumar (removed)', $rows[$broker->id]['label']);
        $this->assertSame(1, $rows[$broker->id]['total'], 'the business it brought is still its own');
    }

    public function test_the_partner_grouping_sums_to_the_dashboard_over_the_same_range(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $a = $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker'], now()->subDays(3));
        $b = $this->lead(['source' => 'walk_in'], now()->subDays(5));

        $this->history($a, 'site_visit_done', now()->subDay());
        $this->history($a, 'booking_done', now());
        $this->history($b, 'lost', now());

        $cards = $this->page('/dashboard', ['range' => '30'])['cards'];
        $rows  = $this->reportRows('channel_partner', '30');

        foreach (['total', 'visits', 'booked', 'lost'] as $key) {
            $this->assertSame(
                $cards[$key],
                array_sum(array_column($rows, $key)),
                "grouped by channel partner, the $key column must sum to the dashboard card",
            );
        }
    }

    public function test_a_report_row_drills_through_to_the_same_population(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker'], now()->subDay());
        $this->lead(['source' => 'broker', 'broker_name' => 'Someone else'], now()->subDay());

        $report = $this->reportRows('channel_partner', '30')[$broker->id]['total'];

        $leads = $this->page('/leads', [
            'from' => now()->subDays(30)->toDateString(),
            'to'   => now()->toDateString(),
            'channel_partner_id' => $broker->id,
        ]);

        $this->assertSame(1, $report);
        $this->assertSame($report, $leads['leads']['total'], 'the list behind the row is the length of the row');
    }

    public function test_a_telecaller_grouping_by_partner_sees_only_their_own_leads(): void
    {
        $broker = $this->partner('broker', 'Ravi Kumar');

        $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker'], now()->subDay(), $this->alice);
        $this->lead(['channel_partner_id' => $broker->id, 'source' => 'broker'], now()->subDay(), $this->admin);

        $this->assertSame(2, $this->reportRows('channel_partner', '30')[$broker->id]['total']);
        $this->assertSame(
            1,
            $this->reportRows('channel_partner', '30', $this->alice)[$broker->id]['total'],
            'scopeVisibleTo applies whatever the grouping is',
        );
    }

    /* ---------------- helpers ---------------- */

    private function reportRows(string $dimension, string $range = '30', ?User $as = null): array
    {
        $props = $this->page('/reports/leads', ['group' => $dimension, 'range' => $range], $as);

        return collect($props['rows'])->keyBy('key')->all();
    }

    /** One page visit, as Inertia, returning the props. */
    private function page(string $url, array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)
            ->get($url . '?' . http_build_query($query + ['reset' => 1]));

        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name'      => 'Shreeji Realty',
            'type'      => 'firm',
            'parent_id' => '',
            'phone'     => '9876543210',
            'is_active' => true,
        ];
    }

    /** A lead form post, follow-up fields and all. */
    private function leadPayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at'   => now()->addDay()->format('Y-m-d\TH:i'),
        ];
    }

    private function partner(string $type, string $name, array $attributes = []): ChannelPartner
    {
        return ChannelPartner::create($attributes + [
            'name'  => $name,
            'type'  => $type,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }

    private function lead(array $attributes = [], ?Carbon $createdAt = null, ?User $owner = null): Lead
    {
        $lead = Lead::create($attributes + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'in_discussion',
            'assigned_to'   => ($owner ?? $this->admin)->id,
            'created_by'    => $this->admin->id,
        ]);

        if ($createdAt) {
            $lead->forceFill(['created_at' => $createdAt])->save();
        }

        return $lead;
    }

    /** A completed to-do carrying a stage transition — the only shape history has. */
    private function history(Lead $lead, string $stage, Carbon $at): Todo
    {
        return Todo::create([
            'lead_id'       => $lead->id,
            'assigned_to'   => $lead->assigned_to,
            'type'          => 'call',
            'scheduled_at'  => $at->copy()->subDay(),
            'status'        => 'completed',
            'completed_at'  => $at,
            'completed_by'  => $lead->assigned_to,
            'outcome_stage' => $stage,
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
