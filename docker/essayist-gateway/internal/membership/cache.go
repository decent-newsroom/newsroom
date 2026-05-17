package membership

import (
	"context"
	"log/slog"
	"sync"
	"sync/atomic"
	"time"
)

// CachedChecker is the composite two-tier membership lookup:
//
//	Redis fast-path  →  HTTP slow-path  →  Redis write-back
//
// If Redis is unreachable, the breaker opens for BreakerCooldown and the
// checker falls back to slow-path only (fail-degraded).
type CachedChecker struct {
	Redis  *RedisStore
	HTTP   *HTTPChecker
	Logger *slog.Logger

	BreakerCooldown time.Duration

	mu              sync.Mutex
	breakerOpenedAt time.Time

	HitApproved atomic.Uint64
	HitRejected atomic.Uint64
	Miss        atomic.Uint64
	Errors      atomic.Uint64
}

func (c *CachedChecker) breakerOpen() bool {
	if c.BreakerCooldown <= 0 {
		return false
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.breakerOpenedAt.IsZero() {
		return false
	}
	if time.Since(c.breakerOpenedAt) > c.BreakerCooldown {
		c.breakerOpenedAt = time.Time{}
		return false
	}
	return true
}

func (c *CachedChecker) tripBreaker() {
	c.mu.Lock()
	c.breakerOpenedAt = time.Now()
	c.mu.Unlock()
}

// IsMember resolves membership with the two-tier strategy.
func (c *CachedChecker) IsMember(ctx context.Context, pubkeyHex string) (bool, error) {
	// 1. Fast path
	if c.Redis != nil && !c.breakerOpen() {
		switch res, err := c.Redis.Get(ctx, pubkeyHex); {
		case err != nil:
			c.Errors.Add(1)
			c.tripBreaker()
			if c.Logger != nil {
				c.Logger.Warn("redis fast-path error, opening breaker", "err", err)
			}
		case res == Approved:
			c.HitApproved.Add(1)
			return true, nil
		case res == Rejected:
			c.HitRejected.Add(1)
			return false, nil
		default:
			c.Miss.Add(1)
		}
	}

	// 2. Slow path
	ok, err := c.HTTP.IsMember(ctx, pubkeyHex)
	if err != nil {
		return false, err
	}

	// 3. Write-back (best effort)
	if c.Redis != nil && !c.breakerOpen() {
		if setErr := c.Redis.Set(ctx, pubkeyHex, ok); setErr != nil {
			c.tripBreaker()
			if c.Logger != nil {
				c.Logger.Warn("redis write-back error, opening breaker", "err", setErr)
			}
		}
	}
	return ok, nil
}

// Invalidate drops the cached decision for a pubkey (used on revocation pub/sub).
func (c *CachedChecker) Invalidate(ctx context.Context, pubkeyHex string) {
	if c.Redis == nil {
		return
	}
	_ = c.Redis.Delete(ctx, pubkeyHex)
}

