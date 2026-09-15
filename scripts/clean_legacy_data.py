#!/usr/bin/env python3
"""Remove invalid older leads and their exclusive references; no database access.

Default: analysis only. --apply requires --remove-2026-child-todos, the explicit
exception authorized for this dataset. Existing backups are never overwritten.
Retained record text is preserved; shared records lose only dangling references.
Historical aggregate counts/ranks are intentionally not recalculated.
"""
import argparse
from collections import Counter
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import tempfile


FIELDS = ('first_name', 'last_name', 'mobile_number')
FILES = ('leads', 'todos', 'duplicates', 'channel_partners')


def empty(value):
    return value is None or isinstance(value, str) and not value.strip()


def year(row):
    # Fail closed on missing/invalid dates: never infer a year from other fields.
    return datetime.strptime(row['created_at'], '%Y-%m-%d %H:%M:%S').year


def reject_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f'Duplicate JSON key: {key}')
        result[key] = value
    return result


def parse(text):
    return json.loads(text, object_pairs_hook=reject_duplicates,
                      parse_constant=lambda value: (_ for _ in ()).throw(ValueError(value)))


def prune_text(text, removals):
    """Delete selected array members by JSON path, retaining other source text.

    raw_decode supplies exact value spans. Nested edits are applied only to
    objects containing arrays with removed members; all other bytes survive.
    """
    decoder = json.JSONDecoder()

    def whitespace(pos):
        while pos < len(text) and text[pos].isspace():
            pos += 1
        return pos

    def visit(start, path):
        value, end = decoder.raw_decode(text, start)
        if not isinstance(value, (list, dict)):
            return text[start:end], end
        pos = whitespace(start + 1)
        edits = []
        members = []
        for index, item in enumerate(value):
            member_start = pos
            if isinstance(value, dict):
                key, key_end = decoder.raw_decode(text, pos)
                pos = whitespace(key_end)
                assert text[pos] == ':'
                pos = whitespace(pos + 1)
                rendered, child_end = visit(pos, path + (key,))
                edits.append((pos, child_end, rendered))
            else:
                rendered, child_end = visit(pos, path + (index,))
                members.append((member_start, child_end, rendered))
            pos = whitespace(child_end)
            if text[pos] == ',':
                pos = whitespace(pos + 1)
        if isinstance(value, list) and path in removals:
            kept = [m for i, m in enumerate(members) if i not in removals[path]]
            if not kept:
                return '[]', end
            prefix = text[start:members[0][0]]
            suffix = text[members[-1][1]:end]
            separator = text[members[0][1]:members[1][0]] if len(members) > 1 else ', '
            return prefix + separator.join(m[2] for m in kept) + suffix, end
        if isinstance(value, list):
            edits = members
        result = text[start:end]
        for left, right, replacement in reversed(edits):
            result = result[:left-start] + replacement + result[right-start:]
        return result, end

    start = whitespace(0)
    rendered, end = visit(start, ())
    return text[:start] + rendered + text[end:]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, default=Path(__file__).resolve().parents[1] / 'old-data')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--remove-2026-child-todos', action='store_true')
    args = parser.parse_args()
    root = args.directory
    original = {name: (root / f'{name}.json').read_bytes() for name in FILES}
    data = {name: parse(raw.decode('utf-8')) for name, raw in original.items()}
    leads = data['leads']
    keys = {r['_import_key'] for r in leads}
    assert len(keys) == len(leads), 'Lead import keys must be unique'
    years = [year(r) for r in leads]
    missing = [sum(empty(r.get(f)) for f in FIELDS) for r in leads]
    deleted = {r['_import_key'] for r, y, m in zip(leads, years, missing) if y != 2026 and m}
    paths = {name: {} for name in FILES}
    paths['leads'][()] = {i for i, r in enumerate(leads) if r['_import_key'] in deleted}
    todos = data['todos']
    assert all(r['_lead_import_key'] in keys for r in todos), 'Existing orphan todo'
    paths['todos'][()] = {i for i, r in enumerate(todos) if r['_lead_import_key'] in deleted}
    child_2026 = sum(str(todos[i].get('created_at') or '').startswith('2026-') for i in paths['todos'][()])
    groups = data['duplicates']['groups']
    removed_groups = set()
    duplicate_members = 0
    affected_groups = 0
    for i, group in enumerate(groups):
        assert group['import_keys'] == [r['import_key'] for r in group['rows']]
        assert set(group['import_keys']) <= keys, 'Existing orphan duplicate member'
        indices = {j for j, key in enumerate(group['import_keys']) if key in deleted}
        duplicate_members += len(indices)
        affected_groups += bool(indices)
        if indices and len(indices) == len(group['rows']):
            removed_groups.add(i)
        elif indices:
            paths['duplicates'][('groups', i, 'rows')] = indices
            paths['duplicates'][('groups', i, 'import_keys')] = indices
    paths['duplicates'][('groups',)] = removed_groups
    partner_refs = 0
    for i, partner in enumerate(data['channel_partners']):
        assert set(partner['lead_import_keys']) <= keys, 'Existing orphan partner reference'
        indices = {j for j, key in enumerate(partner['lead_import_keys']) if key in deleted}
        if indices:
            paths['channel_partners'][(i, 'lead_import_keys')] = indices
            partner_refs += len(indices)
    report = {
        'leads_before': len(leads), 'protected_2026': years.count(2026),
        'non_2026': len(leads) - years.count(2026),
        'empty_fields': {f: sum(empty(r.get(f)) for r in leads) for f in FIELDS},
        'multiple_empty_fields': sum(m > 1 for m in missing),
        'protected_invalid_2026': sum(y == 2026 and m > 0 for y, m in zip(years, missing)),
        'leads_removed': len(deleted), 'leads_after': len(leads) - len(deleted),
        'lead_deletions_by_year': dict(sorted(Counter(y for r, y in zip(leads, years) if r['_import_key'] in deleted).items())) | {2026: 0},
        'todos_before': len(todos), 'todos_removed': len(paths['todos'][()]),
        'todos_after': len(todos) - len(paths['todos'][()]), 'authorized_2026_child_todos_removed': child_2026,
        'duplicate_members_removed': duplicate_members, 'duplicate_groups_affected': affected_groups,
        'empty_duplicate_groups_removed': len(removed_groups),
        'duplicate_groups_after': len(groups) - len(removed_groups),
        'partner_references_removed': partner_refs, 'partner_records_removed': 0,
        'partner_records_affected': len(paths['channel_partners']),
    }
    print(json.dumps(report, indent=2))
    output = {name: prune_text(raw.decode('utf-8'), paths[name]).encode('utf-8') for name, raw in original.items()}
    cleaned = {name: parse(raw.decode('utf-8')) for name, raw in output.items()}
    assert cleaned['leads'] == [r for r in leads if r['_import_key'] not in deleted]
    assert [r for r in cleaned['leads'] if year(r) == 2026] == [r for r in leads if year(r) == 2026]
    assert cleaned['todos'] == [r for r in todos if r['_lead_import_key'] not in deleted]
    remaining = keys - deleted
    assert all(r['_lead_import_key'] in remaining for r in cleaned['todos'])
    expected_groups = []
    for g in groups:
        if set(g['import_keys']) <= deleted:
            continue
        expected_groups.append(g | {'import_keys': [k for k in g['import_keys'] if k not in deleted],
                                   'rows': [r for r in g['rows'] if r['import_key'] not in deleted]})
    assert cleaned['duplicates'] == data['duplicates'] | {'groups': expected_groups}
    assert cleaned['channel_partners'] == [p | {'lead_import_keys': [k for k in p['lead_import_keys'] if k not in deleted]} for p in data['channel_partners']]
    for g in cleaned['duplicates']['groups']:
        assert set(g['import_keys']) <= remaining
    for p in cleaned['channel_partners']:
        assert set(p['lead_import_keys']) <= remaining
    print('Output validation passed; retained values and 2026 leads unchanged.')
    if not args.apply:
        print('Analysis only; no files written.')
        return
    if child_2026 and not args.remove_2026_child_todos:
        raise SystemExit('Refusing to remove 2026 child todos without explicit flag.')
    changed = [name for name in FILES if output[name] != original[name]]
    for name in changed:
        if (root / f'{name}.before-cleanup.json').exists():
            raise SystemExit(f'Refusing to overwrite backup for {name}')
    staged = {}
    try:
        for name in changed:
            with tempfile.NamedTemporaryFile(dir=root, prefix=f'.{name}.cleanup-', delete=False) as handle:
                staged[name] = Path(handle.name)
                handle.write(output[name])
                handle.flush()
                os.fsync(handle.fileno())
            assert parse(staged[name].read_text()) == cleaned[name]
            os.chmod(staged[name], (root / f'{name}.json').stat().st_mode & 0o777)
        for name in changed:
            assert (root / f'{name}.json').read_bytes() == original[name], 'Input changed during cleanup'
            with (root / f'{name}.before-cleanup.json').open('xb') as backup:
                backup.write(original[name])
                backup.flush()
                os.fsync(backup.fileno())
            assert (root / f'{name}.before-cleanup.json').read_bytes() == original[name]
        for name in changed:
            assert (root / f'{name}.json').read_bytes() == original[name], 'Input changed during cleanup'
            os.replace(staged[name], root / f'{name}.json')
        for name in changed:
            assert (root / f'{name}.json').read_bytes() == output[name]
            print(f'{name}.json: validated; backup SHA256 {hashlib.sha256(original[name]).hexdigest()}')
    finally:
        for path in staged.values():
            path.unlink(missing_ok=True)


if __name__ == '__main__':
    main()
