package proxy

import (
	"context"
	"encoding/json"
	"log/slog"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/google/uuid"
	"github.com/gorilla/websocket"
	"github.com/nbd-wtf/go-nostr"

	"github.com/decentnewsroom/essayist-gateway/internal/auth"
	"github.com/decentnewsroom/essayist-gateway/internal/membership"
	"github.com/decentnewsroom/essayist-gateway/internal/metrics"
)

const (
	defaultHandshakeTimeout = 10 * time.Second
)

// Handler config — populated by main and used to construct per-connection state.
type Handler struct {
	UpstreamRelayURL     string
	RelayPublicURL       string
	AuthTimeout          time.Duration
	CreatedAtTolerance   time.Duration
	MaxBufferFrames      int
	MaxPreauthFrameBytes int
	MaxConnections       int
	MaxConnectionsPerIP  int

	Membership membership.Checker
	Logger     *slog.Logger

	connMu     sync.Mutex
	total      int
	perIP      map[string]int
	authedConn map[string]map[string]*websocket.Conn // pubkey -> connID -> client conn
}

// NewHandler initialises maps and returns a ready-to-use handler.
func (h *Handler) Init() *Handler {
	h.perIP = make(map[string]int)
	h.authedConn = make(map[string]map[string]*websocket.Conn)
	return h
}

// upgrader for client connections. Origin is intentionally not checked — Nostr
// relays are public WebSocket endpoints and the gateway gates by AUTH, not Origin.
var upgrader = websocket.Upgrader{
	ReadBufferSize:  4096,
	WriteBufferSize: 4096,
	CheckOrigin:     func(_ *http.Request) bool { return true },
}

// ServeHTTP is the entry point invoked by net/http on every inbound request.
//
// Non-WebSocket GET requests with `Accept: application/nostr+json` are handed
// to the NIP-11 handler at the mux level; this method only sees WS upgrades.
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	remote := clientIP(r)
	if !h.acquire(remote) {
		http.Error(w, "too many connections", http.StatusServiceUnavailable)
		return
	}
	defer h.release(remote)

	c, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		// Upgrade already wrote an error response.
		return
	}
	defer c.Close()

	metrics.ActiveConnections.Inc()
	defer metrics.ActiveConnections.Dec()

	connID := uuid.NewString()[:8]
	logger := h.Logger.With("conn_id", connID, "remote", remote)

	h.run(r.Context(), connID, c, logger)
}

// run drives one connection through: AUTH → membership → proxy.
func (h *Handler) run(ctx context.Context, connID string, client *websocket.Conn, logger *slog.Logger) {
	challenge, err := auth.NewChallenge()
	if err != nil {
		logger.Error("challenge generation failed", "err", err)
		return
	}

	// Bound the write so a stalled socket cannot block us indefinitely.
	_ = client.SetWriteDeadline(time.Now().Add(5 * time.Second))
	if err := client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"AUTH", challenge})); err != nil {
		logger.Warn("AUTH challenge write failed", "err", err, "outcome", "write_failed")
		metrics.AuthTotal.WithLabelValues("write_failed").Inc()
		return
	}
	_ = client.SetWriteDeadline(time.Time{})
	logger.Info("AUTH challenge sent")

	// Apply a hard deadline for the AUTH phase.
	authDeadline := time.Now().Add(h.AuthTimeout)
	_ = client.SetReadDeadline(authDeadline)
	client.SetReadLimit(int64(h.MaxPreauthFrameBytes))

	verifier := &auth.Verifier{
		RelayPublicURL: h.RelayPublicURL,
		Tolerance:      h.CreatedAtTolerance,
	}

	start := time.Now()
	var buffered [][]byte

	for {
		mt, data, err := client.ReadMessage()
		if err != nil {
			if ne, ok := err.(net.Error); ok && ne.Timeout() {
				logger.Warn("AUTH timeout, closing", "outcome", "timeout")
				metrics.AuthTotal.WithLabelValues("timeout").Inc()
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", "auth-required: AUTH timeout"}))
				return
			}
			logger.Debug("client closed during AUTH", "err", err)
			return
		}
		if mt != websocket.TextMessage {
			continue
		}

		var frame []any
		if err := json.Unmarshal(data, &frame); err != nil || len(frame) == 0 {
			continue
		}
		cmd, _ := frame[0].(string)
		switch cmd {
		case "AUTH":
			if len(frame) < 2 {
				continue
			}
			evBytes, err := json.Marshal(frame[1])
			if err != nil {
				continue
			}
			var ev nostr.Event
			if err := json.Unmarshal(evBytes, &ev); err != nil {
				rejectAndClose(client, "rejected_sig", "restricted: authentication failed", logger, start)
				return
			}
			outcome, verr := verifier.Verify(&ev, challenge)
			if outcome != auth.OutcomeOK {
				logger.Warn("AUTH rejected", "outcome", string(outcome), "err", verr)
				metrics.AuthTotal.WithLabelValues(string(outcome)).Inc()
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"OK", ev.ID, false, "restricted: authentication failed"}))
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", "restricted: authentication failed"}))
				return
			}

			// Membership check
			memCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
			isMember, err := h.Membership.IsMember(memCtx, ev.PubKey)
			cancel()
			if err != nil {
				logger.Warn("membership lookup failed", "err", err, "pubkey", short(ev.PubKey))
				metrics.AuthTotal.WithLabelValues("rejected_membership").Inc()
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"OK", ev.ID, false, "error: membership check temporarily unavailable"}))
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", "error: membership check temporarily unavailable"}))
				return
			}
			if !isMember {
				logger.Warn("membership rejected", "pubkey", short(ev.PubKey), "outcome", "rejected_membership", "latency_ms", time.Since(start).Milliseconds())
				metrics.AuthTotal.WithLabelValues("rejected_membership").Inc()
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"OK", ev.ID, false, "restricted: active Essayist membership required"}))
				_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", "restricted: active Essayist membership required — decentnewsroom.com/essayist"}))
				return
			}

			// Approved — confirm to client and start proxying.
			_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"OK", ev.ID, true, ""}))
			metrics.AuthTotal.WithLabelValues("authed").Inc()
			logger.Info("AUTH accepted, proxying",
				"pubkey", short(ev.PubKey),
				"outcome", "authed",
				"latency_ms", time.Since(start).Milliseconds(),
			)

			_ = client.SetReadDeadline(time.Time{}) // clear deadline
			client.SetReadLimit(0)                  // remove pre-auth size limit

			h.trackAuthed(ev.PubKey, connID, client)
			defer h.untrackAuthed(ev.PubKey, connID)

			h.proxy(ctx, client, buffered, logger)
			return

		case "REQ", "EVENT", "COUNT", "CLOSE":
			// Pre-auth frame.
			if len(buffered) >= h.MaxBufferFrames || len(data) > h.MaxPreauthFrameBytes {
				metrics.PreauthBufferOverflowTotal.Inc()
				// Tell the client this specific frame is rejected as auth-required.
				switch cmd {
				case "REQ":
					subID := ""
					if len(frame) >= 2 {
						subID, _ = frame[1].(string)
					}
					_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"CLOSED", subID, "auth-required: membership required"}))
				case "EVENT":
					evID := ""
					if m, ok := frame[1].(map[string]any); ok {
						evID, _ = m["id"].(string)
					}
					_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"OK", evID, false, "auth-required: membership required"}))
				}
				continue
			}
			buffered = append(buffered, data)

		default:
			// Unknown command pre-auth — ignore.
		}
	}
}

// proxy opens the upstream socket and runs the bidirectional copy loop.
func (h *Handler) proxy(ctx context.Context, client *websocket.Conn, replay [][]byte, logger *slog.Logger) {
	dialCtx, cancel := context.WithTimeout(ctx, defaultHandshakeTimeout)
	defer cancel()
	upDial := time.Now()
	upstream, _, err := dialUpstream(dialCtx, h.UpstreamRelayURL)
	if err != nil {
		metrics.UpstreamDialFailuresTotal.Inc()
		logger.Error("upstream dial failed", "err", err)
		_ = client.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", "error: upstream relay unreachable"}))
		return
	}
	defer upstream.Close()
	logger.Debug("upstream dialled", "upstream_latency_ms", time.Since(upDial).Milliseconds())

	errCh := make(chan error, 2)
	go func() { errCh <- pipeClientToUpstream(client, upstream, replay) }()
	go func() { errCh <- pipeUpstreamToClient(upstream, client) }()

	err = <-errCh
	if err != nil && !isCloseErr(err) {
		logger.Debug("proxy loop ended", "err", err)
	}
}

// ----------------------------------------------------------------------------
// connection accounting + revocation
// ----------------------------------------------------------------------------

func (h *Handler) acquire(ip string) bool {
	h.connMu.Lock()
	defer h.connMu.Unlock()
	if h.MaxConnections > 0 && h.total >= h.MaxConnections {
		return false
	}
	if h.MaxConnectionsPerIP > 0 && h.perIP[ip] >= h.MaxConnectionsPerIP {
		return false
	}
	h.total++
	h.perIP[ip]++
	return true
}

func (h *Handler) release(ip string) {
	h.connMu.Lock()
	defer h.connMu.Unlock()
	h.total--
	if h.perIP[ip] > 0 {
		h.perIP[ip]--
	}
}

func (h *Handler) trackAuthed(pubkey, connID string, c *websocket.Conn) {
	h.connMu.Lock()
	defer h.connMu.Unlock()
	if h.authedConn[pubkey] == nil {
		h.authedConn[pubkey] = make(map[string]*websocket.Conn)
	}
	h.authedConn[pubkey][connID] = c
}

func (h *Handler) untrackAuthed(pubkey, connID string) {
	h.connMu.Lock()
	defer h.connMu.Unlock()
	if m, ok := h.authedConn[pubkey]; ok {
		delete(m, connID)
		if len(m) == 0 {
			delete(h.authedConn, pubkey)
		}
	}
}

// RevokePubkey forcibly closes every authenticated connection belonging to pubkey.
// Returns the number of connections that were closed.
func (h *Handler) RevokePubkey(pubkey string) int {
	h.connMu.Lock()
	conns := h.authedConn[pubkey]
	closed := len(conns)
	h.connMu.Unlock()
	if conns == nil {
		return 0
	}
	for _, c := range conns {
		_ = c.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", "restricted: membership revoked"}))
		_ = c.Close()
	}
	if closed > 0 {
		metrics.RevocationCloseTotal.Add(float64(closed))
	}
	return closed
}

// ----------------------------------------------------------------------------
// helpers
// ----------------------------------------------------------------------------

func mustMarshal(v any) []byte {
	b, err := json.Marshal(v)
	if err != nil {
		panic(err) // should never happen for our value shapes
	}
	return b
}

func rejectAndClose(c *websocket.Conn, outcome, msg string, logger *slog.Logger, start time.Time) {
	metrics.AuthTotal.WithLabelValues(outcome).Inc()
	logger.Warn("AUTH rejected", "outcome", outcome, "latency_ms", time.Since(start).Milliseconds())
	_ = c.WriteMessage(websocket.TextMessage, mustMarshal([]any{"CLOSED", "*", msg}))
	_ = c.WriteMessage(websocket.TextMessage, mustMarshal([]any{"NOTICE", msg}))
}

func clientIP(r *http.Request) string {
	if xf := r.Header.Get("X-Forwarded-For"); xf != "" {
		if idx := strings.IndexByte(xf, ','); idx > 0 {
			return strings.TrimSpace(xf[:idx])
		}
		return strings.TrimSpace(xf)
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}

func short(hex string) string {
	if len(hex) > 8 {
		return hex[:8]
	}
	return hex
}



