package proxy

import (
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// NIP11Handler proxies GET / requests with `Accept: application/nostr+json`
// directly to the upstream relay's HTTP listener. All other paths/methods/Accepts
// return 404.
//
// The upstream relay URL must be a ws:// or wss:// URL; we transform it to
// http:// or https:// for the metadata request.
func NIP11Handler(upstreamRelayWS string) http.HandlerFunc {
	u, err := url.Parse(upstreamRelayWS)
	if err != nil {
		panic("nip11: invalid upstream relay URL: " + err.Error())
	}
	httpScheme := "http"
	if u.Scheme == "wss" {
		httpScheme = "https"
	}
	upstreamHTTP := httpScheme + "://" + u.Host

	client := &http.Client{Timeout: 5 * time.Second}

	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/" {
			http.NotFound(w, r)
			return
		}
		accept := r.Header.Get("Accept")
		if !strings.Contains(accept, "application/nostr+json") {
			http.NotFound(w, r)
			return
		}

		req, err := http.NewRequestWithContext(r.Context(), http.MethodGet, upstreamHTTP, nil)
		if err != nil {
			http.Error(w, "upstream error", http.StatusBadGateway)
			return
		}
		req.Header.Set("Accept", "application/nostr+json")

		resp, err := client.Do(req)
		if err != nil {
			http.Error(w, "upstream unreachable", http.StatusBadGateway)
			return
		}
		defer resp.Body.Close()

		w.Header().Set("Content-Type", "application/nostr+json")
		w.WriteHeader(resp.StatusCode)
		_, _ = io.Copy(w, resp.Body)
	}
}

