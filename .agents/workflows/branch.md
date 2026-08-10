---
description: Auto-create a proper git branch before starting any work
---

# Branch Creation Rule

> **CRITICAL**: Before making ANY code changes, you MUST create and checkout a new git branch. Never commit directly to `main`, `dev`, or `{service}/dev`.

## Branch Hierarchy

```
main                          ← Production-ready
└── dev                       ← Integration (all services)
    ├── api/dev               ← API service integration
    │   ├── api/feat/auth     ← Feature work
    │   └── api/fix/session   ← Bug fix
    ├── web/dev               ← Web frontend integration
    │   └── web/feat/login
    └── ui/dev                ← UI/Design integration
        └── ui/feat/dashboard
```

| Branch | Purpose | Merge target |
|---|---|---|
| `main` | Production-ready | — |
| `dev` | Integration (all services) | `main` |
| `{service}/dev` | Per-service integration | `dev` |
| `{service}/{type}/{name}` | Feature/fix work | `{service}/dev` |

## Branch Naming Convention

```
{service}/{type}/{short-description}
```

### Service Prefixes
- `api/` — Laravel API service (`services/api/`)
- `web/` — Frontend web app (`services/web/`)
- `ui/` — UI/Design work
- `infra/` — Infrastructure / Docker / deploy configs
- `docs/` — Documentation only changes
- `shared/` — Shared schemas / libraries

### Type
- `feat/` — New feature or capability
- `fix/` — Bug fix
- `refactor/` — Code restructuring
- `chore/` — Maintenance, deps, config

### Examples
```
api/feat/crud-controllers
api/fix/auth-session
web/feat/login-page
infra/chore/rabbitmq-config
docs/feat/erd-diagram
```

## Steps

// turbo-all

1. Check current branch:
```bash
git branch --show-current
```

2. Create and checkout the new branch from the current service dev branch:
```bash
git checkout -b {service}/{type}/{short-description}
```

3. Confirm the branch was created:
```bash
git branch --show-current
```

4. Proceed with your work on this branch.

## Rules

- Branch name must be **lowercase**, using **hyphens** for word separation in the description.
- Always branch from the appropriate `{service}/dev` branch (or `dev` for cross-service work).
- One branch per logical unit of work (e.g., one feature, one fix).
- If you are working on multiple services in one task, use the **primary** service as the prefix.
- Never push directly to `main`, `dev`, or `{service}/dev` — always go through a feature branch.
