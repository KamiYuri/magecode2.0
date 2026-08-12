# MageCode 2.0 — Work Session Guide

> **Version**: 1.0 — 10/08/2026
> **Audience**: every developer and AI agent session working on this repo.
> Read this at the START of every session, together with `docs/progress.md`.

---

## 1. Source-of-Truth Hierarchy

When sources conflict, the higher one wins. Fix the lower one or record an amendment —
never silently code against the lower one.

1. `shared/schemas/*.json` — RabbitMQ message contracts
2. `docs/api-contracts/openapi.yml` — HTTP API contract
3. `docs/database-schema.md` + `docs/erd/` — data model
4. `docs/technical-design.md` + decision logs D-01–D-90 (`docs/decision-log/`, amendments in decisions-v3 §7)
5. `docs/ui-ux-design.md` — frontend behavior & visuals
6. `docs/roadmap.md` — what to build, in what order, how to verify
7. Code in this repo
8. `/home/gideon/Documents/BKCS/deprecated_magecode` — **reference only, never SoT**
   (abandoned 2.0 prototype; salvage per the matrix in
   `docs/superpowers/plans/2026-08-10-magecode-2.0-upgrade-roadmap.md` §1)

Deviating from a D-decision requires recording an amendment row in
`docs/decision-log/decisions-v3.md` §7 in the same branch.

## 2. Session Start Checklist

1. **Sync state**: `git fetch --all --prune && git status` — confirm clean tree; note current branch.
2. **Read the board**: `docs/progress.md` — check for `wip` tasks (continue them first),
   `blocked` notes from previous sessions, and the current milestone.
3. **Pick work**: the lowest-numbered unblocked **P0** task of the current milestone, unless
   the human directs otherwise. Respect task ordering — a task may assume everything above
   it in its plan table is done.
4. **Load context for the task**: read its row in `docs/roadmap.md`, the decisions (D-xx/U-x)
   it cites, and the relevant SoT sections. For frontend tasks, read the matching
   `docs/ui-ux-design.md` sections.
5. **Branch** per `.agents/workflows/branch.md`: `{service}/{type}/{short-description}`
   from the appropriate `{service}/dev` (create `dev` / `{service}/dev` from `main` if they
   don't exist yet). Never work directly on `main`, `dev`, or `{service}/dev`.
6. **Mark the task `wip`** in `docs/progress.md` (with branch name) — commit this on your
   feature branch together with your first change, so parallel sessions see the claim.

## 3. During the Session

- **TDD is mandatory** (`.agents/workflows/dev-rules.md`): failing test → implementation →
  refactor. The task's "Test mechanism" column defines the minimum test surface.
- **Definition of Done** is `docs/roadmap.md` §1 (tests green, JSON logging only, no
  `panic()`, schema-validated payloads, linters clean) **plus** the task's Verify column.
- **Contract changes go schema-first**: edit `shared/schemas/` + `docs/rabbitmq-schemas.md`
  (or `openapi.yml`) before any code, and update both producer and consumer in the same
  task or an explicitly linked pair of tasks.
- **Commits**: `<type>(<scope>): <subject>` (≤50 chars, imperative, lowercase), one logical
  change per commit, task ID in the subject or body, e.g.
  `feat(shared): add slog json handler [A1]`.
- **Ask, don't assume**, when hitting: a SoT conflict this guide can't resolve, a new
  runtime dependency, a schema/API change not covered by the task, or anything touching the
  privacy-tagged tasks' guarantees (B5, D8, F9, G3).

## 4. Session End Checklist

1. Run the task's verification (tests, linters, E2E script if the task is a milestone gate).
   No green, no done — report actual output, never claim untested success.
2. Update `docs/progress.md`: status (`done` / still `wip` / `blocked` + one-line reason),
   commit ref, and a note if the next session needs context that isn't in the code.
3. Commit everything; leave the working tree clean. Push the feature branch if a remote exists.
4. If the task is complete: merge/PR into `{service}/dev` per branch workflow (or hand off
   for review). Milestone gates (C8, E9, exit criteria) additionally get their result noted
   in `docs/progress.md` under the milestone header.
5. If docs were found wrong during the task: fix them in the same branch (`docs:` commit)
   per the SoT hierarchy above.

## 5. Documentation Map

| Question | Read |
|---|---|
| What is this system? Key decisions? | `CLAUDE.md`, `docs/technical-design.md` |
| Why was X decided? | `docs/decision-log/decisions-v{1,2,3}.md` (amendments: v3 §7) |
| What exactly do I build next? | `docs/roadmap.md` + `docs/progress.md` |
| Strategy, salvage from prototype, U-decisions | `docs/superpowers/plans/2026-08-10-magecode-2.0-upgrade-roadmap.md` |
| Table/column/constraint details | `docs/database-schema.md`, `docs/erd/` |
| HTTP endpoint shapes | `docs/api-contracts/openapi.yml` |
| Queue/message formats | `shared/schemas/*.json`, `docs/rabbitmq-schemas.md` |
| Screen/UX behavior, copy, tokens | `docs/ui-ux-design.md` |
| Containers, networks, env vars, Judge0 | `docs/docker-compose-architecture.md` |
| Branch & TDD rules | `.agents/workflows/branch.md`, `.agents/workflows/dev-rules.md` |

## 6. Environment Quickstart

```bash
cp .env.example .env          # first time only — fill passwords
make up                       # infrastructure: postgres, pgbouncer, rabbitmq, minio, loki
make migrate && make seed     # runs in the api image against PgBouncer; seed is idempotent
make test-api && make lint-api  # api suite + pint/phpstan, also in the image
make up-judge0                # only for CES work (needs cgroup v1 — see docker-compose-architecture §17)
make status                   # container health
make logs service=<name>      # tail one service
```

**PHP runs in Docker, not on the host.** Every `make *-api` target executes inside
`magecode-api:test`, because the api needs the `pdo_pgsql` and `amqp` extensions. Only
`make dev-api` / `make dev-reverb` (:8000/:8080) call host PHP and therefore need those
extensions installed locally. `make dev-web` (:5173) needs Node.

Application containers declare the Loki log driver (D-88), so install the plugin before
`--profile app`/`analysis`:
`docker plugin install grafana/loki-docker-driver:latest --alias loki --grant-all-permissions`.

Go: `cd shared/go && go test ./...` for units. Integration suites are behind
`-tags integration` and skip unless their env var is set — `RMQ_TEST_URL`, `DB_TEST_DSN`,
`MINIO_TEST_ENDPOINT` (+ key/secret/bucket). Slow suites (Judge0/CodeBERT/CodeQL) run only
under their explicit tag/group — keep the default loop fast.

## 7. Language Rules

Code, comments, commits, identifiers, tests, and all docs in this repo: **English**.
End-user UI strings in `services/web`: **Vietnamese** (glossary in `technical-design.md`
§2.3 and copy rules in `ui-ux-design.md` §8). Chat with the human may be Vietnamese.

---

*— End of Work Session Guide v1.0 —*
