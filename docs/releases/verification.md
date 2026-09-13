# Release evidence

Publishing requires a clean, pushed `main`, every required CI check, and local
gate evidence for the exact commit and three package SHA-256 hashes. Missing
GitHub status, missing logs and stale provenance are failures.

1. Commit the candidate and build with `scripts/build-release.sh --version=X.Y.Z`.
   The three ZIPs are reproducible. The adjacent
   `faz-cookie-manager-X.Y.Z-build.json` records their hashes and commit.
2. Back up the reference WordPress database before testing. Install the wp.org
   ZIP and run unit and full E2E checks. Set `WP_PATH`, `FAZ_PLUGIN_DEPLOY_PATH`,
   `FAZ_E2E_PACKAGE` and `FAZ_E2E_BUILD_MANIFEST` for package verification;
   the preflight compares every deployed file to that ZIP and verifies HEAD.
3. `scripts/run-e2e-batches.sh` runs the mandatory consent/browser gate, then all
   single-site specs serially without retries. `E2E_BATCH_OUT` chooses an external
   output directory. Its per-batch JSON reports retain skip names and reasons;
   `evidence.json` rejects missing, failed or flaky batches. Resuming requires
   the same commit. The multisite scanner runs separately through
   `WP_PATH=... npm run test:e2e:multisite` on a disposable database.
4. Run compliance and verify. A fatal compliance error is a failed assertion,
   and sections not reached are named. Enable the GCM/TCF features required by
   the compliance suite on the disposable/test site; record any skipped groups.
5. Run `WP_PATH=... bash scripts/test-release-install.sh /path/to/package.zip PREVIOUS`.
   It creates a disposable database, verifies clean activation, resets that
   database, installs the previous published version, inserts a probe, and
   verifies the upgrade, migration notice and one-shot behavior. It never
   uninstalls the plugin from the reference site.
6. Run Plugin Check 1.9.0 against the extracted wp.org package with
   `--categories=plugin_repo --format=strict-json --fields=file,line,column,type,code,message`.
   Check the result with `python3 scripts/check-plugin-check.py result.json`;
   Plugin Check's exit code alone does not enforce zero ERROR findings.
7. Restore the reference database, verify the restoration and retain its log.
   Do not store database dumps in the plugin or its release assets.

Collect the completed evidence in the parent directory as
`faz-cookie-manager-X.Y.Z-evidence.json`. Its `commit`, `version` and `packages`
fields have the same format as the build manifest. Add a `gates` object with
entries `unit`, `e2e`, `multisite`, `compliance`, `verify`, `install`, `upgrade`,
`plugin_check`, and `restore`. Every entry requires `status: "passed"`, a `log`
path and that log's `log_sha256`. Relative log paths resolve beside the evidence
file. Every skipped test must be listed as `{ "test": "name", "reason": "..." }`
in the corresponding entry's `skips` array. This is an attestation of inspected
test results, not an instruction to label incomplete runs as passed.

`scripts/verify-release-evidence.py EVIDENCE COMMIT VERSION PACKAGE_DIRECTORY`
checks the evidence. The publisher checks it before staging and again after
building, so the artifacts approved for SVN must still be the ones tested.

`release-state.py` binds every completed publication step to the commit and ZIP
hashes. Old build/draft records without provenance require a rebuild/re-upload.
A mismatch after SVN is not automatically recoverable: the publisher stops.
The two human SVN gates remain. Neither tests nor this evidence authorize
publication; Playground and live distribution checks remain post-publication
checks. The versioned article source is `docs/releases/X.Y.Z.html` and is retained
after publishing.
