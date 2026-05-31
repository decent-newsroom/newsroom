package proxy

import (
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// NIP11Handler proxies unauthenticated HTTP GET requests to the upstream relay's
// HTTP listener so clients receive strfry's default HTTP behavior. Non-GET
// methods return 404.
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
		setNIP11CORSHeaders(w)

		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}

		if r.Method != http.MethodGet {
			http.NotFound(w, r)
			return
		}

		upstreamURL := upstreamHTTP + r.URL.RequestURI()

		req, err := http.NewRequestWithContext(r.Context(), http.MethodGet, upstreamURL, nil)
		if err != nil {
			http.Error(w, "upstream error", http.StatusBadGateway)
			return
		}
		accept := r.Header.Get("Accept")
		isNIP11 := accept == "" || strings.Contains(accept, "*/*") || strings.Contains(accept, "application/nostr+json")
		if isNIP11 {
			// Some web clients omit the explicit NIP-11 accept header.
			// Default to nostr+json so relay metadata can still be discovered.
			req.Header.Set("Accept", "application/nostr+json")
		} else {
			req.Header.Set("Accept", accept)
		}

		resp, err := client.Do(req)
		if err != nil {
			http.Error(w, "upstream unreachable", http.StatusBadGateway)
			return
		}
		defer resp.Body.Close()

		if contentType := resp.Header.Get("Content-Type"); contentType != "" {
			w.Header().Set("Content-Type", contentType)
		}
		w.WriteHeader(resp.StatusCode)
		_, _ = io.Copy(w, resp.Body)
	}
}

func setNIP11CORSHeaders(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Headers", "Accept, Content-Type, Authorization")
	w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
}
