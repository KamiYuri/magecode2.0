"""D-88 structured logging for AID.

The shape is the one `shared/go/logger` emits, because Loki queries and
dashboards read both: `timestamp`, `level`, `service`, `message`, and the
optional `trace_id`, `data` and `error`. Anything else in the line makes a
query that works for the Go workers fail for this one.

The module is named `log` rather than `logging` so nothing inside the package
has to think about which one an import means.
"""

from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, timezone
from typing import Any

SERVICE_NAME = "ai-detector"

# Keys the formatter puts in the line itself; anything else attached to a
# record is caller data and would otherwise be silently dropped.
_STRUCTURED_FIELDS = ("trace_id", "data")


class JsonFormatter(logging.Formatter):
    """Formats records to the D-88 unified JSON log shape."""

    def format(self, record: logging.LogRecord) -> str:
        entry: dict[str, Any] = {
            "timestamp": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%f")[:-3] + "Z",
            "level": record.levelname.lower().replace("warning", "warn"),
            "service": SERVICE_NAME,
            "message": record.getMessage(),
        }

        for field in _STRUCTURED_FIELDS:
            value = getattr(record, field, None)
            if value not in (None, {}):
                entry[field] = value

        error = getattr(record, "error", None)
        if error is not None:
            entry["error"] = str(error)
        elif record.exc_info:
            entry["error"] = self.formatException(record.exc_info)

        return json.dumps(entry, default=str)


def configure(level: int = logging.INFO) -> logging.Logger:
    """Installs the JSON handler on the service logger and returns it.

    Calling twice replaces the handler rather than adding a second, so a
    reconfigure in a test does not double every line.
    """
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())

    logger = logging.getLogger(SERVICE_NAME)
    logger.handlers = [handler]
    logger.setLevel(level)
    # Records stop here: propagating to the root logger would print each line
    # a second time in its default, non-JSON format.
    logger.propagate = False
    return logger
