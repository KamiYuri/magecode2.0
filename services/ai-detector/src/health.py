"""The HTTP liveness endpoint D-72 requires of every service.

AID is a queue consumer with no HTTP surface of its own, so without this
compose has nothing to ask: a container whose connection died but whose
process is still running would sit in the service list looking fine. G1 hangs
`/metrics` on the same server (U-10).
"""

from __future__ import annotations

import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Callable

PATH = "/healthz"
DEFAULT_ADDRESS = ("0.0.0.0", 9090)


def serve(ready: Callable[[], bool], address: tuple[str, int] = DEFAULT_ADDRESS) -> ThreadingHTTPServer:
    """Starts the health server on a daemon thread and returns it.

    `ready` reports whether the service can work; a worker that has lost its
    broker answers 503 so compose restarts it rather than leaving a container
    that consumes nothing.
    """

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):  # noqa: N802 — BaseHTTPRequestHandler's spelling
            if self.path.split("?")[0] != PATH:
                self.send_error(404)
                return

            healthy = bool(ready())
            body = b"ok\n" if healthy else b"not ready\n"
            self.send_response(200 if healthy else 503)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *_):
            # The service speaks D-88 JSON on stdout; the default access log
            # would be the one line in the stream that is not.
            return

    server = ThreadingHTTPServer(address, Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server
