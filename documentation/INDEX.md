# Project Documentation Index

This folder contains **project / product / feature documentation**.

For **environment setup & operations** (Dockerized Symfony stack, deployment, troubleshooting), see `docs/`.

## Areas

### Core Features
- [Article Editor](Editor/editor.md) — IDE-style editor with Quill, advanced metadata, Nostr publishing
- [Links, Mentions & Embeds](links-mentions-embeds.md) — NIP-19/NIP-27 references in articles
- [Search](Newsroom/search.md) — Dual Elasticsearch/Database search with anonymous support
- [Comments](Newsroom/comments.md) — Kind 1111 comment system with async relay fetch
- [Highlights](Reader/highlights.md) — Kind 9802 highlight display and caching
- [Reading Lists](Newsroom/reading-lists.md) — Curated article lists with workflow state machine
- [Home Feed](home-feed-logged-in.md) — Tabbed feed for logged-in users (Latest, Follows, Interests, Podcasts, News Bots)

### Content & Publishing
- [Discover Page](Reader/discover-page.md) — Latest articles with bot filtering
- [Follows Feed](Reader/follows-feature-implementation.md) — Articles from followed npubs
- [Magazine Wizard](magazine-wizard.md) — 4-step magazine creation flow
- [Magazine Manifest](magazine-manifest.md) — Machine-readable JSON API for magazines
- [Magazine Existing Lists](Reader/magazine-existing-list-attachment.md) — Attach existing reading lists to magazines
- [Kind 30040 Ingestion](Newsroom/kind-30040-ingestion.md) — Publication index event processing
- [AsciiDoc Support](asciidoc-support.md) — AsciiDoc format for articles and kind 30041 chapters
- [Article Broadcast](article-broadcast-feature.md) — Broadcast articles to additional relays
- [Article Placeholder](article-placeholder.md) — Placeholder cards for unfetched articles
- [Article Preview Cards](article-preview-cards.md) — Preview card rendering
- [Article From Coordinate](article-from-coordinate.md) — Resolve articles by Nostr coordinate
- [Article Not Found Search](article-not-found-search-integration.md) — Search integration for missing articles
- [Slug Preservation](slug-preservation-on-publish.md) — Preserve article slugs on republish

### Profiles & Identity
- [Author Profiles](Newsroom/author-profile.md) — Profile display, metadata sync, user persistence
- [Profile Projection](Processes/profile-projection.md) — Async profile aggregation system
- [NIP-05 Badge](Nostr/nip05-badge-component.md) — NIP-05 verification badge component
- [Vanity Names](vanity-names.md) — Custom NIP-05 names on the project domain
- [Interests Editor](interests-editor.md) — Kind 10015 interests list editing

### Media
- [Media Discovery](Media/media-discovery.md) — NIP-68/NIP-71 media events, media manager
- [Extra Metadata (imeta)](extra-metadata-sources-imeta.md) — Source references and media attachment tags

### Relay & Infrastructure
- [Relay Setup](Strfry/relay-setup.md) — strfry configuration, Docker services, Makefile targets
- [Relay Pool](Strfry/relay-pool.md) — Two-tier relay pool, health tracking, gateway
- [Relay Admin](Strfry/relay-admin.md) — Admin dashboard for relay monitoring
- [Redis Views](Redis/redis-views.md) — Redis view store pattern for fast page rendering
- [Cron Processing](Cron/cron-processing.md) — Background job schedule and configuration
- [Workers](Processes/workers.md) — Consolidated worker, event-driven processing
- [Elasticsearch](Elasticsearch/elasticsearch.md) — Optional search backend (feature-flagged)
- [Priority Queue](priority-queue-setup.md) — Messenger queue priority configuration

### Subscriptions & Payments
- [Publication Subdomains](publication-subdomain.md) — Hosted magazine subdomains via Lightning payment
- [Active Indexing](Newsroom/active-indexing-service.md) — On-demand indexing subscription
- [Zaps](LN/zaps.md) — Lightning payments, zap splits
- [Contribution Widget](Newsroom/contribution-widget.md) — Donation/support widget

### Nostr Protocol
- [NIP-46 Remote Signing](Nostr/nip46-remote-signing.md) — Bunker session persistence and relay configuration
- [Tabular Data (NIP-XX)](Nostr/NIP-tabular.md) — Kind 1450 CSV events
- [getNpubRelays Optimization](get-npub-relays-optimization.md) — Relay list resolution performance
- [Session Expiry Fallback](session-expiry-fallback.md) — Resilient session handling

### UI & Navigation
- [My Content Page](my-content-page.md) — Unified content management view
- [Segmented Navigation](menu-segmented-navigation.md) — Sidebar navigation sections
- [QoL: Prev/Next & Back to Top](qol-prev-next-back-to-top.md) — Navigation improvements
- [Custom Homepage](Newsroom/custom-homepage.md) — Homepage configuration
- [Featured Writers](Newsroom/featured-writers.md) — Featured writers component
- [JSON-LD Structured Data](json-ld-structured-data.md) — SEO metadata for articles and magazines

### Hosted Magazines
- [Unfold](Unfold/unfold.md) — Subdomain rendering, theming, caching

### i18n
- [Translations](translations.md) — Locale switching and YAML translation files

### RSS
- [RSS Feeds](RSS/rss-feeds.md) — RSS generation and RSS-to-Nostr import

### Business
- [Architecture Overview](Business/architecture-overview.md)
- [Error Analytics](Business/error-analytics-system.md)
- [Subscriptions Spec](Business/Subscriptions/subscriptions.md) — ReWire relay and scope subscriptions
- [Submissions](Business/Submissions/submissions.md)

### Audience
- [Audience](Audience/audience.md)

### Deployment
- [Strfry Separation](Deployment/strfry-separation.md) — Running relay independently

### Reference (specs — do not modify)
- `NIP/` — Nostr Implementation Possibilities (spec mirror)
- `NKBIP/` — Nostr Knowledge Base Implementation Possibilities
