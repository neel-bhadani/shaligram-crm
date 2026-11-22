<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The leads report grouped by residence type and by floor.
 *
 * Unlike every other grouping a lead can be in several rows, so the rows are
 * allowed to add up to more than the totals and the totals count each lead
 * once. Everything else has to be counted exactly as the other groupings are.
 *
 * @see ReportController::multiLeadRows()
 */
class ReportByRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_SPECIFIED = '__none__';

    private User $admin;

    private User $alice;

    private User $bob;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00'));

        $this->admin = $this->user('admin', 'Admin');
        $this->alice = $this->user('telecaller', 'Alice');
        $this->bob = $this->user('telecaller', 'Bob');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The two groupings, with three option keys to use. Floors 1 and 11 are
     * there on purpose: one key is the start of the other.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function dimensions(): array
    {
        return [
            'residence type' => ['residence_type', 'residence_types', 'crm.residence_types', ['2_bhk', '3_bhk', '2_5_bhk']],
            'floor' => ['floor', 'preferred_floors', 'crm.floors', ['1', '2', '11']],
        ];
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_a_lead_counts_once_in_each_row_it_chose_and_once_in_the_total(string $group, string $column, string $options, array $keys): void
    {
        [$a, $b, $c] = $keys;
        $this->lead([$column => [$a, $b]]);

        $report = $this->report($group);

        $this->assertSame(1, $report['rows'][$a]['total']);
        $this->assertSame(1, $report['rows'][$b]['total']);
        $this->assertSame(0, $report['rows'][$c]['total']);
        $this->assertSame(0, $report['rows'][self::NOT_SPECIFIED]['total']);
        $this->assertSame(1, $report['totals']['total']);
        $this->assertTrue($report['multi']);
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_a_lead_with_no_requirements_is_not_specified(string $group, string $column, string $options, array $keys): void
    {
        $this->lead([$column => null]);
        $this->lead([$column => []]);

        $report = $this->report($group);

        $this->assertSame('Not specified', $report['rows'][self::NOT_SPECIFIED]['label']);
        $this->assertSame(2, $report['rows'][self::NOT_SPECIFIED]['total']);
        $this->assertSame(2, array_sum(array_column($report['rows'], 'total')), 'and in no other row');
        $this->assertSame(2, $report['totals']['total']);
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_every_option_is_a_zero_filled_row_in_config_order_with_not_specified_last(string $group, string $column, string $options, array $keys): void
    {
        $rows = $this->props($group)['rows'];

        $this->assertSame([...array_values(config($options)), 'Not specified'], array_column($rows, 'label'));
        $this->assertSame([...array_map('strval', array_keys(config($options))), self::NOT_SPECIFIED], array_column($rows, 'key'));

        foreach ($rows as $row) {
            foreach (['total', 'visits', 'booked', 'lost', 'no_phone', 'no_contact'] as $figure) {
                $this->assertSame(0, $row[$figure]);
            }

            $this->assertNull($row['conversion'], 'nothing to divide by is a dash, never 0%');
            $this->assertNull($row['share']);
        }
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_intake_is_created_at_and_history_is_the_follow_ups_in_range(string $group, string $column, string $options, array $keys): void
    {
        [$a, $b] = $keys;

        // arrived before the range, visited twice and booked inside it
        $old = $this->lead([$column => [$a]], now()->subDays(90));
        $this->history($old, 'site_visit_done', now()->subDays(3));
        $this->history($old, 'site_visit_done', now()->subDays(2));
        $this->history($old, 'booking_done', now()->subDay());

        // arrived inside the range, lost inside it, visited long before it
        $new = $this->lead([$column => [$a, $b]], now()->subDays(5));
        $this->history($new, 'lost', now()->subDay());
        $this->history($new, 'site_visit_done', now()->subDays(60));

        $report = $this->report($group);

        $this->assertSame(['total' => 1, 'visits' => 1, 'booked' => 1, 'lost' => 1], $this->figures($report['rows'][$a]));
        $this->assertSame(['total' => 1, 'visits' => 0, 'booked' => 0, 'lost' => 1], $this->figures($report['rows'][$b]));
        $this->assertSame(['total' => 1, 'visits' => 1, 'booked' => 1, 'lost' => 1], $this->figures($report['totals']));
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_totals_and_percentages_use_distinct_leads_not_the_sum_of_the_rows(string $group, string $column, string $options, array $keys): void
    {
        $both = $this->lead([$column => $keys, 'mobile_number' => null, 'email' => null]);
        $this->history($both, 'site_visit_done', now());
        $this->history($both, 'booking_done', now());
        $this->lead([$column => [$keys[0]], 'mobile_number' => null, 'email' => 'a@example.test']);
        $this->lead([$column => null]);
        $this->lead([$column => null]);

        $report = $this->report($group);

        $this->assertSame(6, array_sum(array_column($report['rows'], 'total')), 'the rows overlap');
        $this->assertSame(['total' => 4, 'visits' => 1, 'booked' => 1, 'lost' => 0], $this->figures($report['totals']));
        $this->assertSame(2, $report['totals']['no_phone']);
        $this->assertSame(1, $report['totals']['no_contact']);
        $this->assertEquals(25, $report['totals']['conversion']);
        $this->assertEquals(100, $report['totals']['share']);

        $row = $report['rows'][$keys[0]];
        $this->assertSame([2, 1], [$row['no_phone'], $row['no_contact']]);
        $this->assertEquals(50, $row['share'], 'two of the four leads');
        $this->assertEquals(50, $row['conversion'], 'one booking over its two leads');
    }

    /** The totals are the same leads any other grouping counts over the range. */
    #[DataProvider('dimensions')]
    public function test_totals_agree_with_the_other_groupings(string $group, string $column): void
    {
        foreach (['2026-11-20', '2026-11-01', '2026-01-01'] as $i => $date) {
            $lead = $this->lead([$column => array_slice($column === 'residence_types' ? ['2_bhk', '3_bhk'] : ['1', '11'], 0, $i)], Carbon::parse($date));
            $this->history($lead, ['site_visit_done', 'booking_done', 'lost'][$i], now());
        }

        $expected = $this->props('source');
        $this->assertFalse($expected['multi']);

        $totals = $this->props($group)['totals'];
        unset($totals['share']);
        ksort($totals);
        ksort($expected['totals']);

        $this->assertEquals($expected['totals'], $totals);
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_a_telecaller_sees_only_their_own_leads(string $group, string $column, string $options, array $keys): void
    {
        $mine = $this->lead([$column => [$keys[0]]]);
        $theirs = $this->lead([$column => [$keys[0]], 'assigned_to' => $this->bob->id]);
        $this->lead([$column => null, 'assigned_to' => $this->bob->id]);
        $this->history($mine, 'booking_done', now());
        $this->history($theirs, 'booking_done', now());

        $report = $this->report($group, $this->alice);

        $this->assertSame(1, $report['rows'][$keys[0]]['total']);
        $this->assertSame(1, $report['rows'][$keys[0]]['booked']);
        $this->assertSame(0, $report['rows'][self::NOT_SPECIFIED]['total']);
        $this->assertSame(1, $report['totals']['total']);
        $this->assertSame(1, $report['totals']['booked']);
        $this->assertSame(3, $this->report($group)['totals']['total'], 'the admin sees everyone');
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_no_row_has_a_drill_down(string $group, string $column, string $options, array $keys): void
    {
        $this->lead([$column => $keys]);

        $this->assertSame([false], array_values(array_unique(array_column($this->props($group)['rows'], 'drillable'))));
    }

    /** @param  list<string>  $keys */
    #[DataProvider('dimensions')]
    public function test_a_deleted_lead_leaves_the_rows_and_the_totals(string $group, string $column, string $options, array $keys): void
    {
        $lead = $this->lead([$column => $keys]);
        $this->history($lead, 'booking_done', now());
        $lead->delete();

        $report = $this->report($group);

        $this->assertSame(0, $report['rows'][$keys[0]]['total']);
        $this->assertSame(['total' => 0, 'visits' => 0, 'booked' => 0, 'lost' => 0], $this->figures($report['totals']));
        $this->assertNull($report['totals']['conversion']);
    }

    /* ---------------- helpers ---------------- */

    /** @return array{rows: array<string, array<string, mixed>>, totals: array<string, mixed>, multi: bool} */
    private function report(string $group, ?User $as = null): array
    {
        $props = $this->props($group, $as);

        return ['rows' => collect($props['rows'])->keyBy('key')->all(), 'totals' => $props['totals'], 'multi' => $props['multi']];
    }

    /** @return array<string, mixed> */
    private function props(string $group, ?User $as = null): array
    {
        $props = $this->actingAs($as ?? $this->admin)
            ->get('/reports/leads?'.http_build_query(['reset' => 1, 'group' => $group, 'range' => '30']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($group, $props['filters']['group']);

        return $props;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int>
     */
    private function figures(array $row): array
    {
        return ['total' => $row['total'], 'visits' => $row['visits'], 'booked' => $row['booked'], 'lost' => $row['lost']];
    }

    /** @param  array<string, mixed>  $attributes */
    private function lead(array $attributes, ?Carbon $createdAt = null): Lead
    {
        $lead = Lead::create($attributes + [
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'in_discussion',
            'assigned_to' => $this->alice->id,
            'created_by' => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt ?? now()->subDay()])->save();

        return $lead;
    }

    private function history(Lead $lead, string $stage, Carbon $completedAt): void
    {
        Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'scheduled_at' => $completedAt->copy()->subHour(),
            'type' => 'call',
            'status' => 'completed',
            'outcome_stage' => $stage,
            'completed_at' => $completedAt,
        ]);
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
