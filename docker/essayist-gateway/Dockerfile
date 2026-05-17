# syntax=docker/dockerfile:1.7

# ─── build stage ──────────────────────────────────────────────────────────────
FROM golang:1.23-alpine AS build
WORKDIR /src

# Cache deps first.
COPY go.mod go.sum* ./
RUN go mod download || true

COPY . .
# Resolve any missing sums after copying sources (works for first build without go.sum).
RUN go mod tidy

# Static binary, no cgo, stripped.
ENV CGO_ENABLED=0 GOOS=linux
RUN go build -trimpath -ldflags="-s -w" -o /out/essayist-gateway ./cmd/gateway

# Run unit tests as part of the image build to keep the artefact honest.
RUN go test ./...

# ─── runtime stage ────────────────────────────────────────────────────────────
FROM alpine:3.20
RUN apk add --no-cache ca-certificates wget && adduser -D -u 10001 gateway
COPY --from=build /out/essayist-gateway /usr/local/bin/essayist-gateway
USER gateway
EXPOSE 7780 7781 7782
ENTRYPOINT ["/usr/local/bin/essayist-gateway"]

