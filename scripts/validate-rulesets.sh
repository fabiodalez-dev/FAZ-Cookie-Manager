#!/usr/bin/env bash
#
# validate-rulesets.sh — schema-validate every ruleset JSON file.
#
# Spec: specs/001-geo-routing-next/spec.md NFR-05
# Task: T003 (P1 Foundation)
#
# Validates admin/modules/geo-routing/rulesets/*.json against
# admin/modules/geo-routing/schemas/ruleset.schema.json (JSON Schema
# Draft 2020-12). Skips _index.json and any file starting with `_`.
#
# Usage:
#   scripts/validate-rulesets.sh              # validate, exit 1 on fail
#   scripts/validate-rulesets.sh --strict     # also fail on warnings
#
# CI integration: hook into `.github/workflows/*.yml` on pull_request.

set -euo pipefail

# Resolve project root portably: env override > git toplevel > script-relative.
# The hardcoded developer-machine path that lived here previously broke CI.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${PROJECT_ROOT:-}" ]]; then
    if command -v git >/dev/null 2>&1 && git -C "$SCRIPT_DIR" rev-parse --show-toplevel >/dev/null 2>&1; then
        PROJECT_ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel)"
    else
        PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
    fi
fi
RULESETS_DIR="${PROJECT_ROOT}/admin/modules/geo-routing/rulesets"
SCHEMA_PATH="${PROJECT_ROOT}/admin/modules/geo-routing/schemas/ruleset.schema.json"

STRICT_MODE="no"
for arg in "$@"; do
    case "$arg" in
        --strict) STRICT_MODE="yes" ;;
        -h|--help)
            sed -n '3,18p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown arg: $arg" >&2
            exit 1
            ;;
    esac
done

if [[ ! -d "${RULESETS_DIR}" ]]; then
    echo "ERROR: rulesets directory not found: ${RULESETS_DIR}" >&2
    exit 1
fi
if [[ ! -f "${SCHEMA_PATH}" ]]; then
    echo "ERROR: schema file not found: ${SCHEMA_PATH}" >&2
    exit 1
fi

# Validator: prefer Python jsonschema lib (lightweight + ubiquitous).
# Fallback to ajv-cli (Node) if python3 + jsonschema absent.

cyan() { printf '\033[36m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
red() { printf '\033[31m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }

run_python_validator() {
    python3 - "$SCHEMA_PATH" "$RULESETS_DIR" "$STRICT_MODE" <<'PYEOF'
import json
import sys
import os
import glob

try:
    from jsonschema import validate, exceptions  # noqa: F401
    from jsonschema.validators import Draft202012Validator
except ImportError:
    print("WARN: python jsonschema lib not installed. Install: pip3 install jsonschema", file=sys.stderr)
    sys.exit(2)

schema_path, rulesets_dir, strict_mode = sys.argv[1], sys.argv[2], sys.argv[3]
with open(schema_path) as f:
    schema = json.load(f)

validator = Draft202012Validator(schema)

files = sorted(glob.glob(os.path.join(rulesets_dir, "*.json")))
errors = 0
warnings = 0

for f in files:
    basename = os.path.basename(f)
    if basename.startswith("_"):
        continue  # skip _index.json + helper files

    print(f"  Validating {basename}...", end=" ")
    try:
        with open(f) as fh:
            data = json.load(fh)
    except json.JSONDecodeError as e:
        print(f"\033[31mFAIL — JSON parse error: {e}\033[0m")
        errors += 1
        continue

    violations = list(validator.iter_errors(data))
    if not violations:
        # Cross-check: id field matches filename.
        expected_id = basename[:-5]  # strip .json
        if data.get("id") != expected_id:
            print(f"\033[33mWARN — id={data.get('id')!r} != filename {expected_id!r}\033[0m")
            warnings += 1
        else:
            print("\033[32mOK\033[0m")
    else:
        print(f"\033[31mFAIL ({len(violations)} violations)\033[0m")
        for v in violations[:5]:  # first 5 only for brevity
            path = "/".join(str(p) for p in v.absolute_path) or "<root>"
            print(f"    at {path}: {v.message}")
        errors += 1

print()
print(f"Validated: {len(files)} ruleset file(s)")
print(f"Errors:    {errors}")
print(f"Warnings:  {warnings}")

if errors > 0:
    sys.exit(1)
# --strict: treat warnings as errors (id/filename mismatch, etc.).
if strict_mode == "yes" and warnings > 0:
    print("Strict mode: failing due to warnings.")
    sys.exit(1)
sys.exit(0)
PYEOF
}

run_ajv_validator() {
    if ! command -v ajv >/dev/null 2>&1; then
        return 2
    fi
    for f in "${RULESETS_DIR}"/*.json; do
        basename=$(basename "$f")
        [[ "$basename" == _* ]] && continue
        echo -n "  Validating $basename... "
        if ajv validate -s "$SCHEMA_PATH" -d "$f" --strict=false 2>/dev/null; then
            green "OK"
        else
            red "FAIL"
            ajv validate -s "$SCHEMA_PATH" -d "$f" --strict=false 2>&1 | head -10
            return 1
        fi
    done
}

cyan "Validating rulesets against schema..."
echo "  Schema:   $SCHEMA_PATH"
echo "  Rulesets: $RULESETS_DIR"
echo ""

# Try python first.
if command -v python3 >/dev/null 2>&1; then
    if run_python_validator; then
        green "All rulesets valid."
        exit 0
    else
        rc=$?
        if [[ $rc -eq 2 ]]; then
            yellow "Python jsonschema unavailable, trying ajv-cli..."
        else
            red "Schema validation failed."
            exit 1
        fi
    fi
fi

# Fallback to ajv-cli. NB: AJV only checks JSON-Schema conformance — it does
# NOT replicate the Python validator's id-vs-filename warning, so it has no
# notion of "warnings" to escalate. To honour the --strict contract uniformly,
# refuse to use the AJV path at all when strict was requested.
if [[ "$STRICT_MODE" == "yes" ]]; then
    red "--strict requires the Python validator (warning-aware: id/filename consistency check). AJV fallback cannot enforce equivalent warnings; refusing to proceed."
    yellow "Install python3 + jsonschema:  pip3 install jsonschema"
    exit 1
fi

if run_ajv_validator; then
    green "All rulesets valid (ajv)."
    exit 0
fi

red "No validator available (need: python3 + jsonschema, or ajv-cli)."
exit 1
