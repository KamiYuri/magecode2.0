"""The log shape is a contract with Loki, not a preference: dashboards and
queries written against the Go workers must read AID's lines too (D-88)."""

from __future__ import annotations

import json
import logging

from src.log import JsonFormatter, configure


def formatted(record: logging.LogRecord) -> dict:
    return json.loads(JsonFormatter().format(record))


def make_record(level: int = logging.INFO, message: str = "hello", **extra) -> logging.LogRecord:
    record = logging.LogRecord("ai-detector", level, __file__, 1, message, None, None)
    for key, value in extra.items():
        setattr(record, key, value)
    return record


def test_line_carries_the_shared_fields():
    entry = formatted(make_record())

    assert entry["service"] == "ai-detector"
    assert entry["level"] == "info"
    assert entry["message"] == "hello"
    assert entry["timestamp"].endswith("Z")


def test_warning_is_reported_as_warn():
    # slog says "warn"; python says "warning". A dashboard filtering on one
    # would miss half the fleet.
    assert formatted(make_record(level=logging.WARNING))["level"] == "warn"


def test_trace_id_and_data_are_top_level_fields():
    entry = formatted(make_record(trace_id="1b4e28ba", data={"submission_id": 11}))

    assert entry["trace_id"] == "1b4e28ba"
    assert entry["data"] == {"submission_id": 11}


def test_absent_context_is_omitted_rather_than_null():
    entry = formatted(make_record())

    assert "trace_id" not in entry
    assert "data" not in entry
    assert "error" not in entry


def test_error_is_rendered_as_a_string():
    entry = formatted(make_record(level=logging.ERROR, error=RuntimeError("broker is gone")))

    assert entry["error"] == "broker is gone"


def test_configure_replaces_its_handler_rather_than_stacking():
    first = configure()
    second = configure()

    assert first is second
    assert len(second.handlers) == 1
    # Propagation off, or every line prints twice — once as JSON and once in
    # the root logger's default format.
    assert second.propagate is False
