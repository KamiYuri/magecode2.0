"""RabbitMQ for AID: the same topology, headers and retry routing the Go
workers use.

**This file is a transcription of `shared/go/rmq`, and must stay one.** The Go
services and this one declare the same queues on the same broker, and RabbitMQ
answers a mismatched redeclare with PRECONDITION_FAILED — whichever process
declares second dies. C3 learned that on the api side; the integration suite
pins it here.

The parts that have to agree:
  * the per-queue trio of D-79e — `Q`, `Q.retry` (dead-lettering back to `Q`
    when its per-message TTL expires) and `Q.dlq` — all durable, and only the
    retry queue carrying arguments;
  * the default exchange with the queue name as routing key, never a named
    exchange (a named exchange with no binding drops every message silently);
  * the headers `trace_id`, `x-retry-count` and `x-last-error`;
  * the routing decision: Permanent dead-letters at once, anything else
    retries with `base << retry_count` until the budget is spent.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Any, Callable, Iterator

import pika
from pika.exchange_type import ExchangeType  # noqa: F401 — documents the default-exchange choice

from src.errors import is_transient

HEADER_RETRY_COUNT = "x-retry-count"
HEADER_LAST_ERROR = "x-last-error"
HEADER_TRACE_ID = "trace_id"

DEFAULT_MAX_RETRIES = 3
DEFAULT_RETRY_BASE_DELAY_MS = 1000
# D-76: CES 5, SIM 1, AID/VUL 3.
DEFAULT_PREFETCH = 3


def retry_queue(queue: str) -> str:
    return f"{queue}.retry"


def dlq(queue: str) -> str:
    return f"{queue}.dlq"


def declare_topology(channel, queue: str) -> None:
    """Declares the durable trio, in the same order and with the same
    arguments as `declareTopology` in `shared/go/rmq/client.go`. Idempotent."""
    channel.queue_declare(queue=dlq(queue), durable=True)
    channel.queue_declare(
        queue=retry_queue(queue),
        durable=True,
        arguments={"x-dead-letter-exchange": "", "x-dead-letter-routing-key": queue},
    )
    channel.queue_declare(queue=queue, durable=True)


def retry_count(properties) -> int:
    headers = getattr(properties, "headers", None) or {}
    value = headers.get(HEADER_RETRY_COUNT, 0)
    return value if isinstance(value, int) and not isinstance(value, bool) else 0


def trace_id(properties) -> str:
    headers = getattr(properties, "headers", None) or {}
    value = headers.get(HEADER_TRACE_ID, "")
    return value if isinstance(value, str) else ""


@dataclass(frozen=True)
class Routing:
    """What happens to a failed delivery."""

    target: str
    expiration_ms: int | None
    retry_count: int


def route_failure(
    error: BaseException,
    consumed: int,
    max_retries: int = DEFAULT_MAX_RETRIES,
    base_delay_ms: int = DEFAULT_RETRY_BASE_DELAY_MS,
) -> Routing:
    """The Python form of `routeFailure`. `consumed` is how many retries the
    message has already used (0 on first delivery)."""
    if not is_transient(error) or consumed >= max_retries:
        return Routing(target="dlq", expiration_ms=None, retry_count=consumed)
    return Routing(target="retry", expiration_ms=base_delay_ms << consumed, retry_count=consumed + 1)


def publish(channel, queue: str, body: bytes, trace: str, expiration_ms: int | None = None,
            headers: dict[str, Any] | None = None) -> None:
    """Publishes over the **default exchange**, persistent, with the queue name
    as routing key — exactly as `shared/go/rmq.Publisher` does."""
    properties = pika.BasicProperties(
        content_type="application/json",
        delivery_mode=2,
        headers={HEADER_TRACE_ID: trace, **(headers or {})},
        expiration=None if expiration_ms is None else str(expiration_ms),
    )
    channel.basic_publish(exchange="", routing_key=queue, body=body, properties=properties)


def encode(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload).encode()


class Connection:
    """One blocking connection and channel, with the topology declared.

    pika's blocking adapter is not thread-safe, so a single connection serves
    both the consume loop and the result publishes: AID handles one message at
    a time (prefetch buys buffering, not parallelism), which makes that the
    simple and correct arrangement rather than a compromise.
    """

    def __init__(self, url: str, queues: tuple[str, ...], prefetch: int = DEFAULT_PREFETCH,
                 connect: Callable[[str], Any] | None = None):
        self._connect = connect or (lambda u: pika.BlockingConnection(pika.URLParameters(u)))
        self.connection = self._connect(url)
        self.channel = self.connection.channel()
        self.channel.basic_qos(prefetch_count=prefetch)
        for queue in queues:
            declare_topology(self.channel, queue)

    def close(self) -> None:
        try:
            if self.channel.is_open:
                self.channel.close()
        finally:
            if self.connection.is_open:
                self.connection.close()


def consume(channel, queue: str, handler: Callable[[bytes, Any], None],
            should_stop: Callable[[], bool], inactivity_timeout: float = 1.0,
            max_retries: int = DEFAULT_MAX_RETRIES,
            base_delay_ms: int = DEFAULT_RETRY_BASE_DELAY_MS,
            on_failure: Callable[[BaseException, Routing, str], None] | None = None) -> None:
    """Drains `queue` until `should_stop` says otherwise.

    Deliveries are handled one at a time and settled before the next is taken,
    so a SIGTERM finishes the message in hand and stops (D-73) — there is
    nothing in flight to abandon.
    """
    for method, properties, body in _deliveries(channel, queue, inactivity_timeout):
        if method is None:
            if should_stop():
                break
            continue

        consumed = retry_count(properties)
        trace = trace_id(properties)

        try:
            handler(body, properties)
        except BaseException as error:  # noqa: BLE001 — the routing decision is the point
            routing = route_failure(error, consumed, max_retries, base_delay_ms)
            target = dlq(queue) if routing.target == "dlq" else retry_queue(queue)
            publish(
                channel,
                target,
                body,
                trace,
                expiration_ms=routing.expiration_ms,
                headers={
                    HEADER_RETRY_COUNT: routing.retry_count,
                    HEADER_LAST_ERROR: str(error)[:500],
                },
            )
            if on_failure is not None:
                on_failure(error, routing, trace)

        channel.basic_ack(delivery_tag=method.delivery_tag)

        if should_stop():
            break

    channel.cancel()


def _deliveries(channel, queue: str, inactivity_timeout: float) -> Iterator[tuple]:
    return channel.consume(queue, inactivity_timeout=inactivity_timeout)
