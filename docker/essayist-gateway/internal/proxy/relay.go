package proxy

import (
	"context"
	"encoding/json"
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
		if handled, err := handleClientEventKindGuard(client, msg); handled {
			if err != nil {
				return err
			}
			continue
		}
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
		if mt == websocket.TextMessage {
			if handled, err := handleClientEventKindGuard(client, data); handled {
				if err != nil {
					return err
				}
				continue
			}
		}
		if err := upstream.WriteMessage(mt, data); err != nil {
			return err
		}
	}
}

func handleClientEventKindGuard(client *websocket.Conn, data []byte) (bool, error) {
	var frame []json.RawMessage
	if err := json.Unmarshal(data, &frame); err != nil || len(frame) < 2 {
		return false, nil
	}

	var cmd string
	if err := json.Unmarshal(frame[0], &cmd); err != nil || cmd != "EVENT" {
		return false, nil
	}

	var ev struct {
		ID   string `json:"id"`
		Kind int    `json:"kind"`
	}
	if err := json.Unmarshal(frame[1], &ev); err != nil {
		return false, nil
	}

	if ev.Kind == 30023 {
		return false, nil
	}

	msg := "blocked: only published longform articles accepted on this relay (kind 30023)"
	if err := client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"OK", ev.ID, false, msg})); err != nil {
		return true, err
	}
	if err := client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", msg})); err != nil {
		return true, err
	}

	return true, nil
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

