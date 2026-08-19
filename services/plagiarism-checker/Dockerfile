# MageCode plagiarism-checker (SIM) — multi-stage build (D-79a).
# Build context is the monorepo root:
#   docker build -f services/plagiarism-checker/Dockerfile .
#
# Unlike CES this image is not a bare Alpine binary: SIM drives Dolos, which is
# a Node library with native tree-sitter parsers (E2 — the CLI's CSV export has
# no fragment coordinates, so the library is the only way to get regions). The
# runtime is therefore Node with the Go worker copied in, and A7's "<30MB Go
# image" holds for CES alone.

FROM golang:1.26-alpine AS builder
WORKDIR /build

COPY shared/go/ ./shared/go/
COPY services/plagiarism-checker/ ./services/plagiarism-checker/

WORKDIR /build/services/plagiarism-checker
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /out/worker ./cmd/main.go

# The reporter's native modules are compiled here — node:22-slim has no
# toolchain, and prebuilt binaries do not cover every ABI.
FROM node:22 AS reporter
WORKDIR /reporter
COPY services/plagiarism-checker/reporter/package.json services/plagiarism-checker/reporter/package-lock.json ./
RUN npm ci --omit=dev
COPY services/plagiarism-checker/reporter/report.mjs ./

FROM node:22-slim
# The base image already ships a `node` user at uid 1000, which is the uid the
# other worker images use; adding a second one there fails outright.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl tzdata \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /out/worker /usr/local/bin/worker
COPY --from=reporter /reporter /app/reporter

ENV REPORTER_SCRIPT=/app/reporter/report.mjs \
    NODE_BIN=node \
    HEALTH_ADDR=:9090

EXPOSE 9090
USER node
CMD ["worker"]
