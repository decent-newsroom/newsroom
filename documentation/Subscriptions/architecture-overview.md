# Architecture Overview: ReWire Relay + DN Client

This document provides a comprehensive view of the ReWire subscription system architecture, showing the separation between relay services and client services, what's gated vs. open, and the complete feature set.

## System Architecture Understanding

### Core Concept

The system has **two distinct layers**:

1. **ReWire Relay** - The Nostr relay infrastructure (paid access)
2. **DN Client** - The web application (provides public and premium features)

These work together but serve different purposes and have different monetization models.

---

## Architecture Diagram: Access Levels & Payment Flows

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              GUEST / PUBLIC                                 │
│                          (No Authentication Required)                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  DN Client Public Features (FREE):                                          │
│  ✓ Browse all OPEN content                                                  │
│  ✓ View article previews/teasers for GATED content                          │
│  ✓ Search, discovery, topics, journals                                      │
│  ✓ Read content from public relays                                          │
│  ✓ See author profiles                                                      │
│  ✓ View publication landing pages                                           │
│                                                                             │
│  ReWire Relay Access (RESTRICTED):                                          │
│  ✗ Cannot connect to ReWire Relay directly                                  │
│  ✗ Cannot read from ReWire Relay (NIP-42 AUTH required)                     │
│  ✗ Cannot write to ReWire Relay                                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ User wants to subscribe to gated scope
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          SUBSCRIBER (Scope Access)                          │
│                        (Paid to Scope Owner via Zap)                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Payment Flow:                                                              │
│  User → Lightning Zap → Scope Owner (author/publisher)                      │
│         Amount: Set by scope owner                                          │
│         Purpose: Access to specific scope's gated content                   │
│                                                                             │
│  What User Gets:                                                            │
│  ✓ Read GATED content in subscribed scope (via DN Client)                   │
│  ✓ Membership Grant issued by ReWire Relay                         │
│  ✓ Content unlocked in DN Client interface                                  │
│                                                                             │
│                                                                             │
│  Relay Role (Automated):                                                    │
│  • Validates zap receipt                                                    │
│  • Mints Membership Grant                                          │
│  • Does NOT receive payment (goes to scope owner)                           │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ Author wants to publish
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    PUBLISHER (Content Creation Rights)                      │
│                         (Paid to Relay Operator via Zap)                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Payment Flow:                                                              │
│  Author → Lightning Zap → Relay Operator                                    │
│          Amount: Set by relay operator (publisher grant fee)                │
│          Purpose: Right to publish and create scopes on ReWire Relay        │
│                                                                             │
│  What Author Gets:                                                          │
│  ✓ Write access to ReWire Relay                                             │
│  ✓ Can publish Scope Definition (create subscription scopes)                    │
│  ✓ Can publish articles (kind 30023, 30024) to ReWire Relay                 │
│  ✓ Can publish media (kind 20, 21) to ReWire Relay                         │
│  ✓ Can publish lists/magazines (kind 30040) to ReWire Relay                 │
│  ✓ Publish Grant issued                                            │
│  ✓ Access to publisher dashboard in DN Client                               │
│                                                                             │
│  Scope Creation Capabilities:                                               │
│  ✓ Create npub scope (all author's content)                                 │
│  ✓ Create publication scope (specific publication)                          │
│  ✓ Create article scope (single article)                                    │
│  ✓ Set subscription price (sats)                                            │
│  ✓ Set preview content (title, summary, image)                              │
│                                                                             │
│  Subscriber Management:                                                     │
│  ✓ View subscriber list (from Membership Grant events)             │
│  ✓ Export subscribers (NIP-51 list, CSV)                                    │
│  ✓ Issue whitelist grants (comped access)                                   │
│  ✓ See paid vs. comped breakdown                                            │
│                                                                             │
│  Revenue Model:                                                             │
│  • Receives zap payments directly from subscribers                          │
│  • Relay does not take a cut of subscription revenue                        │
│  • Author keeps 100% of subscription income (minus Lightning fees)          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## DN Client Extra Services (Author Tools)

In addition to the relay-based subscription system, **DN Client offers premium services** for authors who want enhanced features, even if they publish all content as open access.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                DN Client Premium Author Services (OPTIONAL)                 │
│                    (Paid to DN Client Operator Separately)                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Target Audience:                                                           │
│  • Authors who publish OPEN content (no gating)                             │
│  • Authors who want professional publishing tools                           │
│  • Authors who want better discoverability                                  │
│                                                                             │
│  Payment Flow:                                                              │
│  Author → Lightning/Stripe → DN Client Operator                             │
│          Separate from relay publisher grant                                │
│          Tiered pricing model (Basic/Pro/Enterprise)                        │
│                                                                             │
│  Service 1: Versioning                                                      │
│  ───────────────────────────────────────────────────────                    │
│  ✓ Auto-save                                                                │
│  ✓ Version history (full edit history)                                      │
│                                                                             │
│  Service 2: Vanity URLs                                                     │
│  ──────────────────────────────────────────────────────────                 │
│  ✓ Custom short links for profile and articles:                             │
│    • decentnewsroom.com/your-handle/article-slug                            │                                                             │
│                                                                             │
│  Service 3: Advanced Analytics Dashboard                                    │
│  ────────────────────────────────────────────────────────────               │
│  ✓ Readership metrics:                                                      │
│    • Page views, unique readers                                             │ 
│  ✓ Engagement analytics:                                                    │
│    • Highlights, comments, zaps per article                                 │
│    • Most popular articles                                                  │
│    • Audience growth over time                                              │
│                                                                             │
│  Service 4: Custom Subdomains for Publications                              │
│  ──────────────────────────────────────────────────                         │
│  ✓ Dedicated subdomain:                                                     │
│    • your-publication.decentnewsroom.com                                    │
│    • Full branding control                                                  │
│  ✓ Custom homepage:                                                         │
│    • Publication masthead                                                   │
│    • Featured articles                                                      │
│    • About page, contributors page                                          │
│  ✓ Theme customization:                                                     │
│    • Colors, fonts, logo                                                    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```


## Content Access Matrix

### What's OPEN vs. GATED

```
┌────────────────────┬──────────────────────┬───────────────────────────────┐
│ Content Type       │ Access Level         │ Requirements                  │
├────────────────────┼──────────────────────┼───────────────────────────────┤
│ Open Articles      │ PUBLIC               │ None (guest can read)         │
│ (no scope def)     │                      │                               │
├────────────────────┼──────────────────────┼───────────────────────────────┤
│ Gated Article      │ SCOPE ENTITLEMENT    │ Pay scope owner               │
│ Preview/Teaser     │ PUBLIC               │ + Membership Grant   │
│ Full Article       │ GATED                │                               │
├────────────────────┼──────────────────────┼───────────────────────────────┤
│ Publication        │ SCOPE ENTITLEMENT    │ Pay scope owner               │
│ (all articles)     │ GATED                │ + Membership Grant   │
├────────────────────┼──────────────────────┼───────────────────────────────┤
│ Author npub        │ SCOPE ENTITLEMENT    │ Pay scope owner               │
│ (all content)      │ GATED                │ + Membership Grant   │
├────────────────────┼──────────────────────┼───────────────────────────────┤
│ Publishing Rights  │ PUBLISHER GRANT      │ Pay relay operator            │
│ (write to relay)   │ GATED                │ + Publish Grant      │
├────────────────────┼──────────────────────┼───────────────────────────────┤
│ DN Client          │ PREMIUM SERVICE      │ Pay DN Client operator        │
│ Extras             │ (optional upgrade)   │ (separate from relay/scopes)  │
└────────────────────┴──────────────────────┴───────────────────────────────┘
```



### Three separate revenue streams

   - Scope owners earn from subscribers (content monetization)
   - Relay operator earns from publisher grants (infrastructure)
   - DN Client operator earns from premium services (value-added features)

---

## User Journey Examples

### Example 1: Free Reader (Guest)
- Browses DN Client
- Reads open content
- Sees previews of gated content
- **Pays nothing**

### Example 2: Subscriber
- Wants to read one author's premium articles
- Zaps author subscription fee
- Gets Membership Grant
- **Pays: fee to author**

### Example 3: Open Access Author
- Publishes everything open (no gating)
- Wants analytics and vanity URLs
- Subscribes to DN Client "Creator" tier
- **Pays: publisher grant + DN Client "Creator" tier**
- **Earns: tips**

### Example 4: Premium Publisher
- Runs a publication with 3 authors
- Wants gated content + custom subdomain
- Multiple revenue streams:
  - Subscription income from readers
  - Merchandise, courses, etc.
- **Pays: 3x publisher grant + 1x DN Client "Publication" tier**
- **Earns: subscription revenue from readers (100%)**

---

## Summary: Three Independent Systems

### 1. ReWire Relay (Infrastructure Layer)
- **Purpose**: Nostr relay with access control
- **Revenue**: Publisher grants to relay operator
- **Services**: Relay infrastructure, entitlement minting
- **Target**: Authors

### 2. Scope Subscriptions (Content Monetization)
- **Purpose**: Pay creators for content access
- **Revenue**: 100% to content creators
- **Services**: Entitlement validation, subscriber management
- **Target**: Readers who want gated content access

### 3. DN Client Premium (Value-Added Services)
- **Purpose**: Professional publishing tools
- **Revenue**: To DN Client operator
- **Services**: Analytics, vanity URLs, subdomains
- **Target**: Professional authors and publications

All three can work independently or together, giving maximum flexibility for users and creators.

