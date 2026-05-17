// Command essayist-gateway is a NIP-42 AUTH-enforcing WebSocket proxy for a
// membership-gated strfry relay (see documentation/essayist-gateway.md).
package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/prometheus/client_golang/prometheus/promhttp"
	"github.com/redis/go-redis/v9"

	"github.com/decentnewsroom/essayist-gateway/internal/config"
	"github.com/decentnewsroom/essayist-gateway/internal/health"
	"github.com/decentnewsroom/essayist-gateway/internal/membership"
	"github.com/decentnewsroom/essayist-gateway/internal/proxy"
)

func main() {
	cfg, err := config.FromEnv()
	if err != nil {
		// Use plain stderr — slog not initialised yet.
		os.Stderr.WriteString("essayist-gateway: " + err.Error() + "\n")
		os.Exit(2)
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: parseLevel(cfg.LogLevel),
	}))
	slog.SetDefault(logger)
	logger.Info("starting essayist-gateway",
		"listen", cfg.ListenAddr,
		"upstream", cfg.UpstreamRelayURL,
		"relay_public_url", cfg.RelayPublicURL,
	)

	// Redis client (used by the cache + revocation subscriber).
	opt, err := redis.ParseURL(cfg.RedisURL)
	if err != nil {
		logger.Error("invalid REDIS_URL", "err", err)
		os.Exit(2)
	}
	rdb := redis.NewClient(opt)

	checker := &membership.CachedChecker{
		Redis:           membership.NewRedisStore(rdb, cfg.RedisMemberKeyPrefix, cfg.RedisMemberTTL, cfg.RedisMemberNegTTL),
		HTTP:            membership.NewHTTPChecker(cfg.PolicyURLTemplate, cfg.PolicyToken, cfg.PolicyTimeout),
		Logger:          logger,
		BreakerCooldown: cfg.RedisBreakerCooldown,
	}

	handler := (&proxy.Handler{
		UpstreamRelayURL:     cfg.UpstreamRelayURL,
		RelayPublicURL:       cfg.RelayPublicURL,
		AuthTimeout:          cfg.AuthTimeout,
		CreatedAtTolerance:   cfg.CreatedAtTolerance,
		MaxBufferFrames:      cfg.MaxBufferFrames,
		MaxPreauthFrameBytes: cfg.MaxPreauthFrameBytes,
		MaxConnections:       cfg.MaxConnections,
		MaxConnectionsPerIP:  cfg.MaxConnectionsPerIP,
		Membership:           checker,
		Logger:               logger,
	}).Init()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	// Revocation subscriber: closes live connections when PHP publishes a revocation.
	go subscribeRevocations(ctx, rdb, cfg.RedisRevocationChannel, checker, handler, logger)

	// Main listener: WS upgrades, with NIP-11 passthrough on plain GET /.
	mux := http.NewServeMux()
	nip11 := proxy.NIP11Handler(cfg.UpstreamRelayURL)
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		// Distinguish WS upgrade from NIP-11 metadata fetch.
		if strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
			handler.ServeHTTP(w, r)
			return
		}
		nip11(w, r)
	})

	mainSrv := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}
	healthSrv := &http.Server{
		Addr:              cfg.HealthAddr,
		Handler:           health.Handler(cfg.UpstreamRelayURL, rdb),
		ReadHeaderTimeout: 5 * time.Second,
	}
	metricsMux := http.NewServeMux()
	metricsMux.Handle("/metrics", promhttp.Handler())
	metricsSrv := &http.Server{
		Addr:              cfg.MetricsAddr,
		Handler:           metricsMux,
		ReadHeaderTimeout: 5 * time.Second,
	}

	go listen(healthSrv, "health", logger)
	go listen(metricsSrv, "metrics", logger)
	go listen(mainSrv, "main", logger)

	<-ctx.Done()
	logger.Info("shutdown requested, closing listeners")

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = mainSrv.Shutdown(shutdownCtx)
	_ = healthSrv.Shutdown(shutdownCtx)
	_ = metricsSrv.Shutdown(shutdownCtx)
	_ = rdb.Close()
}

func listen(s *http.Server, name string, logger *slog.Logger) {
	logger.Info("listener up", "name", name, "addr", s.Addr)
	if err := s.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		logger.Error("listener exited", "name", name, "err", err)
		os.Exit(1)
	}
}

func subscribeRevocations(
	ctx context.Context,
	rdb *redis.Client,
	channel string,
	checker *membership.CachedChecker,
	handler *proxy.Handler,
	logger *slog.Logger,
) {
	for {
		if ctx.Err() != nil {
			return
		}
		sub := rdb.Subscribe(ctx, channel)
		ch := sub.Channel()
		logger.Info("revocation subscriber connected", "channel", channel)

	loop:
		for {
			select {
			case <-ctx.Done():
				_ = sub.Close()
				return
			case msg, ok := <-ch:
				if !ok {
					_ = sub.Close()
					break loop
				}
				pubkey := strings.TrimSpace(msg.Payload)
				if pubkey == "" {
					continue
				}
				closed := handler.RevokePubkey(pubkey)
				checker.Invalidate(ctx, pubkey)
				logger.Info("revocation processed", "pubkey", short(pubkey), "closed_conns", closed)
			}
		}

		// Reconnect after a short backoff.
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}

func short(hex string) string {
	if len(hex) > 8 {
		return hex[:8]
	}
	return hex
}

func parseLevel(s string) slog.Level {
	switch strings.ToLower(s) {
	case "debug":
		return slog.LevelDebug
	case "warn":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

