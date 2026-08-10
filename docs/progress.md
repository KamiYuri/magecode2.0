# MageCode 2.0 — Progress Board

> **Live state document** — update at the start (`wip` claim) and end (result) of every
> session per `docs/session-guide.md`. Task definitions live in `docs/roadmap.md`; do not
> duplicate them here.
>
> **Status values**: `todo` · `wip` · `review` · `done` · `blocked` (+ one-line reason in Notes)

**Current milestone: M0** — exit: `go test ./...` green in shared/go; smoke pub/sub vs compose RabbitMQ.

## M0 — Plan A: Shared Go Packages & Scaffolds

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| A1 logger | P0 | done | `ab7f1e5` | slog JSON handler per D-88; tests green (GOWORK=off — go.work still broken until A7) |
| A2 config | P0 | done | `5dff1fe` | fail-fast Load(spec) validates presence + coercion up front; getters infallible |
| A3 apperror | P0 | wip | `shared/feat/apperror` | — |
| A4 rmq | P0 | todo | — | — |
| A5 db | P0 | todo | — | — |
| A6 storage | P0 | todo | — | — |
| A7 scaffolds + go.work | P0 | todo | — | — |

## M1 — Plan B: API Core

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| B1 laravel skeleton | P0 | todo | — | — |
| B2 migrations | P0 | todo | — | — |
| B3 models + seeders | P0 | todo | — | — |
| B4 auth | P0 | todo | — | — |
| B5 rbac + policies | P0 | todo | — | privacy-tagged: authz matrix gates M1 |
| B6 org/course/semester/section crud | P0 | todo | — | — |
| B7 roster import + transfer | P1 | todo | — | — |
| B8 problems + test cases lifecycle | P0 | todo | — | — |
| B9 tags | P2 | todo | — | — |
| B10 problem bank | P1 | todo | — | — |
| B11 route conformance /api/v1 | P0 | todo | — | — |
| B12 profile + notifications | P2 | todo | — | — |

## M2 — Plan C: Submission Loop & CES

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| C1 minio storage service | P0 | todo | — | — |
| C2 submission endpoints + quota | P0 | todo | — | — |
| C3 amqp publisher | P0 | todo | — | — |
| C4 ces consumer loop | P0 | todo | — | — |
| C5 ces repository + upsert | P0 | todo | — | — |
| C6 ces judge0 client | P0 | todo | — | — |
| C7 result signalling + reverb | P0 | todo | — | — |
| C8 e2e submission script | P0 | todo | — | milestone gate M2 |

## M3 — Plan D: Analysis Orchestration (api)

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| D1 trigger + scope + is_latest | P0 | todo | — | — |
| D2 sim job building | P0 | todo | — | — |
| D3 aid/vul job publishing | P0 | todo | — | — |
| D4 sim result handler | P0 | todo | — | — |
| D5 aid/vul result handlers | P0 | todo | — | — |
| D6 batch completion + events | P0 | todo | — | — |
| D7 timeout scheduler | P1 | todo | — | — |
| D8 analysis read apis two-tier | P0 | todo | — | privacy-tagged: gates M3 |

## M3 — Plan E: Batch Workers

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| E1 sim skeleton + downloads | P0 | todo | — | — |
| E2 dolos wrapper + parsing | P0 | todo | — | — |
| E3 sim result assembly | P0 | todo | — | — |
| E4 aid consumer | P0 | todo | — | — |
| E5 aid codebert inference | P0 | todo | — | — |
| E6 aid result publish | P0 | todo | — | — |
| E7 vul codeql service | P1 | todo | — | — |
| E8 worker images + compose | P1 | todo | — | — |
| E9 e2e analysis script | P0 | todo | — | milestone gate M3 |

## M4–M5 — Plan F: Web Frontend

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| F1 bootstrap + tokens | P0 | todo | — | — |
| F2 api client + auth store | P0 | todo | — | — |
| F3 auth screens | P0 | todo | — | — |
| F4 app shell + dashboards | P0 | todo | — | — |
| F5 verdict kit + echo | P0 | todo | — | signature component |
| F6 student flow e2e | P0 | todo | — | — |
| F7 staff section workspace | P1 | todo | — | — |
| F8 analysis ui | P1 | todo | — | — |
| F9 similarity viewer two-tier | P0 | todo | — | privacy-tagged: gates M4 |
| F10 bank + admin screens | P1 | todo | — | — |
| F11 a11y + responsive pass | P1 | todo | — | — |

## M6 — Plan G: Production Readiness

| Task | Prio | Status | Ref | Notes |
|---|---|---|---|---|
| G1 metrics endpoints | P1 | todo | — | — |
| G2 dashboards + alert drills | P2 | todo | — | — |
| G3 security pass | P0 | todo | — | privacy-tagged: gates M6 |
| G4 load test 500 ccu | P1 | todo | — | — |
| G5 ops docs + restore drill | P2 | todo | — | — |
| G6 ci pipeline | P2 | todo | — | — |

## Milestone Gate Results

| Gate | Date | Result | Evidence |
|---|---|---|---|
| M0 exit | — | — | — |
| M1 exit (authz matrix) | — | — | — |
| M2 exit (C8) | — | — | — |
| M3 exit (E9) | — | — | — |
| M4 exit (Playwright suites) | — | — | — |
| M5 exit (bank e2e + axe) | — | — | — |
| M6 exit (load + security) | — | — | — |

## Session Log

> Append one line per session: date · task(s) · outcome. Keep it terse.

| Date | Task(s) | Outcome |
|---|---|---|
| 2026-08-10 | — | Docs phase complete: design SoT, roadmap, UI/UX spec, session workflow. Code not started. |
| 2026-08-10 | A1 | done — `shared/go/logger` implemented TDD, 7 tests green, merged to `shared/dev`. Note: `go.work` lists nonexistent `services/*` modules + stale go directive; run shared/go tooling with `GOWORK=off` until A7 fixes it. |
| 2026-08-10 | A2 | done — `shared/go/config` fail-fast env loader, 8 tests green, merged to `shared/dev`. |
