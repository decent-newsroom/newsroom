package proxy

import (
	"context"
	"net/http"
	"net/url"
	"strings"

	"github.com/gorilla/websocket"
)

// dialUpstream opens a WebSocket connection to the upstream strfry relay.
//
// It deliberately uses a plain client (no compression negotiation) — payload
// compression is the upstream relay's concern and Caddy already handles encode
// on the client-facing leg.
func dialUpstream(ctx context.Context, upstreamURL string) (*websocket.Conn, *http.Response, error) {
	u, err := url.Parse(upstreamURL)
	if err != nil {
		return nil, nil, err
	}
	dialer := websocket.Dialer{
		HandshakeTimeout: defaultHandshakeTimeout,
	}
	headers := http.Header{}
	// Hint to upstream that this is the gateway, useful for relay-side logs.
	headers.Set("User-Agent", "essayist-gateway/1.0")

	c, resp, err := dialer.DialContext(ctx, u.String(), headers)
	return c, resp, err
}

// pipeClientToUpstream copies WS frames from client → upstream until either side closes.
func pipeClientToUpstream(client, upstream *websocket.Conn, replay [][]byte) error {
	for _, msg := range replay {
		if err := upstream.WriteMessage(websocket.TextMessage, msg); err != nil {
			return err
		}
	}
	for {
		mt, data, err := client.ReadMessage()
		if err != nil {
			return err
		}
		if mt != websocket.TextMessage && mt != websocket.BinaryMessage {
			continue
		}
		if err := upstream.WriteMessage(mt, data); err != nil {
			return err
		}
	}
}

// pipeUpstreamToClient copies WS frames from upstream → client until either side closes.
func pipeUpstreamToClient(upstream, client *websocket.Conn) error {
	for {
		mt, data, err := upstream.ReadMessage()
		if err != nil {
			return err
		}
		if mt != websocket.TextMessage && mt != websocket.BinaryMessage {
			continue
		}
		if err := client.WriteMessage(mt, data); err != nil {
			return err
		}
	}
}

// isCloseErr is true when an error from ReadMessage represents a normal close.
func isCloseErr(err error) bool {
	if err == nil {
		return false
	}
	if websocket.IsCloseError(err, websocket.CloseNormalClosure, websocket.CloseGoingAway, websocket.CloseNoStatusReceived) {
		return true
	}
	return strings.Contains(err.Error(), "use of closed network connection")
}

