"""Entry point for the ai-detector worker (AID).

AID is stateless (D-80): a job carries the submission, its language and a
pre-signed URL, and everything AID learns goes back to api on the
result-analysis queue. There is no database and no MinIO credential here.
"""

from __future__ import annotations

import signal
import sys

from src.config import INT, ConfigError, Field, load
from src.downloader import Downloader
from src.handler import Handler
from src.job import QUEUE_NAME
from src.log import configure
from src.rabbit import Connection, consume

# D-76: CES 5, SIM 1, AID/VUL 3.
DEFAULT_PREFETCH = "3"
# D-34 caps a submission at 50KB; this is headroom, not a second limit.
DEFAULT_MAX_SOURCE_BYTES = str(1 << 20)

SPEC = {
    "RABBITMQ_URL": Field(required=True),
    "RMQ_PREFETCH": Field(type=INT, default=DEFAULT_PREFETCH),
    "MAX_SOURCE_BYTES": Field(type=INT, default=DEFAULT_MAX_SOURCE_BYTES),
}


class Shutdown:
    """SIGTERM turns into a flag the consume loop checks between deliveries,
    so the message in hand is finished and settled before the process goes
    (D-73). Compose sends SIGTERM on `docker compose down`."""

    def __init__(self):
        self.requested = False

    def install(self) -> None:
        for received in (signal.SIGTERM, signal.SIGINT):
            signal.signal(received, self._request)

    def _request(self, *_):
        self.requested = True

    def __call__(self) -> bool:
        return self.requested


def main() -> int:
    logger = configure()

    try:
        cfg = load(SPEC)
    except ConfigError as error:
        logger.error("loading config", extra={"error": error})
        return 1

    shutdown = Shutdown()
    shutdown.install()

    try:
        broker = Connection(
            cfg["RABBITMQ_URL"], queues=(QUEUE_NAME,), prefetch=cfg["RMQ_PREFETCH"]
        )
    except Exception as error:  # noqa: BLE001 — startup failure is fatal, whatever it is
        logger.error("connecting to rabbitmq", extra={"error": error})
        return 1

    handler = Handler(downloader=Downloader(max_bytes=cfg["MAX_SOURCE_BYTES"]), logger=logger)

    logger.info(
        "worker started",
        extra={"data": {"queue": QUEUE_NAME, "prefetch": cfg["RMQ_PREFETCH"]}},
    )

    def report(error: BaseException, routing, trace: str) -> None:
        logger.warning(
            "delivery failed",
            extra={
                "trace_id": trace,
                "error": error,
                "data": {"queue": QUEUE_NAME, "action": routing.target,
                         "retry_count": routing.retry_count},
            },
        )

    try:
        consume(broker.channel, QUEUE_NAME, handler.handle, shutdown, on_failure=report)
    finally:
        broker.close()

    logger.info("shutdown complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
