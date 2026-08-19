# ai-detector (AID) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `ai-detector` jobs
(one per analysis submission, pre-signed file URL), downloads the source,
scores AI-generation likelihood, publishes full results to `result-analysis`.

## Status
E4 done: pika consumer with the shared topology, strict job decode, downloader,
graceful shutdown. E5 (inference) and E6 (result publish) still to come.

## Tech Stack
Python 3.12, JSON logging to stdout (D-88), pika, requests. Model:
`microsoft/codebert-base` (env-overridable). No DB driver — D-80 forbids DB access.

## Key Files
- `src/main.py` — wiring: config, connection, consume loop, SIGTERM flag
- `src/rabbit.py` — **a transcription of `shared/go/rmq`, and must stay one.** The Go
  workers and this one declare the same queues on the same broker, and RabbitMQ answers a
  mismatched redeclare with PRECONDITION_FAILED — whichever process declares second dies
  (C3 learned this on the api side). `tests/test_integration_rabbit.py` pins it, with a
  negative control proving the broker really does refuse
- `src/job.py` — strict decode of `job.ai-detector.v1`; every rejection Permanent
- `src/errors.py` — the Transient/Permanent taxonomy of `shared/go/apperror`
- `src/downloader.py` — pre-signed URL GET with a retry budget; 4xx Permanent, 5xx Transient
- `src/log.py` — the D-88 line shape, identical to `shared/go/logger`'s
- `src/config.py` — fail-fast env loading, the Python half of `shared/go/config`

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 3 | D-76: AID prefetch 3 |
| `MAX_SOURCE_BYTES` | no | 1048576 | Refuses a body that is not a submission (D-34 caps at 50KB) |
| `MODEL_NAME` | no (from E5) | microsoft/codebert-base | HF model id |
| `BATCH_SIZE` | no (from E5) | 8 | inference batch size |

## Testing
```bash
python3 -m venv .venv && .venv/bin/pip install -r requirements-dev.txt
.venv/bin/python -m pytest                      # fast loop: no torch, no downloads
.venv/bin/python -m ruff check src tests
RMQ_TEST_URL=amqp://... .venv/bin/python -m pytest tests/test_integration_rabbit.py
.venv/bin/python -m pytest -m slow              # real weights; needs requirements.txt
```
The fast loop deliberately excludes `torch`/`transformers` (`requirements-dev.txt`), so
model code must import them lazily. Slow suites are opt-in per roadmap §10.
Task definitions in `docs/roadmap.md` §7.
