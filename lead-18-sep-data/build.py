#!/usr/bin/env python3
"""
Build lead-18-sep-data/leads.json and lead-18-sep-data/report.json from
Lead 18 Sep.xls, following the same conversion pattern as old-data/build.py
(_legacy preservation, _import_key idempotency, _filled/_flags, duplicate
groups) - scaled down for one small file instead of the four-source merge.

old-data/ is READ ONLY here - used only as a live reference for the JSON
shape and for cross-checking project keys (old-data/projects.json). Nothing
in old-data/ is written or re-run; that batch is already imported.

Reads  Lead 18 Sep.xls              (read only)
       old-data/projects.json       (read only - key/name cross-check)
Writes lead-18-sep-data/leads.json
       lead-18-sep-data/duplicates.json
       lead-18-sep-data/report.json

Nothing is inserted anywhere; no application code is touched. This is the
JSON-only step. The actual import command comes after the report below is
reviewed and the assignment/duplicate questions are answered.

_import_key uses the "lead-18-sep:row-N" form throughout - deliberately
different in shape from old-data's "master_sheet:N" / "myco:N" /
"12_sep_lead:N" keys, so this batch's idempotency can never collide with,
or be confused with, the earlier one.

Standard library only except xlrd. Re-run with:
    python3 -B lead-18-sep-data/build.py
"""

import collections
import json
import os
import re
import subprocess
import sys

import xlrd

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
XLS_PATH = os.path.join(ROOT, "Lead 18 Sep.xls")
OLD_DATA = os.path.join(ROOT, "old-data")  # read-only reference

SOURCE_FILE = "lead_18_sep"          # _source_file value on every row
IMPORT_KEY_PREFIX = "lead-18-sep"    # _import_key = "lead-18-sep:row-N"

# ---------------------------------------------------------------------------
# Stage mapping - the brief's table. Every target value already exists in
# config/crm.php (checked below in main()); nothing new is proposed.
# ---------------------------------------------------------------------------
STAGE_MAP = {
    "Fresh": ("fresh", None),
    "Not Connected": ("not_connected", None),
    "Details Share": ("details_shared", None),
    "Lost (Choice not available)": ("lost", "choice_unavailable"),
    "Lost (Duplicate lead)": ("lost", "duplicate"),
    "Lost (Location Issue)": ("lost", "location_issue"),
}

SOURCE_KEY = "facebook"  # all 55 rows

# stage -> role, mirroring config('crm.stage_owner_roles') /
# stage_owner_role_default (fresh/not_connected -> telecaller; everything
# else, including details_shared and the terminal "lost", -> salesperson)
TELECALLER = "Riya Gandhi"
PROJECT_KEY_BY_FILE_NAME = {
    "skydeck": "skydeck",
    "felicity": "felicity",
}

# ---------------------------------------------------------------------------
# Decisions confirmed by the client (2026-09-22), overriding what a fresh
# run would otherwise propose/flag for confirmation:
#
#   1. Assignment: every lead, whatever its stage, goes to the telecaller
#      Riya Gandhi - not the stage-owner-role split (fresh/not_connected ->
#      telecaller, details_shared/lost -> project salesperson) that a plain
#      read of config('crm.stage_owner_roles') would otherwise produce, and
#      not the file's own sales_person column. That column is preserved in
#      _legacy regardless.
#   2. Duplicates: any row that would break unique(mobile_number, project_id)
#      - either against another row in this same file, or against a lead
#      already in the database from the earlier /old-data import - is
#      dropped, not imported. Nothing is merged, updated, or overwritten;
#      the existing lead is left exactly as it is. All 20 such rows happen
#      to be ones where the conflicting DB lead already exists, so the
#      3 in-file same-project duplicate groups are entirely a subset of
#      these 20 - no row is double-counted as skipped.
# ---------------------------------------------------------------------------


def split_name(full_name_str):
    """Same rule as old-data/build.py:split_name."""
    clean = re.sub(r"\s+", " ", (full_name_str or "").strip())
    if not clean:
        return "", "", ""
    parts = clean.split(" ")
    if len(parts) == 1:
        return parts[0], "", ""
    if len(parts) == 2:
        return parts[0], "", parts[1]
    if len(parts) == 3:
        return parts[0], parts[1], parts[2]
    return " ".join(parts[:-2]), parts[-2], parts[-1]


def xldate(value, datemode):
    dt = xlrd.xldate_as_datetime(value, datemode)
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def compact_json(value, indent=0):
    """Same pretty-printer as old-data/build.py: nested objects indented,
    short scalar lists kept on one line."""
    pad = "  " * indent
    inner = "  " * (indent + 1)
    if isinstance(value, dict):
        if not value:
            return "{}"
        items = [
            f"{inner}{json.dumps(k if isinstance(k, str) else str(k), ensure_ascii=False)}: "
            f"{compact_json(v, indent + 1)}"
            for k, v in value.items()
        ]
        return "{\n" + ",\n".join(items) + "\n" + pad + "}"
    if isinstance(value, list):
        if not value:
            return "[]"
        inline = "[" + ", ".join(json.dumps(v, ensure_ascii=False) for v in value) + "]"
        if all(isinstance(v, (int, float)) or v is None for v in value):
            return inline
        if all(not isinstance(v, (dict, list)) for v in value) and len(inline) <= 120:
            return inline
        items = [f"{inner}{compact_json(v, indent + 1)}" for v in value]
        return "[\n" + ",\n".join(items) + "\n" + pad + "]"
    return json.dumps(value, ensure_ascii=False)


def write(name, data, compact=False):
    path = os.path.join(HERE, name)
    with open(path, "w", encoding="utf-8") as handle:
        if compact:
            handle.write(compact_json(data) + "\n")
        else:
            json.dump(data, handle, indent=2, ensure_ascii=False)
            handle.write("\n")


def check_projects_against_reference():
    """Cross-check Skydeck/Felicity against old-data/projects.json purely as
    a read-only sanity check - old-data is never written here."""
    projects = json.load(open(os.path.join(OLD_DATA, "projects.json"), encoding="utf-8"))
    by_key = {p["key"]: p["name"] for p in projects}
    for key in ("skydeck", "felicity"):
        if key not in by_key:
            raise SystemExit(f"reference check failed: old-data/projects.json has no {key!r} project key")
    return by_key


def existing_db_conflicts(rows):
    """
    Ask the app (via tinker) which (mobile, project) pairs from this file
    already exist in leads - live-database read, not guessed - so a
    unique(mobile_number, project_id) violation is caught before import,
    not at insert time. Read only; writes nothing.

    Returns {(mobile, project_key): {"lead_id", "stage", "created_at",
    "name", "deleted_at"}}.
    """
    project_id_by_key = {"skydeck": 771, "felicity": 770}
    mobiles = sorted({r["mobile"] for r in rows})
    php = f"""
    $mobiles = json_decode('{json.dumps(mobiles)}', true);
    $rows = App\\Models\\Lead::withTrashed()->whereIn('mobile_number', $mobiles)
        ->get(['id','mobile_number','project_id','first_name','last_name','stage','created_at','deleted_at']);
    echo $rows->map(fn($r) => [
        'id' => $r->id, 'mobile_number' => $r->mobile_number, 'project_id' => $r->project_id,
        'name' => trim($r->first_name.' '.$r->last_name), 'stage' => $r->stage,
        'created_at' => (string) $r->created_at, 'deleted_at' => $r->deleted_at ? (string) $r->deleted_at : null,
    ])->toJson();
    """
    result = subprocess.run(
        ["php", "artisan", "tinker", "--execute", php],
        cwd=ROOT, capture_output=True, text=True, check=True,
    )
    db_rows = json.loads(result.stdout.strip().splitlines()[-1])
    key_by_pid = {v: k for k, v in project_id_by_key.items()}
    conflicts = {}
    for r in db_rows:
        pkey = key_by_pid.get(r["project_id"])
        if pkey:
            conflicts[(r["mobile_number"], pkey)] = r
    return conflicts


def main():
    check_projects_against_reference()

    wb = xlrd.open_workbook(XLS_PATH)
    sh = wb.sheet_by_index(0)
    header = [sh.cell_value(0, c) for c in range(sh.ncols)]
    idx = {h: i for i, h in enumerate(header)}

    def val(row, name):
        return sh.cell_value(row, idx[name])

    raw_rows = []
    for r in range(1, sh.nrows):
        row_number = r + 1  # 1-indexed, header is row 1
        mobile_cell = val(r, "mobile")
        mobile = str(int(mobile_cell)) if mobile_cell != "" else ""
        raw_rows.append({
            "_row_number": row_number,
            "client_name": str(val(r, "client_name") or "").strip(),
            "mobile": mobile,
            "mobile2": val(r, "mobile2"),
            "project": str(val(r, "project") or "").strip(),
            "unitrequirement": val(r, "unitrequirement"),
            "lead_source": str(val(r, "lead_source") or "").strip(),
            "lead_sub_status": str(val(r, "lead_sub_status") or "").strip(),
            "sales_person": str(val(r, "sales_person") or "").strip(),
            "created_at": xldate(val(r, "created_at"), wb.datemode),
        })

    assert len(raw_rows) == 55, f"expected 55 rows, got {len(raw_rows)}"

    leads = []
    stage_counts = collections.Counter()
    project_counts = collections.Counter()
    mobile_length_flags = []
    mobile2_nonblank = 0

    for row in raw_rows:
        filled, flags = [], []

        first_name, middle_name, last_name = split_name(row["client_name"])
        if not row["client_name"]:
            flags.append("no_name")
        elif re.search(r"\d", row["client_name"]):
            flags.append("name_contains_digits")

        mobile = row["mobile"]
        if len(mobile) != 10:
            flags.append("mobile_not_10_digits")
            mobile_length_flags.append((row["_row_number"], mobile))

        mobile2_raw = row["mobile2"]
        if str(mobile2_raw).strip() not in ("",):
            mobile2_nonblank += 1
            flags.append("mobile2_ignored_not_blank")

        project_raw = row["project"]
        project_key = PROJECT_KEY_BY_FILE_NAME.get(project_raw.strip().lower())
        if project_key is None:
            raise SystemExit(f"row {row['_row_number']}: unrecognised project {project_raw!r} - "
                              f"does not match an existing project (Skydeck/Felicity)")
        project_counts[project_raw] += 1

        stage_raw = row["lead_sub_status"]
        if stage_raw not in STAGE_MAP:
            raise SystemExit(f"row {row['_row_number']}: unrecognised lead_sub_status {stage_raw!r} - "
                              f"not in the brief's stage mapping table")
        stage, reason = STAGE_MAP[stage_raw]
        stage_counts[stage_raw] += 1

        # Decision 1 above: every lead to the telecaller, regardless of stage.
        assigned_to_name, role = TELECALLER, "telecaller"
        flags.append("assigned_to_telecaller_per_client_decision_2026_09_22")

        legacy = dict(row)

        lead = {
            "_row_number": row["_row_number"],
            "first_name": first_name,
            "middle_name": middle_name or None,
            "last_name": last_name,
            "mobile_number": mobile or None,
            "mobile_alt": None,
            "email": None,
            "project_key": project_key,
            "source": SOURCE_KEY,
            "external_id": None,
            "broker_name": None,
            "channel_partner_key": None,
            "stage": stage,
            "stage_changed_at": row["created_at"],
            "not_connected_count": 0,
            "assigned_to_name": assigned_to_name,
            "assigned_role": role,
            "created_by": None,
            "requirement": row["unitrequirement"],
            "reason": reason,
            "booked_unit": None,
            "booking_date": None,
            "last_activity_at": None,
            "created_at": row["created_at"],
            "updated_at": row["created_at"],
            "deleted_at": None,
            "_filled": filled,
            "_flags": flags,
            "_duplicate_group": None,
            "_duplicate_count": None,
            "_duplicate_rank": None,
            "_legacy": legacy,
            "_source_file": SOURCE_FILE,
            "_import_key": f"{IMPORT_KEY_PREFIX}:row-{row['_row_number']}",
        }
        leads.append(lead)

    # -----------------------------------------------------------------------
    # Within-file duplicate groups (same mobile, any project)
    # -----------------------------------------------------------------------
    by_mobile = collections.defaultdict(list)
    for lead in leads:
        if lead["mobile_number"]:
            by_mobile[lead["mobile_number"]].append(lead)

    duplicate_groups = []
    for mobile, members in by_mobile.items():
        if len(members) < 2:
            continue
        members.sort(key=lambda l: (l["created_at"] or "", l["_row_number"]), reverse=True)
        for rank, lead in enumerate(members, start=1):
            lead["_duplicate_group"] = mobile
            lead["_duplicate_count"] = len(members)
            lead["_duplicate_rank"] = rank
        per_project = collections.Counter(l["project_key"] for l in members)
        clashing_projects = [p for p, c in per_project.items() if c > 1]
        for l in members:
            if l["project_key"] in clashing_projects:
                l["_flags"].append("breaks_unique_mobile_number_project_id_within_file")
        duplicate_groups.append({
            "mobile_number": mobile,
            "count": len(members),
            "projects": sorted({l["project_key"] for l in members}),
            "same_project_more_than_once": sorted(clashing_projects),
            "rows": [{
                "rank": l["_duplicate_rank"],
                "row_number": l["_row_number"],
                "name": f"{l['first_name']} {l['last_name']}".strip(),
                "project_key": l["project_key"],
                "stage": l["stage"],
                "created_at": l["created_at"],
            } for l in members],
        })
    duplicate_groups.sort(key=lambda g: (-g["count"], g["mobile_number"]))

    # -----------------------------------------------------------------------
    # Cross-database conflicts (same mobile + project already in leads,
    # from the earlier /old-data import or any other prior source)
    # -----------------------------------------------------------------------
    db_conflicts = existing_db_conflicts(raw_rows)
    for lead in leads:
        key = (lead["mobile_number"], lead["project_key"])
        hit = db_conflicts.get(key)
        if hit:
            lead["_flags"].append("breaks_unique_mobile_number_project_id_existing_db_lead:" + str(hit["id"]))

    conflict_rows = [{
        "row_number": l["_row_number"],
        "name": f"{l['first_name']} {l['last_name']}".strip(),
        "mobile_number": l["mobile_number"],
        "project_key": l["project_key"],
        "file_stage": l["stage"],
        "existing_lead_id": db_conflicts[(l["mobile_number"], l["project_key"])]["id"],
        "existing_stage": db_conflicts[(l["mobile_number"], l["project_key"])]["stage"],
        "existing_created_at": db_conflicts[(l["mobile_number"], l["project_key"])]["created_at"],
        "existing_name": db_conflicts[(l["mobile_number"], l["project_key"])]["name"],
    } for l in leads if (l["mobile_number"], l["project_key"]) in db_conflicts]

    # Decision 2 above: anything that still carries a
    # breaks_unique_mobile_number_project_id_* flag at this point cannot be
    # inserted without violating unique(mobile_number, project_id) - drop it,
    # not merge/update/overwrite. Every other row is imported as normal.
    for lead in leads:
        conflict_flags = [f for f in lead["_flags"] if f.startswith("breaks_unique_mobile_number_project_id")]
        if conflict_flags:
            lead["_action"] = "skip_duplicate"
            lead["_skip_reason"] = conflict_flags
        else:
            lead["_action"] = "create"
            lead["_skip_reason"] = []

    write("leads.json", leads)
    write("duplicates.json", {
        "within_file_groups": duplicate_groups,
        "existing_db_conflicts": conflict_rows,
    }, compact=True)

    created_ats = [l["created_at"] for l in leads]
    report = {
        "source_file": SOURCE_FILE,
        "import_key_prefix": IMPORT_KEY_PREFIX,
        "input_rows": len(raw_rows),
        "leads_out": len(leads),
        "stage_breakdown_raw": dict(stage_counts),
        "stage_breakdown_mapped": dict(collections.Counter(l["stage"] for l in leads)),
        "project_breakdown": dict(project_counts),
        "assigned_role_breakdown": dict(collections.Counter(l["assigned_role"] for l in leads)),
        "assigned_to_breakdown": dict(collections.Counter(l["assigned_to_name"] for l in leads)),
        "unique_mobiles": len(by_mobile) + sum(1 for l in leads if not l["mobile_number"]),
        "mobile_not_10_digits": mobile_length_flags,
        "mobile2_nonblank_count": mobile2_nonblank,
        "oldest_created_at": min(created_ats),
        "newest_created_at": max(created_ats),
        "within_file_duplicate_groups": len(duplicate_groups),
        "within_file_duplicate_rows": sum(g["count"] for g in duplicate_groups),
        "within_file_same_project_conflicts": sum(1 for g in duplicate_groups if g["same_project_more_than_once"]),
        "existing_db_conflicts_count": len(conflict_rows),
        "rows_to_create": sum(1 for l in leads if l["_action"] == "create"),
        "rows_to_skip_duplicate": sum(1 for l in leads if l["_action"] == "skip_duplicate"),
        "open_leads": sum(1 for l in leads if l["stage"] in ("fresh", "not_connected", "details_shared")),
        "lost_leads": sum(1 for l in leads if l["stage"] == "lost"),
        "to_create_by_stage": dict(collections.Counter(l["stage"] for l in leads if l["_action"] == "create")),
        "to_skip_by_stage": dict(collections.Counter(l["stage"] for l in leads if l["_action"] == "skip_duplicate")),
    }
    write("report.json", report)

    print(json.dumps(report, indent=2, ensure_ascii=False), file=sys.stderr)


if __name__ == "__main__":
    main()
