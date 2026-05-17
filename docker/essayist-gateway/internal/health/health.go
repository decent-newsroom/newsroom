package health

import (
	"context"
	"encoding/json"
	"net"
	"net/http"
	"net/url"
	"time"

	"github.com/redis/go-redis/v9"
)

// Handler returns an http.Handler that runs liveness + readiness probes:
//   - The process is up (implicit if this code is running).
//   - A TCP dial to upstreamRelayWS succeeds within 1s.
//   - A Redis PING succeeds within 500ms.
//
// All three must pass for 200. Otherwise 503 with a JSON body explaining
// which dependency failed.
func Handler(upstreamRelayWS string, rdb *redis.Client) http.Handler {
	u, _ := url.Parse(upstreamRelayWS)
	hostPort := u.Host

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		report := map[string]string{}
		ok := true

		dialCtx, cancel := context.WithTimeout(r.Context(), 1*time.Second)
		conn, err := (&net.Dialer{}).DialContext(dialCtx, "tcp", hostPort)
		cancel()
		if err != nil {
			report["upstream"] = "fail: " + err.Error()
			ok = false
		} else {
			report["upstream"] = "ok"
			_ = conn.Close()
		}

		if rdb != nil {
			pingCtx, cancel := context.WithTimeout(r.Context(), 500*time.Millisecond)
			err := rdb.Ping(pingCtx).Err()
			cancel()
			if err != nil {
				report["redis"] = "fail: " + err.Error()
				ok = false
			} else {
				report["redis"] = "ok"
			}
		}

		w.Header().Set("Content-Type", "application/json")
		if !ok {
			w.WriteHeader(http.StatusServiceUnavailable)
		}
		_ = json.NewEncoder(w).Encode(report)
	})
}

