"""Integration tests for E4, against the compose broker. Run:

    RMQ_TEST_URL="amqp://user:pass@localhost:5672/vhost" \
      .venv/bin/python -m pytest tests/test_integration_rabbit.py -v

They skip when RMQ_TEST_URL is unset.

The first one is the reason this file exists. AID declares the same queues the
Go workers declare, and RabbitMQ answers a mismatched redeclare with
PRECONDITION_FAILED — so a drift between `src/rabbit.py` and
`shared/go/rmq/client.go` does not show up as a failing unit test, it shows up
as whichever container started second refusing to run.
"""

from __future__ import annotations

import json
import os
import uuid

import pika
import pytest

from src.errors import TransientError
from src.rabbit import consume, declare_topology, publish

BROKER_URL = os.getenv("RMQ_TEST_URL", "")

pytestmark = pytest.mark.skipif(not BROKER_URL, reason="RMQ_TEST_URL not set")

# The arguments `declareTopology` in shared/go/rmq/client.go passes, written
# out here so the assertion is against the Go client's table and not against
# src/rabbit.py's own idea of it.
GO_RETRY_ARGUMENTS = {"x-dead-letter-exchange": "", "x-dead-letter-routing-key": None}


@pytest.fixture()
def queue():
    name = f"it-aid-{uuid.uuid4().hex[:8]}"
    yield name

    connection = pika.BlockingConnection(pika.URLParameters(BROKER_URL))
    channel = connection.channel()
    for target in (name, f"{name}.retry", f"{name}.dlq"):
        try:
            channel.queue_delete(queue=target)
        except Exception:  # noqa: BLE001 — cleanup is best effort
            channel = connection.channel()
    connection.close()


def channel():
    return pika.BlockingConnection(pika.URLParameters(BROKER_URL)).channel()


def test_the_go_client_can_redeclare_what_pika_declared(queue):
    pika_channel = channel()
    declare_topology(pika_channel, queue)

    # A fresh connection, because a PRECONDITION_FAILED closes the channel it
    # happens on — which is exactly how this fails in production.
    go_channel = channel()
    go_channel.queue_declare(queue=f"{queue}.dlq", durable=True)
    go_channel.queue_declare(
        queue=f"{queue}.retry",
        durable=True,
        arguments={**GO_RETRY_ARGUMENTS, "x-dead-letter-routing-key": queue},
    )
    go_channel.queue_declare(queue=queue, durable=True)

    assert go_channel.is_open


def test_the_broker_really_does_refuse_a_mismatched_redeclare(queue):
    # Without this the test above is vacuous: it would pass just as happily
    # if RabbitMQ ignored declare arguments altogether.
    declare_topology(channel(), queue)

    mismatched = channel()
    with pytest.raises(pika.exceptions.ChannelClosedByBroker) as raised:
        mismatched.queue_declare(
            queue=f"{queue}.retry",
            durable=True,
            arguments={"x-dead-letter-exchange": "", "x-dead-letter-routing-key": "somewhere-else"},
        )

    assert raised.value.reply_code == 406  # PRECONDITION_FAILED


def test_a_published_job_is_consumed_and_acked(queue):
    producer = channel()
    declare_topology(producer, queue)

    trace = str(uuid.uuid4())
    job = {
        "analysis_submission_id": 501,
        "submission_id": 42,
        "file_url": "http://minio:9000/a",
        "language": "python",
        "trace_id": trace,
        "timestamp": "2026-08-19T09:30:00Z",
        "version": "1.0",
    }
    publish(producer, queue, json.dumps(job).encode(), trace)

    consumer = channel()
    seen = []

    consume(
        consumer,
        queue,
        lambda body, properties: seen.append(json.loads(body)),
        should_stop=lambda: bool(seen),
        inactivity_timeout=5,
    )

    assert seen and seen[0]["analysis_submission_id"] == 501

    # Acked means gone: a message left unacked would be redelivered to the
    # next consumer and the submission scored twice.
    state = channel().queue_declare(queue=queue, durable=True, passive=True)
    assert state.method.message_count == 0


def test_a_transient_failure_lands_on_the_retry_queue(queue):
    producer = channel()
    declare_topology(producer, queue)
    publish(producer, queue, b'{"broken":true}', "trace-1")

    consumer = channel()
    attempts = []

    def failing(body, properties):
        attempts.append(body)
        raise TransientError("minio is unreachable")

    consume(consumer, queue, failing, should_stop=lambda: bool(attempts), inactivity_timeout=5)

    # It waits out its TTL on .retry before the broker sends it back, so the
    # depth is checked there rather than on the main queue.
    state = channel().queue_declare(queue=f"{queue}.retry", durable=True, passive=True,
                                    arguments={"x-dead-letter-exchange": "",
                                               "x-dead-letter-routing-key": queue})
    assert state.method.message_count == 1
