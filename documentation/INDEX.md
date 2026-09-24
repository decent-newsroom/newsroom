# Documentation

Start here for reader, writer, and developer guides. Detailed feature documentation lives in the subject folders below.

## Start here

- [Getting started](Guides/getting-started.md) — reading, signing in, writing, publishing, and common questions.
- [Nostr publishing](Guides/nostr-cms.md) — identities, event kinds, revisions, relay publication, and deletion.
- [Architecture](Guides/architecture.md) — application services, Composer packages, data flow, and workers.
- [Development guide](Guides/development.md) — code layout, working conventions, and validation.
- [Setup and operations](../docs/INDEX.md) — environment setup, deployment, and troubleshooting.
- [Implementation task guides](../skills/README.md) — repeatable development workflows.
- [Jev decision records](Processes/jev-decision-record.md) — public-context sharing scope and decision workflow.

## Package documentation

Unfold is maintained locally in [packages/unfold-bundle](../packages/unfold-bundle/). Other reusable bundles are installed through Composer; their documentation lives with their source repositories, not in nonexistent host package directories. Versions and repository sources are recorded in [composer.lock](../composer.lock).

- [decent-newsroom/bookshelf-bundle](https://github.com/decent-newsroom/bookshelf-bundle)
- [decent-newsroom/expression-bundle](https://github.com/decent-newsroom/expressions-bundle)
- [decent-newsroom/identity-bundle](https://github.com/decent-newsroom/identity-bundle)
- [decent-newsroom/nostr-client-bundle](https://github.com/decent-newsroom/nostr-client-bundle)
- [decent-newsroom/nostr-kernel-bundle](https://github.com/decent-newsroom/nostr-kernel-bundle)
- [decent-newsroom/relay-gateway-bundle](https://github.com/decent-newsroom/relay-gateway-bundle)
- [decent-newsroom/signing-bundle](https://github.com/decent-newsroom/signing-bundle)

## Feature reference

Each page owns one feature or operational topic. Pages explicitly labeled as proposals describe future work; they are not deployment instructions.

### Reading and discovery

- [Advanced Search](Reader/advanced-search.md)
- [Article Actions Dropdown](Reader/article-actions-dropdown.md)
- [Article engagement turbo frames](Reader/article-engagement-turbo-frames.md)
- [ArticleFromCoordinate Component](Reader/article-from-coordinate.md)
- [Article Not Found Search Integration](Reader/article-not-found-search-integration.md)
- [Article Placeholder Implementation](Reader/article-placeholder.md)
- [Article Preview Cards](Reader/article-preview-cards.md)
- [Article revision hardening (v0.0.38)](Reader/article-revision-hardening.md)
- [Async Event Fetching](Reader/async-event-fetch.md)
- [Bookmarks Feature](Reader/bookmarks.md)
- [Bookshelf Android App Links](Reader/bookshelf-android-app.md)
- [Bookshelf Local Relay and API Fallback](Reader/bookshelf-local-fallback.md)
- [Bot Traffic Detection & Analytics Differentiation](Reader/bot-detection.md)
- [Chapter previews (kind 30041)](Reader/chapter-previews.md)
- [Web Preview for NIP-22 Comment External References](Reader/comment-web-preview.md)
- [Deferred Nostr Embeds](Reader/deferred-nostr-embeds.md)
- [Discover Page](Reader/discover-page.md)
- [Featured Unfold Sites](Reader/featured-unfold-sites.md)
- [Follow Packs Page](Reader/follow-packs-page.md)
- [Following Feed](Reader/follows-feature-implementation.md)
- [Highlights](Reader/highlights.md)
- [Home Feed for Logged-In Users](Reader/home-feed-logged-in.md)
- [JSON-LD Structured Data Implementation](Reader/json-ld-structured-data.md)
- [Magazine Wizard: Attach Existing Lists Feature](Reader/magazine-existing-list-attachment.md)
- [Math / LaTeX Rendering](Reader/math-rendering.md)
- [Nostr Address (naddr) Search Recognition](Reader/naddr-search.md)
- [Profile Editorial Tab: Featured Collections](Reader/profile-editorial-featured-content.md)
- [Publication Index Routing and Reading List Kinds](Reader/publication-index-routing-and-reading-list-kinds.md)
- [Article List Browser Cache](Reader/pwa-article-list-caching.md)
- [Quality of Life Improvements: Prev/Next Navigation & Back to Top](Reader/qol-prev-next-back-to-top.md)
- [Reading Nook](Reader/reading-nook.md)
- [Related Articles Suggestions](Reader/related-articles.md)
- [Relay Feed](Reader/relay-feed.md)
- [Single Event Page Details](Reader/single-event-page.md)
- [Sitemap](Reader/sitemap.md)
- [User Mute List Filtering (NIP-51 kind 10000)](Reader/user-mute-list-filtering.md)

### Writing and editing

- [AsciiDoc Support](Editor/asciidoc-support.md)
- [Article Editor](Editor/editor.md)
- [Extra Metadata for Articles: Sources and Media Attachments](Editor/extra-metadata-sources-imeta.md)
- [Links, Mentions and Embeds in Articles](Editor/links-mentions-embeds.md)
- [Math in the Quill Editor: Implementation Plan](Editor/math-editor-plan.md)
- [Quill view font picker](Editor/quill-view-font-picker.md)
- [Slug Preservation on Publish Feature](Editor/slug-preservation-on-publish.md)
- [Post-Publish Note Suggestion](Editor/suggest-note-after-publish.md)
- [Translation Helper](Editor/translation-helper.md)
- [Writing Articles with Math](Editor/writing-math.md)

### Publishing and profiles

- [Active Indexing removal plan](Newsroom/active-indexing-removal-plan.md)
- [Article Broadcast Feature](Newsroom/article-broadcast-feature.md)
- [Author Profiles & User Metadata](Newsroom/author-profile.md)
- [Automatic Role Promotion](Newsroom/automatic-role-promotion.md)
- [Blog Journey — Creator Onboarding Wizard](Newsroom/blog-journey.md)
- [Comments](Newsroom/comments.md)
- [Contribution Widget](Newsroom/contribution-widget.md)
- [Custom homepage](Newsroom/custom-homepage.md)
- [Editorial Design System](Newsroom/editorial-design-system.md)
- [Essayist Landing Page](Newsroom/essayist-landing-page.md)
- [Featured Reading Lists in the Author Overview](Newsroom/featured-reading-lists-overview.md)
- [User Roles: Featured Writers & Muted Users](Newsroom/featured-writers.md)
- [Follow Pack Setup](Newsroom/follow-pack-setup.md)
- [Hidden Magazines (Admin)](Newsroom/hidden-magazines.md)
- [Interests Editor Feature](Newsroom/interests-editor.md)
- [Kind 30040 Event Ingestion Setup](Newsroom/kind-30040-ingestion.md)
- [Magazine front page loading behavior](Newsroom/magazine-front-page-loading.md)
- [Magazine Manifest API](Newsroom/magazine-manifest.md)
- [Magazine Wizard Upgrade](Newsroom/magazine-wizard.md)
- [My Content Publishing Inventory](Newsroom/my-content-page.md)
- [Navigation Layouts](Newsroom/navigation-layouts-implementation.md)
- [NIP-70 Protected Articles](Newsroom/nip70-protected-articles.md)
- [Publication chapter loading](Newsroom/publication-chapter-loading.md)
- [Reading Lists](Newsroom/reading-lists.md)
- [Search](Newsroom/search.md)
- [User Settings Page](Newsroom/settings-page.md)
- [Translations (i18n)](Newsroom/translations.md)

### Media

- [Media Discovery](Media/media-discovery.md)

### Essayist

- [Essayist zap claims](Essayist/essayist-zap-claims.md)
- [Essayist](Essayist/essayist.md)
- [Essayist-exclusive articles](Essayist/exclusive-articles.md)
- [Essayist Membership Gateway](Essayist/gateway.md)
- [Essayist home](Essayist/home.md)
- [Essayist Member Relay Pool](Essayist/member-relay-pool.md)

### Subscriptions, payments, and analytics

- [Publishing services and access models](Business/architecture-overview.md)
- [Footer and pricing](Business/footer-and-pricing.md)
- [Payment Targets (NIP-A3 — kind 10133, `payto:` Tips)](Business/payment-targets.md)
- [Publication Subdomain Subscriptions](Business/publication-subdomain.md)
- [Vanity links (NIP-05)](Business/vanity-names.md)
- [Visitor Analytics](Business/visitor-analytics.md)

### Lightning

- [Zaps (Lightning Payments)](LN/zaps.md)

### Hosted publications

- [Comments in UnfoldBundle](Unfold/comments.md)
- [Unfold discovery documents](Unfold/discovery-documents.md)
- [Unfold publication footer](Unfold/publication-footer.md)
- [Local Development Unfold Sites](Unfold/local-development-sites.md)
- [Unfold Publication Administration](Unfold/publication-admin.md)
- [Unfold Setup And Local Settings](Unfold/site-creation-signing.md)
- [Unfold (Hosted Magazines)](Unfold/unfold.md)

### APIs

- [Books API](API/books-api.md)

### MCP

- [MCP server (articles and books)](MCP/mcp-server.md)

### Search

- [Search Documentation](Search/INDEX.md)
- [Content Search API](Search/content-search-api.md)

### Elasticsearch

- [Elasticsearch](Elasticsearch/elasticsearch.md)

### Nostr integration

- [NIP-XX — Tabular Data (CSV)](Nostr/NIP-tabular.md)
- [Fetch Optimization](Nostr/fetch-optimization-implementation.md)
- [Candidate Nostr Event Kinds](Nostr/missing-kinds-review.md)
- [NIP-09: Event Deletion Requests](Nostr/nip-09-deletion.md)
- [NIP-05 Badge Component](Nostr/nip05-badge-component.md)
- [NIP-46 Remote Signing](Nostr/nip46-remote-signing.md)
- [NIP-11 & NIP-66 Relay Discovery](Nostr/relay-discovery.md)
- [Relay filter stats](Nostr/relay-filter-stats.md)
- [Relay Gateway Service](Nostr/relay-gateway-service.md)
- [Relay Infrastructure Proposals](Nostr/relay-improvements.md)
- [Relay Pool Lazy Loading](Nostr/relay-pool-lazy-loading.md)
- [Replaceable Event Cleanup](Nostr/replaceable-event-cleanup.md)
- [User-Visible Relay Activity Log](Nostr/user-relay-activity-log.md)
- [User-Scoped Direct Relay AUTH](Nostr/user-scoped-direct-relay-auth.md)

### Publication graph

- [Graph Layer](Graph/graph-layer.md)

### Workers and background processing

- [Profile Projection System](Processes/profile-projection.md)
- [Workers and Messenger Queues](Processes/workers.md)

### Scheduled jobs

- [Cron Processing](Cron/cron-processing.md)

### Redis

- [Redis View Store](Redis/redis-views.md)
- [Relay Selection When a Session Expires](Redis/session-expiry-fallback.md)

### Relay operations

- [Backfilling articles from the local relay](Strfry/article-backfill.md)
- [Relay Administration](Strfry/relay-admin.md)
- [Relay Pool Management](Strfry/relay-pool.md)
- [Strfry Relay Setup](Strfry/relay-setup.md)
- [Strfry Storage Maintenance](Strfry/storage-maintenance.md)

### Administration

- [Bulk Event Deletion](Admin/deletion-optimization.md)
- [Production Deployment Validation](Admin/deployment-validation.md)
- [Docker Development Reference](Admin/docker-quick-reference.md)
- [Magazine Index Deletion](Admin/magazine-index-deletion.md)
- [Mercure Administration](Admin/mercure-admin.md)

### Runtime and deployment

- [Runtime Resource Configuration](Deployment/runtime-tuning.md)

### Notification proposal

- [Notification subscriptions: retained schema and proposal](Notifications/notifications-center.md)

### RSS

- [RSS Feeds](RSS/rss-feeds.md)

## Protocol references

- [Nostr Implementation Possibilities](NIP/README.md) — local protocol mirror.
- [Nostr Key Binding Implementation Possibilities](NKBIP/) — Markdown and AsciiDoc reference sources.

Protocol mirrors are separate from application feature documentation; a protocol reference does not imply that every feature is implemented.

## Maintaining these docs

Keep guides and feature docs in this tree, with one canonical page per feature. Update existing pages when behavior changes and merge useful details before removing superseded plans or completion reports. Keep independent future proposals clearly labeled. Update this index and incoming links when moving a page; Git history retains removed material. Setup documentation remains in `docs/` and package-owned documentation stays with its package.
