# MageCode ai-detector — Python 3.12 worker (D-79a).
# Build context is this directory:
#   docker build -f services/ai-detector/Dockerfile services/ai-detector

FROM python:3.12-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY src/ ./src/

RUN useradd --uid 1000 --create-home magecode
USER magecode

CMD ["python", "src/main.py"]
