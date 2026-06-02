package auth

import (
	"crypto/rand"
	"encoding/hex"
)

// NewChallenge returns 16 random bytes encoded as 32 hex characters.
// Each WebSocket connection owns its own challenge for the lifetime of the AUTH window.
// 16 bytes provides sufficient cryptographic entropy for one-time challenge use
// while remaining compact for reliable client-server transmission.
func NewChallenge() (string, error) {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

