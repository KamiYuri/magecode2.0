"""Fetching submission sources from the pre-signed URLs the job carries.

AID holds no MinIO credentials (D-80/D-85): the URL is the whole of its access,
signed against the internal endpoint for six hours. So this is a plain GET with
a retry budget, and its real job is classification — §2.5 asks services to
retry transient failures internally, at most three times, before reporting an
error.
"""

from __future__ import annotations

import time
from dataclasses import dataclass

import requests

from src.errors import PermanentError, TransientError

# D-34 caps a submission at 50KB; the ceiling here is generous headroom, meant
# to refuse something that is not a submission at all rather than to
# re-enforce the API's limit.
DEFAULT_MAX_BYTES = 1 << 20
DEFAULT_ATTEMPTS = 3
DEFAULT_BASE_DELAY_SECONDS = 0.5
DEFAULT_TIMEOUT_SECONDS = 30.0


@dataclass(frozen=True)
class Downloader:
    """Downloads sources. Immutable and safe to share."""

    max_bytes: int = DEFAULT_MAX_BYTES
    attempts: int = DEFAULT_ATTEMPTS
    base_delay_seconds: float = DEFAULT_BASE_DELAY_SECONDS
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS
    # Injected in tests; None means the module-level `requests` API.
    http: object = None

    def download(self, url: str) -> str:
        """Returns the source as text, retrying transient failures.

        Raises PermanentError for a 4xx — an expired or wrong pre-signed URL
        answers the same way every time — or an oversized body, and
        TransientError once the attempt budget is spent.
        """
        delay = self.base_delay_seconds
        last: Exception | None = None

        for attempt in range(1, self.attempts + 1):
            try:
                return self._attempt(url)
            except PermanentError:
                raise
            except TransientError as error:
                last = error
                if attempt < self.attempts:
                    time.sleep(delay)
                    delay *= 2

        raise TransientError(f"downloading source after {self.attempts} attempts: {last}")

    def _attempt(self, url: str) -> str:
        client = self.http or requests

        try:
            response = client.get(url, timeout=self.timeout_seconds, stream=True)
        except requests.RequestException as error:
            raise TransientError(f"requesting source: {error}") from error

        with response:
            status = response.status_code
            if 400 <= status < 500:
                raise PermanentError(
                    f"source download answered {status} — the pre-signed URL is expired or wrong"
                )
            if status != 200:
                raise TransientError(f"source download answered {status}")

            # Streamed and capped: a body larger than any submission this
            # system stores is refused rather than read into memory.
            chunks: list[bytes] = []
            size = 0
            try:
                for chunk in response.iter_content(chunk_size=64 * 1024):
                    if not chunk:
                        continue
                    chunks.append(chunk)
                    size += len(chunk)
                    if size > self.max_bytes:
                        raise PermanentError(f"source exceeds the {self.max_bytes} byte cap")
            except requests.RequestException as error:
                raise TransientError(f"reading source body: {error}") from error

        # Student source is scored as text; a file that is not decodable as
        # UTF-8 is not source this system stored.
        try:
            return b"".join(chunks).decode("utf-8")
        except UnicodeDecodeError as error:
            raise PermanentError(f"source is not valid UTF-8: {error}") from error
