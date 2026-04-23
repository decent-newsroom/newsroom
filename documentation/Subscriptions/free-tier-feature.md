# Free Tier Subscriptions (0 Sats)

**Date**: January 4, 2026  
**Feature**: Support for free subscriptions with analytics tracking

---

## Overview

Publishers can set scope subscription minimum to **0 sats**, enabling free subscriptions while still collecting subscriber information 
for analytics and engagement tracking. And users get a better sense of what is included before committing to paid subscriptions.

---

## Use Cases

### 1. Newsletter Sign-ups

Publishers want to know who's interested in their content without requiring payment:

```json
{
  "kind": 38110,
  "tags": [
    ["d", "newsletter-weekly"],
    ["scope", "<pubkey>"],
    ["subscription", "0"],
    ["title", "Weekly Newsletter"],
    ["summary", "Free weekly updates (opt-in for analytics)"]
  ]
}
```

**Benefits:**
- Build audience before charging
- Track engagement metrics
- Convert free subscribers to paid later

### 2. Content Previews

Offer free tier with limited content, premium tier with full access:

```json
// Free tier
{
  "kind": 38110,
  "tags": [
    ["d", "basic"],
    ["subscription", "0"],
    ["title", "Basic Access - Free"]
  ]
}

// Premium tier
{
  "kind": 38110,
  "tags": [
    ["d", "premium"],
    ["subscription", "50000"],
    ["title", "Premium Access - 50k sats"]
  ]
}
```

### 3. Community Building

Allow free access to community discussions, charge for exclusive content:

```json
{
  "kind": 38110,
  "tags": [
    ["d", "community"],
    ["subscription", "0"],
    ["title", "Community Hall - Free Join"]
  ]
}
```

---

## How It Works

### Publisher Flow

1. **Create scope definition** with `subscription: 0` (or omit tag)
2. **Publish scope** to ReWire Relay
3. **Receive subscribe requests** without payment requirements
4. **Get analytics** on who subscribed (subscriber count, demographics if available)

### Subscriber Flow

1. **See free scope** (0 sats displayed as "Free")
2. **Click subscribe** (no payment required)
3. **Auth and submit** Subscribe Request (no zap tag needed)
4. **Receive grant** immediately
5. **Access content** with entitlement

### Relay Flow

1. **Receive subscribe request** for scope with min_sats = 0
2. **Skip payment validation** (no zap receipt required)
3. **Issue grant immediately** with `["free", "true"]` tag
4. **Track subscription** in ledger for analytics

---

## Event Examples

### Scope Definition (Free Tier)

```json
{
  "kind": 38110,
  "pubkey": "<publisher_pubkey>",
  "tags": [
    ["d", "newsletter-free"],
    ["scope", "<pubkey>"],
    ["p", "<pubkey>"],
    ["subscription", "0"],
    ["expires_in", "31536000"],
    ["title", "Weekly Newsletter - Free"],
    ["summary", "Subscribe to receive weekly updates"]
  ],
  "content": "Free newsletter with weekly articles and updates"
}
```

### Subscribe Request (No Payment)

```json
{
  "kind": 8110,
  "pubkey": "<subscriber_pubkey>",
  "tags": [
    ["a", "38110:<publisher>:newsletter-free"],
    ["scope", "<publisher_pubkey>"],
    ["p", "<publisher_pubkey>"]
    // No ["zap"] tag needed for free tier
  ],
  "content": "Subscribing to free newsletter"
}
```

### Membership Grant (Free Tier)

```json
{
  "kind": 8102,
  "pubkey": "<relay_operator_pubkey>",
  "tags": [
    ["p", "<subscriber_pubkey>"],
    ["a", "38110:<publisher>:newsletter-free"],
    ["scope", "<publisher_pubkey>"],
    ["expiration", "1735863400"],
    ["e", "<subscribe_request_id>"],
    ["free", "true"],
    ["min_sats", "0"]
    // No ["zap"] tag
  ],
  "content": "Free tier subscription granted (analytics tracking)"
}
```

---

## Best Practices

### For Publishers

**1. Be Clear About Free Tier:**
```
Title: "Weekly Newsletter"
Summary: "Subscribe for free to get a weekly newsletter."
```

**2. Set Reasonable Defaults:**
```json
{
  "subscription": "0",
  "expires_in": "2628000" // 1 month
}
```

**3. Provide Value in Free Tier:**
- Don't make free tier useless
- Give enough value to build trust
- Use free tier as marketing for premium

**4. Clear Upgrade Path:**
- Show what premium adds
- Make upgrade seamless
- Offer limited-time promotions


---


## Comparison: Free vs Paid vs Comped

| Aspect | Free (0 sats) | Paid (>0 sats) | Comped (whitelist) |
|--------|---------------|----------------|-------------------|
| **Cost** | $0 | Set by publisher | $0 |
| **Payment** | None | Zap receipt required | Coupon/whitelist required |
| **Analytics** | Yes | Yes | Yes |
| **Grant tag** | `["free", "true"]` | `["zap", "<id>"]` | `["comped", "true"]` |
| **Use case** | Mass signup | Revenue | VIPs, press, gifts |
| **Barrier** | Low (just AUTH) | High (payment) | Medium (need coupon) |
| **Scale** | Unlimited | Unlimited | Limited by publisher |

---

## Future Enhancements

### Potential Features

1. **Tiered Free Limits:**
   - Time-limited free access (30 days)
   - Content-limited free tier (3 articles/month)

2. **Conversion Tracking:**
   - Track free → paid conversions
   - Optimize free tier value

3. **Engagement Scoring:**
   - Identify most engaged free users
   - Target for upgrade offers
   - Reward super-fans

4. **Analytics Dashboard:**
   - Real-time subscriber counts
   - Growth charts
   - Revenue projections

---

## Summary

**Free tier subscriptions (0 sats):**
- ✅ Lower barrier to entry
- ✅ Enable "freemium" models  
- ✅ Collect analytics without payment
- ✅ Build audience before monetizing

**Implementation:**
- Publishers: Set `["subscription", "0"]` or omit tag
- Subscribers: No payment needed, just AUTH
- Relay: Skip payment validation, issue grant with `["free", "true"]`

This enables a more flexible monetization strategy while maintaining the core subscription system architecture.

