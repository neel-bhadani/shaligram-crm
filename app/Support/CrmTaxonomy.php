<?php

namespace App\Support;

use App\Models\LeadSource;
use App\Models\LeadStage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The pipeline vocabulary — every stage and every source — read once and shared
 * by everything that used to call `config('crm.stages')`.
 *
 * ---------------------------------------------------------------------------
 * Why this is a cache and not a query
 * ---------------------------------------------------------------------------
 *
 * The old config calls were free. StageBadge's labels, the zero-fill on four
 * charts, the terminal test inside Lead::open(), the validation rule on two
 * forms — a single dashboard request touches this vocabulary dozens of times,
 * and Lead::open() is called from inside loops. Turning each of those into a
 * SELECT is how a page that rendered in 80ms starts taking 400. So both tables
 * are read whole, once, into one cache entry, and every write to either model
 * busts it — see LeadStage::booted().
 *
 * Two layers, deliberately. `Cache::rememberForever` is the one that survives
 * between requests; `self::$memo` is the one that survives inside a request,
 * because even a cache hit is a round trip to Redis or to the `cache` table and
 * a dashboard would make fifty of them.
 *
 * ---------------------------------------------------------------------------
 * Why config is still here
 * ---------------------------------------------------------------------------
 *
 * `config/crm.php` keeps its `stages`, `stage_colors`, `terminal_stages` and
 * `sources` blocks, and this class falls back to them whenever the tables are
 * missing or empty. That is not belt-and-braces, it is what lets the
 * application boot during its own migration: `php artisan migrate` on a fresh
 * database runs framework code that resolves this class before the tables it
 * reads exist, and an installer that has to be run in a particular order to
 * come up at all is an installer that goes wrong on somebody's laptop.
 *
 * ---------------------------------------------------------------------------
 * Active, or all?
 * ---------------------------------------------------------------------------
 *
 * The distinction that matters most in this file, and the one that decides
 * whether switching a stage off loses data:
 *
 *   - ACTIVE is what may be CHOSEN. Dropdowns, the option lists on the two
 *     forms, the write-side validation rules.
 *   - ALL is what may be READ. Labels, colours, the terminal test, the report
 *     groupings. A lead sitting in a retired stage still has to render with
 *     that stage's name and colour, and last quarter's report still has to
 *     count it.
 *
 * Getting that backwards is the whole failure mode of this feature, so the two
 * are different methods with different names rather than one method with a
 * boolean.
 */
class CrmTaxonomy
{
    // v2: stage rows carry `owner_role`. A new key, so a cache warmed before
    // the column existed is never read back as "no stage has a desk"
    private const CACHE_KEY = 'crm.taxonomy.v2';

    /** @var array{stages: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>}|null */
    private static ?array $memo = null;

    /* ====================================================================
     | Loading
     ==================================================================== */

    /**
     * @return array{stages: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>}
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            $data = Cache::rememberForever(self::CACHE_KEY, fn () => self::fromDatabase());
        } catch (Throwable) {
            // no cache store yet (a console command running before the cache
            // table exists) — the vocabulary still has to be answerable
            $data = self::fromDatabase();
        }

        return self::$memo = $data;
    }

    /**
     * @return array{stages: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>}
     */
    private static function fromDatabase(): array
    {
        try {
            if (! Schema::hasTable('lead_stages') || ! Schema::hasTable('lead_sources')) {
                return self::fromConfig();
            }

            $stages = LeadStage::ordered()->get()->map(fn (LeadStage $s) => [
                'key' => $s->key,
                'label' => $s->label,
                'color' => $s->color,
                'sort_order' => $s->sort_order,
                'is_terminal' => $s->is_terminal,
                'owner_role' => $s->owner_role,
                'is_system' => $s->is_system,
                'is_active' => $s->is_active,
            ])->all();

            $sources = LeadSource::ordered()->get()->map(fn (LeadSource $s) => [
                'key' => $s->key,
                'label' => $s->label,
                'sort_order' => $s->sort_order,
                'default_stage_key' => $s->default_stage_key,
                'default_owner_role' => $s->default_owner_role,
                'is_system' => $s->is_system,
                'is_active' => $s->is_active,
            ])->all();
        } catch (Throwable) {
            return self::fromConfig();
        }

        /*
         | An empty table is a half-installed application, not a company with no
         | stages: nothing in the UI can produce one, because the last active
         | stage cannot be switched off and a system stage cannot be deleted.
         | Falling back keeps every screen rendering while somebody works out
         | why the seed did not run.
         */
        if ($stages === [] || $sources === []) {
            $fallback = self::fromConfig();

            return [
                'stages' => $stages === [] ? $fallback['stages'] : $stages,
                'sources' => $sources === [] ? $fallback['sources'] : $sources,
                'store' => 'config',
            ];
        }

        return ['stages' => $stages, 'sources' => $sources, 'store' => 'database'];
    }

    /**
     * @return array{stages: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>}
     */
    private static function fromConfig(): array
    {
        $terminal = (array) config('crm.terminal_stages', []);
        $order = 0;

        $stages = collect((array) config('crm.stages', []))
            ->map(fn ($label, $key) => [
                'key' => (string) $key,
                'label' => (string) $label,
                'color' => (string) config("crm.stage_colors.$key", '#8A94A0'),
                'sort_order' => $order += 10,
                'is_terminal' => in_array($key, $terminal, true),
                'owner_role' => in_array($key, $terminal, true) ? null : self::seededOwnerRole((string) $key),
                'is_system' => true,
                'is_active' => true,
            ])
            ->values()
            ->all();

        $order = 0;

        $sources = collect((array) config('crm.sources', []))
            ->map(fn ($label, $key) => [
                'key' => (string) $key,
                'label' => (string) $label,
                'sort_order' => $order += 10,
                'default_stage_key' => config("crm.source_defaults.$key.stage"),
                'default_owner_role' => config("crm.source_defaults.$key.owner_role"),
                'is_system' => $key === 'broker',
                'is_active' => true,
            ])
            ->values()
            ->all();

        return ['stages' => $stages, 'sources' => $sources, 'store' => 'config'];
    }

    /**
     * Whether the vocabulary in hand came from the two tables or from the
     * config fallback.
     *
     * The one thing that actually needs to know is validation. A rule written
     * as `Rule::exists('lead_stages', ...)` against a table that is missing or
     * empty refuses every stage there is, which would turn a half-installed
     * application from "renders, with the old nine stages" into "no lead can be
     * saved at all" — see ValidatesTaxonomy.
     */
    public static function usingDatabase(): bool
    {
        return (self::all()['store'] ?? 'config') === 'database';
    }

    /**
     * Forget the vocabulary, in both layers.
     *
     * Called from LeadStage and LeadSource on every save and delete, and from
     * the migration that seeds them — a `migrate:fresh` over a warm cache would
     * otherwise serve the previous database's stages.
     */
    public static function flush(): void
    {
        self::$memo = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // no cache store to forget from; the memo above was the only copy
        }
    }

    /* ====================================================================
     | Stages
     ==================================================================== */

    /** @return array<int, array<string, mixed>> every stage row, in sort order */
    public static function stageRows(): array
    {
        return self::all()['stages'];
    }

    /**
     * What may be CHOSEN: active stages, in order, key => label.
     * This is the replacement for `config('crm.stages')` in a dropdown.
     *
     * @return array<string, string>
     */
    public static function stages(): array
    {
        return collect(self::stageRows())
            ->filter(fn (array $s) => $s['is_active'])
            ->mapWithKeys(fn (array $s) => [$s['key'] => $s['label']])
            ->all();
    }

    /**
     * What may be READ: every stage, active or not, in order, key => label.
     * This is the replacement for `config('crm.stages')` in a label lookup.
     *
     * @return array<string, string>
     */
    public static function allStages(): array
    {
        return collect(self::stageRows())
            ->mapWithKeys(fn (array $s) => [$s['key'] => $s['label']])
            ->all();
    }

    /** @return list<string> */
    public static function stageKeys(): array
    {
        return array_keys(self::allStages());
    }

    /** @return list<string> */
    public static function activeStageKeys(): array
    {
        return array_keys(self::stages());
    }

    /**
     * A stage's own words, falling back to the raw key.
     *
     * The fallback is not decoration. `todos.outcome_stage` is history and can
     * name a stage that was deleted back when nothing pointed at it; printing
     * the key is ugly and honest, printing nothing is a blank cell nobody can
     * explain.
     */
    public static function stageLabel(?string $key): string
    {
        return self::allStages()[$key] ?? (string) $key;
    }

    /** @return array<string, string> every stage's colour, active or not */
    public static function stageColors(): array
    {
        return collect(self::stageRows())
            ->mapWithKeys(fn (array $s) => [$s['key'] => $s['color']])
            ->all();
    }

    /**
     * The stages that end a lead's journey — what `config('crm.terminal_stages')`
     * used to be, now derived from `is_terminal`.
     *
     * EVERY row, not only the active ones, and that is load-bearing. A lead that
     * booked before somebody retired the Booking done stage is still booked;
     * reading only the active rows would put it back into Lead::open(), give it
     * a pending to-do it must never have, and break the one invariant this
     * application has.
     *
     * @return list<string>
     */
    public static function terminalStages(): array
    {
        return collect(self::stageRows())
            ->filter(fn (array $s) => $s['is_terminal'])
            ->pluck('key')
            ->values()
            ->all();
    }

    public static function isTerminal(?string $key): bool
    {
        return in_array($key, self::terminalStages(), true);
    }

    /**
     * The desk a new lead at this stage is given to — `telecaller` or
     * `salesperson` — or null for a terminal stage, which gives it to nobody
     * new. LeadAssignmentService is what turns this into a person.
     *
     * An open stage always answers. A row whose `owner_role` is empty (the
     * column was added after the row, or the key names a stage no row knows)
     * falls back to the seeded rule rather than to "nobody", because an open
     * lead with nobody's name on it is on nobody's list.
     */
    public static function ownerRoleFor(?string $key): ?string
    {
        if (self::isTerminal($key)) {
            return null;
        }

        $row = collect(self::stageRows())->firstWhere('key', $key);

        return ($row['owner_role'] ?? null) ?: self::seededOwnerRole((string) $key);
    }

    /** What `crm.stage_owner_roles` says for an open stage — the mapping as first seeded. */
    public static function seededOwnerRole(string $key): string
    {
        return (string) (config('crm.stage_owner_roles')[$key]
            ?? config('crm.stage_owner_role_default', 'salesperson'));
    }

    /**
     * Every stage's own answer to ownerRoleFor(), as one map, for a frontend
     * that has to resolve a stage it has not saved yet — the reassign form
     * lets a stage and a person be picked together, and the person list has
     * to follow the stage a user has just clicked without a round trip to ask
     * this class again.
     *
     * @return array<string, string|null>
     */
    public static function stageOwnerRoles(): array
    {
        return collect(self::stageRows())
            ->mapWithKeys(fn (array $s) => [$s['key'] => self::ownerRoleFor($s['key'])])
            ->all();
    }

    /**
     * The stage that hands a lead from a telecaller to a salesperson.
     *
     * Still a config value, and deliberately: it is a POINTER at a stage rather
     * than a stage, one scalar naming which of the rows carries a behaviour
     * written in PHP. What the tables add is the guarantee that it points at
     * something real — LeadStageController refuses to deactivate whatever this
     * names, and the migration marks it `is_system` so it cannot be deleted or
     * re-keyed either.
     */
    public static function handoverStage(): ?string
    {
        $key = config('crm.handover_stage');

        return $key === null ? null : (string) $key;
    }

    /**
     * The stage list a CHART should be drawn over: the active stages in order,
     * plus any retired stage that leads or history rows are actually sitting
     * in, appended in its own sort position.
     *
     * The second half is what stops a deactivation from losing a number. Every
     * stage chart in the application sums its own bars to produce the total
     * printed above it — see DashboardController::stagesByLead() — so a stage
     * dropped from the axis while thirty leads stand in it does not draw a
     * shorter chart, it draws a chart whose header is thirty short of the truth.
     * ReportController has always done this with its `$present` net; this is the
     * same idea, offered to everything else.
     *
     * @param  iterable<int|string, mixed>  $present  keys the query actually returned
     * @return array<string, string>
     */
    public static function stageUniverse(iterable $present = []): array
    {
        return self::universe(self::stageRows(), $present);
    }

    /* ====================================================================
     | Sources
     ==================================================================== */

    /** @return array<int, array<string, mixed>> */
    public static function sourceRows(): array
    {
        return self::all()['sources'];
    }

    /** @return array<string, string> active only — for dropdowns */
    public static function sources(): array
    {
        return collect(self::sourceRows())
            ->filter(fn (array $s) => $s['is_active'])
            ->mapWithKeys(fn (array $s) => [$s['key'] => $s['label']])
            ->all();
    }

    /** @return array<string, string> every source — for labels and reports */
    public static function allSources(): array
    {
        return collect(self::sourceRows())
            ->mapWithKeys(fn (array $s) => [$s['key'] => $s['label']])
            ->all();
    }

    /** @return list<string> */
    public static function sourceKeys(): array
    {
        return array_keys(self::allSources());
    }

    /** @return list<string> */
    public static function activeSourceKeys(): array
    {
        return array_keys(self::sources());
    }

    public static function sourceLabel(?string $key): string
    {
        return self::allSources()[$key] ?? (string) $key;
    }

    /**
     * @param  iterable<int|string, mixed>  $present
     * @return array<string, string>
     */
    public static function sourceUniverse(iterable $present = []): array
    {
        return self::universe(self::sourceRows(), $present);
    }

    /* ====================================================================
     | Shared
     ==================================================================== */

    /**
     * Active rows in sort order, plus the inactive ones the data still uses,
     * each in its own sort position rather than tacked on the end — a retired
     * stage between two live ones belongs between them on the axis.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  iterable<int|string, mixed>  $present
     * @return array<string, string>
     */
    private static function universe(array $rows, iterable $present): array
    {
        $present = collect($present)->map(fn ($v) => (string) $v)->all();

        $universe = collect($rows)
            ->filter(fn (array $r) => $r['is_active'] || in_array($r['key'], $present, true))
            ->mapWithKeys(fn (array $r) => [$r['key'] => $r['label']])
            ->all();

        /*
         | A key the data holds that no row explains — a stage hard-deleted back
         | when nothing pointed at it, or a value written by an import. Labelled
         | with itself and kept, because these lists are summed to produce the
         | totals printed above them and a dropped key is a number that no longer
         | adds up.
         */
        foreach ($present as $key) {
            if ($key !== '' && ! array_key_exists($key, $universe)) {
                $universe[$key] = $key;
            }
        }

        return $universe;
    }
}
