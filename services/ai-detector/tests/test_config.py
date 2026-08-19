from __future__ import annotations

import pytest

from src.config import BOOL, INT, ConfigError, Field, load


def test_required_values_are_read():
    values = load({"RABBITMQ_URL": Field(required=True)}, {"RABBITMQ_URL": "amqp://localhost"})

    assert values["RABBITMQ_URL"] == "amqp://localhost"


def test_defaults_fill_in_and_coerce():
    values = load({"BATCH_SIZE": Field(type=INT, default="8")}, {})

    assert values["BATCH_SIZE"] == 8


def test_an_empty_value_counts_as_unset():
    values = load({"DEVICE": Field(default="cpu")}, {"DEVICE": "   "})

    assert values["DEVICE"] == "cpu"


def test_every_problem_is_reported_at_once():
    # One variable at a time means one restart at a time, and in compose that
    # is a crash loop nobody reads to the end.
    with pytest.raises(ConfigError) as raised:
        load(
            {
                "RABBITMQ_URL": Field(required=True),
                "MODEL_NAME": Field(required=True),
                "BATCH_SIZE": Field(type=INT, default="8"),
            },
            {"BATCH_SIZE": "eight"},
        )

    message = str(raised.value)
    assert "RABBITMQ_URL is required" in message
    assert "MODEL_NAME is required" in message
    assert "BATCH_SIZE" in message


def test_booleans_accept_the_usual_spellings():
    values = load({"A": Field(type=BOOL, default="false"), "B": Field(type=BOOL)}, {"B": "yes"})

    assert values["A"] is False
    assert values["B"] is True


def test_an_optional_key_with_no_default_is_none():
    assert load({"TRACE": Field()}, {})["TRACE"] is None
