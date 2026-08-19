"""Fail-fast environment loading, the Python half of `shared/go/config`.

The rule is the Go package's: validate the whole specification at startup and
report every problem at once, so a worker dies on the launchpad with a complete
list rather than one missing variable at a time — or, worse, halfway through
its first job.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Any, Callable


class ConfigError(Exception):
    """Raised when the environment does not satisfy the specification."""


@dataclass(frozen=True)
class Field:
    """One environment variable. An empty value counts as unset: `required`
    rejects it and `default` replaces it."""

    type: Callable[[str], Any] = str
    required: bool = False
    default: str | None = None


def _boolean(raw: str) -> bool:
    normalised = raw.strip().lower()
    if normalised in ("1", "true", "yes", "on"):
        return True
    if normalised in ("0", "false", "no", "off"):
        return False
    raise ValueError(f"{raw!r} is not a boolean")


BOOL = _boolean
INT = int
FLOAT = float
STR = str


def load(spec: dict[str, Field], environ: dict[str, str] | None = None) -> dict[str, Any]:
    """Reads the environment against spec, raising ConfigError naming every
    missing required key and every value that fails coercion."""
    environ = os.environ if environ is None else environ

    values: dict[str, Any] = {}
    problems: list[str] = []

    for key in sorted(spec):
        field = spec[key]
        raw = environ.get(key, "").strip()

        if raw == "":
            if field.required:
                problems.append(f"{key} is required")
                continue
            if field.default is None:
                values[key] = None
                continue
            raw = field.default

        try:
            values[key] = field.type(raw)
        except (TypeError, ValueError) as error:
            problems.append(f"{key}={raw!r} is not usable: {error}")

    if problems:
        raise ConfigError("; ".join(problems))

    return values
