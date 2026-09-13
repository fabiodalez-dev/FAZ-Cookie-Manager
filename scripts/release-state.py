#!/usr/bin/env python3
"""Resume release steps only for the recorded commit and exact local packages."""
import argparse
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("check", "mark", "validate"))
    parser.add_argument("step", nargs="?")
    parser.add_argument("--state", required=True, type=Path)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--project-root", required=True, type=Path)
    parser.add_argument("--version", required=True)
    args = parser.parse_args()
    records = [json.loads(line) for line in args.state.read_text().splitlines() if line.strip()] if args.state.exists() else []
    if any(not isinstance(row, dict) or not isinstance(row.get("step"), str) for row in records):
        raise ValueError("malformed release state")
    packages = [f"faz-cookie-manager-{args.version}.zip", f"faz-cookie-manager-{args.version}-full.zip", f"faz-cookie-manager-v{args.version}.zip"]

    def hashes():
        return {name: hashlib.sha256((args.project_root / name).read_bytes()).hexdigest() for name in packages}

    if args.action == "validate":
        # Before publication, legacy build/draft records can be safely rebuilt.
        # Afterwards, guessing provenance could move an immutable version.
        for row in records:
            if row["step"] in ("svn_committed", "tagged", "published"):
                if row.get("commit") != args.commit or row.get("packages") != hashes():
                    raise ValueError("published release state does not match this commit/packages; resolve manually")
        return 0
    if not args.step:
        raise ValueError("step is required")
    if args.action == "check":
        row = next((row for row in reversed(records) if row["step"] == args.step), {})
        if row.get("commit") != args.commit:
            return 1
        try:
            return 0 if row.get("packages") == hashes() else 1
        except OSError:
            return 1
    record = {"step": args.step, "at": datetime.now(timezone.utc).isoformat(), "commit": args.commit, "packages": hashes()}
    with args.state.open("a") as stream:
        stream.write(json.dumps(record) + "\n")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError) as exc:
        print(f"Invalid release state: {exc}", file=sys.stderr)
        sys.exit(2)
