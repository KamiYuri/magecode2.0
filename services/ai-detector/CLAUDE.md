# ai-detector (AID) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `ai-detector` jobs
(one per analysis submission, pre-signed file URL), downloads the source,
runs CodeBERT inference to score AI-generation likelihood, publishes full
results to `result-analysis`.

## Status
A7 scaffold: startup log only. Consumer (E4), CodeBERT inference (E5), and
result publishing (E6) land in Plan E.

## Tech Stack
Python 3.12, JSON logging to stdout (D-88). Model: `microsoft/codebert-base`
(env-overridable). No DB driver — D-80 forbids DB access.

## Key Files
- `src/main.py` — entrypoint + D-88 JSON log formatter
- `Dockerfile` — python:3.12-slim, build context = this directory
- `requirements.txt` — empty until Plan E adds pika/transformers

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes (from E4) | — | amqp URL incl. vhost |
| `MODEL_NAME` | no | microsoft/codebert-base | HF model id |
| `BATCH_SIZE` | no | 8 | inference batch size |
| `RMQ_PREFETCH` | no | 3 | D-76: AID prefetch 3 |

## Testing
```bash
python -m venv .venv && source .venv/bin/activate
python src/main.py                              # prints one JSON startup log
```
Slow CodeBERT integration tests run only under their explicit tag/group (session guide §6).
Task definitions in `docs/roadmap.md` §7.
