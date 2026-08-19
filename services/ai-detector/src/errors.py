"""The error taxonomy AID shares with the Go services.

`shared/go/apperror` classifies failures Transient (worth retrying) or
Permanent (a retry can never succeed), and the consumer routes on that
classification per D-79e. AID consumes the same queues with the same retry and
dead-letter topology, so it needs the same two words to mean the same things.
"""

from __future__ import annotations


class AnalysisError(Exception):
    """Base class carrying a retry classification."""

    transient = False


class TransientError(AnalysisError):
    """A failure that a later attempt may not hit: the network, the broker,
    MinIO having a bad moment."""

    transient = True


class PermanentError(AnalysisError):
    """A failure that will recur identically: a malformed message, an expired
    URL, a language the model does not serve."""

    transient = False


def is_transient(error: BaseException) -> bool:
    """Unclassified errors default to transient, matching `routeFailure` in
    `shared/go/rmq`: a flaky third-party failure still gets its three attempts
    before it parks."""
    return getattr(error, "transient", True)
