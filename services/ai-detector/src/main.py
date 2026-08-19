"""Entry point for the ai-detector worker (AID).

AID is stateless (D-80): a job carries the submission, its language and a
pre-signed URL, and everything AID learns goes back to api on the
result-analysis queue. There is no database and no MinIO credential here.
"""

from __future__ import annotations

import signal
import sys

from src.config import BOOL, FLOAT, INT, ConfigError, Field, load
from src.downloader import Downloader
from src.handler import Handler
from src.health import serve as serve_health
from src.job import QUEUE_NAME
from src.log import configure
from src.model import (
    DEFAULT_BATCH_SIZE,
    DEFAULT_CALIBRATION_MEAN,
    DEFAULT_CALIBRATION_SCALE,
    DEFAULT_DEVICE,
    DEFAULT_MODEL_NAME,
    Detector,
)
from src.rabbit import Connection, consume
from src.result import RESULT_QUEUE

# D-76: CES 5, SIM 1, AID/VUL 3.
DEFAULT_PREFETCH = "3"
# D-34 caps a submission at 50KB; this is headroom, not a second limit.
DEFAULT_MAX_SOURCE_BYTES = str(1 << 20)

SPEC = {
    "RABBITMQ_URL": Field(required=True),
    "RMQ_PREFETCH": Field(type=INT, default=DEFAULT_PREFETCH),
    "MAX_SOURCE_BYTES": Field(type=INT, default=DEFAULT_MAX_SOURCE_BYTES),
    "MODEL_NAME": Field(default=DEFAULT_MODEL_NAME),
    "BATCH_SIZE": Field(type=INT, default=str(DEFAULT_BATCH_SIZE)),
    "DEVICE": Field(default=DEFAULT_DEVICE),
    # The compose model-cache volume. transformers reads HF_HOME itself, but
    # passing it explicitly means a misconfigured cache fails here rather
    # than silently re-downloading the checkpoint on every restart.
    "HF_HOME": Field(default="/app/models"),
    "AID_CALIBRATION_MEAN": Field(type=FLOAT, default=str(DEFAULT_CALIBRATION_MEAN)),
    "AID_CALIBRATION_SCALE": Field(type=FLOAT, default=str(DEFAULT_CALIBRATION_SCALE)),
    # Loading the checkpoint takes seconds; doing it before the first message
    # means the first student scored does not wait for it.
    "WARM_MODEL": Field(type=BOOL, default="true"),
    # D-72: compose asks here. G1 will hang /metrics on the same server.
    "HEALTH_PORT": Field(type=INT, default="9090"),
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
        # Both queues are declared here: the result queue is published to, and
        # declaring it up front means a topology mismatch (PRECONDITION_FAILED)
        # is a startup failure rather than a lost result an hour in.
        broker = Connection(
            cfg["RABBITMQ_URL"],
            queues=(QUEUE_NAME, RESULT_QUEUE),
            prefetch=cfg["RMQ_PREFETCH"],
        )
    except Exception as error:  # noqa: BLE001 — startup failure is fatal, whatever it is
        logger.error("connecting to rabbitmq", extra={"error": error})
        return 1

    detector = Detector(
        model_name=cfg["MODEL_NAME"],
        device=cfg["DEVICE"],
        batch_size=cfg["BATCH_SIZE"],
        cache_dir=cfg["HF_HOME"],
        calibration_mean=cfg["AID_CALIBRATION_MEAN"],
        calibration_scale=cfg["AID_CALIBRATION_SCALE"],
    )

    if cfg["WARM_MODEL"]:
        try:
            detector.warm()
        except Exception as error:  # noqa: BLE001 — a bad checkpoint is fatal
            # Including the guard from E5: a checkpoint whose head is not
            # trained is refused here rather than scoring noise all semester.
            logger.error("loading model", extra={"error": error,
                                                 "data": {"model_name": cfg["MODEL_NAME"]}})
            broker.close()
            return 1

    # Readiness is the broker: pika's blocking connection knows whether it is
    # still open, and a worker that has lost it must not look healthy.
    health = serve_health(lambda: broker.connection.is_open, ("0.0.0.0", cfg["HEALTH_PORT"]))

    handler = Handler(
        downloader=Downloader(max_bytes=cfg["MAX_SOURCE_BYTES"]),
        detector=detector,
        channel=broker.channel,
        logger=logger,
    )

    logger.info(
        "worker started",
        extra={"data": {
            "queue": QUEUE_NAME,
            "prefetch": cfg["RMQ_PREFETCH"],
            "model_name": cfg["MODEL_NAME"],
            "device": cfg["DEVICE"],
        }},
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
        health.shutdown()
        health.server_close()
        broker.close()

    logger.info("shutdown complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
