package auth

import (
	"errors"
	"fmt"
	"time"

	"github.com/nbd-wtf/go-nostr"
)

// VerifyOutcome enumerates the possible results of verifying a kind:22242 AUTH event.
type VerifyOutcome string

const (
	OutcomeOK              VerifyOutcome = "authed"
	OutcomeRejectedKind    VerifyOutcome = "rejected_kind"
	OutcomeRejectedExpired VerifyOutcome = "rejected_expired"
	OutcomeRejectedChall   VerifyOutcome = "rejected_challenge"
	OutcomeRejectedRelay   VerifyOutcome = "rejected_relay"
	OutcomeRejectedSig     VerifyOutcome = "rejected_sig"
)

var (
	ErrKind      = errors.New("kind must be 22242")
	ErrExpired   = errors.New("created_at outside tolerance window")
	ErrChallenge = errors.New("missing or mismatched challenge tag")
	ErrRelay     = errors.New("missing or mismatched relay tag")
	ErrSignature = errors.New("invalid signature")
)

// Verifier verifies kind:22242 (NIP-42) AUTH events.
type Verifier struct {
	RelayPublicURL string
	Tolerance      time.Duration
	Now            func() time.Time // override for tests; defaults to time.Now
}

// Verify checks the event against the issued challenge and returns either OK
// with the verified pubkey, or a typed error explaining the failure mode.
func (v *Verifier) Verify(ev *nostr.Event, issuedChallenge string) (VerifyOutcome, error) {
	if ev == nil {
		return OutcomeRejectedSig, errors.New("nil event")
	}
	if ev.Kind != 22242 {
		return OutcomeRejectedKind, fmt.Errorf("%w: got %d", ErrKind, ev.Kind)
	}

	now := time.Now
	if v.Now != nil {
		now = v.Now
	}
	tol := v.Tolerance
	if tol <= 0 {
		tol = 60 * time.Second
	}

	ts := time.Unix(int64(ev.CreatedAt), 0)
	delta := now().Sub(ts)
	if delta < 0 {
		delta = -delta
	}
	if delta > tol {
		return OutcomeRejectedExpired, fmt.Errorf("%w: |Δ|=%s > tol=%s", ErrExpired, delta, tol)
	}

	gotChallenge := firstTagValue(ev, "challenge")
	if gotChallenge == "" || gotChallenge != issuedChallenge {
		return OutcomeRejectedChall, ErrChallenge
	}

	gotRelay := firstTagValue(ev, "relay")
	if gotRelay == "" {
		return OutcomeRejectedRelay, ErrRelay
	}
	if !SameRelayURL(gotRelay, v.RelayPublicURL) {
		return OutcomeRejectedRelay, fmt.Errorf("%w: got %q want %q", ErrRelay, gotRelay, v.RelayPublicURL)
	}

	ok, err := ev.CheckSignature()
	if err != nil || !ok {
		return OutcomeRejectedSig, ErrSignature
	}

	return OutcomeOK, nil
}

func firstTagValue(ev *nostr.Event, name string) string {
	for _, t := range ev.Tags {
		if len(t) >= 2 && t[0] == name {
			return t[1]
		}
	}
	return ""
}

