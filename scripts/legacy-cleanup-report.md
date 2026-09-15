# Legacy JSON cleanup — 2026-09-15

Workspace: `/home/etech7/lead-crm1` (`~/lead-crm` was not present).

## Analysis before changes

`created_at` is present and valid on every lead. Required fields were checked
for null, missing values, empty strings, and whitespace-only strings without
changing those values. Only non-2026 leads with any empty required field were
removed.

| Measure | Count |
|---|---:|
| Original leads | 16,139 |
| 2026 leads protected | 8,039 |
| Non-2026 leads | 8,100 |
| Empty first_name | 12 |
| Empty last_name | 1,727 |
| Empty mobile_number | 94 |
| More than one empty required field | 26 |
| Invalid 2026 leads protected | 939 |
| Invalid older leads removed | 868 |
| Remaining leads | 15,271 |

## Changes

| File | Before | Removed | After |
|---|---:|---:|---:|
| leads.json | 16,139 | 868 leads | 15,271 |
| todos.json | 12,289 | 1,144 todos | 11,145 |
| duplicates.json | 3,803 groups | 21 empty groups; 262 members across 221 affected groups | 3,782 groups |
| channel_partners.json | 546 partners | 53 lead references across 42 partners; no partners | 546 partners |

Todos link exclusively through `_lead_import_key` to the lead `_import_key`,
as confirmed in `LegacyImportPlan::assemble`. Of the removed todos, 309 have
2024 created_at, 810 have 2025 created_at, 24 have 2026 created_at, and one has
null created_at. The user explicitly authorized deleting those 24 child todos
with their invalid older parent leads. No 2026 **lead** was deleted.

Duplicate group `import_keys` and nested `rows[].import_key` refer to leads;
these relationships were confirmed in `old-data/build.py` (which was only
read, never executed). Removed only members for removed leads, and groups
with no remaining members. Singleton groups remain; no deduplication or
reranking was performed. Retained duplicate member records are unchanged.

Channel partners retain their records and all other fields; only deleted
lead keys were removed from `lead_import_keys`.

### Lead deletions by year

| Year | Removed |
|---|---:|
| 2024 | 311 |
| 2025 | 557 |
| 2026 | 0 |

### Other JSON files inspected

`users.json` contains user reference records with proposed names, rather than
the three required fields. `channel_partners.json` uses name/phone fields,
rather than the applicable lead schema. Neither received the name/mobile
filter. Projects, sources, stages, and lost reasons are reference data.
`report.json` is the original build report, not a person dataset. These files
were not regenerated or cleaned by the lead rule.

Historical aggregates in the original report, reference `lead_count` fields,
duplicate group summaries, and lead duplicate ranks/counts remain as originally
written. They describe the original dataset and may no longer match the
filtered membership. Recalculating them would exceed this removal-only task.

## Backups and script

Each changed file has a byte-for-byte original backup named
`old-data/<name>.before-cleanup.json`. All four backups were verified against
the original Git HEAD. No pre-existing backup was overwritten.

`scripts/clean_legacy_data.py` defaults to analysis only. Applied with:

```bash
python3 scripts/clean_legacy_data.py --apply --remove-2026-child-todos
```

The script stages all outputs, parses and validates them, checks that only
authorized records/references were removed, creates exclusive backups, then
atomically replaces each intended file. All retained lead and todo record
text is preserved. Shared records lose only the specified references.

## Verification

- Every old-data JSON file, including backups, parses successfully.
- All 8,039 leads from 2026 remain identical to the originals.
- All retained lead and todo records remain unchanged.
- No dangling todo, duplicate-member, or partner lead references remain.
- Importer's in-memory plan: 15,271 leads, 11,145 todos, zero errors.
- Database preflight: zero errors; 378 read queries; zero database writes.
- Preflight ran directly under a MySQL read-only transaction with a query
  guard rejecting non-read statements. Cache activity stayed in memory;
  scheduler status was read directly without expiring database cache entries.
- Preflight reported 16 warnings and the real-import blocker that the scheduler
  is not paused. Scheduler state was left unchanged.
- `git diff --check` passed.
- Per the user's clarification, `import:legacy --dry-run` was skipped because
  it executes inserts before rollback. No real import, migrations, source
  rebuild, database mutation, commit, or push was performed.
