"""These pin the transcription. `shared/go/rmq` is the original; a divergence
here is a PRECONDITION_FAILED in production, on whichever process declares
second."""

from __future__ import annotations

import pika

from src.errors import PermanentError, TransientError
from src.rabbit import (
    DEFAULT_MAX_RETRIES,
    HEADER_LAST_ERROR,
    HEADER_RETRY_COUNT,
    HEADER_TRACE_ID,
    consume,
    declare_topology,
    publish,
    retry_count,
    route_failure,
)


class FakeChannel:
    def __init__(self, deliveries=None):
        self.declared: list[tuple] = []
        self.published: list[dict] = []
        self.acked: list[int] = []
        self.cancelled = False
        self._deliveries = list(deliveries or [])
        self.is_open = True

    def queue_declare(self, queue, durable=False, arguments=None, **_):
        self.declared.append((queue, durable, arguments))

    def basic_publish(self, exchange, routing_key, body, properties):
        self.published.append(
            {"exchange": exchange, "routing_key": routing_key, "body": body, "properties": properties}
        )

    def basic_ack(self, delivery_tag):
        self.acked.append(delivery_tag)

    def consume(self, queue, inactivity_timeout=None):
        yield from self._deliveries

    def cancel(self):
        self.cancelled = True


class Method:
    def __init__(self, delivery_tag: int):
        self.delivery_tag = delivery_tag


def properties(**headers):
    return pika.BasicProperties(headers=headers or None)


def test_the_trio_is_declared_exactly_as_the_go_client_declares_it():
    channel = FakeChannel()

    declare_topology(channel, "ai-detector")

    assert channel.declared == [
        ("ai-detector.dlq", True, None),
        (
            "ai-detector.retry",
            True,
            {"x-dead-letter-exchange": "", "x-dead-letter-routing-key": "ai-detector"},
        ),
        ("ai-detector", True, None),
    ]


def test_publishing_uses_the_default_exchange():
    # A named exchange with no binding drops every message silently — the
    # mistake C3 caught on the api side.
    channel = FakeChannel()

    publish(channel, "result-analysis", b"{}", "trace-1")

    sent = channel.published[0]
    assert sent["exchange"] == ""
    assert sent["routing_key"] == "result-analysis"
    assert sent["properties"].delivery_mode == 2
    assert sent["properties"].content_type == "application/json"
    assert sent["properties"].headers[HEADER_TRACE_ID] == "trace-1"


def test_a_permanent_failure_dead_letters_at_once():
    routing = route_failure(PermanentError("unreadable"), consumed=0)

    assert routing.target == "dlq"
    assert routing.expiration_ms is None


def test_a_transient_failure_retries_with_exponential_backoff():
    assert route_failure(TransientError("broker"), consumed=0).expiration_ms == 1000
    assert route_failure(TransientError("broker"), consumed=1).expiration_ms == 2000
    assert route_failure(TransientError("broker"), consumed=2).expiration_ms == 4000


def test_an_unclassified_failure_is_treated_as_transient():
    # Matching routeFailure: a flaky third-party failure still gets its three
    # attempts before it parks.
    assert route_failure(RuntimeError("who knows"), consumed=0).target == "retry"


def test_the_retry_budget_is_finite():
    assert route_failure(TransientError("broker"), consumed=DEFAULT_MAX_RETRIES).target == "dlq"


def test_a_missing_retry_header_counts_as_the_first_delivery():
    assert retry_count(properties()) == 0
    assert retry_count(properties(**{HEADER_RETRY_COUNT: 2})) == 2


def test_a_handled_delivery_is_acked_and_nothing_is_republished():
    channel = FakeChannel([(Method(1), properties(), b'{"ok":true}')])
    seen = []

    consume(channel, "ai-detector", lambda body, props: seen.append(body), should_stop=lambda: True)

    assert seen == [b'{"ok":true}']
    assert channel.acked == [1]
    assert channel.published == []
    assert channel.cancelled is True


def test_a_failed_delivery_is_republished_with_its_counters_and_then_acked():
    channel = FakeChannel([(Method(7), properties(**{HEADER_TRACE_ID: "trace-1"}), b"{}")])

    def failing(body, props):
        raise TransientError("minio is unreachable")

    consume(channel, "ai-detector", failing, should_stop=lambda: True)

    sent = channel.published[0]
    assert sent["routing_key"] == "ai-detector.retry"
    assert sent["properties"].expiration == "1000"
    assert sent["properties"].headers[HEADER_RETRY_COUNT] == 1
    assert "minio is unreachable" in sent["properties"].headers[HEADER_LAST_ERROR]
    assert sent["properties"].headers[HEADER_TRACE_ID] == "trace-1"
    # The original is acked once its copy is safely on the retry queue —
    # otherwise the message exists twice or not at all.
    assert channel.acked == [7]


def test_a_permanently_failed_delivery_goes_to_the_dlq():
    channel = FakeChannel([(Method(3), properties(), b"{")])

    def failing(body, props):
        raise PermanentError("ai-detector job carries no file_url")

    consume(channel, "ai-detector", failing, should_stop=lambda: True)

    assert channel.published[0]["routing_key"] == "ai-detector.dlq"
    assert channel.published[0]["properties"].expiration is None
    assert channel.acked == [3]


def test_an_exhausted_message_stops_being_retried():
    channel = FakeChannel([(Method(4), properties(**{HEADER_RETRY_COUNT: 3}), b"{}")])

    consume(
        channel,
        "ai-detector",
        lambda body, props: (_ for _ in ()).throw(TransientError("still down")),
        should_stop=lambda: True,
    )

    assert channel.published[0]["routing_key"] == "ai-detector.dlq"


def test_the_loop_finishes_the_message_in_hand_before_stopping():
    # D-73: SIGTERM drains. Deliveries are settled one at a time, so there is
    # never an in-flight message to abandon.
    channel = FakeChannel(
        [
            (Method(1), properties(), b"{}"),
            (Method(2), properties(), b"{}"),
        ]
    )
    stopping = {"value": False}
    handled = []

    def handler(body, props):
        handled.append(body)
        stopping["value"] = True

    consume(channel, "ai-detector", handler, should_stop=lambda: stopping["value"])

    assert len(handled) == 1
    assert channel.acked == [1]
    assert channel.cancelled is True


def test_an_idle_tick_is_not_a_delivery():
    channel = FakeChannel([(None, None, None), (Method(1), properties(), b"{}")])
    handled = []

    consume(channel, "ai-detector", lambda body, props: handled.append(body), should_stop=lambda: False)

    assert handled == [b"{}"]
