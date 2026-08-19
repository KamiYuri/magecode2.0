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


def test_a_job_becomes_a_result_on_the_result_queue(queue):
    """The whole loop against a real broker: job in, result out, message acked.

    The detector is a stub — E5's real-weights suite covers the model, and
    what is under test here is the plumbing between the two queues.
    """
    import http.server
    import threading

    from src.downloader import Downloader
    from src.handler import Handler

    source = b"print('hello')\n"

    class Serve(http.server.BaseHTTPRequestHandler):
        def do_GET(self):  # noqa: N802 — BaseHTTPRequestHandler's spelling
            self.send_response(200)
            self.send_header("Content-Length", str(len(source)))
            self.end_headers()
            self.wfile.write(source)

        def log_message(self, *_):  # keep the test output clean
            return

    server = http.server.HTTPServer(("127.0.0.1", 0), Serve)
    threading.Thread(target=server.serve_forever, daemon=True).start()

    results_queue = f"{queue}-results"
    producer = channel()
    declare_topology(producer, queue)
    declare_topology(producer, results_queue)

    trace = str(uuid.uuid4())
    job = {
        "analysis_submission_id": 501,
        "submission_id": 42,
        "file_url": f"http://127.0.0.1:{server.server_port}/main.py",
        "language": "python",
        "trace_id": trace,
        "timestamp": "2026-08-19T09:30:00Z",
        "version": "1.0",
    }
    publish(producer, queue, json.dumps(job).encode(), trace)

    class StubDetector:
        def score(self, text, language):
            assert text == source.decode()
            return 0.61

    class SilentLogger:
        def info(self, *_, **__): ...
        def warning(self, *_, **__): ...
        def error(self, *_, **__): ...

    consumer = channel()
    handler = Handler(
        downloader=Downloader(),
        detector=StubDetector(),
        channel=consumer,
        logger=SilentLogger(),
        # A queue of this test's own: api's consumer would otherwise race it
        # for the message once E8 puts that process in compose.
        result_queue=results_queue,
    )

    handled = []

    def handle(body, properties):
        handler.handle(body, properties)
        handled.append(body)

    try:
        consume(consumer, queue, handle, should_stop=lambda: bool(handled), inactivity_timeout=5)
    finally:
        server.shutdown()

    inspector = channel()
    method, properties, payload = inspector.basic_get(queue=results_queue, auto_ack=True)
    assert method is not None, "no result was published"

    message = json.loads(payload)
    assert message["service"] == "ai-detector"
    assert message["analysis_submission_id"] == 501
    assert message["probability"] == 0.61
    assert message["status"] == "completed"
    assert properties.headers["trace_id"] == trace

    # And the job is gone from its own queue.
    state = channel().queue_declare(queue=queue, durable=True, passive=True)
    assert state.method.message_count == 0

    for name in (results_queue, f"{results_queue}.retry", f"{results_queue}.dlq"):
        try:
            channel().queue_delete(queue=name)
        except Exception:  # noqa: BLE001 — cleanup is best effort
            pass
