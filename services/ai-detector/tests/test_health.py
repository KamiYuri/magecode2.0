from __future__ import annotations

import urllib.error
import urllib.request

import pytest

from src.health import PATH, serve


@pytest.fixture()
def server_factory():
    started = []

    def start(ready):
        # Port 0 asks the kernel for a free one, so two tests never collide.
        server = serve(ready, ("127.0.0.1", 0))
        started.append(server)
        return f"http://127.0.0.1:{server.server_address[1]}"

    yield start

    for server in started:
        server.shutdown()
        server.server_close()


def get(url: str) -> int:
    try:
        with urllib.request.urlopen(url, timeout=5) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def test_liveness_answers_ok_when_ready(server_factory):
    assert get(server_factory(lambda: True) + PATH) == 200


def test_liveness_answers_503_when_the_broker_is_gone(server_factory):
    # A worker that has lost its connection still has a process; without this
    # the container looks healthy while consuming nothing (D-72).
    healthy = {"value": False}
    base = server_factory(lambda: healthy["value"])

    assert get(base + PATH) == 503

    healthy["value"] = True
    assert get(base + PATH) == 200


def test_other_paths_are_not_served(server_factory):
    assert get(server_factory(lambda: True) + "/") == 404
