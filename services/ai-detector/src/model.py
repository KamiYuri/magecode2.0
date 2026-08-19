"""Scoring how likely a submission is to have been generated.

**What this measures, and why it is built this way.** E5 names
`microsoft/codebert-base`, which is an encoder pre-trained with masked-language
modelling — it has no AI-detection head, and putting
`AutoModelForSequenceClassification` on it produces a *randomly initialised*
classifier whose output is noise dressed as a probability. Reporting that to an
instructor would be worse than reporting nothing.

So the scorer is pluggable (settled 2026-08-19, decisions-v3 §7):

  * If `MODEL_NAME` resolves to a checkpoint whose config declares a
    `*ForSequenceClassification` architecture — a fine-tuned detector — AID
    uses that head and reports its probability. This is the path to real
    detection quality, and it needs no code change to take.
  * Otherwise AID scores **predictability**: the masked-language model's mean
    negative log-likelihood over the source, mapped to 0–1 by a logistic whose
    midpoint and width are environment constants. Generated code is more
    predictable to a code language model than hand-written code, so this is a
    real signal from the model that was specified, computed the way that model
    was trained.

**The default checkpoint is `microsoft/codebert-base-mlm`, not
`microsoft/codebert-base`.** The plain `codebert-base` release carries the
encoder only: loading it as a masked LM leaves `lm_head.*` newly initialised,
which is a *random* head, and it scored the two fixtures at 1e-13 apart — noise
with a decimal point. `codebert-base-mlm` is Microsoft's release of the same
model with the MLM head trained, and it scores the same fixtures at 1.46 and
1.32 nats per token. `_default_loader` therefore refuses to start when weights
are missing, rather than serving a semester of numbers that mean nothing.

The calibration constants are honest placeholders: without a labelled corpus
there is nothing to fit them to, and `docs/progress.md` records that. They move
the threshold, not the ordering.

torch and transformers are imported lazily, inside the loader, so the fast test
suite runs without a 900MB wheel.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from typing import Any, Callable, Sequence

# The job schema's enum is python|java|c|cpp; its description says AID serves
# python, java and cpp. `c` is answered not_applicable — a result, not a fault.
SUPPORTED_LANGUAGES = ("python", "java", "cpp")

# The -mlm release, deliberately: see the module docstring. `codebert-base`
# has no trained MLM head.
DEFAULT_MODEL_NAME = "microsoft/codebert-base-mlm"
DEFAULT_BATCH_SIZE = 8
DEFAULT_DEVICE = "cpu"
# CodeBERT's positional limit is 512, less the two special tokens.
DEFAULT_WINDOW = 510
# Bounds latency on a long file: four windows is ~2000 tokens of evidence,
# which is more than enough to characterise a submission.
DEFAULT_MAX_WINDOWS = 4
# Masking one position per forward pass would need 510 passes per window;
# striding scores every position in `stride` passes.
DEFAULT_MASK_STRIDE = 8
# The midpoint and width of the logistic. See the module docstring: these set
# where the 50/50 line falls and how fast the curve leaves it, and they are
# **not fitted to a labelled corpus** — the midpoint sits between the two
# fixtures measured with codebert-base-mlm (1.46 hand-written, 1.32
# generated-looking). Fitting them is what a labelled dataset would buy.
DEFAULT_CALIBRATION_MEAN = 1.40
DEFAULT_CALIBRATION_SCALE = 0.15

# Substrings that identify the generated class when a checkpoint labels its
# outputs. Convention otherwise is that index 1 is the positive class.
_AI_LABEL_HINTS = ("ai", "machine", "generated", "gpt", "llm", "fake", "synthetic")


def is_supported(language: str) -> bool:
    return language in SUPPORTED_LANGUAGES


def softmax(logits: Sequence[float]) -> list[float]:
    """Numerically stable softmax over a small vector."""
    largest = max(logits)
    exponentials = [math.exp(value - largest) for value in logits]
    total = sum(exponentials)
    return [value / total for value in exponentials]


def probability_from_nll(mean_nll: float, mean: float, scale: float) -> float:
    """Maps mean negative log-likelihood to 0–1.

    Lower is more predictable, so the sign is inverted: `mean` is the value
    that scores 0.5 and `scale` is how quickly the curve moves away from it.
    """
    if scale <= 0:
        raise ValueError("calibration scale must be positive")

    exponent = (mean_nll - mean) / scale
    # exp overflows for large positive exponents long before the probability
    # stops being 0 to every digit anyone reads.
    if exponent > 60:
        return 0.0
    if exponent < -60:
        return 1.0
    return 1.0 / (1.0 + math.exp(exponent))


def plan_windows(token_count: int, window: int, max_windows: int) -> list[tuple[int, int]]:
    """Splits a token sequence into at most `max_windows` full windows."""
    if token_count <= 0:
        return []
    if token_count <= window:
        return [(0, token_count)]

    windows: list[tuple[int, int]] = []
    for start in range(0, token_count, window):
        end = min(start + window, token_count)
        # A trailing sliver is dropped rather than scored on its own: a
        # handful of tokens produces a noisy mean that would move the result.
        if end - start < window:
            break
        windows.append((start, end))
        if len(windows) == max_windows:
            break

    return windows or [(0, window)]


def mask_positions(length: int, stride: int, offset: int) -> list[int]:
    """The positions masked in one forward pass of a strided sweep."""
    return list(range(offset, length, stride))


def ai_label_index(id2label: dict[Any, str] | None) -> int:
    """Which logit is the generated class."""
    if id2label:
        for index, label in id2label.items():
            if any(hint in str(label).lower() for hint in _AI_LABEL_HINTS):
                return int(index)
    return 1


@dataclass
class Backend:
    """The loaded model and the module it needs.

    `kind` is either "sequence-classification" (a fine-tuned detector) or
    "mlm" (the predictability scorer).
    """

    torch: Any
    tokenizer: Any
    model: Any
    kind: str
    id2label: dict[Any, str] | None = None


@dataclass
class Detector:
    """Scores one submission. The model is loaded on first use and reused:
    CodeBERT costs seconds and hundreds of megabytes, so paying that per
    message would dominate every job."""

    model_name: str = DEFAULT_MODEL_NAME
    device: str = DEFAULT_DEVICE
    batch_size: int = DEFAULT_BATCH_SIZE
    cache_dir: str | None = None
    window: int = DEFAULT_WINDOW
    max_windows: int = DEFAULT_MAX_WINDOWS
    mask_stride: int = DEFAULT_MASK_STRIDE
    calibration_mean: float = DEFAULT_CALIBRATION_MEAN
    calibration_scale: float = DEFAULT_CALIBRATION_SCALE
    loader: Callable[[], Backend] | None = None
    _backend: Backend | None = field(default=None, init=False, repr=False)

    def score(self, source: str, language: str) -> float | None:
        """Returns the probability, or None when the language is one AID does
        not serve — which the caller reports as `not_applicable`."""
        if not is_supported(language):
            return None
        if not source.strip():
            raise ValueError("submission source is empty")

        backend = self._load()
        if backend.kind == "sequence-classification":
            return self._score_with_head(backend, source)
        return self._score_by_predictability(backend, source)

    def warm(self) -> None:
        """Loads the model ahead of the first message, so the first student to
        be scored does not wait for the download."""
        self._load()

    def _load(self) -> Backend:
        if self._backend is None:
            self._backend = (self.loader or self._default_loader)()
        return self._backend

    def _default_loader(self) -> Backend:
        # Imported here, not at module scope: the fast test suite runs
        # without torch or transformers installed.
        import torch
        from transformers import AutoConfig, AutoModelForMaskedLM, AutoTokenizer

        torch.manual_seed(0)

        config = AutoConfig.from_pretrained(self.model_name, cache_dir=self.cache_dir)
        architectures = getattr(config, "architectures", None) or []
        has_head = any(str(name).endswith("ForSequenceClassification") for name in architectures)

        tokenizer = AutoTokenizer.from_pretrained(self.model_name, cache_dir=self.cache_dir)

        if has_head:
            from transformers import AutoModelForSequenceClassification

            model, info = AutoModelForSequenceClassification.from_pretrained(
                self.model_name, cache_dir=self.cache_dir, output_loading_info=True
            )
            kind = "sequence-classification"
        else:
            # No classification head means no trained notion of
            # "AI-generated"; scoring predictability with the model as it was
            # actually trained is the honest reading of it (decisions-v3 §7,
            # 2026-08-19).
            model, info = AutoModelForMaskedLM.from_pretrained(
                self.model_name, cache_dir=self.cache_dir, output_loading_info=True
            )
            kind = "mlm"

        # The guard that makes the above safe. transformers happily fills a
        # missing head with random weights and prints a warning nobody reads;
        # `microsoft/codebert-base` does exactly that, and the resulting
        # probabilities were noise. A checkpoint that does not carry the
        # weights for the task it is being asked to do is a startup failure.
        missing = list(info.get("missing_keys") or [])
        if missing:
            raise ValueError(
                f"{self.model_name} does not carry trained weights for {kind}: "
                f"{', '.join(missing[:6])}"
                + (" …" if len(missing) > 6 else "")
                + ". Point MODEL_NAME at a checkpoint that does — "
                "microsoft/codebert-base-mlm for the predictability scorer, or a "
                "fine-tuned *ForSequenceClassification detector."
            )

        model.to(self.device)
        model.eval()

        return Backend(
            torch=torch,
            tokenizer=tokenizer,
            model=model,
            kind=kind,
            id2label=getattr(config, "id2label", None),
        )

    def _score_with_head(self, backend: Backend, source: str) -> float:
        inputs = backend.tokenizer(
            source, return_tensors="pt", truncation=True, max_length=self.window + 2
        )
        with backend.torch.no_grad():
            outputs = backend.model(**inputs)

        logits = list(outputs.logits[0].tolist())
        if len(logits) < 2:
            raise ValueError(f"classification head produced {len(logits)} logit(s)")

        return softmax(logits)[ai_label_index(backend.id2label)]

    def _score_by_predictability(self, backend: Backend, source: str) -> float:
        return probability_from_nll(
            self._mean_nll(backend, source), self.calibration_mean, self.calibration_scale
        )

    def _mean_nll(self, backend: Backend, source: str) -> float:
        """Mean negative log-likelihood of the source under the masked LM.

        Each window is swept `mask_stride` times; every sweep masks a disjoint
        set of positions and reads the model's log-probability of the token
        that was actually there. Masking one position per pass would be 510
        forward passes per window, which on CPU is minutes.
        """
        torch = backend.torch
        tokenizer = backend.tokenizer

        mask_id = tokenizer.mask_token_id
        if mask_id is None:
            raise ValueError(f"{self.model_name} has no mask token, so it cannot score predictability")

        encoded = tokenizer(source, return_tensors="pt", truncation=False, add_special_tokens=False)
        ids = encoded["input_ids"][0].tolist()

        windows = plan_windows(len(ids), self.window, self.max_windows)
        if not windows:
            raise ValueError("submission tokenises to nothing")

        # How many special tokens the model puts in front of the sequence —
        # 1 for BERT and RoBERTa, but read rather than assumed.
        offset = tokenizer.build_inputs_with_special_tokens([mask_id]).index(mask_id)

        total = 0.0
        scored = 0

        for start, end in windows:
            window_ids = ids[start:end]
            base = torch.tensor(tokenizer.build_inputs_with_special_tokens(window_ids))

            for sweep in range(self.mask_stride):
                positions = mask_positions(len(window_ids), self.mask_stride, sweep)

                for chunk_start in range(0, len(positions), self.batch_size):
                    chunk = positions[chunk_start : chunk_start + self.batch_size]

                    rows = []
                    for position in chunk:
                        row = base.clone()
                        row[position + offset] = mask_id
                        rows.append(row)

                    batch = torch.stack(rows).to(self.device)
                    with torch.no_grad():
                        logits = backend.model(input_ids=batch).logits
                    log_probs = torch.log_softmax(logits, dim=-1)

                    for row_index, position in enumerate(chunk):
                        total -= float(log_probs[row_index, position + offset, window_ids[position]])
                        scored += 1

        if scored == 0:
            raise ValueError("no tokens could be scored")

        return total / scored
