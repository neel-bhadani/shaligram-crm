<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\User;
use App\Support\LeadRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The lead form's Requirements section: residence types and preferred floors.
 *
 * @see LeadRequirements
 */
class LeadRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $telecaller;

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'Ann');
        $this->telecaller = $this->user('telecaller', 'Tia');
        $this->alpha = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- options ---------------- */

    public function test_the_form_is_offered_every_option_in_order_with_string_keys(): void
    {
        $this->actingAs($this->admin)->get('/leads')->assertInertia(fn ($page) => $page
            ->has('options.residenceTypes', 8)
            ->where('options.residenceTypes.0', ['key' => '2_bhk', 'label' => '2 BHK'])
            ->where('options.residenceTypes.7', ['key' => '5_5_bhk', 'label' => '5.5 BHK'])
            ->where('options.floors', fn ($floors) => collect($floors)->pluck('label')->all() === [
                '1st Floor', '2nd Floor', '3rd Floor', '4th Floor', '5th Floor', '6th Floor', '7th Floor',
                '8th Floor', '9th Floor', '10th Floor', '11th Floor', '12th Floor', '13th Floor', '14th Floor',
            ] && collect($floors)->pluck('key')->all() === array_map('strval', range(1, 14))));
    }

    /* ---------------- create ---------------- */

    public function test_a_new_lead_stores_its_selections_as_keys_in_config_order(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload([
                'residence_types' => ['3_bhk', '2_bhk'],
                'preferred_floors' => ['12', '1', '3'],
            ]))
            ->assertSessionHasNoErrors();

        $lead = Lead::sole();

        $this->assertSame(['2_bhk', '3_bhk'], $lead->residence_types);
        $this->assertSame(['1', '3', '12'], $lead->preferred_floors);
        $this->assertSame('["1","3","12"]', $lead->getRawOriginal('preferred_floors'));
    }

    #[DataProvider('emptySelections')]
    public function test_a_new_lead_with_nothing_selected_stores_null(array $selections): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload($selections))
            ->assertSessionHasNoErrors();

        $lead = Lead::sole();

        $this->assertNull($lead->getRawOriginal('residence_types'));
        $this->assertNull($lead->getRawOriginal('preferred_floors'));
    }

    public static function emptySelections(): array
    {
        return [
            'not sent' => [[]],
            'sent empty' => [['residence_types' => [], 'preferred_floors' => []]],
        ];
    }

    /* ---------------- validation ---------------- */

    #[DataProvider('badSelections')]
    public function test_a_bad_selection_is_refused_on_create_and_update(array $selection, string $error): void
    {
        $this->actingAs($this->admin)
            ->postJson('/leads', $this->payload($selection))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);

        $this->assertSame(0, Lead::count());

        $lead = $this->lead();

        $this->actingAs($this->admin)
            ->putJson("/leads/{$lead->id}", $this->editPayload($lead, $selection))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
    }

    public static function badSelections(): array
    {
        return [
            'unknown residence type' => [['residence_types' => ['2_bhk', 'villa']], 'residence_types.1'],
            'repeated residence type' => [['residence_types' => ['2_bhk', '2_bhk']], 'residence_types.0'],
            'residence types not a list' => [['residence_types' => '2_bhk'], 'residence_types'],
            'unknown floor' => [['preferred_floors' => ['15']], 'preferred_floors.0'],
            'repeated floor' => [['preferred_floors' => ['4', '4']], 'preferred_floors.0'],
            'floors not a list' => [['preferred_floors' => '4'], 'preferred_floors'],
        ];
    }

    /* ---------------- edit ---------------- */

    public function test_the_page_sends_the_saved_selections_to_the_edit_form(): void
    {
        $this->lead(['residence_types' => ['2_bhk'], 'preferred_floors' => ['5', '11'], 'requirement' => 'Garden facing']);

        $this->actingAs($this->admin)->get('/leads')->assertInertia(fn ($page) => $page
            ->where('leads.data.0.residence_types', ['2_bhk'])
            ->where('leads.data.0.preferred_floors', ['5', '11'])
            ->where('leads.data.0.requirement', 'Garden facing'));
    }

    public function test_an_update_replaces_the_selections_and_an_empty_array_clears_them(): void
    {
        $lead = $this->lead(['residence_types' => ['2_bhk', '3_bhk'], 'preferred_floors' => ['1']]);

        $this->save($lead, ['residence_types' => ['3_5_bhk', '3_bhk'], 'preferred_floors' => ['14', '2']]);

        $this->assertSame(['3_bhk', '3_5_bhk'], $lead->refresh()->residence_types);
        $this->assertSame(['2', '14'], $lead->preferred_floors);

        $this->save($lead, ['residence_types' => [], 'preferred_floors' => []]);

        $this->assertNull($lead->refresh()->residence_types);
        $this->assertNull($lead->preferred_floors);
    }

    public function test_a_field_left_out_of_an_update_keeps_its_selection_and_the_old_note_is_untouched(): void
    {
        $lead = $this->lead(['residence_types' => ['2_bhk'], 'preferred_floors' => ['1'], 'requirement' => 'Garden facing']);

        $this->save($lead, ['preferred_floors' => ['2']]);

        $this->assertSame(['2_bhk'], $lead->refresh()->residence_types);
        $this->assertSame(['2'], $lead->preferred_floors);
        $this->assertSame('Garden facing', $lead->requirement);
    }

    public function test_a_telecaller_can_edit_the_requirements_of_their_own_lead(): void
    {
        $this->assertFalse($this->telecaller->can_('edit_leads'));

        $lead = $this->lead();

        $this->save($lead, ['residence_types' => ['4_bhk'], 'preferred_floors' => ['7']], $this->telecaller);

        $this->assertSame(['4_bhk'], $lead->refresh()->residence_types);
        $this->assertSame(['7'], $lead->preferred_floors);
    }

    /* ---------------- timeline ---------------- */

    public function test_a_change_is_on_the_timeline_with_readable_labels(): void
    {
        $lead = $this->lead(['residence_types' => ['2_bhk', '3_bhk']]);

        $this->save($lead, ['residence_types' => ['3_bhk', '3_5_bhk'], 'preferred_floors' => ['11', '2']]);

        $this->assertSame([
            ['field' => 'Type of residence', 'from' => '2 BHK, 3 BHK', 'to' => '3 BHK, 3.5 BHK'],
            ['field' => 'Preferred floors', 'from' => null, 'to' => '2nd Floor, 11th Floor'],
        ], $this->changes($lead));

        $this->save($lead, ['preferred_floors' => []]);

        $this->assertSame(
            ['field' => 'Preferred floors', 'from' => '2nd Floor, 11th Floor', 'to' => null],
            $this->changes($lead)[2],
        );
    }

    /**
     * The same selection ticked in another order is not a change — nor is it
     * one against MySQL's own spacing of the stored JSON.
     */
    public function test_an_unchanged_selection_writes_no_timeline_entry(): void
    {
        $lead = $this->lead(['residence_types' => ['2_bhk', '3_bhk'], 'preferred_floors' => ['1', '2']]);
        DB::table('leads')->where('id', $lead->id)->update(['residence_types' => '["2_bhk", "3_bhk"]']);

        $this->save($lead, ['residence_types' => ['3_bhk', '2_bhk'], 'preferred_floors' => ['2', '1']]);
        $this->save($lead, []);

        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::FieldUpdated)->count());
    }

    /* ---------------- viewing ---------------- */

    public function test_the_lead_detail_carries_readable_labels_and_the_old_note(): void
    {
        $lead = $this->lead(['residence_types' => ['2_5_bhk', '5_bhk'], 'preferred_floors' => ['3', '13'], 'requirement' => 'Garden facing']);

        $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('lead.residence_type_labels', '2.5 BHK, 5 BHK')
            ->assertJsonPath('lead.preferred_floor_labels', '3rd Floor, 13th Floor')
            ->assertJsonPath('lead.requirement', 'Garden facing');
    }

    public function test_a_lead_with_no_requirements_has_null_labels(): void
    {
        $this->actingAs($this->admin)->getJson("/leads/{$this->lead()->id}")
            ->assertOk()
            ->assertJsonPath('lead.residence_type_labels', null)
            ->assertJsonPath('lead.preferred_floor_labels', null);
    }

    /** An option removed from the config stays readable on the leads that carry it. */
    public function test_a_removed_option_still_shows_on_the_lead_that_has_it(): void
    {
        $lead = $this->lead(['residence_types' => ['2_bhk', '5_5_bhk']]);

        $options = config('crm.residence_types');
        unset($options['5_5_bhk']);
        config(['crm.residence_types' => $options]);

        $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('lead.residence_type_labels', '2 BHK, 5_5_bhk');
    }

    /* ---------------- export ---------------- */

    public function test_the_csv_and_excel_exports_carry_both_columns(): void
    {
        $this->lead(['residence_types' => ['2_bhk', '3_bhk'], 'preferred_floors' => ['1', '12']]);
        $this->lead();

        $csv = array_map(
            fn (string $line) => str_getcsv($line, escape: ''),
            explode("\n", trim($this->export('csv'))),
        );

        $path = tempnam(sys_get_temp_dir(), 'requirements').'.xlsx';
        file_put_contents($path, $this->export('excel'));
        $excel = (new XlsxReader)->load($path)->getActiveSheet()->toArray(nullValue: '');
        unlink($path);

        foreach (['csv' => $csv, 'excel' => $excel] as $format => $rows) {
            $this->assertSame(
                [['Type of residence', 'Preferred floor'], ['2 BHK, 3 BHK', '1st Floor, 12th Floor'], ['', '']],
                array_map(fn (array $row) => array_slice($row, -2), $rows),
                $format,
            );
        }
    }

    /* ---------------- helpers ---------------- */

    private function export(string $format): string
    {
        return $this->actingAs($this->admin)
            ->post('/export-data/download', ['data_type' => 'leads', 'format' => $format])
            ->assertOk()
            ->streamedContent();
    }

    private function save(Lead $lead, array $changes, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead->fresh(), $changes))
            ->assertSessionHasNoErrors();
    }

    /** @return list<array{field: string, from: ?string, to: ?string}> */
    private function changes(Lead $lead): array
    {
        $timeline = $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")->json('timeline');

        return array_values(array_filter(array_column($timeline, 'change')));
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create($attributes + [
            'first_name' => 'Riya',
            'last_name' => 'Shah',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->alpha->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_to' => $this->telecaller->id,
            'assigned_role' => 'telecaller',
            'created_by' => $this->admin->id,
            'stage_changed_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Riya',
            'last_name' => 'Shah',
            'mobile_number' => '9512779297',
            'project_id' => $this->alpha->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ];
    }

    private function editPayload(Lead $lead, array $changes): array
    {
        return $changes + [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number,
            'project_id' => $lead->project_id,
            'source' => $lead->source,
            'stage' => $lead->stage,
        ];
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
