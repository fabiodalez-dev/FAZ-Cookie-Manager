# GitHub-hosted CI and private Git backups

GitHub remains authoritative for code, PRs, issues, discussions, Actions checks,
logs, artifacts and releases. Quality, Plugin Check and CodeQL always use
standard GitHub-hosted Ubuntu runners, including same-repository and fork PRs.
There is no actor-dependent route to the personal server.

The owner withdrew the self-hosted migration on 7 September 2026. The CI
controller on SSH host `fabiodalez` is stopped and disabled at boot. Do not
re-enable it or restore self-hosted labels. Superseded queued runs must be
cancelled and replaced by runs on the updated workflow revision.

Existing WordPress, Plugin Check, dependency pins and quality gates remain.
Concurrency cancels superseded PR runs; workflow timeouts prevent unbounded
execution. No plugin version, tag or release is created by this change.

The private GitLab mirror is retained with CI disabled. The server polls for
new GitHub pushes every minute when the mirror queue is free, backs up changed
repositories and performs an hourly full check. Replaced Git history is kept;
PRs/issues remain on GitHub, not in this Git-only backup.

Shared backup code lives in `eventi/infra/ci/`. Keep `fabio-git-mirror.timer`
and `fabio-git-mirror-poll.timer` active. Credentials remain outside Git.
