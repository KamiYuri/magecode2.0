"""Entry point for the ai-detector worker.

A7 scaffold: emits a D-88 JSON startup log and exits. The RabbitMQ consumer,
CodeBERT inference, and result publishing land in Plan E (E4-E6).
"""

import json
import logging
import sys
from datetime import datetime, timezone

SERVICE_NAME = "ai-detector"


class JsonFormatter(logging.Formatter):
    """Formats records to the D-88 unified JSON log shape."""

    def format(self, record: logging.LogRecord) -> str:
        entry = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname.lower().replace("warning", "warn"),
            "service": SERVICE_NAME,
            "message": record.getMessage(),
        }
        if record.exc_info:
            entry["error"] = self.formatException(record.exc_info)
        return json.dumps(entry)


def configure_logging() -> logging.Logger:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    logger = logging.getLogger(SERVICE_NAME)
    logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    return logger


def main() -> None:
    logger = configure_logging()
    logger.info("ai-detector scaffold started; consumer arrives with E4")


if __name__ == "__main__":
    main()
