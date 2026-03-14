# Submissions - Draft Review Workflow

**Use Case**: Authors submit drafts to magazine editors for review using scoped access control. Serves as a submission workflow and a review audit trail.

---

## Overview

The submissions workflow reuses the **scope subscription system** to grant temporary read access to drafts. An author with a publisher grant can:

1. Create a scope definition for their draft (or set of draft assets)
2. Grant whitelist access to specific editors
3. Revoke access after editorial decision

This is a specialized use of the existing subscription system.

---

## Workflow

### 0. Author Writes Draft

Author writes an article and saves it (kind 30023 or 30024):

```json
{
  "kind": 30024,
  "pubkey": "<author_pubkey>",
  "tags": [
    ["d", "lightning-article-draft"],
    ["title", "Understanding the Lightning Network"],
    ["a", "30040:<publication_pubkey>:bitcoin-magazine"], // Target publication
    ["t", "lightning"]
  ],
  "content": "# Understanding the Lightning Network\n\n[Draft content here]..."
}
```

### 1. Author Creates Draft Scope

Author publishes a `DN_SCOPE_DEF` (kind 38110) defining the submission:

```json
{
  "kind": 38110,
  "pubkey": "<author_pubkey>",
  "tags": [
    ["d", "submission-2026-01-lightning-article"],
    ["scope", "30024:<author_pubkey>:lightning-article-draft"],
    ["a", "30024:<author_pubkey>:lightning-article-draft"],
    ["subscription", "0"],
    ["expires_in", "2592000"],
    ["title", "Lightning Article - Draft Submission"],
    ["summary", "Draft article submitted to Bitcoin Magazine"],
    ["t", "draft"]
  ],
  "content": "Draft submission for review by Bitcoin Magazine editors"
}
```

**Key tags**:
- `["scope", "30024:<author_pubkey>:lightning-article-draft"]` To ensure gated access,
- `["subscription", "0"]` - Free (no payment for editorial access)
- `["expires_in", "2592000"]` - 30 days (reasonable review period)

### 2. Author Grants Editor Access

Author publishes `DN_SCOPE_WHITELIST_GRANT` (kind 8103) for each editor:

```json
{
  "kind": 8103,
  "pubkey": "<author_pubkey>",
  "tags": [
    ["a", "38110:<author_pubkey>:submission-2026-01-lightning-article"],
    ["scope", "30024:<author_pubkey>:lightning-article-draft"],
    ["p", "<editor_pubkey>"],
    ["expiration", "1738713600"]
  ],
  "content": "Granting editorial access for draft review"
}
```

**Key tags**:
- `["p", "<editor_pubkey>"]` - Specific editor
- `["expiration", "..."]` - Time-limited access

### 3. Editor Reads Draft

Editor authenticates and queries for their assigned drafts:

```javascript
// Query whitelist grants for editor
{
  "kinds": [8103],
  "#p": ["<editor_pubkey>"],
  "#role": ["editor"]
}
```

Editor requests draft with NIP-42 AUTH:
- Relay checks whitelist grant exists and not expired
- Relay serves draft content if authorized

### 4. Author Revokes Access

After editorial decision (accept/reject), author revokes:

```json
{
  "kind": 8113,
  "pubkey": "<author_pubkey>",
  "tags": [
    ["e", "<whitelist_grant_event_id>"],
    ["scope", "30023:<author_pubkey>:lightning-article-draft"],
    ["p", "<editor_pubkey>"],
    ["reason", "Editorial decision completed"]
  ],
  "content": "Revoking editorial access"
}
```

### 5. (Optional) Author Publishes Final Article

If the article is accepted, it can be included in the publication directly. 
The author might want to republish the final event without the scope or reissue a new scope for the published version.

It is up to the publication's workflow how they handle accepted articles and if they allow payable articles within their 
publication, or the scope must be removed by the author.


---

## Client Implementation Possibilities

### For Authors (DN Client)

**Submit Draft UI:**

```
┌─────────────────────────────────────┐
│ Submit Draft for Review             │
├─────────────────────────────────────┤
│ Draft: "Lightning Network Guide"   │
│                                     │
│ Submit to:                          │
│ [▼ Bitcoin Magazine         ]      │
│                                     │
│ Editors will have access for:      │
│ [▼ 30 days                  ]      │
│                                     │
│ [Submit Draft]                      │
└─────────────────────────────────────┘
```

**Action**: Creates scope def + whitelist grants for magazine editors

**Manage Submissions UI:**

```
┌─────────────────────────────────────┐
│ Your Submissions                    │
├─────────────────────────────────────┤
│ Lightning Guide                     │
│ → Bitcoin Magazine                  │
│ → Submitted: 2026-01-01             │
│ → Status: Under review              │
│ → Editors: Jane, Bob                │
│   [Revoke Access] [View Draft]      │
├─────────────────────────────────────┤
│ Nostr Protocol Deep Dive            │
│ → Tech Weekly                       │
│ → Submitted: 2025-12-15             │
│ → Status: Accepted ✓                │
│   [Revoke Access] [Publish Final]   │
└─────────────────────────────────────┘
```

### For Editors (Magazine Client)

**Submissions Inbox:**

```
┌─────────────────────────────────────┐
│ Submissions Inbox                   │
├─────────────────────────────────────┤
│ [3] New Submissions                 │
│                                     │
│ Lightning Guide                     │
│ by Alice (@alice)                   │
│ Submitted: 2 days ago               │
│ Expires: 28 days                    │
│ [Read Draft] [Accept] [Reject]      │
├─────────────────────────────────────┤
│ Bitcoin Mining Economics            │
│ by Bob (@bob)                       │
│ Submitted: 5 days ago               │
│ [Read Draft] [Accept] [Reject]      │
└─────────────────────────────────────┘
```

---

## Advantages

### 1. **Reuses Existing Infrastructure**

- No new event kinds
- Same authorization logic as subscriptions
- Relay already validates whitelist grants

### 2. **Flexible Access Control**

- Grant access to specific editors
- Time-limited (expiration)
- Revocable (explicit revoke event)
- Multiple editors per submission

### 3. **Privacy**

- Draft content gated behind whitelist
- Only editors with grants can read
- Author controls who has access

### 4. **Audit Trail**

- All grants are events (permanent record)
- Revocations are events (know when access removed)
- Can track submission history

### 5. **Compatible with Subscriptions**

- Same scope can be used for both:
  - Whitelist grants for editorial review
  - Paid subscriptions for published version
- Smooth transition: Draft → Review → Publish → Monetize

---

## Extended Use Cases

Grant preview access to:

* multiple editors
* peer reviewers
* co-authors
* fact-checkers
* legal reviewers
* media asset contributors
* VIP early access readers

---

## Differences from Regular Subscriptions

| Aspect | Subscription | Submission |
|--------|-------------|-----------|
| **Purpose** | Reader pays for content | Editor reviews draft |
| **Payment** | Required (or free tier) | Always free (editorial access) |
| **Duration** | Long (annual) | Short (review period) |
| **Access Type** | Read published content | Read unpublished draft |
| **Grant Type** | Whitelist or paid | Whitelist only |
| **Scope Def** | Published article/publication | Draft article |


---

## Security Considerations

### 1. Draft Leakage

**Risk**: Editor shares whitelist grant or draft content.

**Mitigation**:
- Grants are bound to specific editor pubkey (can't be transferred)
- Relay validates AUTH matches granted pubkey
- Time-limited access (auto-expires)

---

## Example: Full Workflow

**1. Alice (author) creates draft:**

```javascript
const draft = {
  kind: 30024,
  tags: [
    ["d", "lightning-guide-draft"],
    ["title", "Lightning Network Complete Guide"],
    ["t", "guide"],
  ],
  content: "# Lightning Guide\n\n[draft content]..."
};
```

**2. Alice submits to Bitcoin Magazine:**

Scope definition acts as a submission record.

```javascript
// Create scope def
const scopeDef = {
  kind: 38110,
  tags: [
    ["d", "submission-lightning-guide"],
    ["scope", "30024:<alice>:lightning-guide-draft"],
    ["a", "30024:<alice>:lightning-guide-draft"], // Target draft pointer
    ["A", "30040:<publication_pubkey>:bitcoin-magazine"] // Target publication as root
    ["subscription", "0"], // Or omit
    ["expires_in", "2592000"], // 30 days
    ["title", "Lightning Guide - Draft Submission"],
    ["summary", "Draft article for Bitcoin Magazine"],
  ]
};

// Grant Jane (editor) access
const grant = {
    kind: 8103,
    tags: [
      ["a", "38110:<alice>:submission-lightning-guide"],
      ["scope", "30024:<alice>:lightning-guide-draft"],
      ["p", "<jane_pubkey>"],
      ["expiration", (Math.floor(Date.now() / 1000) + 2592000).toString()] // 30 days
    ]
}
```

**3. Jane reviews, Alice optionally revokes access (or lets it run out), and republishes final version:**


```javascript
// Alice publishes revoke
const revoke = {
  kind: 8113,
  tags: [
    ["e", "<whitelist_grant_id>"],
    ["scope", "30023:<alice>:lightning-guide-draft"],
    ["reason", "Accepted for publication"]
  ]
};

// Alice publishes final version
const published = {
  kind: 30023,
  tags: [
    ["d", "lightning-guide"],
    ["title", "Lightning Network Complete Guide"],
    ["published_at", Math.floor(Date.now() / 1000).toString()], 
  ],
  content: "[final content]..."
};
```

---

## Summary

**Submissions workflow uses existing subscription system:**

- ✅ `DN_SCOPE_DEF` (38110) - Define draft scope
- ✅ `DN_SCOPE_WHITELIST_GRANT` (8103) - Grant editor access
- ✅ `DN_SCOPE_WHITELIST_REVOKE` (8113) - Revoke after decision
- ✅ No new event kinds needed
- ✅ Reuses existing relay authorization logic
- ✅ Time-limited, revocable access control
- ✅ Full audit trail

**Key distinction**: Use `["A", "<target-publication-coordinate>"]` tag in scope definition to distinguish submissions from subscriptions.

This approach reuses existing infrastructure, and provides the necessary access control for editorial workflows.

