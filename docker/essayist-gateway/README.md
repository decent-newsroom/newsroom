# essayist-gateway

NIP-42 AUTH-enforcing WebSocket proxy in front of the members-only
`strfry-essayist` relay. See [`documentation/essayist-gateway.md`](../../documentation/essayist-gateway.md)
for the full design.

## Layout

```
cmd/gateway/main.go          entrypoint
internal/config/             env-based config
internal/auth/               challenge + NIP-42 verify + relay URL normalisation
internal/membership/         Checker interface + Redis fast-path + HTTP slow-path + cached composite
internal/proxy/              WS upgrade, AUTH state machine, NIP-11 passthrough, bidirectional copy
internal/health/             /health probe (process + upstream TCP + Redis PING)
internal/metrics/            Prometheus metrics
```

## Local build

```sh
cd docker/essayist-gateway
go mod tidy
go test ./...
go build -o /tmp/essayist-gateway ./cmd/gateway
```

## Docker

```sh
docker compose --profile essayist build essayist-gateway
docker compose --profile essayist up -d
```

## Required env

```
ESSAYIST_POLICY_TOKEN   # bearer secret shared with the PHP policy endpoint
REDIS_URL               # e.g. redis://:<password>@redis:6379
```

All other knobs have safe defaults — see `internal/config/config.go` or the
design doc for the full table.

