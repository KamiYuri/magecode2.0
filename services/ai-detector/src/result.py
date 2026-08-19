"""The messages AID sends back to api on the result-analysis queue.

The wire format is the AID branch of
`shared/schemas/result.analysis.v1.schema.json`. api is the single writer of
analysis rows (D-80), so this message is everything a batch will know about
this submission — including its failures, because a job that dead-letters
leaves api waiting until D-82's 30-minute sweep for an answer AID already has.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

from src.job import Detection

RESULT_QUEUE = "result-analysis"
SERVICE = "ai-detector"
VERSION = "1.0"

STATUS_COMPLETED = "completed"
STATUS_ERROR = "error"
STATUS_NOT_APPLICABLE = "not_applicable"


def _timestamp(at: datetime | None = None) -> str:
    moment = (at or datetime.now(timezone.utc)).astimezone(timezone.utc)
    return moment.strftime("%Y-%m-%dT%H:%M:%SZ")


def _envelope(job: Detection, status: str, at: datetime | None) -> dict[str, Any]:
    return {
        "analysis_submission_id": job.analysis_submission_id,
        "service": SERVICE,
        "status": status,
        # The job's trace id, not a new one: it is what joins the two sides of
        # this submission in Loki (D-88).
        "trace_id": job.trace_id,
        "timestamp": _timestamp(at),
        "version": VERSION,
    }


def completed(job: Detection, probability: float, at: datetime | None = None) -> dict[str, Any]:
    """A scored submission."""
    if not 0.0 <= probability <= 1.0:
        raise ValueError(f"probability {probability!r} is outside 0..1")

    return {**_envelope(job, STATUS_COMPLETED, at), "probability": probability}


def not_applicable(job: Detection, at: datetime | None = None) -> dict[str, Any]:
    """A language AID does not serve. `probability` is null, which the schema
    requires for any status other than completed."""
    return {**_envelope(job, STATUS_NOT_APPLICABLE, at), "probability": None}


def failed(job: Detection, cause: BaseException | str, at: datetime | None = None) -> dict[str, Any]:
    """A submission AID could not score.

    api stores a completed result with a null probability as an error anyway
    (D5) — the column is NOT NULL and telling an instructor a detection
    completed with nothing behind it is worse than reporting the failure — so
    the two sides agree on this shape.
    """
    return {
        **_envelope(job, STATUS_ERROR, at),
        "probability": None,
        # Optional in the schema and for debugging only: api logs it and
        # never shows it to a user (§2.5).
        "error_message": str(cause)[:1000],
    }


def encode(message: dict[str, Any]) -> bytes:
    """Renders a message body."""
    return json.dumps(message).encode()
