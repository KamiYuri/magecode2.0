# ai-detector (AID) — Agent Context

## Purpose
Stateless batch worker (D-80: no DB access). Consumes `ai-detector` jobs
(one per analysis submission, pre-signed file URL), downloads the source,
scores AI-generation likelihood, publishes full results to `result-analysis`.

## Status
E4 + E5 done: consumer, job decode, downloader, and the scorer. E6 (result publish)
still to come.

## Tech Stack
Python 3.12, JSON logging to stdout (D-88), pika, requests. Model:
`microsoft/codebert-base-mlm` (env-overridable). No DB driver — D-80 forbids DB access.

**Not `microsoft/codebert-base`.** That release is the encoder alone: loaded as a masked
LM its `lm_head` is randomly initialised, and it scored two fixtures 1e-13 apart — noise
with a decimal point. The loader now refuses any checkpoint whose weights are missing
(v3 §7, 2026-08-19).

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
- `src/model.py` — the pluggable scorer. A `*ForSequenceClassification` checkpoint is used
  as a detector; anything else is scored on **predictability** (mean masked-token negative
  log-likelihood, strided). The calibration constants are unfitted placeholders — they set
  where the 50/50 line falls, not the ordering

## Env Vars
| Var | Required | Default | Notes |
|---|---|---|---|
| `RABBITMQ_URL` | yes | — | amqp URL incl. vhost |
| `RMQ_PREFETCH` | no | 3 | D-76: AID prefetch 3 |
| `MAX_SOURCE_BYTES` | no | 1048576 | Refuses a body that is not a submission (D-34 caps at 50KB) |
| `MODEL_NAME` | no | microsoft/codebert-base-mlm | HF model id; a `*ForSequenceClassification` checkpoint switches the scorer |
| `BATCH_SIZE` | no | 8 | inference batch size |
| `DEVICE` | no | cpu | torch device |
| `HF_HOME` | no | /app/models | the compose `model-cache` volume |
| `AID_CALIBRATION_MEAN` | no | 1.40 | NLL that scores 0.5 (predictability scorer only) |
| `AID_CALIBRATION_SCALE` | no | 0.15 | how fast the curve leaves the midpoint |

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
