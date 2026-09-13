#!/usr/bin/env python3
"""Require completed local release gates bound to a commit and the three ZIPs."""
import hashlib
import json
import sys
from pathlib import Path


def verify(path, commit, version, root):
    evidence = json.loads(Path(path).read_text())
    if evidence.get("commit") != commit or evidence.get("version") != version:
        raise ValueError("release evidence belongs to a different commit/version")
    packages = [f"faz-cookie-manager-{version}.zip", f"faz-cookie-manager-{version}-full.zip", f"faz-cookie-manager-v{version}.zip"]
    hashes = {name: hashlib.sha256((Path(root) / name).read_bytes()).hexdigest() for name in packages}
    if evidence.get("packages") != hashes:
        raise ValueError("release evidence does not match the three package hashes")
    for name in ("unit", "e2e", "multisite", "compliance", "verify", "install", "upgrade", "plugin_check", "restore"):
        gate = evidence.get("gates", {}).get(name, {})
        if gate.get("status") != "passed" or not gate.get("log"):
            raise ValueError(f"release gate {name} is missing or incomplete")
        log = Path(gate["log"])
        if not log.is_absolute():
            log = Path(path).parent / log
        if hashlib.sha256(log.read_bytes()).hexdigest() != gate.get("log_sha256"):
            raise ValueError(f"release gate {name} log is missing or changed")
        for skip in gate.get("skips", []):
            if not isinstance(skip, dict) or not skip.get("test") or not skip.get("reason"):
                raise ValueError(f"release gate {name} has an undocumented skip")
    print("All local release gates recorded for this commit and these packages")


if __name__ == "__main__":
    try:
        verify(*sys.argv[1:])
    except (OSError, ValueError, TypeError, KeyError) as exc:
        print(f"Release evidence rejected: {exc}", file=sys.stderr)
        sys.exit(1)
