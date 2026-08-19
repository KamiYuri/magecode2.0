from __future__ import annotations

import pytest
import requests

from src.downloader import Downloader
from src.errors import PermanentError, TransientError


class FakeResponse:
    def __init__(self, status_code: int, content: bytes = b"", raises: Exception | None = None):
        self.status_code = status_code
        self._content = content
        self._raises = raises

    def iter_content(self, chunk_size: int = 1):
        if self._raises is not None:
            raise self._raises
        for start in range(0, len(self._content), chunk_size):
            yield self._content[start : start + chunk_size]

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False


class FakeHttp:
    """Answers each call with the next scripted response."""

    def __init__(self, *responses):
        self.responses = list(responses)
        self.calls = 0

    def get(self, url, timeout=None, stream=False):
        self.calls += 1
        answer = self.responses[min(self.calls - 1, len(self.responses) - 1)]
        if isinstance(answer, Exception):
            raise answer
        return answer


def client(http, **kwargs) -> Downloader:
    return Downloader(http=http, base_delay_seconds=0.001, **kwargs)


def test_the_body_is_returned_as_text():
    http = FakeHttp(FakeResponse(200, "print('xin chào')\n".encode()))

    assert client(http).download("http://minio:9000/a") == "print('xin chào')\n"


def test_server_errors_are_retried():
    http = FakeHttp(FakeResponse(503), FakeResponse(502), FakeResponse(200, b"ok"))

    assert client(http).download("http://minio:9000/a") == "ok"
    assert http.calls == 3


def test_the_attempt_budget_is_finite_and_the_failure_stays_transient():
    # §2.5: at most three internal retries, then the caller reports an error
    # rather than retrying forever.
    http = FakeHttp(FakeResponse(503))

    with pytest.raises(TransientError):
        client(http).download("http://minio:9000/a")

    assert http.calls == 3


def test_a_client_error_is_permanent_and_not_retried():
    # 403 is what an expired pre-signed URL answers (D-85, 6h TTL).
    http = FakeHttp(FakeResponse(403))

    with pytest.raises(PermanentError) as raised:
        client(http).download("http://minio:9000/a")

    assert http.calls == 1
    assert "403" in str(raised.value)


def test_a_network_failure_is_transient():
    http = FakeHttp(requests.ConnectionError("connection refused"), FakeResponse(200, b"ok"))

    assert client(http).download("http://minio:9000/a") == "ok"


def test_an_oversized_body_is_refused():
    http = FakeHttp(FakeResponse(200, b"x" * 200))

    with pytest.raises(PermanentError):
        client(http, max_bytes=100).download("http://minio:9000/a")


def test_a_body_that_is_not_utf8_is_permanent():
    http = FakeHttp(FakeResponse(200, b"\xff\xfe\x00"))

    with pytest.raises(PermanentError):
        client(http).download("http://minio:9000/a")
