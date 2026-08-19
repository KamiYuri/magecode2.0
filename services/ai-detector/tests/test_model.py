"""E5's unit surface.

Everything here runs without torch: the maths that turns model output into a
probability is pure Python, and the tokenizer and model are fakes. The real
weights are exercised by `tests/test_model_slow.py`, which is opt-in.
"""

from __future__ import annotations

import math
from contextlib import nullcontext

import pytest

from src.model import (
    Backend,
    Detector,
    SUPPORTED_LANGUAGES,
    ai_label_index,
    is_supported,
    mask_positions,
    plan_windows,
    probability_from_nll,
    softmax,
)


# ── the language gate ────────────────────────────────────────────────────────

def test_the_supported_set_is_the_schema_s():
    # The job schema's enum is python|java|c|cpp and its description says AID
    # serves python, java and cpp — `c` is answered not_applicable, which is
    # a result rather than a failure.
    assert SUPPORTED_LANGUAGES == ("python", "java", "cpp")
    assert is_supported("python") and is_supported("java") and is_supported("cpp")
    assert not is_supported("c")
    assert not is_supported("rust")


def test_an_unsupported_language_is_scored_as_not_applicable_without_loading_the_model():
    loaded = []
    detector = Detector(loader=lambda: loaded.append(True) or Backend(None, None, None, "mlm"))

    assert detector.score("print(1)", "c") is None
    assert loaded == [], "the model was loaded for a language it cannot serve"


# ── the calibration ──────────────────────────────────────────────────────────

def test_probability_is_bounded_and_monotonic_in_predictability():
    # AI-generated code is more predictable to a code LM, so a *lower* mean
    # negative log-likelihood must raise the probability.
    low = probability_from_nll(0.3, mean=1.5, scale=0.5)
    mid = probability_from_nll(1.5, mean=1.5, scale=0.5)
    high = probability_from_nll(3.0, mean=1.5, scale=0.5)

    assert 0.0 <= high < mid < low <= 1.0
    assert mid == pytest.approx(0.5), "the calibration mean is the 50/50 point"


def test_probability_saturates_without_overflowing():
    assert probability_from_nll(-1000, mean=1.5, scale=0.5) == pytest.approx(1.0)
    assert probability_from_nll(1000, mean=1.5, scale=0.5) == pytest.approx(0.0)


def test_softmax_is_a_distribution():
    values = softmax([2.0, 1.0, 0.0])

    assert sum(values) == pytest.approx(1.0)
    assert values[0] > values[1] > values[2]
    # Shifted by the max before exponentiating, or a large logit overflows.
    assert softmax([1000.0, 999.0])[0] == pytest.approx(math.e / (math.e + 1))


# ── window planning ──────────────────────────────────────────────────────────

def test_a_short_file_is_one_window():
    assert plan_windows(120, window=510, max_windows=4) == [(0, 120)]


def test_a_long_file_is_split_and_bounded():
    # The cap is latency, not truth: a 50KB file must not turn into minutes
    # of CPU while the batch waits.
    windows = plan_windows(5000, window=510, max_windows=4)

    assert len(windows) == 4
    assert windows[0] == (0, 510)
    assert all(end - start == 510 for start, end in windows)


def test_an_empty_file_has_no_windows():
    assert plan_windows(0, window=510, max_windows=4) == []


def test_masking_walks_the_window_in_strides():
    # Masking one token per forward pass would mean 510 passes per window;
    # striding scores every position in `stride` passes instead.
    assert mask_positions(10, stride=4, offset=0) == [0, 4, 8]
    assert mask_positions(10, stride=4, offset=1) == [1, 5, 9]
    assert mask_positions(10, stride=4, offset=3) == [3, 7]
    assert mask_positions(2, stride=4, offset=3) == []


# ── the classification head ──────────────────────────────────────────────────

class FakeTensor:
    def __init__(self, values):
        self.values = values

    def tolist(self):
        return self.values

    def to(self, _device):
        return self


class FakeOutputs:
    def __init__(self, logits):
        self.logits = [FakeTensor(logits)]


class FakeTokenizer:
    def __init__(self):
        self.calls = []

    def __call__(self, text, **kwargs):
        self.calls.append((text, kwargs))
        return {"input_ids": FakeTensor([[1, 2, 3]])}


class FakeModel:
    def __init__(self, logits, config=None):
        self.logits = logits
        self.config = config

    def __call__(self, **_):
        return FakeOutputs(self.logits)

    def to(self, _device):
        return self

    def eval(self):
        return self


class FakeTorch:
    @staticmethod
    def no_grad():
        return nullcontext()


def head_detector(logits, **kwargs) -> Detector:
    backend = Backend(
        torch=FakeTorch(),
        tokenizer=FakeTokenizer(),
        model=FakeModel(logits),
        kind="sequence-classification",
    )
    return Detector(loader=lambda: backend, **kwargs)


def test_a_checkpoint_with_a_head_maps_its_logits_to_the_probability():
    # Deterministic tensor in, known probability out — the mapping is
    # softmax over the two classes, taking the AI one.
    probability = head_detector([1.0, 3.0]).score("print(1)", "python")

    assert probability == pytest.approx(1 / (1 + math.exp(-2)))


def test_the_ai_label_is_read_from_the_checkpoint_when_it_names_one():
    assert ai_label_index({0: "AI", 1: "HUMAN"}) == 0
    assert ai_label_index({0: "human", 1: "machine-generated"}) == 1
    assert ai_label_index({0: "LABEL_0", 1: "LABEL_1"}) == 1, "index 1 is the convention"
    assert ai_label_index(None) == 1


def test_the_model_is_loaded_once_and_reused():
    loads = []

    def loader():
        loads.append(True)
        return Backend(FakeTorch(), FakeTokenizer(), FakeModel([0.0, 0.0]), "sequence-classification")

    detector = Detector(loader=loader)
    detector.score("a", "python")
    detector.score("b", "python")

    # Loading CodeBERT takes seconds and hundreds of megabytes; doing it per
    # message would make every job pay for it.
    assert len(loads) == 1


def test_empty_source_is_refused_rather_than_scored():
    # A probability for nothing is worse than an error: api stores it and an
    # instructor reads it as a finding.
    detector = head_detector([0.0, 5.0])

    with pytest.raises(ValueError):
        detector.score("   \n\t ", "python")
