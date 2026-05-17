package auth

import (
	"testing"
	"time"

	"github.com/nbd-wtf/go-nostr"
)

func TestNormaliseRelayURL(t *testing.T) {
	cases := []struct {
		name    string
		in      string
		want    string
		wantErr bool
	}{
		{"plain wss", "wss://essayist.decentnewsroom.com", "wss://essayist.decentnewsroom.com", false},
		{"uppercase host", "WSS://Essayist.DecentNewsroom.COM", "wss://essayist.decentnewsroom.com", false},
		{"trailing slash", "wss://essayist.decentnewsroom.com/", "wss://essayist.decentnewsroom.com", false},
		{"default port 443 stripped", "wss://essayist.decentnewsroom.com:443", "wss://essayist.decentnewsroom.com", false},
		{"default port 80 stripped for ws", "ws://essayist.decentnewsroom.com:80", "ws://essayist.decentnewsroom.com", false},
		{"non-default port preserved", "wss://essayist.decentnewsroom.com:8443", "wss://essayist.decentnewsroom.com:8443", false},
		{"path rejected", "wss://essayist.decentnewsroom.com/foo", "", true},
		{"query rejected", "wss://essayist.decentnewsroom.com?x=1", "", true},
		{"fragment rejected", "wss://essayist.decentnewsroom.com#frag", "", true},
		{"wrong scheme", "https://essayist.decentnewsroom.com", "", true},
		{"empty", "", "", true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, err := NormaliseRelayURL(tc.in)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("expected error, got %q", got)
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if got != tc.want {
				t.Fatalf("got %q want %q", got, tc.want)
			}
		})
	}
}

func TestVerify_RejectsWrongKind(t *testing.T) {
	v := &Verifier{RelayPublicURL: "wss://example", Tolerance: time.Minute}
	ev := &nostr.Event{Kind: 1}
	outcome, err := v.Verify(ev, "abc")
	if outcome != OutcomeRejectedKind || err == nil {
		t.Fatalf("expected rejected_kind, got %v / %v", outcome, err)
	}
}

func TestVerify_RejectsExpired(t *testing.T) {
	v := &Verifier{
		RelayPublicURL: "wss://example",
		Tolerance:      30 * time.Second,
		Now:            func() time.Time { return time.Unix(10_000_000, 0) },
	}
	ev := &nostr.Event{Kind: 22242, CreatedAt: nostr.Timestamp(10_000_000 - 120)}
	outcome, _ := v.Verify(ev, "abc")
	if outcome != OutcomeRejectedExpired {
		t.Fatalf("got %v", outcome)
	}
}

func TestVerify_RejectsBadChallenge(t *testing.T) {
	now := time.Unix(10_000_000, 0)
	v := &Verifier{RelayPublicURL: "wss://example", Tolerance: time.Minute, Now: func() time.Time { return now }}
	ev := &nostr.Event{
		Kind:      22242,
		CreatedAt: nostr.Timestamp(now.Unix()),
		Tags:      nostr.Tags{{"challenge", "wrong"}, {"relay", "wss://example"}},
	}
	outcome, _ := v.Verify(ev, "issued")
	if outcome != OutcomeRejectedChall {
		t.Fatalf("got %v", outcome)
	}
}

func TestVerify_RejectsRelayMismatch(t *testing.T) {
	now := time.Unix(10_000_000, 0)
	v := &Verifier{RelayPublicURL: "wss://example", Tolerance: time.Minute, Now: func() time.Time { return now }}
	ev := &nostr.Event{
		Kind:      22242,
		CreatedAt: nostr.Timestamp(now.Unix()),
		Tags:      nostr.Tags{{"challenge", "issued"}, {"relay", "wss://otherhost"}},
	}
	outcome, _ := v.Verify(ev, "issued")
	if outcome != OutcomeRejectedRelay {
		t.Fatalf("got %v", outcome)
	}
}

