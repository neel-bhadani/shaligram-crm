<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Requirements section of a lead: types of residence and preferred floors.
 * Optional everywhere, editable by anyone who can open the form.
 */
class LeadRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $telecaller;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->telecaller = $this->user('telecaller', 'Tia');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_selections_are_stored_as_keys_in_config_order(): void
    {
        $lead = $this->createLead([
            'residence_types' => ['4_bhk', '3_5_bhk', '3_bhk'],
            'preferred_floors' => ['12', 3, '1'],
        ]);

        $this->assertSame(['3_bhk', '3_5_bhk', '4_bhk'], $lead->residence_types);
        $this->assertSame(['1', '3', '12'], $lead->preferred_floors);
        $this->assertSame('3 BHK, 3.5 BHK, 4 BHK', $lead->residence_type_labels);
        $this->assertSame('1st Floor, 3rd Floor, 12th Floor', $lead->preferred_floor_labels);
    }

    /** @param  array<string, mixed>  $fields */
    #[DataProvider('emptySelections')]
    public function test_no_selection_is_stored_as_null(array $fields): void
    {
        $lead = $this->createLead($fields);

        $row = DB::table('leads')->find($lead->id);
        $this->assertNull($row->residence_types);
        $this->assertNull($row->preferred_floors);
        $this->assertNull($lead->residence_type_labels);
        $this->assertNull($lead->preferred_floor_labels);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function emptySelections(): array
    {
        return [
            'not sent' => [[]],
            'empty lists' => [['residence_types' => [], 'preferred_floors' => []]],
            'nulls' => [['residence_types' => null, 'preferred_floors' => null]],
        ];
    }

    #[DataProvider('refusedSelections')]
    public function test_an_invalid_or_duplicate_option_is_refused(string $field, mixed $value, string $errorKey): void
    {
        $this->actingAs($this->admin)
            ->postJson('/leads', $this->payload([$field => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);

        $this->assertSame(0, Lead::count());
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> */
    public static function refusedSelections(): array
    {
        return [
            'unknown residence type' => ['residence_types', ['3_bhk', '9_bhk'], 'residence_types.1'],
            'retired residence type' => ['residence_types', ['2_5_bhk'], 'residence_types.0'],
            'duplicate residence type' => ['residence_types', ['3_bhk', '3_bhk'], 'residence_types.0'],
            'residence types not a list' => ['residence_types', '3_bhk', 'residence_types'],
            'unknown floor' => ['preferred_floors', ['15'], 'preferred_floors.0'],
            'duplicate floor' => ['preferred_floors', ['2', 2], 'preferred_floors.0'],
            'nested floor' => ['preferred_floors', [['2']], 'preferred_floors.0'],
        ];
    }

    public function test_an_edit_replaces_the_selections(): void
    {
        $lead = $this->leadWithRequirements();

        $this->edit($lead, $this->admin, ['residence_types' => ['5_bhk', '2_bhk'], 'preferred_floors' => ['14']]);

        $lead->refresh();
        $this->assertSame(['2_bhk', '5_bhk'], $lead->residence_types);
        $this->assertSame(['14'], $lead->preferred_floors);
    }

    public function test_an_empty_array_clears_the_selections(): void
    {
        $lead = $this->leadWithRequirements();

        $this->edit($lead, $this->admin, ['residence_types' => [], 'preferred_floors' => []]);

        $row = DB::table('leads')->find($lead->id);
        $this->assertNull($row->residence_types);
        $this->assertNull($row->preferred_floors);
    }

    public function test_a_missing_field_keeps_the_selections(): void
    {
        $lead = $this->leadWithRequirements();

        $this->edit($lead, $this->admin, ['first_name' => 'Renamed']);

        $lead->refresh();
        $this->assertSame('Renamed', $lead->first_name);
        $this->assertSame(['3_bhk'], $lead->residence_types);
        $this->assertSame(['4', '5'], $lead->preferred_floors);
    }

    public function test_a_telecaller_can_edit_them(): void
    {
        $this->assertFalse($this->telecaller->can_('edit_leads'));
        $lead = $this->leadWithRequirements();
        $this->assertSame($this->telecaller->id, $lead->assigned_to);

        $this->edit($lead, $this->telecaller, ['residence_types' => ['4_bhk'], 'preferred_floors' => ['6']]);

        $lead->refresh();
        $this->assertSame(['4_bhk'], $lead->residence_types);
        $this->assertSame(['6'], $lead->preferred_floors);
    }

    public function test_a_change_is_on_the_timeline_with_labels(): void
    {
        $lead = $this->leadWithRequirements();

        $this->edit($lead, $this->admin, ['residence_types' => ['3_5_bhk', '3_bhk'], 'preferred_floors' => []]);

        $changes = collect($this->getJson("/leads/{$lead->id}")->assertOk()->json('timeline'))
            ->pluck('change')->filter()->values()->all();

        $this->assertSame([
            ['field' => 'Type of residence', 'from' => '3 BHK', 'to' => '3 BHK, 3.5 BHK'],
            ['field' => 'Preferred floor', 'from' => '4th Floor, 5th Floor', 'to' => null],
        ], $changes);
    }

    public function test_saving_the_same_selections_adds_nothing_to_the_timeline(): void
    {
        $lead = $this->leadWithRequirements();

        $this->edit($lead, $this->admin, ['residence_types' => ['3_bhk'], 'preferred_floors' => ['5', '4']]);

        $this->assertEmpty(collect($this->getJson("/leads/{$lead->id}")->json('timeline'))->pluck('change')->filter());
    }

    public function test_the_leads_index_rows_and_options_include_the_fields(): void
    {
        $this->leadWithRequirements();

        $this->actingAs($this->admin)->get('/leads')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Leads/Index')
            ->where('leads.data.0.residence_types', ['3_bhk'])
            ->where('leads.data.0.preferred_floors', ['4', '5'])
            ->where('options.residenceTypes', config('crm.residence_types'))
            ->where('options.floors', config('crm.floors')));
    }

    public function test_the_view_popup_data_includes_the_labels(): void
    {
        $lead = $this->leadWithRequirements();
        $lead->update(['requirement' => 'Garden facing']);

        $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('lead.residence_type_labels', '3 BHK')
            ->assertJsonPath('lead.preferred_floor_labels', '4th Floor, 5th Floor')
            ->assertJsonPath('lead.requirement', 'Garden facing');
    }

    public function test_penthouse_can_be_saved_on_its_own(): void
    {
        $lead = $this->createLead(['preferred_floors' => ['penthouse']]);

        $this->assertSame(['penthouse'], $lead->preferred_floors);
        $this->assertSame('Penthouse', $lead->preferred_floor_labels);
    }

    public function test_penthouse_is_stored_after_the_numbered_floors(): void
    {
        $lead = $this->createLead(['preferred_floors' => ['penthouse', '14', 3]]);

        $this->assertSame(['3', '14', 'penthouse'], $lead->preferred_floors);
        $this->assertSame('3rd Floor, 14th Floor, Penthouse', $lead->preferred_floor_labels);
    }

    public function test_penthouse_is_labelled_in_the_view_popup_and_the_timeline(): void
    {
        $lead = $this->leadWithRequirements();

        $this->edit($lead, $this->admin, ['residence_types' => ['3_bhk'], 'preferred_floors' => ['3', 'penthouse']]);

        $response = $this->getJson("/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('lead.preferred_floor_labels', '3rd Floor, Penthouse');

        $this->assertSame(
            [['field' => 'Preferred floor', 'from' => '4th Floor, 5th Floor', 'to' => '3rd Floor, Penthouse']],
            collect($response->json('timeline'))->pluck('change')->filter()->values()->all(),
        );
    }

    public function test_the_leads_index_has_penthouse_and_its_label_for_the_table_line(): void
    {
        $this->createLead(['preferred_floors' => ['3', 'penthouse']]);

        $this->actingAs($this->admin)->get('/leads')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leads.data.0.preferred_floors', ['3', 'penthouse'])
            ->where('options.floors.penthouse', 'Penthouse'));
    }

    public function test_sky_villa_can_be_saved_on_its_own(): void
    {
        $lead = $this->createLead(['residence_types' => ['sky_villa']]);

        $this->assertSame(['sky_villa'], $lead->residence_types);
        $this->assertSame('Sky Villa', $lead->residence_type_labels);
    }

    public function test_sky_villa_is_stored_after_the_bhk_types_and_labelled_in_the_view_popup_and_the_table_line(): void
    {
        $lead = $this->createLead(['residence_types' => ['sky_villa', '4_bhk']]);

        $this->assertSame(['4_bhk', 'sky_villa'], $lead->residence_types);

        $this->getJson("/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('lead.residence_type_labels', '4 BHK, Sky Villa');

        $this->get('/leads')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leads.data.0.residence_types', ['4_bhk', 'sky_villa'])
            ->where('options.residenceTypes.sky_villa', 'Sky Villa'));
    }

    public function test_the_retired_half_bhk_types_are_no_longer_options(): void
    {
        $this->assertSame(
            ['2_bhk' => '2 BHK', '3_bhk' => '3 BHK', '3_5_bhk' => '3.5 BHK', '4_bhk' => '4 BHK', '5_bhk' => '5 BHK', 'sky_villa' => 'Sky Villa'],
            config('crm.residence_types'),
        );
    }

    public function test_the_migration_removes_retired_types_from_every_lead(): void
    {
        $mixed = $this->storedLead(['2_5_bhk', '4_bhk']);
        $only = $this->storedLead(['5_5_bhk']);
        $untouched = $this->storedLead(['3_bhk', 'sky_villa']);
        $only->delete();

        (require database_path('migrations/2026_11_25_175301_remove_retired_residence_types_from_leads.php'))->up();

        $this->assertSame(['4_bhk'], $mixed->fresh()->residence_types);
        $this->assertNull(DB::table('leads')->where('id', $only->id)->value('residence_types'), 'soft-deleted leads too, and null when nothing is left');
        $this->assertSame(['3_bhk', 'sky_villa'], $untouched->fresh()->residence_types);
    }

    /* ---------------- fixtures ---------------- */

    private function leadWithRequirements(): Lead
    {
        return $this->createLead(['residence_types' => ['3_bhk'], 'preferred_floors' => ['4', '5']]);
    }

    /** @param  list<string>  $residenceTypes  written straight to the column, bypassing validation */
    private function storedLead(array $residenceTypes): Lead
    {
        $lead = Lead::create([
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'created_by' => $this->admin->id,
        ]);
        DB::table('leads')->where('id', $lead->id)->update(['residence_types' => json_encode($residenceTypes)]);

        return $lead;
    }

    /** @param  array<string, mixed>  $fields */
    private function createLead(array $fields): Lead
    {
        $this->actingAs($this->admin)
            ->postJson('/leads', $this->payload($fields))
            ->assertRedirect()->assertSessionHasNoErrors();

        return Lead::sole();
    }

    /** @param  array<string, mixed>  $fields */
    private function edit(Lead $lead, User $by, array $fields): void
    {
        $this->actingAs($by)
            ->putJson("/leads/{$lead->id}", $fields + $lead->only([
                'first_name', 'middle_name', 'last_name', 'mobile_number', 'email', 'project_id', 'source', 'stage',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function payload(array $fields): array
    {
        return $fields + [
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => '9876543210',
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ];
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
