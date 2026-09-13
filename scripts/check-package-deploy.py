#!/usr/bin/env python3
"""Compare a deployed plugin to a release ZIP whose manifest matches HEAD."""
import hashlib
import json
import os
import tempfile
import subprocess
import sys
import zipfile
from pathlib import Path, PurePosixPath


def archive_files(package):
    expected = {}
    with zipfile.ZipFile(package) as archive:
        for entry in archive.infolist():
            if entry.is_dir():
                continue
            parts = PurePosixPath(entry.filename).parts
            if len(parts) < 2 or parts[0] != 'faz-cookie-manager' or '..' in parts:
                raise ValueError('unexpected ZIP path')
            relative = '/'.join(parts[1:])
            if relative in expected:
                raise ValueError('duplicate ZIP path')
            expected[relative] = hashlib.sha256(archive.read(entry)).hexdigest()
    return expected


def compare(package, manifest, repo, deployed):
    package, deployed = Path(package), Path(deployed)
    data = json.loads(Path(manifest).read_text())
    head = subprocess.check_output(['git', '-C', repo, 'rev-parse', 'HEAD'], text=True).strip()
    if subprocess.check_output(['git', '-C', repo, 'status', '--porcelain', '--untracked-files=all'], text=True).strip():
        raise ValueError('candidate contains uncommitted or untracked files')
    if data.get('commit') != head or data.get('packages', {}).get(package.name) != hashlib.sha256(package.read_bytes()).hexdigest():
        raise ValueError('release package manifest does not match HEAD and ZIP')
    expected = archive_files(package)
    # A self-consistent manifest is not proof of origin: independently rebuild
    # HEAD with the canonical exclusions and transformations, then compare the
    # complete file map. Keep this outside the checkout and the installed site.
    with tempfile.TemporaryDirectory(prefix='faz-canonical-package-') as output:
        subprocess.run(['bash', str(Path(repo) / 'scripts/build-release.sh'),
                        '--version=' + data['version'], '--output-dir=' + output],
                       cwd=repo, env={**os.environ, 'PLUGIN_SRC':str(Path(repo).resolve()), 'CP_REQUIRES':'1.0'},
                       check=True, capture_output=True, timeout=120)
        canonical = archive_files(Path(output) / package.name)
        if expected != canonical:
            raise ValueError('release package contents differ from the canonical HEAD build')
    actual = {str(p.relative_to(deployed)): hashlib.sha256(p.read_bytes()).hexdigest() for p in deployed.rglob('*') if p.is_file()}
    if not expected or not (deployed / 'faz-cookie-manager.php').is_file():
        raise ValueError('empty package or missing deployed plugin')
    return sorted(['missing ' + p for p in expected.keys() - actual.keys()] +
                  ['extra ' + p for p in actual.keys() - expected.keys()] +
                  ['changed ' + p for p in expected.keys() & actual.keys() if expected[p] != actual[p]])


if __name__ == '__main__':
    try:
        print(json.dumps(compare(*sys.argv[1:])))
    except (OSError, ValueError, TypeError, subprocess.CalledProcessError, subprocess.TimeoutExpired, KeyError, zipfile.BadZipFile) as exc:
        print(f'Package preflight failed: {exc}', file=sys.stderr)
        sys.exit(2)
