#!/usr/bin/env python3
"""Retain test names and skip reasons; reject missing, failed or flaky batches."""
import json
import sys
from pathlib import Path


def summarize(directory, count):
    total = {"expected": 0, "unexpected": 0, "flaky": 0, "skipped": 0}
    skipped = []
    failures = []

    def walk(suites):
        for suite in suites:
            for spec in suite.get("specs", []):
                for test in spec.get("tests", []):
                    if test.get("status") == "skipped":
                        reasons = [a.get("description", "") for a in test.get("annotations", []) if a.get("type") in ("skip", "fixme")]
                        skipped.append({"file": spec.get("file", suite.get("file")), "title": spec["title"], "reasons": reasons})
            walk(suite.get("suites", []))

    for batch in range(1, count + 1):
        path = directory / f"batch-{batch:02d}.json"
        data = json.loads(path.read_text())
        stats = data["stats"]
        for key in total:
            total[key] += stats[key]
        if data.get("errors") or stats["unexpected"] or stats["flaky"]:
            failures.append(batch)
        if sum(stats[key] for key in total) == 0:
            failures.append(batch)
        walk(data["suites"])
    result = {"commit": (directory / "commit.txt").read_text().strip(), "batches": count, "counts": total, "failed_batches": failures, "skips": skipped}
    (directory / "evidence.json").write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result, indent=2))
    return 1 if failures else 0


if __name__ == "__main__":
    try:
        sys.exit(summarize(Path(sys.argv[1]), int(sys.argv[2])))
    except (OSError, ValueError, KeyError, TypeError, IndexError) as exc:
        print(f"Incomplete E2E evidence: {exc}", file=sys.stderr)
        sys.exit(2)
