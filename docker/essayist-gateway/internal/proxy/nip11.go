package proxy

import (
	"encoding/json"
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

		if isNIP11 && strings.Contains(strings.ToLower(resp.Header.Get("Content-Type")), "application/json") {
			body, err := io.ReadAll(resp.Body)
			if err != nil {
				http.Error(w, "upstream read error", http.StatusBadGateway)
				return
			}
			if enriched, ok := enrichNIP11Response(body, r); ok {
				w.WriteHeader(resp.StatusCode)
				_, _ = w.Write(enriched)
				return
			}
			w.WriteHeader(resp.StatusCode)
			_, _ = w.Write(body)
			return
		}

		w.WriteHeader(resp.StatusCode)
		_, _ = io.Copy(w, resp.Body)
	}
}

func enrichNIP11Response(body []byte, r *http.Request) ([]byte, bool) {
	var doc map[string]any
	if err := json.Unmarshal(body, &doc); err != nil {
		return nil, false
	}

	if _, ok := doc["icon"]; !ok {
		doc["icon"] = requestPublicBaseURL(r) + "/favicon.ico"
	}

	limitation, _ := doc["limitation"].(map[string]any)
	if limitation == nil {
		limitation = make(map[string]any)
	}
	if _, ok := limitation["auth_required"]; !ok {
		limitation["auth_required"] = true
	}
	if _, ok := limitation["restricted_writes"]; !ok {
		limitation["restricted_writes"] = true
	}
	doc["limitation"] = limitation

	enriched, err := json.Marshal(doc)
	if err != nil {
		return nil, false
	}

	return enriched, true
}

func requestPublicBaseURL(r *http.Request) string {
	scheme := strings.TrimSpace(r.Header.Get("X-Forwarded-Proto"))
	if scheme == "" {
		if r.TLS != nil {
			scheme = "https"
		} else {
			scheme = "http"
		}
	}

	host := strings.TrimSpace(r.Header.Get("X-Forwarded-Host"))
	if host == "" {
		host = r.Host
	}

	return scheme + "://" + host
}

func setNIP11CORSHeaders(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Headers", "Accept, Content-Type, Authorization")
	w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
}
