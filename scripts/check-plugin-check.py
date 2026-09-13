#!/usr/bin/env python3
"""Fail closed on Plugin Check findings, even when its CLI exits successfully."""
import json
import sys
from pathlib import Path


def check(path):
    raw = Path(path).read_text().strip()
    # Plugin Check emits this instead of JSON when there are no findings at all.
    if raw == "Success: Checks complete. No errors found.":
        findings = []
    else:
        findings = json.loads(raw)
    if not isinstance(findings, list) or any(
        not isinstance(row, dict) or row.get("type") not in ("ERROR", "WARNING")
        for row in findings
    ):
        raise ValueError("expected Plugin Check --format=strict-json findings")
    errors = [row for row in findings if row["type"] == "ERROR"]
    for row in errors:
        print(f"ERROR {row.get('file')}:{row.get('line')} {row.get('code')}: {row.get('message')}")
    print(f"Plugin Check: {len(errors)} errors, {len(findings) - len(errors)} warnings")
    return 1 if errors else 0


if __name__ == "__main__":
    try:
        sys.exit(check(sys.argv[1]))
    except (OSError, ValueError, IndexError) as exc:
        print(f"Plugin Check result missing or invalid: {exc}", file=sys.stderr)
        sys.exit(2)
