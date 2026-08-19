"""What AID does with one job: fetch the source, score it, answer api.

**AID always answers.** api is waiting on a result for this submission, and a
job that dead-letters leaves the batch to burn D-82's 30-minute timeout for an
answer AID already has. So a failure AID can describe — an expired URL, a model
that would not load, source it cannot read — is published as `status: error`
and acked, which is §2.5's "publish error only after exhausting retries" seen
from the other side. The two exceptions are a body that cannot be decoded (it
names no analysis_submission_id to answer for, so the DLQ is the only place it
can go) and a broker that cannot be reached (retried, because acking would
throw the score away).
"""

from __future__ import annotations

import time
from dataclasses import dataclass
from typing import Any, Callable

from src import result
from src.downloader import Downloader
from src.errors import is_transient
from src.job import decode
from src.model import Detector
from src.rabbit import publish

# §2.5: services retry transient failures internally, at most three times,
# before reporting an error. The downloader has its own budget for the network;
# this one covers the whole attempt, including the model.
DEFAULT_ATTEMPTS = 3
DEFAULT_BASE_DELAY_SECONDS = 0.5


@dataclass
class Handler:
    """Processes one delivery."""

    downloader: Downloader
    detector: Detector
    channel: Any
    logger: Any
    # Overridable so an integration test can drive the loop without racing
    # api's own consumer for messages on the real queue.
    result_queue: str = result.RESULT_QUEUE
    attempts: int = DEFAULT_ATTEMPTS
    base_delay_seconds: float = DEFAULT_BASE_DELAY_SECONDS
    sleep: Callable[[float], None] = time.sleep

    def handle(self, body: bytes, properties: Any = None) -> None:
        # Not caught: an undecodable body names no submission to answer for.
        job = decode(body)

        self.logger.info(
            "scoring submission",
            extra={
                "trace_id": job.trace_id,
                "data": {
                    "analysis_submission_id": job.analysis_submission_id,
                    "submission_id": job.submission_id,
                    "language": job.language,
                },
            },
        )

        started = time.monotonic()

        try:
            message = self._score(job)
        except BaseException as error:  # noqa: BLE001 — the answer is the point
            self.logger.error(
                "scoring failed",
                extra={
                    "trace_id": job.trace_id,
                    "error": error,
                    "data": {"analysis_submission_id": job.analysis_submission_id},
                },
            )
            message = result.failed(job, error)

        # Publishing is outside the try: a broker failure must reach the
        # consumer loop, which retries the delivery, rather than being
        # reported as a scoring error that never gets sent either.
        publish(self.channel, self.result_queue, result.encode(message), job.trace_id)

        self.logger.info(
            "result published",
            extra={
                "trace_id": job.trace_id,
                "data": {
                    "analysis_submission_id": job.analysis_submission_id,
                    "status": message["status"],
                    "probability": message.get("probability"),
                    "duration_ms": int((time.monotonic() - started) * 1000),
                },
            },
        )

    def _score(self, job) -> dict:
        source = self._with_retries(lambda: self.downloader.download(job.file_url), job)

        probability = self._with_retries(lambda: self.detector.score(source, job.language), job)
        if probability is None:
            # A language the model does not serve is a result, not a failure:
            # api marks the submission not_applicable and the batch closes
            # without waiting for it (D6 counts not_applicable as terminal).
            self.logger.info(
                "language not served by the model",
                extra={
                    "trace_id": job.trace_id,
                    "data": {"analysis_submission_id": job.analysis_submission_id,
                             "language": job.language},
                },
            )
            return result.not_applicable(job)

        return result.completed(job, probability)

    def _with_retries(self, work: Callable[[], Any], job) -> Any:
        """Runs `work`, retrying transient failures with exponential backoff.

        A permanent failure is raised at once — retrying an expired pre-signed
        URL only delays the error result api is waiting for.
        """
        delay = self.base_delay_seconds
        last: BaseException | None = None

        for attempt in range(1, self.attempts + 1):
            try:
                return work()
            except BaseException as error:  # noqa: BLE001 — classification decides
                if not is_transient(error):
                    raise
                last = error
                self.logger.warning(
                    "transient failure, retrying",
                    extra={
                        "trace_id": job.trace_id,
                        "error": error,
                        "data": {"analysis_submission_id": job.analysis_submission_id,
                                 "attempt": attempt, "attempts": self.attempts},
                    },
                )
                if attempt < self.attempts:
                    self.sleep(delay)
                    delay *= 2

        raise last if last is not None else RuntimeError("no attempt was made")
