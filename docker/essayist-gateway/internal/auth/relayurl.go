package auth

import (
	"fmt"
	"net/url"
	"strings"
)

// NormaliseRelayURL produces a canonical form of a Nostr relay URL for equality
// comparison against the kind:22242 "relay" tag.
//
// Rules:
//   - Scheme is required and lowercased (must be ws or wss).
//   - Host is lowercased.
//   - Default ports are stripped (80 for ws, 443 for wss).
//   - Trailing slash on the path is stripped.
//   - Any non-empty path, query, or fragment results in an error.
//
// This mirrors the normalisation libraries like nostr-tools / NDK perform when
// signing kind:22242 events. Pin it explicitly here so cross-client behaviour is
// predictable.
func NormaliseRelayURL(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", fmt.Errorf("empty relay url")
	}

	u, err := url.Parse(raw)
	if err != nil {
		return "", fmt.Errorf("parse relay url: %w", err)
	}

	scheme := strings.ToLower(u.Scheme)
	if scheme != "ws" && scheme != "wss" {
		return "", fmt.Errorf("relay url scheme must be ws or wss, got %q", u.Scheme)
	}

	host := strings.ToLower(u.Hostname())
	if host == "" {
		return "", fmt.Errorf("relay url is missing host")
	}

	port := u.Port()
	if (scheme == "ws" && port == "80") || (scheme == "wss" && port == "443") {
		port = ""
	}

	path := strings.TrimRight(u.Path, "/")

	if u.RawQuery != "" || u.Fragment != "" {
		return "", fmt.Errorf("relay url must not have query or fragment")
	}
	// Allow an empty path or root "/", but not anything deeper.
	if path != "" {
		return "", fmt.Errorf("relay url must not have a path component")
	}

	hostPort := host
	if port != "" {
		hostPort = host + ":" + port
	}
	return scheme + "://" + hostPort, nil
}

// SameRelayURL returns true if the two URLs compare equal after normalisation.
func SameRelayURL(a, b string) bool {
	na, errA := NormaliseRelayURL(a)
	nb, errB := NormaliseRelayURL(b)
	if errA != nil || errB != nil {
		return false
	}
	return na == nb
}

