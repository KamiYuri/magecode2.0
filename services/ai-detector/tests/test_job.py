from __future__ import annotations

import json

import pytest

from src.errors import PermanentError, is_transient
from src.job import decode

VALID = {
    "analysis_submission_id": 501,
    "submission_id": 42,
    "file_url": "http://minio:9000/magecode/submissions/1/42/main.py?X-Amz-Signature=x",
    "language": "python",
    "trace_id": "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
    "timestamp": "2026-08-19T09:30:00Z",
    "version": "1.0",
}


def body(**overrides) -> bytes:
    payload = dict(VALID)
    payload.update(overrides)
    for key, value in list(payload.items()):
        if value is ...:
            del payload[key]
    return json.dumps(payload).encode()


def test_a_well_formed_job_is_decoded():
    job = decode(body())

    assert job.analysis_submission_id == 501
    assert job.submission_id == 42
    assert job.language == "python"
    assert job.trace_id == "1b4e28ba-2fa1-11d2-883f-0016d3cca427"


@pytest.mark.parametrize(
    "case,raw",
    [
        ("not json", b"{"),
        ("empty body", b""),
        ("a json array", b"[]"),
        ("unknown field", json.dumps({**VALID, "probability": 0.5}).encode()),
        ("version 2.0", body(version="2.0")),
        ("missing version", body(version=...)),
        ("no analysis_submission_id", body(analysis_submission_id=...)),
        ("zero analysis_submission_id", body(analysis_submission_id=0)),
        ("id as string", body(submission_id="42")),
        ("id as bool", body(submission_id=True)),
        ("empty file_url", body(file_url="")),
        ("language outside the enum", body(language="rust")),
        ("no trace_id", body(trace_id=...)),
    ],
)
def test_unusable_messages_are_rejected_permanently(case: str, raw: bytes):
    # Permanent means the DLQ now rather than three more identical failures
    # holding a prefetch slot (D-79e).
    with pytest.raises(PermanentError) as raised:
        decode(raw)

    assert is_transient(raised.value) is False


def test_the_rejection_names_the_unknown_field():
    with pytest.raises(PermanentError) as raised:
        decode(json.dumps({**VALID, "compared_submissions": 3}).encode())

    assert "compared_submissions" in str(raised.value)


def test_c_is_in_the_contract_and_left_to_the_language_gate():
    # The schema's enum carries `c` while the model serves python, java and
    # cpp: an unsupported language is a not_applicable result, not a broken
    # message, so decoding must not refuse it.
    assert decode(body(language="c")).language == "c"
