package config

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config is the gateway runtime configuration, loaded from environment variables.
//
// All env vars and their defaults are documented in documentation/essayist-gateway.md.
type Config struct {
	ListenAddr       string
	HealthAddr       string
	MetricsAddr      string
	UpstreamRelayURL string
	RelayPublicURL   string

	PolicyURLTemplate string
	PolicyToken       string
	PolicyTimeout     time.Duration

	RedisURL                 string
	RedisMemberKeyPrefix     string
	RedisMemberTTL           time.Duration
	RedisMemberNegTTL        time.Duration
	RedisBreakerCooldown     time.Duration
	RedisRevocationChannel   string

	AuthTimeout            time.Duration
	CreatedAtTolerance     time.Duration
	MaxConnections         int
	MaxConnectionsPerIP    int
	MaxPreauthFrameBytes   int
	MaxBufferFrames        int

	LogLevel string
}

func FromEnv() (*Config, error) {
	c := &Config{
		ListenAddr:             env("LISTEN_ADDR", ":7780"),
		HealthAddr:             env("HEALTH_ADDR", ":7781"),
		MetricsAddr:            env("METRICS_ADDR", ":7782"),
		UpstreamRelayURL:       env("UPSTREAM_RELAY_URL", "ws://strfry-essayist:7779"),
		RelayPublicURL:         env("RELAY_PUBLIC_URL", "wss://essayist.decentnewsroom.com"),
		PolicyURLTemplate:      env("POLICY_URL_TEMPLATE", "http://php/api/internal/essayist/writer/{pubkey}"),
		PolicyToken:            os.Getenv("ESSAYIST_POLICY_TOKEN"),
		PolicyTimeout:          envDuration("POLICY_HTTP_TIMEOUT_SECONDS", 3*time.Second),
		RedisURL:               os.Getenv("REDIS_URL"),
		RedisMemberKeyPrefix:   env("REDIS_MEMBER_KEY_PREFIX", "essayist_member:"),
		RedisMemberTTL:         envDuration("REDIS_MEMBER_TTL_SECONDS", 600*time.Second),
		RedisMemberNegTTL:      envDuration("REDIS_MEMBER_NEG_TTL_SECONDS", 30*time.Second),
		RedisBreakerCooldown:   envDuration("REDIS_BREAKER_COOLDOWN_SECONDS", 30*time.Second),
		RedisRevocationChannel: env("REVOCATION_CHANNEL", "essayist_member_revoked"),
		AuthTimeout:            envDuration("AUTH_TIMEOUT_SECONDS", 10*time.Second),
		CreatedAtTolerance:     envDuration("CREATED_AT_TOLERANCE_SECONDS", 60*time.Second),
		MaxConnections:         envInt("MAX_CONNECTIONS", 2000),
		MaxConnectionsPerIP:    envInt("MAX_CONNECTIONS_PER_IP", 20),
		MaxPreauthFrameBytes:   envInt("MAX_PREAUTH_FRAME_BYTES", 32*1024),
		MaxBufferFrames:        envInt("MAX_BUFFER_FRAMES", 10),
		LogLevel:               strings.ToLower(env("LOG_LEVEL", "info")),
	}

	var missing []string
	if c.PolicyToken == "" {
		missing = append(missing, "ESSAYIST_POLICY_TOKEN")
	}
	if c.RedisURL == "" {
		missing = append(missing, "REDIS_URL")
	}
	if !strings.Contains(c.PolicyURLTemplate, "{pubkey}") {
		return nil, errors.New("POLICY_URL_TEMPLATE must contain the literal substring {pubkey}")
	}
	if len(missing) > 0 {
		return nil, fmt.Errorf("required env vars not set: %s", strings.Join(missing, ", "))
	}

	return c, nil
}

func env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func envInt(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}

func envDuration(key string, def time.Duration) time.Duration {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return time.Duration(n) * time.Second
		}
	}
	return def
}

