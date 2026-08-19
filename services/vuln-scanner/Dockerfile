# MageCode vuln-scanner (VUL) — multi-stage build (D-79a).
# Build context is the monorepo root:
#   docker build -f services/vuln-scanner/Dockerfile .
#
# The image carries the CodeQL bundle (~1GB unpacked), a JRE for the Java
# extractor and g++ for the C/C++ one. That last is not incidental: a
# submission is one file with no project, so java is extracted with
# --build-mode=none while cpp has no such mode and is built around
# `g++ -fsyntax-only` (E7).

FROM golang:1.26-alpine AS builder
WORKDIR /build

COPY shared/go/ ./shared/go/
COPY services/vuln-scanner/ ./services/vuln-scanner/

WORKDIR /build/services/vuln-scanner
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /out/worker ./cmd/main.go

FROM debian:bookworm-slim AS codeql
ARG CODEQL_BUNDLE_URL=https://github.com/github/codeql-action/releases/latest/download/codeql-bundle-linux64.tar.zst
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl zstd \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /opt
RUN curl -fsSL "${CODEQL_BUNDLE_URL}" -o bundle.tar.zst \
    && tar --zstd -xf bundle.tar.zst \
    && rm bundle.tar.zst

FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl tzdata \
        g++ \
        openjdk-17-jre-headless \
        python3 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --uid 1000 --create-home magecode

COPY --from=builder /out/worker /usr/local/bin/worker
COPY --from=codeql /opt/codeql /opt/codeql

ENV PATH="/opt/codeql:${PATH}" \
    CODEQL_BIN=/opt/codeql/codeql \
    HEALTH_ADDR=:9090

# The query pack cache is a volume in compose so a restart does not re-resolve
# every suite.
ENV CODEQL_HOME=/home/magecode/.codeql

EXPOSE 9090
USER magecode
CMD ["worker"]
