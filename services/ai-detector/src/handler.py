"""What AID does with one job.

E4 stages the work — decode the message, fetch the source — and logs what it
found. Scoring lands in E5 and the result message in E6.
"""

from __future__ import annotations

import time
from dataclasses import dataclass

from src.downloader import Downloader
from src.job import decode


@dataclass
class Handler:
    """Processes one delivery."""

    downloader: Downloader
    logger: object

    def handle(self, body: bytes, properties=None) -> None:
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
        source = self.downloader.download(job.file_url)

        self.logger.info(
            "source ready",
            extra={
                "trace_id": job.trace_id,
                "data": {
                    "analysis_submission_id": job.analysis_submission_id,
                    "bytes": len(source.encode("utf-8")),
                    "duration_ms": int((time.monotonic() - started) * 1000),
                },
            },
        )
