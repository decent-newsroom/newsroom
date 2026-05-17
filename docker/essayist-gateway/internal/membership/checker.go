package membership

import "context"

// Result represents the outcome of a membership lookup.
type Result int

const (
	Unknown Result = iota
	Approved
	Rejected
)

// Checker resolves whether a hex pubkey holds an active Essayist membership.
//
// Implementations are expected to be safe for concurrent use.
type Checker interface {
	IsMember(ctx context.Context, pubkeyHex string) (bool, error)
}

