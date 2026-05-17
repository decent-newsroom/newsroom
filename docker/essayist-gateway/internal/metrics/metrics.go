package metrics

import (
	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/promauto"
)

// All Prometheus metrics exposed on METRICS_ADDR.
var (
	AuthTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "gateway_auth_total",
		Help: "Total NIP-42 AUTH attempts, partitioned by outcome.",
	}, []string{"outcome"})

	ActiveConnections = promauto.NewGauge(prometheus.GaugeOpts{
		Name: "gateway_active_connections",
		Help: "Currently open WebSocket connections (any state).",
	})

	MembershipCacheTotal = promauto.NewCounterVec(prometheus.CounterOpts{
		Name: "gateway_membership_cache_total",
		Help: "Membership lookups, partitioned by result.",
	}, []string{"result"})

	PolicyRequestSeconds = promauto.NewHistogramVec(prometheus.HistogramOpts{
		Name:    "gateway_policy_request_seconds",
		Help:    "Latency of slow-path policy lookups, partitioned by outcome.",
		Buckets: prometheus.DefBuckets,
	}, []string{"outcome"})

	UpstreamDialFailuresTotal = promauto.NewCounter(prometheus.CounterOpts{
		Name: "gateway_upstream_dial_failures_total",
		Help: "Total failures dialling the upstream strfry relay after AUTH.",
	})

	PreauthBufferOverflowTotal = promauto.NewCounter(prometheus.CounterOpts{
		Name: "gateway_preauth_buffer_overflow_total",
		Help: "Frames discarded because the pre-auth buffer was full or oversized.",
	})

	RevocationCloseTotal = promauto.NewCounter(prometheus.CounterOpts{
		Name: "gateway_revocation_close_total",
		Help: "Live authenticated connections forcibly closed by a revocation pub/sub event.",
	})
)

