# Subscription Flows

This document provides user journey diagrams and quick start guides for the three main flows in the ReWire Relay subscription system.

## Overview

The subscription system supports two primary user journeys:

1. **Guest → Subscriber**: Access gated content without relay membership
2. **Author → Publisher**: Create scopes and manage subscriptions

---

## Flow 1: Guest → Subscriber (Scope Access)

### Purpose
Allow users to subscribe to gated content (scopes) and access it.

### User Journey Diagram

```
┌─────────────┐
│   GUEST     │ 
└──────┬──────┘
       │
       │ Browsing DN Client, sees gated content
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client shows:                                        │
│ • Scope preview (title, summary, cover)                 │
│ • Price (from Scope Definition)                             │
│ • What content unlocks                                  │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Clicks "Pay & Subscribe"
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client prompts for login/auth                        │
│ (to bind entitlement to pubkey)                         │
└──────┬──────────────────────────────────────────────────┘
       │
       │ User authenticates (NIP-07/NIP-46)
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client generates Lightning invoice                   │
│ • Payee: Scope owner                                    │
│ • Amount: subscription minimum (or more)                │
│ • Zap request embeds:                                   │
│   - subscriber pubkey                                   │
│   - Scope Definition coordinate                             │
└──────┬──────────────────────────────────────────────────┘
       │
       │ User pays invoice
       ▼
┌─────────────────────────────────────────────────────────┐
│ Lightning wallet/LNURL service processes payment        │
│ • Issues zap receipt event (kind 9735)                  │
│ • Receipt contains proof of payment                     │
└──────┬──────────────────────────────────────────────────┘
       │
       │ DN Client monitors for zap receipt
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client generates Subscribe Request          │
│ • Signed by subscriber                                │
│ • Tags:                                                 │
│   - ["a", "<Scope Definition_coordinate>"]                  │
│   - ["zap", "<receipt_event_id>"]                       │
│   - ["scope", "<scope_coordinate>"]                     │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Publishes to ReWire Relay
       ▼
┌─────────────────────────────────────────────────────────┐
│ ReWire Relay validates:                                 │
│ • Zap receipt is valid (kind 9735)                      │
│ • Receipt binds payer to subscriber pubkey              │
│ • Receipt references scope definition                   │
│ • Amount paid ≥ minimum from Scope Definition               │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Validation passes
       ▼
┌─────────────────────────────────────────────────────────┐
│ ReWire Relay issues Membership Grant           │
│ • Signed by relay                                       │
│ • Tags:                                                 │
│   - ["p", "<subscriber_pubkey>"]                        │
│   - ["a", "<Scope Definition_coordinate>"]                  │
│   - ["scope", "<scope_coordinate>"]                     │
│   - ["expiration", "<unix_timestamp>"]                  │
│   - ["zap", "<receipt_event_id>"] (audit trail)         │
└──────┬──────────────────────────────────────────────────┘
       │
       │ DN Client monitors for grant
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client receives grant event                          │
│ • Updates UI to show unlocked status and content        │
└─────────────────────────────────────────────────────────┘

```

---

## Flow 2: Author → Publisher (Scope Creation)

### Purpose
Allow authors to become publishers, enabling them to create subscription scopes (npub, publication, or article level) and manage their subscribers.

### User Journey Diagram

```
┌─────────────┐
│   AUTHOR    │ Wants to publish gated content
└──────┬──────┘
       │
       │ 
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client shows publisher onboarding:                   │
│ • "Become a publisher"                                  │
│ • Benefits: create scopes, publish gated content        │
│ • Price for publisher grant                             │
│ • Duration (e.g., 1 year)                               │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Clicks "Become Publisher"
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client generates Lightning invoice                   │
│ • Payee: Relay operator                                 │
│ • Amount: publisher grant fee                           │
│ • Zap request embeds:                                   │
│   - author pubkey                                       │
│   - publisher grant indicator                           │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Author pays invoice
       ▼
┌─────────────────────────────────────────────────────────┐
│ Payment processing                                      │
│ • Lightning payment completes                           │
│ • Relay backend verifies payment                        │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Payment verified
       ▼
┌─────────────────────────────────────────────────────────┐
│ ReWire Relay issues Publish Grant              │
│ • Authored by relay                                     │
│ • Tags:                                                 │
│   - ["p", "<publisher_pubkey>"]                         │
│   - ["expiration", "<unix_timestamp>"] (MUST)           │
│   - Payment audit trail                                 │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Relay updates authorization layer
       ▼
┌─────────────────────────────────────────────────────────┐
│ Publisher status activated                              │
│ • Pubkey can now publish Scope Definition                   │
│ • Pubkey can write to relay                             │
│ • Publisher tools unlocked in DN Client                 │
└──────┬──────────────────────────────────────────────────┘
       │
       │ DN Client detects publish grant
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client shows publisher dashboard:                    │
│ • "Create Gated Content" button enabled                 │
│ • "Create Scope" button enabled                         │
│ • Access to subscriber management tools                 │
│ • Analytics (if implemented)                            │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Publisher clicks "Create Scope"
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client shows scope creation form:                    │
│                                                         │
│ Scope Type:                                             │
│ • [ ] npub scope (all my content)                       │
│ • [ ] publication scope (specific publication)          │
│ • [ ] article scope (single article)                    │
│                                                         │
│ Pricing:                                                │
│ • Minimum subscription: [___] sats                      │
│ • Duration: [___] days (default: 30)                    │
│                                                         │
│ Preview (optional):                                     │
│ • Title: [_________________________]                    │
│ • Summary: [________________________]                   │
│ • Cover image: [_______] [Browse]                       │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Publisher fills form and clicks "Create"
       ▼
┌─────────────────────────────────────────────────────────┐
│ DN Client publishes Scope Definition                        │
│ • Authored by publisher (scope owner)                   │
│ • Tags:                                                 │
│   - ["scope", "<coordinate>"]                           │
│   - ["p", "<owner_pubkey>"] (for npub)                  │
│     OR ["a", "<kind>:<pubkey>:<dtag>"] (pub/article)    │
│   - ["subscription", "<min_sats>"]                      │
│   - ["expires_in", "<seconds>"]                         │
│   - ["title", "<title>"]                                │
│   - ["summary", "<summary>"]                            │
│   - ["image", "<url>"]                                  │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Publishes to ReWire Relay
       ▼
┌─────────────────────────────────────────────────────────┐
│ ReWire Relay validates Scope Definition:                    │
│ • Publisher has active Publish Grant           │
│ • Scope coordinate is well-formed                       │
│ • Author pubkey matches scope owner                     │
│ • Required tags present                                 │
└──────┬──────────────────────────────────────────────────┘
       │
       │ Validation passes
       ▼
┌─────────────────────────────────────────────────────────┐
│ Relay accepts scope definition                          │
│ • Available for discovery                               │
│ • Ready to accept subscribe requests                    │
└──────┬──────────────────────────────────────────────────┘
       │
       │ DN Client confirms creation
       ▼
┌─────────────────────────────────────────────────────────┐
│ PUBLISHER IS NOW ACTIVE                                 │
│ • Scope is live and discoverable                        │
│ • Can accept subscribers                                │
│ • Publisher can:                                        │
│   - View subscriber list (see Flow 1)                   │
│   - Export subscribers (NIP-51 lists, CSV)              │
│   - Issue whitelist grants (comped access)              │
│   - Update scope definition                             │
│   - Create additional scopes                            │
└─────────────────────────────────────────────────────────┘
```

