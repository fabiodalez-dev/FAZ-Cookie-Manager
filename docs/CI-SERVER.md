# CI server, with GitHub as the primary platform

GitHub remains authoritative for code, PRs, issues, discussions, Actions checks,
logs, artifacts and releases. The private GitLab mirror only backs up Git refs;
it does not replace GitHub.

Quality, Plugin Check and CodeQL run on Fabio's disposable Linux VM runners
for the repository owner and same-repository Dependabot jobs. Fork PRs and
other actors use GitHub-hosted Ubuntu runners. Preserve this routing.

The `fabiodalez` SSH host executes one job at a time across repositories, in
a fresh KVM Ubuntu 24.04 VM (4 vCPU, 8 GiB RAM), labelled
`self-hosted`, `Linux`, `X64`, `fabio-ci`. Docker/wp-env runs inside the VM;
the disk is discarded after the job. Host credentials and private networking
are not available to the VM. Never use a persistent runner for public PR code.
Workflow expressions/labels alone are not a security boundary; host-side VM
and network isolation must stay enabled.

Existing WordPress, Plugin Check, dependency pins and quality gates are retained.
The runner installs its required Linux tools explicitly. No plugin release is
created by this migration. Check all three workflows on the migration commit
before treating the new runner as verified.

Shared orchestration code and tests live in `eventi/infra/ci/`; root-only server
configuration controls reviewed public repositories. GitLab CI remains disabled.
