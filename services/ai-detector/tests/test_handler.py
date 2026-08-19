"""E6's contract: what AID sends back, and what it does when things fail.

The rule under test is that AID always answers. api is waiting on a result for
this submission, so a failure it can describe goes out as status=error rather
than dead-lettering the batch into D-82's 30-minute timeout.
"""

from __future__ import annotations

import json

import pytest

from src.errors import PermanentError, TransientError
from src.handler import Handler
from src.model import Detector
from src.result import STATUS_COMPLETED, STATUS_ERROR, STATUS_NOT_APPLICABLE

JOB = {
    "analysis_submission_id": 501,
    "submission_id": 42,
    "file_url": "http://minio:9000/a",
    "language": "python",
    "trace_id": "1b4e28ba-2fa1-11d2-883f-0016d3cca427",
    "timestamp": "2026-08-19T09:30:00Z",
    "version": "1.0",
}


def body(**overrides) -> bytes:
    return json.dumps({**JOB, **overrides}).encode()


class FakeChannel:
    def __init__(self, error: Exception | None = None):
        self.published: list[dict] = []
        self.error = error

    def basic_publish(self, exchange, routing_key, body, properties):
        if self.error is not None:
            raise self.error
        self.published.append({"routing_key": routing_key, "body": json.loads(body),
                               "properties": properties})

    def only(self) -> dict:
        assert len(self.published) == 1, f"published {len(self.published)} messages"
        return self.published[0]["body"]


class ScriptedDownloader:
    """Answers each call with the next scripted item; an exception is raised."""

    def __init__(self, *answers):
        self.answers = list(answers)
        self.calls = 0

    def download(self, url: str) -> str:
        self.calls += 1
        answer = self.answers[min(self.calls - 1, len(self.answers) - 1)]
        if isinstance(answer, Exception):
            raise answer
        return answer


class ScriptedDetector:
    def __init__(self, *answers):
        self.answers = list(answers)
        self.calls = 0

    def score(self, source: str, language: str):
        self.calls += 1
        answer = self.answers[min(self.calls - 1, len(self.answers) - 1)]
        if isinstance(answer, Exception):
            raise answer
        return answer


class SilentLogger:
    def info(self, *_, **__): ...
    def warning(self, *_, **__): ...
    def error(self, *_, **__): ...


def handler(channel, downloader, detector, **kwargs) -> Handler:
    return Handler(
        downloader=downloader,
        detector=detector,
        channel=channel,
        logger=SilentLogger(),
        sleep=lambda _: None,
        **kwargs,
    )


def test_a_scored_submission_is_published():
    channel = FakeChannel()

    handler(channel, ScriptedDownloader("print(1)"), ScriptedDetector(0.73)).handle(body())

    sent = channel.published[0]
    assert sent["routing_key"] == "result-analysis"
    assert sent["properties"].headers["trace_id"] == JOB["trace_id"]
    assert sent["body"]["status"] == STATUS_COMPLETED
    assert sent["body"]["probability"] == 0.73
    assert sent["body"]["analysis_submission_id"] == 501


def test_an_unsupported_language_is_answered_not_applicable():
    channel = FakeChannel()
    # The real Detector returns None for a language it does not serve; D6
    # counts not_applicable as terminal, so the batch closes without it.
    handler(channel, ScriptedDownloader("int main(){}"), Detector(loader=lambda: None)).handle(
        body(language="c")
    )

    message = channel.only()
    assert message["status"] == STATUS_NOT_APPLICABLE
    assert message["probability"] is None


def test_a_transient_download_failure_is_retried_and_then_succeeds():
    downloader = ScriptedDownloader(
        TransientError("minio is unreachable"),
        TransientError("minio is unreachable"),
        "print(1)",
    )
    channel = FakeChannel()

    handler(channel, downloader, ScriptedDetector(0.5)).handle(body())

    assert downloader.calls == 3
    assert channel.only()["status"] == STATUS_COMPLETED


def test_an_exhausted_retry_budget_is_reported_as_an_error():
    # §2.5: publish status=error only after exhausting retries.
    downloader = ScriptedDownloader(TransientError("minio is unreachable"))
    channel = FakeChannel()

    handler(channel, downloader, ScriptedDetector(0.5)).handle(body())

    assert downloader.calls == 3
    message = channel.only()
    assert message["status"] == STATUS_ERROR
    assert message["probability"] is None
    assert "minio is unreachable" in message["error_message"]


def test_a_permanent_failure_is_not_retried():
    # An expired pre-signed URL answers 403 every time; three attempts only
    # delay the error result api is waiting for.
    downloader = ScriptedDownloader(PermanentError("source download answered 403"))
    channel = FakeChannel()

    handler(channel, downloader, ScriptedDetector(0.5)).handle(body())

    assert downloader.calls == 1
    assert channel.only()["status"] == STATUS_ERROR


def test_a_model_failure_is_reported_rather_than_swallowed():
    channel = FakeChannel()

    handler(
        channel,
        ScriptedDownloader("print(1)"),
        ScriptedDetector(ValueError("submission source is empty")),
    ).handle(body())

    message = channel.only()
    assert message["status"] == STATUS_ERROR
    assert "empty" in message["error_message"]


def test_an_undecodable_body_is_raised_so_it_dead_letters():
    channel = FakeChannel()

    with pytest.raises(PermanentError):
        handler(channel, ScriptedDownloader("x"), ScriptedDetector(0.5)).handle(b"{")

    # Nothing is published: there is no analysis_submission_id to answer for.
    assert channel.published == []


def test_a_broker_failure_reaches_the_consumer_loop():
    # The loop retries the delivery. Swallowing this would lose the score and
    # leave the batch waiting for a message nobody will send.
    channel = FakeChannel(error=TransientError("broker is gone"))

    with pytest.raises(TransientError):
        handler(channel, ScriptedDownloader("print(1)"), ScriptedDetector(0.5)).handle(body())
