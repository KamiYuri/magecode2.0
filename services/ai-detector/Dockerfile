# MageCode ai-detector (AID) — Python 3.12 worker (D-79a).
# Build context is this directory:
#   docker build -f services/ai-detector/Dockerfile services/ai-detector
#
# torch comes from the CPU wheel index deliberately: the default PyPI wheel
# drags in the CUDA runtime, some 2.5GB of it, onto a machine with no GPU.

FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --uid 1000 --create-home magecode

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.txt

COPY src/ ./src/

# The model cache is a volume in compose; without it every restart re-downloads
# the checkpoint.
ENV HF_HOME=/app/models \
    PYTHONPATH=/app \
    PYTHONUNBUFFERED=1 \
    HEALTH_PORT=9090

RUN mkdir -p /app/models && chown -R magecode:magecode /app

EXPOSE 9090
USER magecode
CMD ["python", "-m", "src.main"]
