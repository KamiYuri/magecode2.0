"""Decoding the messages AID consumes from the ai-detector queue.

The wire format is `shared/schemas/job.ai-detector.v1.schema.json`, at the top
of the source-of-truth hierarchy. Decoding is strict because the schema sets
additionalProperties to false: a field AID does not know about means the
producer and this consumer disagree about the contract, and ignoring it would
hide that until something downstream broke.
"""

from __future__ import annotations

import json
from dataclasses import dataclass

from src.errors import PermanentError

QUEUE_NAME = "ai-detector"
VERSION = "1.0"

# The job schema's enum. It is wider than what the model serves — `c` is in the
# contract and not in AID's supported set — so the language gate lives in the
# model, not here: a language AID cannot score is answered `not_applicable`,
# which is a result, while a language the schema forbids is a broken producer.
LANGUAGES = ("python", "java", "c", "cpp")

_FIELDS = {
    "analysis_submission_id",
    "submission_id",
    "file_url",
    "language",
    "trace_id",
    "timestamp",
    "version",
}


@dataclass(frozen=True)
class Detection:
    """One job: a single submission to score."""

    analysis_submission_id: int
    submission_id: int
    file_url: str
    language: str
    trace_id: str
    timestamp: str
    version: str


def decode(body: bytes) -> Detection:
    """Parses and validates a message body.

    Every failure is PermanentError: a body AID cannot read reads no better on
    the third attempt, so it dead-letters now (D-79e) instead of occupying a
    prefetch slot three more times.
    """
    try:
        payload = json.loads(body)
    except (ValueError, TypeError) as error:
        raise PermanentError(f"decoding ai-detector job: {error}") from error

    if not isinstance(payload, dict):
        raise PermanentError("ai-detector job is not a JSON object")

    unknown = sorted(set(payload) - _FIELDS)
    if unknown:
        raise PermanentError(f"ai-detector job carries unknown field(s): {', '.join(unknown)}")

    version = payload.get("version")
    if version != VERSION:
        raise PermanentError(f"ai-detector job version {version!r} is not {VERSION!r}")

    for key in ("analysis_submission_id", "submission_id"):
        value = payload.get(key)
        # A bool is an int in Python, and `True` is not an id.
        if not isinstance(value, int) or isinstance(value, bool) or value < 1:
            raise PermanentError(f"ai-detector job has no usable {key} ({value!r})")

    file_url = payload.get("file_url")
    if not isinstance(file_url, str) or file_url == "":
        raise PermanentError("ai-detector job carries no file_url")

    language = payload.get("language")
    if language not in LANGUAGES:
        raise PermanentError(
            f"ai-detector job language {language!r} is not one of {', '.join(LANGUAGES)}"
        )

    trace_id = payload.get("trace_id")
    if not isinstance(trace_id, str) or trace_id == "":
        raise PermanentError("ai-detector job carries no trace_id")

    return Detection(
        analysis_submission_id=payload["analysis_submission_id"],
        submission_id=payload["submission_id"],
        file_url=file_url,
        language=language,
        trace_id=trace_id,
        timestamp=str(payload.get("timestamp", "")),
        version=version,
    )
