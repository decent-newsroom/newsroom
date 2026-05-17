package membership

import (
	"context"
	"errors"
	"time"

	"github.com/redis/go-redis/v9"
)

// RedisStore is the fast-path tier of the membership cache.
//
// Keys are stored as strings: "1" for approved, "0" for rejected.
// A missing key always falls through to the slow path.
type RedisStore struct {
	client    *redis.Client
	prefix    string
	posTTL    time.Duration
	negTTL    time.Duration
}

func NewRedisStore(client *redis.Client, prefix string, posTTL, negTTL time.Duration) *RedisStore {
	return &RedisStore{client: client, prefix: prefix, posTTL: posTTL, negTTL: negTTL}
}

func (s *RedisStore) key(pubkey string) string { return s.prefix + pubkey }

// Get returns Approved, Rejected, or Unknown (cache miss / redis error).
func (s *RedisStore) Get(ctx context.Context, pubkey string) (Result, error) {
	v, err := s.client.Get(ctx, s.key(pubkey)).Result()
	if errors.Is(err, redis.Nil) {
		return Unknown, nil
	}
	if err != nil {
		return Unknown, err
	}
	switch v {
	case "1":
		return Approved, nil
	case "0":
		return Rejected, nil
	default:
		return Unknown, nil
	}
}

// Set caches a membership decision. Approvals use posTTL, rejections use negTTL.
func (s *RedisStore) Set(ctx context.Context, pubkey string, approved bool) error {
	ttl := s.negTTL
	val := "0"
	if approved {
		ttl = s.posTTL
		val = "1"
	}
	return s.client.Set(ctx, s.key(pubkey), val, ttl).Err()
}

// Delete removes the cached decision (used on revocation propagation).
func (s *RedisStore) Delete(ctx context.Context, pubkey string) error {
	return s.client.Del(ctx, s.key(pubkey)).Err()
}

