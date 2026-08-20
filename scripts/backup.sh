#!/usr/bin/env bash
# MageCode 2.0 — backup.sh
#
# Not implemented. G5 owns the backup and restore drill; until it lands this
# exits non-zero rather than printing a message and succeeding, because a
# backup job that "worked" and wrote nothing is worse than one that failed.
#
# Its siblings (seed.sh, reset.sh, setup.sh) were deleted rather than filled
# in: `make seed`, `make reset` and the quickstart in docs/session-guide.md §6
# already do those, and a second spelling only invites the two to drift.
set -euo pipefail
echo "backup.sh: not implemented — see docs/roadmap.md G5" >&2
exit 1
