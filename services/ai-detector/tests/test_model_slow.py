"""The real weights. Opt-in:

    .venv/bin/python -m pytest -m slow -v

Needs `requirements.txt` installed (torch + transformers) and, on first run,
a download of the checkpoint into the cache. Excluded from the default loop
per roadmap §10.

What is asserted here is deliberately what can be asserted honestly. The
pipeline produces a bounded, deterministic number from the specified model,
and different code gets different numbers. That the number *ranks* AI-written
above hand-written is a claim about calibration, and calibration is not fitted
to anything yet (decisions-v3 §7, 2026-08-19) — a test asserting it would be
asserting a hope.
"""

from __future__ import annotations

import os

import pytest

from src.model import DEFAULT_MODEL_NAME, Detector

pytestmark = pytest.mark.slow

MODEL_NAME = os.getenv("AID_TEST_MODEL", DEFAULT_MODEL_NAME)
CACHE_DIR = os.getenv("HF_HOME") or None

HAND_WRITTEN = '''import sys

def main():
    # đọc từng dòng, bỏ qua dòng trống
    tong = 0
    for dong in sys.stdin:
        dong = dong.strip()
        if not dong:
            continue
        tong += int(dong)
    print(tong)

main()
'''

GENERATED_LOOKING = '''def calculate_sum_of_numbers(numbers):
    """Calculate the sum of a list of numbers.

    Args:
        numbers: A list of integers.

    Returns:
        The sum of the numbers.
    """
    total = 0
    for number in numbers:
        total += number
    return total


if __name__ == "__main__":
    values = [int(line) for line in open(0).read().split()]
    print(calculate_sum_of_numbers(values))
'''


@pytest.fixture(scope="module")
def detector() -> Detector:
    instance = Detector(model_name=MODEL_NAME, cache_dir=CACHE_DIR, max_windows=1, mask_stride=16)
    instance.warm()
    return instance


def test_both_fixtures_score_inside_the_unit_interval(detector: Detector):
    for source in (HAND_WRITTEN, GENERATED_LOOKING):
        probability = detector.score(source, "python")
        assert probability is not None
        assert 0.0 <= probability <= 1.0


def test_scoring_is_deterministic(detector: Detector):
    # api stores this number and an instructor acts on it; the same submission
    # re-analysed (D-53) must not move on its own.
    first = detector.score(HAND_WRITTEN, "python")
    second = detector.score(HAND_WRITTEN, "python")

    assert first == pytest.approx(second, abs=1e-9)


def test_different_sources_get_different_scores(detector: Detector):
    # A scorer that answers the same number for everything would pass the
    # bounds test above and be worthless.
    assert detector.score(HAND_WRITTEN, "python") != pytest.approx(
        detector.score(GENERATED_LOOKING, "python"), abs=1e-6
    )


def test_an_unsupported_language_never_reaches_the_model(detector: Detector):
    assert detector.score(HAND_WRITTEN, "c") is None


def test_the_untrained_head_of_plain_codebert_base_is_refused():
    """The bug this guard exists for.

    `microsoft/codebert-base` carries the encoder only. Loaded as a masked LM
    it gets a randomly initialised `lm_head`, and transformers reports that in
    a warning rather than an error — the two fixtures then scored 1e-13 apart.
    A checkpoint without the weights for the task is a startup failure, not a
    semester of meaningless numbers.
    """
    detector = Detector(model_name="microsoft/codebert-base", cache_dir=CACHE_DIR)

    with pytest.raises(ValueError) as raised:
        detector.warm()

    assert "lm_head" in str(raised.value)
    assert "codebert-base-mlm" in str(raised.value)
