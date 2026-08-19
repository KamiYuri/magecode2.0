from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator

from src.job import Detection
from src.result import (
    RESULT_QUEUE,
    SERVICE,
    STATUS_COMPLETED,
    STATUS_ERROR,
    STATUS_NOT_APPLICABLE,
    completed,
    failed,
    not_applicable,
)

# The contract itself, at the top of the source-of-truth hierarchy —
# validating against a copy would only prove AID agrees with AID.
SCHEMA_PATH = Path(__file__).resolve().parents[3] / "shared/schemas/result.analysis.v1.schema.json"

JOB = Detection(
    analysis_submission_id=501,
    submission_id=42,
    file_url="http://minio:9000/a",
    language="python",
    trace_id="1b4e28ba-2fa1-11d2-883f-0016d3cca427",
    timestamp="2026-08-19T09:30:00Z",
    version="1.0",
)


@pytest.fixture(scope="module")
def validator() -> Draft202012Validator:
    return Draft202012Validator(json.loads(SCHEMA_PATH.read_text()))


def assert_valid(validator: Draft202012Validator, message: dict) -> None:
    errors = sorted(validator.iter_errors(message), key=lambda error: error.path)
    assert not errors, "\n".join(error.message for error in errors)


def test_a_scored_submission_satisfies_the_schema(validator):
    message = completed(JOB, 0.8123, at=datetime(2026, 8, 19, 9, 31, tzinfo=timezone.utc))

    assert_valid(validator, message)
    assert message["service"] == SERVICE
    assert message["status"] == STATUS_COMPLETED
    assert message["probability"] == 0.8123
    assert message["timestamp"] == "2026-08-19T09:31:00Z"
    assert message["trace_id"] == JOB.trace_id


def test_an_unsupported_language_satisfies_the_schema(validator):
    message = not_applicable(JOB)

    assert_valid(validator, message)
    assert message["status"] == STATUS_NOT_APPLICABLE
    # The schema says probability is null unless the status is completed, and
    # api's column is NOT NULL — a 0.0 here would be stored as a finding.
    assert message["probability"] is None


def test_a_failure_satisfies_the_schema(validator):
    message = failed(JOB, RuntimeError("source download answered 403"))

    assert_valid(validator, message)
    assert message["status"] == STATUS_ERROR
    assert message["probability"] is None
    assert "403" in message["error_message"]


def test_the_boundary_probabilities_are_allowed(validator):
    for probability in (0.0, 1.0):
        assert_valid(validator, completed(JOB, probability))


def test_an_impossible_probability_is_refused_before_it_reaches_the_wire():
    for probability in (-0.1, 1.1):
        with pytest.raises(ValueError):
            completed(JOB, probability)


def test_the_schema_would_reject_a_malformed_message(validator):
    # Without this the assertions above could be passing against a schema that
    # accepts anything.
    broken = completed(JOB, 0.5)
    broken["probability"] = 1.5

    assert list(validator.iter_errors(broken))


def test_results_go_to_the_queue_api_listens_on():
    assert RESULT_QUEUE == "result-analysis"
