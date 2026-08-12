# MageCode Go worker — multi-stage build (D-79a).
# Build context is the monorepo root:
#   docker build -f services/vuln-scanner/Dockerfile .

ARG SERVICE=vuln-scanner

FROM golang:1.26-alpine AS builder
ARG SERVICE
WORKDIR /build

COPY shared/go/ ./shared/go/
COPY services/${SERVICE}/ ./services/${SERVICE}/

WORKDIR /build/services/${SERVICE}
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /out/worker ./cmd/main.go

FROM alpine:3.21
RUN apk add --no-cache ca-certificates tzdata \
    && adduser -D -u 1000 magecode
COPY --from=builder /out/worker /usr/local/bin/worker
USER magecode
CMD ["worker"]
