package auth

import (
	"crypto/rand"
	"encoding/hex"
)

// NewChallenge returns 32 random bytes encoded as 64 hex characters.
// Each WebSocket connection owns its own challenge for the lifetime of the AUTH window.
func NewChallenge() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

