<?php

namespace Tests\Feature;

use App\Exports\DataExporter;
use App\Http\Controllers\ExportDataController;
use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Tests\TestCase;

/**
 * The Export Data feature: one page, three formats, three data types.
 *
 * The privacy boundary is the same one everywhere else in the application —
 * Lead::scopeVisibleTo for leads, Todo::scopeForUser for follow-ups — so the
 * real point of these tests is that an export of N rows is an export of the N
 * rows the user is allowed to see, and the tests that prove it are the ones
 * that export a handful of hidden rows next to a couple of allowed ones.
 *
 * @see ExportDataController
 * @see DataExporter
 */
class ExportDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sales;

    private User $salesBoss;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->sales = $this->user('salesperson', 'Suresh', ['permissions' => ['export_data' => true]]);
        $this->salesBoss = $this->user('salesperson', 'Maya', ['permissions' => ['export_data' => true, 'see_all_leads' => true]]);

        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->beta = Project::create(['name' => 'Beta']);

        // the salespeople who work the project, so scopeVisibleTo's project half
        // is satisfied for them
        $this->alpha->salespeople()->attach([$this->sales->id, $this->salesBoss->id]);
        $this->beta->salespeople()->attach([$this->sales->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ----------------------------------------------------------------------
     | The page
     | ------------------------------------------------------------------- */

    public function test_a_user_granted_the_permission_can_open_the_export_page(): void
    {
        $this->actingAs($this->sales)
            ->get('/export-data')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Exports/Index')
                ->has('options.types', 3)
                ->has('options.formats', 3));
    }

    public function test_a_guest_cannot_open_the_export_page(): void
    {
        $this->get('/export-data')->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_permission_is_forbidden(): void
    {
        $telecaller = $this->user('telecaller', 'Tara');

        $this->actingAs($telecaller)->get('/export-data')->assertForbidden();
    }

    public function test_an_admin_can_open_the_export_page_by_default(): void
    {
        $this->actingAs($this->admin)->get('/export-data')->assertOk();
    }

    /* ----------------------------------------------------------------------
     | Formats and filenames
     | ------------------------------------------------------------------- */

    public function test_csv_download_has_the_right_content_type_and_filename(): void
    {
        $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);

        $response = $this->export('leads', 'csv');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'shaligram-leads-2026-09-18.csv',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_excel_download_has_the_right_content_type_and_filename(): void
    {
        $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);

        $response = $this->export('leads', 'excel');

        $response->assertOk()->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString(
            'shaligram-leads-2026-09-18.xlsx',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_pdf_download_has_the_right_content_type_and_filename(): void
    {
        $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);

        $response = $this->export('leads', 'pdf');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'shaligram-leads-2026-09-18.pdf',
            $response->headers->get('Content-Disposition'),
        );
    }

    public function test_channel_partner_exports_use_their_own_filename(): void
    {
        $this->partner('firm', 'Shreeji Realty');

        $this->export('channel_partners', 'excel')
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        $this->assertStringContainsString(
            'shaligram-channel-partners-2026-09-18.xlsx',
            $this->export('channel_partners', 'excel')->headers->get('Content-Disposition'),
        );
    }

    public function test_followup_exports_use_their_own_filename(): void
    {
        $lead = $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);
        $this->todo($lead, ['assigned_to' => $this->admin->id]);

        $this->assertStringContainsString(
            'shaligram-followups-2026-09-18.csv',
            $this->export('followups', 'csv')->headers->get('Content-Disposition'),
        );
    }

    /* ----------------------------------------------------------------------
     | Filters
     | ------------------------------------------------------------------- */

    public function test_leads_project_filter_narrows_the_rows(): void
    {
        $alpha = $this->lead($this->alpha, 'fresh');
        $this->lead($this->beta, 'fresh');

        $rows = $this->parseCsv($this->export('leads', 'csv', ['project_id' => $this->alpha->id])->streamedContent());

        $this->assertCount(2, $rows, 'only the Alpha lead is exported'); // header + one row
        $this->assertSame((string) $alpha->id, $rows[1][0]);
    }

    public function test_leads_stage_filter_narrows_the_rows(): void
    {
        $inStage = $this->lead($this->alpha, 'connected');
        $this->lead($this->alpha, 'fresh');

        $rows = $this->parseCsv($this->export('leads', 'csv', ['stage' => 'connected'])->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $inStage->id, $rows[1][0]);
    }

    public function test_leads_source_filter_narrows_the_rows(): void
    {
        $broker = $this->lead($this->alpha, 'fresh', ['source' => 'broker']);
        $this->lead($this->alpha, 'fresh', ['source' => 'walk_in']);

        $rows = $this->parseCsv($this->export('leads', 'csv', ['source' => 'broker'])->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $broker->id, $rows[1][0]);
    }

    public function test_leads_channel_partner_filter_narrows_the_rows(): void
    {
        $partner = $this->partner('firm', 'Shreeji Realty');
        $via = $this->lead($this->alpha, 'fresh', ['source' => 'broker', 'channel_partner_id' => $partner->id]);
        $this->lead($this->alpha, 'fresh', ['source' => 'broker']);

        $rows = $this->parseCsv($this->export('leads', 'csv', ['channel_partner_id' => $partner->id])->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $via->id, $rows[1][0]);
    }

    public function test_leads_assigned_user_filter_narrows_the_rows(): void
    {
        $mine = $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);
        $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->sales->id]);

        $rows = $this->parseCsv($this->export('leads', 'csv', ['assigned_to' => $this->admin->id])->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $mine->id, $rows[1][0]);
    }

    public function test_leads_date_range_is_inclusive_in_ist(): void
    {
        // 18 Sep 12:00 IST: a lead created at the very end of the last day of
        // the range must be inside it — endOfDay in Asia/Kolkata, not a UTC
        // midnight that would slice the last day in half
        $lead = $this->lead($this->alpha, 'fresh', ['created_at' => '2026-09-18 23:59:00']);
        $this->lead($this->alpha, 'fresh', ['created_at' => '2026-08-01 00:00:00']);

        $rows = $this->parseCsv($this->export('leads', 'csv', [
            'from' => '2026-09-01',
            'to' => '2026-09-18',
        ])->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame((string) $lead->id, $rows[1][0]);
    }

    public function test_followup_status_filter_narrows_the_rows(): void
    {
        $lead = $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);
        $done = $this->todo($lead, [
            'assigned_to' => $this->admin->id,
            'status' => 'completed',
            'completed_at' => '2026-09-17 10:00:00',
        ]);
        $this->todo($lead, ['assigned_to' => $this->admin->id, 'status' => 'pending']);

        $csv = $this->export('followups', 'csv', ['status' => 'completed'])->streamedContent();

        $this->assertStringContainsString((string) $done->id, $csv);
        $this->assertSame(2, $this->dataRowCount($csv));
    }

    public function test_followup_type_filter_narrows_the_rows(): void
    {
        $lead = $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);
        $visit = $this->todo($lead, ['assigned_to' => $this->admin->id, 'type' => 'site_visit']);
        $this->todo($lead, ['assigned_to' => $this->admin->id, 'type' => 'call']);

        $csv = $this->export('followups', 'csv', ['type' => 'site_visit'])->streamedContent();

        $this->assertStringContainsString((string) $visit->id, $csv);
        $this->assertSame(2, $this->dataRowCount($csv));
    }

    public function test_followup_project_stage_and_assignee_filters_narrow_the_rows(): void
    {
        $lead = $this->lead($this->alpha, 'site_visit_done', ['assigned_to' => $this->admin->id]);
        $this->todo($lead, ['assigned_to' => $this->admin->id]);

        $filtered = function (array $extra) {
            return $this->export('followups', 'csv', $extra);
        };

        $project = $filtered(['project_id' => $this->alpha->id])->streamedContent();
        $this->assertSame(2, $this->dataRowCount($project));

        $stage = $filtered(['stage' => 'site_visit_done'])->streamedContent();
        $this->assertSame(2, $this->dataRowCount($stage));

        $assignee = $filtered(['assigned_to' => $this->admin->id])->streamedContent();
        $this->assertSame(2, $this->dataRowCount($assignee));

        $miss = $filtered(['project_id' => $this->beta->id]);
        $this->assertSame(422, $miss->getStatusCode());
    }

    public function test_channel_partner_search_type_and_status_filters_narrow_the_rows(): void
    {
        $firm = $this->partner('firm', 'Shreeji Realty');

        $this->partner('broker', 'Ravi Kumar', ['parent_id' => $firm->id]);
        $this->partner('broker', 'Leena Shah', ['is_active' => false]);

        $search = $this->export('channel_partners', 'csv', ['search' => 'Ravi'])->streamedContent();
        $this->assertSame(2, $this->dataRowCount($search));

        $type = $this->export('channel_partners', 'csv', ['type' => 'firm'])->streamedContent();
        $this->assertSame(2, $this->dataRowCount($type));

        $inactive = $this->export('channel_partners', 'csv', ['status' => 'inactive'])->streamedContent();
        $this->assertSame(2, $this->dataRowCount($inactive));
    }

    /* ----------------------------------------------------------------------
     | Visibility
     | ------------------------------------------------------------------- */

    public function test_a_salesperson_exports_only_the_leads_they_can_see(): void
    {
        // Suresh owns this lead and works the Alpha project
        $mine = $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->sales->id]);
        // Maya owns it, Suresh does not — invisible to him
        $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->salesBoss->id]);
        // owned by Suresh but on a project he does not work — invisible to him
        $this->lead($this->beta, 'fresh', ['assigned_to' => $this->admin->id, 'created_by' => $this->admin->id]);

        $rows = $this->parseCsv($this->export('leads', 'csv', [], $this->sales)->streamedContent());

        $this->assertCount(2, $rows, 'exactly the one allowed lead, nothing else');
        $this->assertSame((string) $mine->id, $rows[1][0]);
    }

    public function test_a_salesperson_sees_no_rows_from_another_users_followups(): void
    {
        $lead = $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);
        $this->todo($lead, ['assigned_to' => $this->admin->id]);

        $this->export('followups', 'csv', [], $this->sales)->assertStatus(422);
    }

    public function test_db_count_matches_export_row_count(): void
    {
        // the SkyDeck / site_visit_done pairing the brief calls out — three
        // leads in it, one outside
        $skydeck = Project::create(['name' => 'SkyDeck', 'is_active' => true]);
        $skydeck->salespeople()->attach([$this->sales->id]);

        foreach ([1, 2, 3] as $n) {
            $this->lead($skydeck, 'site_visit_done', [
                'assigned_to' => $this->sales->id,
                'first_name' => "Deck$n",
                'last_name' => 'Lead',
            ]);
        }
        $this->lead($skydeck, 'fresh', ['assigned_to' => $this->sales->id]);

        $dbCount = Lead::visibleTo($this->sales)
            ->where('project_id', $skydeck->id)
            ->where('stage', 'site_visit_done')
            ->count();

        $csv = $this->export('leads', 'csv', [
            'project_id' => $skydeck->id,
            'stage' => 'site_visit_done',
        ], $this->sales)->streamedContent();

        $this->assertSame(3, $dbCount);
        // header + exactly the three allowed rows
        $this->assertSame($dbCount + 1, $this->dataRowCount($csv));
    }

    /* ----------------------------------------------------------------------
     | Errors and validation
     | ------------------------------------------------------------------- */

    public function test_an_empty_dataset_is_rejected_with_a_clear_message(): void
    {
        $this->export('leads', 'csv')
            ->assertStatus(422)
            ->assertJsonPath('message', 'No records match your filters. Adjust the filters and try again.');
    }

    public function test_an_invalid_format_is_rejected(): void
    {
        $this->lead($this->alpha, 'fresh');

        $response = $this->actingAs($this->admin)->post('/export-data/download', [
            'data_type' => 'leads',
            'format' => 'screenshot',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    public function test_an_invalid_data_type_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->post('/export-data/download', [
            'data_type' => 'users',
            'format' => 'csv',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    public function test_a_backwards_date_range_is_rejected(): void
    {
        $this->lead($this->alpha, 'fresh');

        $response = $this->actingAs($this->admin)->post('/export-data/download', [
            'data_type' => 'leads',
            'format' => 'csv',
            'from' => '2026-09-18',
            'to' => '2026-09-01',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'From must not be after To.');
    }

    public function test_a_partial_date_pair_is_rejected(): void
    {
        $this->lead($this->alpha, 'fresh');

        $this->actingAs($this->admin)
            ->post('/export-data/download', [
                'data_type' => 'leads',
                'format' => 'csv',
                'from' => '2026-09-01',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    /* ----------------------------------------------------------------------
     | Large exports — every format, every row
     |
     | Excel and CSV are never capped: both carry every matching row, so a
     | 500-row export and a 10,000-row export both come out whole. PDF is the
     | one that cannot be trusted with an unbounded document, so past
     | config('crm.exports.pdf_row_limit') it is not refused — it is paged:
     | each request downloads one page of at most that many rows, named by
     | its own row range, and pages line up back to back with no row repeated
     | or skipped. And the exact "Matching records" count the page previews
     | has to agree with the rows a file actually contains, filters and
     | visibility included.
     | ------------------------------------------------------------------- */

    public function test_pdf_exports_every_row_in_one_file_when_within_the_limit(): void
    {
        $this->bulkLeads($this->alpha, 100001, 500);

        $response = $this->export('leads', 'pdf');

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        // 500 rows is exactly the limit — one file, not one of several, so
        // the filename carries no row range
        $this->assertStringContainsString(
            'shaligram-leads-2026-09-18.pdf',
            $response->headers->get('Content-Disposition'),
        );

        // every one of the 500 rows actually landed in the rendered document
        $this->assertPdfCoversIds($this->pdfText($response), 100001, 500);
    }

    public function test_pdf_over_the_limit_downloads_the_first_page_instead_of_being_blocked(): void
    {
        $this->bulkLeads($this->alpha, 100001, 1049);

        $response = $this->export('leads', 'pdf');

        $response->assertOk();
        $this->assertStringContainsString(
            'shaligram-leads-2026-09-18-rows-1-500.pdf',
            $response->headers->get('Content-Disposition'),
        );

        $text = $this->pdfText($response);
        $this->assertPdfCoversIds($text, 100001, 500);
        // page 1 stops at row 500 — page 2's first row must not have leaked in
        $this->assertPdfDoesNotCoverIds($text, 100501, 1);
    }

    public function test_pdf_pages_line_up_back_to_back_with_no_overlap_or_gap(): void
    {
        $this->bulkLeads($this->alpha, 100001, 1049);

        $page1 = $this->pdfText($this->export('leads', 'pdf', ['page' => 1]));
        $page2 = $this->pdfText($this->export('leads', 'pdf', ['page' => 2]));

        // rows 1-500 (ids 100001-100500) are on page 1 and only page 1
        $this->assertPdfCoversIds($page1, 100001, 500);
        $this->assertPdfDoesNotCoverIds($page1, 100501, 500);

        // rows 501-1000 (ids 100501-101000) are on page 2 and only page 2
        $this->assertPdfCoversIds($page2, 100501, 500);
        $this->assertPdfDoesNotCoverIds($page2, 100001, 500);
    }

    public function test_pdf_final_partial_page_downloads_and_the_page_after_it_is_rejected(): void
    {
        // 1,049 rows at 500/page is 3 pages, the last one holding the 49
        // rows (1001-1049) that do not make a full page
        $this->bulkLeads($this->alpha, 100001, 1049);

        $response = $this->export('leads', 'pdf', ['page' => 3]);

        $response->assertOk();
        $this->assertStringContainsString(
            'shaligram-leads-2026-09-18-rows-1001-1049.pdf',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertPdfCoversIds($this->pdfText($response), 101001, 49);

        $this->export('leads', 'pdf', ['page' => 4])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Page 4 does not exist'));
    }

    public function test_pdf_row_limit_is_read_from_config_not_hardcoded(): void
    {
        config(['crm.exports.pdf_row_limit' => 5]);

        $this->bulkLeads($this->alpha, 100001, 6);

        // at the lowered limit: page 1 is exactly 5 rows, not all 6 — this
        // only happens if the pagination logic reads the config, not 500
        $page1 = $this->export('leads', 'pdf');
        $page1->assertOk();
        $this->assertStringContainsString('rows-1-5', $page1->headers->get('Content-Disposition'));

        // the 6th row only exists on page 2
        $page2 = $this->export('leads', 'pdf', ['page' => 2]);
        $page2->assertOk();
        $this->assertStringContainsString('rows-6-6', $page2->headers->get('Content-Disposition'));
    }

    public function test_csv_and_excel_export_all_ten_thousand_matching_rows_uncapped_while_pdf_paginates(): void
    {
        $this->bulkLeads($this->alpha, 100001, 10000);

        // PDF paginates rather than blocking — the plain request still comes
        // back with only the first page
        $pdf = $this->export('leads', 'pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('rows-1-500', $pdf->headers->get('Content-Disposition'));

        $path = $this->writeTemp($this->export('leads', 'excel')->streamedContent(), 'xlsx');
        $sheet = (new XlsxReader)->load($path)->getActiveSheet();

        // one header row, then every lead — no duplicate headers, no truncation
        $this->assertSame(10001, $sheet->getHighestDataRow());
        $this->assertSame('110000', $sheet->getCell('A10001')->getFormattedValue());

        $rows = $this->parseCsv($this->export('leads', 'csv')->streamedContent());
        $this->assertCount(10001, $rows);
        $this->assertSame('110000', $rows[10000][0]);
    }

    public function test_a_salespersons_pdf_pagination_stays_inside_their_own_visible_rows(): void
    {
        // 600 rows Suresh may see — assigned to him on a project he works —
        // enough for two PDF pages
        $this->bulkLeads($this->alpha, 100001, 600, ['assigned_to' => $this->sales->id]);
        // 600 more on a project he also works, but assigned to someone
        // else — scopeVisibleTo hides these regardless of the project
        $this->bulkLeads($this->beta, 200001, 600, ['assigned_to' => $this->admin->id]);

        $page1 = $this->export('leads', 'pdf', [], $this->sales);
        $page1->assertOk();
        $this->assertStringContainsString('rows-1-500', $page1->headers->get('Content-Disposition'));

        $page2 = $this->export('leads', 'pdf', ['page' => 2], $this->sales);
        $page2->assertOk();
        $this->assertStringContainsString('rows-501-600', $page2->headers->get('Content-Disposition'));

        // a page 3 would only exist if the 600 hidden rows had leaked into
        // his scope — bounded to just his own 600, there is no such page
        $this->export('leads', 'pdf', ['page' => 3], $this->sales)->assertStatus(422);
    }

    public function test_every_format_agrees_on_the_same_filtered_rows(): void
    {
        $this->bulkLeads($this->alpha, 100001, 12, ['stage' => 'booking_done']);
        $this->bulkLeads($this->alpha, 100013, 5, ['stage' => 'site_visit_done']);

        $db = Lead::query()->where('stage', 'booking_done')->count();
        $this->assertSame(12, $db);

        foreach (['pdf', 'excel', 'csv'] as $format) {
            $response = $this->export('leads', $format, ['stage' => 'booking_done']);
            $response->assertOk();
        }

        $this->assertPdfCoversIds($this->pdfText($this->export('leads', 'pdf', ['stage' => 'booking_done'])), 100001, 12);

        $sheet = (new XlsxReader)->load($this->writeTemp($this->export('leads', 'excel', ['stage' => 'booking_done'])->streamedContent(), 'xlsx'))->getActiveSheet();
        $this->assertSame($db + 1, $sheet->getHighestDataRow());

        $rows = $this->parseCsv($this->export('leads', 'csv', ['stage' => 'booking_done'])->streamedContent());
        $this->assertCount($db + 1, $rows);
    }

    public function test_zero_matching_rows_counts_as_zero_not_an_error(): void
    {
        // the download refuses an empty export, but the preview must show 0
        $this->countAs([])->assertOk()->assertJsonPath('count', 0);
    }

    public function test_count_reports_the_exact_rows_each_filter_would_export(): void
    {
        $this->bulkLeads($this->alpha, 100001, 40);                                                    // fresh, walk_in, in 2026-09
        $this->bulkLeads($this->alpha, 100041, 5, ['stage' => 'booking_done']);                   // booking_done, walk_in
        $this->bulkLeads($this->beta, 100046, 30, ['created_at' => '2026-08-10 10:00:00',
            'updated_at' => '2026-08-10 10:00:00']);          // old fresh walk_in
        $this->bulkLeads($this->beta, 100076, 7, ['source' => 'referral']);                     // fresh, referral

        // everything the admin may see, exactly
        $this->countAs([])->assertJsonPath('count', 82);

        // each filter narrows the preview to the same rows it would export
        $this->countAs(['project_id' => $this->beta->id])->assertJsonPath('count', 37);
        $this->countAs(['stage' => 'booking_done'])->assertJsonPath('count', 5);
        $this->countAs(['source' => 'referral'])->assertJsonPath('count', 7);

        $this->countAs([
            'stage' => 'fresh',
            'source' => 'walk_in',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertJsonPath('count', 40);
    }

    public function test_count_cares_about_visibility_the_same_way_the_download_does(): void
    {
        $this->bulkLeads($this->alpha, 100001, 7, ['assigned_to' => $this->sales->id]);
        $this->bulkLeads($this->alpha, 100008, 3, ['assigned_to' => $this->admin->id]);
        $this->bulkLeads($this->beta, 100011, 12, ['assigned_to' => $this->sales->id]);

        // Suresh sees his own 19 across both projects; the 3 owned by Ann never
        // leak into the preview — the same boundary the download enforces
        $this->countAs([], $this->sales)->assertJsonPath('count', 19);
        $this->countAs(['project_id' => $this->alpha->id], $this->sales)->assertJsonPath('count', 7);
    }

    public function test_count_requires_the_export_permission(): void
    {
        $telecaller = $this->user('telecaller', 'Tara');

        $this->actingAs($telecaller)
            ->postJson('/export-data/count', ['data_type' => 'leads'])
            ->assertForbidden();
    }

    /* ----------------------------------------------------------------------
     | Column fidelity
     | ------------------------------------------------------------------- */

    public function test_mobile_numbers_survive_excel_as_text(): void
    {
        $this->lead($this->alpha, 'fresh', ['mobile_number' => '9822001122']);

        $path = $this->writeTemp($this->export('leads', 'excel')->streamedContent(), 'xlsx');

        $sheet = (new XlsxReader)->load($path)->getActiveSheet();

        // Lead ID, First Name, Last Name, then Mobile Number in column D
        $this->assertSame('9822001122', $sheet->getCell('D2')->getFormattedValue());
        $this->assertSame('s', $sheet->getCell('D2')->getDataType());
    }

    public function test_mobile_numbers_survive_csv_with_a_text_guard(): void
    {
        $this->lead($this->alpha, 'fresh', ['mobile_number' => '9822001122']);

        $rows = $this->parseCsv($this->export('leads', 'csv')->streamedContent());

        // the apostrophe is Excel's "keep as text" marker on the bare 10 digits
        $this->assertSame("'9822001122", $rows[1][3]);
    }

    public function test_csv_escapes_commas_quotes_and_newlines(): void
    {
        $this->lead($this->alpha, 'fresh', [
            'last_name' => "D'Souza, \"JD\"\nLine2",
            'first_name' => 'John',
        ]);

        $rows = $this->parseCsv($this->export('leads', 'csv')->streamedContent());

        // one header + one row, even though the name contains a comma, quotes
        // and a newline — an unescaped CSV would have split into more records
        $this->assertCount(2, $rows, 'CSV must parse as one logical row despite embedded separators');

        // and the escaped value parses back to exactly what was stored
        $this->assertSame('John', $rows[1][1]);
        $this->assertSame("D'Souza, \"JD\"\nLine2", $rows[1][2]);
    }

    public function test_pdf_head_partial_renders_a_structured_table_header(): void
    {
        $this->lead($this->alpha, 'site_visit_done', [
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => '9822001122',
        ]);

        $html = view('exports.pdf_head', [
            'type' => 'Leads',
            'columns' => [
                ['key' => 'id', 'label' => 'Lead ID'],
                ['key' => 'first_name', 'label' => 'First Name'],
                ['key' => 'mobile_number', 'label' => 'Mobile Number', 'text' => true],
            ],
            'filters' => [],
            'generated' => '2026-09-18 12:00:00',
            'count' => 1,
            'page' => 1,
            'totalPages' => 1,
            'rangeStart' => 1,
            'rangeEnd' => 1,
            'landscape' => true,
        ])->render();

        $this->assertStringContainsString('Shaligram', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('Lead ID', $html);
        $this->assertStringContainsString('Total rows', $html);
        $this->assertStringContainsString('{PAGE_NUM} / {PAGE_COUNT}', $html);
        // a single-page export names a row count, not a file's own range —
        // there being only the one file, the range would say nothing new
        $this->assertStringNotContainsString('This file', $html);
    }

    public function test_pdf_head_partial_shows_the_files_own_row_range_when_paginated(): void
    {
        $html = view('exports.pdf_head', [
            'type' => 'Leads',
            'columns' => [
                ['key' => 'id', 'label' => 'Lead ID'],
            ],
            'filters' => [],
            'generated' => '2026-09-18 12:00:00',
            'count' => 2500,
            'page' => 2,
            'totalPages' => 5,
            'rangeStart' => 501,
            'rangeEnd' => 1000,
            'landscape' => false,
        ])->render();

        $this->assertStringContainsString('This file', $html);
        $this->assertStringContainsString('501', $html);
        $this->assertStringContainsString('1,000', $html);
        $this->assertStringContainsString('2,500', $html);
        $this->assertStringContainsString('page 2 of 5', $html);
    }

    public function test_the_pdf_response_is_a_well_formed_document(): void
    {
        $this->lead($this->alpha, 'fresh');

        $response = $this->export('leads', 'pdf');

        // dompdf renders to a plain Response body, not a stream — read it back
        $content = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringEndsWith('%%EOF', $content);
    }

    /* ----------------------------------------------------------------------
     | No N+1
     | ------------------------------------------------------------------- */

    public function test_a_leads_export_does_not_query_per_row(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->lead($this->alpha, 'fresh', ['assigned_to' => $this->admin->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->export('leads', 'csv');

        $queries = count(DB::getQueryLog());

        // a handful of fixed queries (count, one chunk, its eager loads,
        // the taxonomy peek at most once) — never one per row
        $this->assertLessThanOrEqual(15, $queries, 'Export ran '.$queries.' queries for 20 leads');
    }

    /* ----------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function export(string $type, string $format, array $filters = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->post('/export-data/download', $filters + [
            'data_type' => $type,
            'format' => $format,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], ['Accept' => 'application/json']);
    }

    private function dataRowCount(string $csv): int
    {
        return count(array_filter(explode("\n", trim($csv)), fn ($line) => $line !== ''));
    }

    /** Parse a CSV body the way a spreadsheet program would, not by splitting
     * on line breaks (values may contain them, quoted). */
    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function writeTemp(string $content, string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export-test').'.'.$ext;
        file_put_contents($path, $content);

        return $path;
    }

    /** POST the filter payload to the Matching-records count endpoint. */
    private function countAs(array $filters, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)
            ->postJson('/export-data/count', $filters + ['data_type' => 'leads']);
    }

    /**
     * The text a dompdf document embeds, decompressed from its FlateDecode
     * streams — enough to prove a specific row made it into the PDF.
     */
    private function pdfText(TestResponse $response): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', (string) $response->getContent(), $matches);

        $text = '';

        foreach ($matches[1] as $data) {
            $decoded = @gzuncompress($data);

            if ($decoded === false) {
                $decoded = @gzinflate($data);
            }

            if ($decoded !== false) {
                /* dompdf embeds text as UTF-16LE — every character followed by
                 * a NUL byte — while the drawing operators around it stay
                 * plain ASCII. Collapsing those char+NUL pairs leaves the
                 * searchable text runs intact next to the untouched operators. */
                $text .= preg_replace('/(.)\x00/s', '$1', $decoded);
            }
        }

        return $text;
    }

    /**
     * Assert every row id in [$startId, $startId + $count) appears in the
     * extracted PDF text — i.e. the whole dataset rendered, not just a probe.
     * One pass over the text collects every digit token; the ids are then a
     * membership check. Ids are 6-digit (100001..110000); the 10-digit mobile
     * numbers and 9-digit coordinates fall out of the range trivially.
     */
    private function assertPdfCoversIds(string $text, int $startId, int $count): void
    {
        preg_match_all('/[0-9]+/', $text, $matches);
        $tokens = array_flip($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $this->assertArrayHasKey((string) ($startId + $i), $tokens, 'The PDF should contain lead '.($startId + $i).'.');
        }
    }

    /**
     * The mirror of assertPdfCoversIds: proves none of [$startId, $startId +
     * $count) leaked into a page that should stop short of them — what makes
     * "page 2 doesn't repeat page 1's rows" a checkable fact rather than an
     * assumption.
     */
    private function assertPdfDoesNotCoverIds(string $text, int $startId, int $count): void
    {
        preg_match_all('/[0-9]+/', $text, $matches);
        $tokens = array_flip($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $this->assertArrayNotHasKey((string) ($startId + $i), $tokens, 'The PDF should not contain lead '.($startId + $i).'.');
        }
    }

    /**
     * Insert $count leads with deterministic ids (starting at $startId) so a
     * specific export's rows can be probed by id; batched so the many-row
     * tests stay fast.
     */
    private function bulkLeads(Project $project, int $startId, int $count, array $extra = []): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = $startId + $i;

            $rows[] = $extra + [
                'id' => $id,
                'first_name' => 'Bulk',
                'last_name' => (string) $id,
                'mobile_number' => (string) (7000000000 + $id),
                'project_id' => $project->id,
                'source' => 'walk_in',
                'stage' => 'fresh',
                'assigned_to' => $this->admin->id,
                'created_by' => $this->admin->id,
                'created_at' => '2026-09-18 12:00:00',
                'updated_at' => '2026-09-18 12:00:00',
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('leads')->insert($chunk);
        }
    }

    private function user(string $role, string $name, array $extra = []): User
    {
        return User::create($extra + [
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => strtolower($name).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function lead(Project $project, string $stage, array $extra = []): Lead
    {
        return Lead::create($extra + [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $project->id,
            'source' => 'walk_in',
            'stage' => $stage,
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function todo(Lead $lead, array $extra = []): Todo
    {
        return Todo::create($extra + [
            'lead_id' => $lead->id,
            'assigned_to' => $this->admin->id,
            'scheduled_at' => '2026-09-16 10:00:00',
            'type' => 'call',
            'status' => 'pending',
        ]);
    }

    private function partner(string $type, string $name, array $extra = []): ChannelPartner
    {
        return ChannelPartner::create($extra + [
            'name' => $name,
            'type' => $type,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }
}
