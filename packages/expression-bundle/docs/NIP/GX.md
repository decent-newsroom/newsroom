# NIP-GX

## Graph Traversal Operators for Publishable Feed Expressions

`draft` `optional`

## Abstract

This NIP extends [NIP-EX](EX.md) with four graph traversal operators for `kind:30880` publishable feed expressions:

* `parent`
* `child`
* `ancestor`
* `descendant`

Traversal operators move from one event set to another through an explicit, kind-specific graph. They do not filter — they replace the stage input with the set of related events obtained by following parent/child references.

## Motivation

NIP-EX expressions filter, sort, score, and combine sets, but many useful queries require moving from one event set to another through an explicit graph.

Examples:

* return the root posts of threads where my follows commented
* return the direct replies to a note
* return the full list of descendants in a publication
* return a list of publications that include an article
* collect all comments under the articles in a curated list

These are traversal operations, not filters.

## Conventions

The keywords "MUST", "MUST NOT", "REQUIRED", "SHOULD", "SHOULD NOT", and "MAY" are to be interpreted as described in RFC 2119.

A runner is any implementation that evaluates `kind:30880` expressions, as defined by [NIP-EX](EX.md).

## Relationship to NIP-EX

This NIP does not define a new event kind. Traversal operators are additional `op` stages usable inside `kind:30880` expressions and reuse all infrastructure defined by NIP-EX:

* stage-local tag semantics
* input resolution rules
* canonical item identity for dedupe
* error vocabulary

Traversal stages are **single-input** operations. They follow the same rule as other NIP-EX single-input ops (`all`, `any`, `none`, `sort`, `slice`, `distinct`, `score`):

* if the stage is not the first stage, it implicitly consumes the previous stage result and MUST NOT contain explicit `input` tags
* if the stage is the first stage, it MUST contain one or more explicit `input` tags

A runner MUST reject a non-first traversal stage that contains explicit `input` tags with `invalid_argument`.

## Scope

This NIP defines traversal semantics for the following event kinds:

| Kind         | Standard   | Graph                                                    |
| ------------ | ---------- | -------------------------------------------------------- |
| `kind:1`     | NIP-10     | threaded replies via marked `e` tags                     |
| `kind:1111`  | NIP-22     | scoped comments via `e`/`a` parent and root tags         |
| `kind:30040` | NKBIP-01   | publication indices including items via `a` tags         |

A runner MAY support additional kinds with defined parent/child semantics. Any additional kind MUST be documented by the runner.

An input event whose kind has no defined traversal semantics emits no traversal result. This is not an error.

## Operators

Traversal operators are encoded as `op` tags.

### One-hop upward

```json
["op", "parent"]
```

Returns the direct parents of each input event, if defined.

Each input contributes zero or more events. For kinds whose graph is a tree (`kind:1`, `kind:1111`) the result is at most one event per input. For inclusion-based graphs (`kind:30040`), an item may have multiple parents (multiple indices that include it).

### One-hop downward

```json
["op", "child"]
```

Returns the direct children of each input event.

Each input contributes zero or more events.

### Transitive upward

```json
["op", "ancestor"]
```

Returns all ancestors of each input event, nearest first.

Optional root-only form:

```json
["op", "ancestor", "root"]
```

Returns only the farthest ancestor. If the input has no parent, the input itself is emitted (it is its own root). This makes `ancestor root` total: every input yields exactly one event.

### Transitive downward

```json
["op", "descendant"]
```

Returns all descendants of each input event.

Optional leaf-only form:

```json
["op", "descendant", "leaves"]
```

Returns only terminal descendants — events that have no children under this NIP.

## Core semantics

Traversal is inferred from the kind of each input event.

For an input event `x`, the runner determines whether `x` has defined traversal semantics under this NIP or a runner extension.

* if yes, the runner applies the appropriate parent/child rules for that kind
* if no, `x` emits no traversal result

Traversal is evaluated independently for each input item.

Results are concatenated in input order.

Duplicates are preserved unless an explicit dedupe stage (`distinct`) follows. Dedupe uses the canonical item identity defined by NIP-EX (`a:<kind>:<pubkey>:<d>` for addressable events, otherwise `e:<id>`).

Traversal operators return original full events. Any derived runner state (e.g., `score` produced by a preceding [NIP-FX](FX.md) scoring stage) does not propagate across traversal, because the output events are not the same events as the input.

## Default traversable kinds

### `kind:1`

`kind:1` traversal follows NIP-10 threading semantics.

**Upward traversal (preferred, marked form):**

* if a marked `"reply"` `e` tag is present, that referenced event is the direct parent
* otherwise, if a marked `"root"` `e` tag is present, that referenced event is the direct parent
* otherwise, no parent is defined

**Upward traversal (deprecated positional form):**

A runner MAY additionally support the deprecated positional `e` tag convention described in NIP-10 (last `e` tag is the direct parent, first is the root). A runner that supports it MUST document this behavior. A runner that does not support it MUST treat unmarked `e` tags as non-participating for traversal.

**Downward traversal:**

* a child of event `x` is any `kind:1` event whose direct parent, resolved per the rules above, is `x`

`kind:1111` comments are **not** children of `kind:1` events under this NIP. To combine them, evaluate traversal on each kind separately and combine results with `union`, or use a `kind:777` spell that fetches both before invoking traversal.

### `kind:1111`

`kind:1111` traversal follows NIP-22 comment semantics.

**Upward traversal:**

* the lowercase parent tag identifies the direct parent item (`e` for event-id parents, `a` for addressable parents, `i` for external identity parents)
* the uppercase root tag identifies the root scope
* only event-addressable parents (`e` and `a`) participate in event traversal; `i` parents produce no event

NIP-22 requires uppercase root-scope tags and lowercase parent tags, and allows those references to use event ids, event addresses, or external identities. If a comment is scoped only to an external identity, it has no event parent under this NIP.

**Downward traversal:**

* a child of event `x` is any `kind:1111` event whose direct parent, resolved per the rules above, is `x`

### `kind:30040`

`kind:30040` traversal follows the NKBIP-01 publication index semantics. A publication index lists its items as ordered `a` tags of the form `["a", "<kind>:<pubkey>:<d>", "<relay hint>?", "<event id>?"]`. Referenced items are typically `kind:30041` sections or nested `kind:30040` indices, but other kinds MAY appear (e.g. `30023`, `30818`).

The publication graph is inclusion-based: a publication index is the parent of each event it references. An item MAY have multiple parent indices (the same section or nested index can be included by more than one publication).

**Upward traversal:**

* the direct parents of event `y` are all `kind:30040` events whose `a` tags include `y`'s canonical addressable coordinate (`<kind>:<pubkey>:<d>`)
* `y` must itself be addressable (parameterized replaceable) for an `a`-tag match; non-addressable events are not reachable as children of a `kind:30040` index under this NIP
* optional `event id` positions inside the `a` tag are advisory hints and do not participate in parent matching — the coordinate is authoritative

As with downward traversal for `kind:1` / `kind:1111`, resolving parents of an arbitrary event requires a reverse index over publication indices; the result is best-effort (see Best-effort semantics).

**Downward traversal:**

* the direct children of a `kind:30040` event `x` are the events obtained by resolving each `a` tag in `x` to its current replaceable version (see Address resolution)
* children MUST be emitted in the order the `a` tags appear in `x`; this overrides the default `(created_at, id)` ordering specified in Ordering for `kind:30040` children
* an `a` tag that cannot be resolved contributes no event and is not an error

Nested publication indices traverse recursively. `descendant` on a `kind:30040` yields the flat, depth-first expansion of all referenced items, including transitive items of nested `kind:30040` children. `descendant leaves` yields only non-`kind:30040` referenced items (and any `kind:30040` items that themselves reference nothing resolvable).

## Address resolution

When a parent reference is an `a` tag (addressable), the runner MUST resolve it to the current replaceable version of that coordinate before continuing traversal. The "current" version is the latest event at `<kind>:<pubkey>:<d>` visible to the runner at evaluation time, consistent with NIP-EX's stable evaluation semantics.

If an `a` coordinate cannot be resolved to an event, that traversal step yields no event. This is not an error by itself; referenced-input resolution errors remain governed by NIP-EX (`unresolved_ref`).

If a parent reference resolves to a non-event target (e.g., an external identity under NIP-22 `i`), no event is emitted for that step.

## Ordering

For `parent`, the result is at most one event; ordering is trivial.

For `ancestor`, results MUST be emitted nearest first.

For `ancestor root`, only the farthest ancestor is emitted (or the input itself if it has no parent, per above).

For `child`, children MUST be emitted in ascending `(created_at, id)` order, with `id` compared lexicographically as a tie-breaker. An exception applies to `kind:30040`, whose children are emitted in `a`-tag declaration order (see the `kind:30040` traversal rules).

For `descendant`, traversal MUST be depth-first using the same child ordering rule (including the `kind:30040` tag-order override per step).

For `descendant leaves`, leaves are emitted in the order they are discovered under the depth-first traversal above.

Across multiple input items, per-item results are concatenated in input order.

## Cycles

During expansion from one input item, the runner MUST maintain a visited set keyed on canonical identity.

If a traversal step would revisit an already visited event in the same expansion, that branch stops. The runner MUST NOT fail with `cycle_error` on traversal cycles — cycles are terminated silently. `cycle_error` remains reserved by NIP-EX for circular input references between expressions.

## Best-effort semantics

Downward traversal (`child`, `descendant`) depends on what events the runner has access to. As with [NIP-FX](FX.md) `count` terms, the result is best-effort and depends on the runner's local cache and relay availability.

A runner MAY batch child/descendant lookups across all items in the pipeline for efficiency, and MAY cache traversal results for the duration of one expression evaluation.

## Validation

A traversal stage is structurally valid if and only if:

1. its `op` tag is one of `parent`, `child`, `ancestor`, or `descendant`
2. an `ancestor` stage has at most one modifier, and if present it is `root`
3. a `descendant` stage has at most one modifier, and if present it is `leaves`
4. `parent` and `child` stages have no modifier
5. it is the first stage and contains at least one `input` tag, OR it is not the first stage and contains no `input` tags

A runner MUST reject stages that violate these rules with `invalid_argument`.

## Error vocabulary

Traversal stages reuse the NIP-EX error vocabulary:

| error                 | condition                                                                                |
| --------------------- | ---------------------------------------------------------------------------------------- |
| `invalid_argument`    | malformed op tag, unknown modifier, or explicit `input` on a non-first traversal stage   |
| `arity_error`         | stage receives a wrong number of input lists                                             |
| `unresolved_ref`      | a first-stage `input` reference cannot be resolved                                       |
| `unsupported_feature` | the runner does not implement traversal, or does not implement a referenced additional kind |

An input event whose kind has no defined traversal semantics is NOT an error; it contributes no result (see Core semantics).

An `a` coordinate that resolves to no visible event during traversal is NOT an error; it contributes no result.

## Examples

### Example 1: Root events of threads where my follows commented

The spell fetches recent `kind:1` and `kind:1111` events authored by the user's contacts:

```json
{
  "kind": 777,
  "id": "c1d2e3f4a5b6...",
  "content": "Recent notes and comments from my contacts",
  "tags": [
    ["cmd", "REQ"],
    ["name", "Contacts notes and comments"],
    ["alt", "Spell: recent kind:1 and kind:1111 by contacts"],
    ["k", "1"],
    ["k", "1111"],
    ["authors", "$contacts"],
    ["since", "7d"],
    ["limit", "500"],
    ["close-on-eose"]
  ]
}
```

The expression walks each item to its thread root, dedupes, sorts by recency, and returns the top 50 root events:

```json
{
  "kind": 30880,
  "content": "Roots of threads where my contacts participated, newest first",
  "tags": [
    ["op", "ancestor", "root"],
    ["input", "e", "c1d2e3f4a5b6..."],

    ["op", "distinct"],
    ["op", "sort", "prop", "created_at", "desc"],
    ["op", "slice", "0", "50"],

    ["alt", "Expression: Thread roots from contacts activity"]
  ]
}
```

### Example 2: Direct replies to a note

```json
{
  "kind": 30880,
  "content": "Direct replies to a single note, oldest first",
  "tags": [
    ["op", "child"],
    ["input", "e", "<note-event-id>"],

    ["op", "sort", "prop", "created_at", "asc"],

    ["alt", "Expression: Direct replies"]
  ]
}
```

### Example 3: Leaf comments under a set of articles

Given a curated list of longform articles as input, fetch all terminal comments beneath them:

```json
{
  "kind": 30880,
  "content": "Leaf comments under a curated article list",
  "tags": [
    ["op", "descendant", "leaves"],
    ["input", "e", "<nip51-curation-event-id>"],

    ["op", "distinct"],
    ["op", "sort", "prop", "created_at", "desc"],
    ["op", "slice", "0", "100"],

    ["alt", "Expression: Leaf comments under curated articles"]
  ]
}
```

### Example 4: Combine kind-specific child traversals via union

Because traversal is kind-specific, fetching both `kind:1` replies and `kind:1111` comments for the same root requires two expressions combined at the set layer. For example, define `<replies-expr>` that performs `child` over `kind:1` inputs and `<comments-expr>` that performs `child` over `kind:1111` inputs (each using an appropriate spell), then union them:

```json
{
  "kind": 30880,
  "content": "Union of direct replies and direct comments to a root",
  "tags": [
    ["op", "union"],
    ["input", "a", "30880:<pubkey>:<replies-expr-d>"],
    ["input", "a", "30880:<pubkey>:<comments-expr-d>"],

    ["op", "sort", "prop", "created_at", "desc"],
    ["op", "slice", "0", "50"]
  ]
}
```

### Example 5: Flatten a publication into its sections

Expand a `kind:30040` publication index into the ordered list of its leaf sections (skipping nested indices):

```json
{
  "kind": 30880,
  "content": "All leaf sections of a publication, in reading order",
  "tags": [
    ["op", "descendant", "leaves"],
    ["input", "a", "30040:<pubkey>:<publication-d>"],

    ["alt", "Expression: Publication sections in reading order"]
  ]
}
```

### Example 6: Publications that include a given article

Given an addressable article (e.g. `kind:30023` or `kind:30041`) as input, return the `kind:30040` indices that reference it:

```json
{
  "kind": 30880,
  "content": "Publications that include this article",
  "tags": [
    ["op", "parent"],
    ["input", "a", "30023:<author-pubkey>:<article-d>"],

    ["op", "all"],
    ["match", "prop", "kind", "30040"],

    ["op", "distinct"],
    ["op", "sort", "prop", "created_at", "desc"],

    ["alt", "Expression: Publications including an article"]
  ]
}
```

The `match` stage is defensive: `parent` already returns only `kind:30040` events for inclusion-based traversal, but filtering by kind guards against runner extensions that might define other inclusion-parent kinds.

## Rationale

Traversal is a distinct operation from filtering. A filter reduces a set; a traversal replaces it with a related set. Keeping traversal as its own operator category preserves the inspectability and composability of NIP-EX: each stage in an expression has a single, crisp semantic.

Traversal semantics are kind-specific by design. Threading rules differ between `kind:1` (NIP-10), `kind:1111` (NIP-22), and other event types. Rather than inventing a generic "reply" abstraction that would hide these differences, this NIP binds traversal to the authoritative standards for each kind and allows runners to extend the set of traversable kinds explicitly.

The `ancestor root` and `descendant leaves` modifiers cover the two most common traversal patterns — "what started this conversation?" and "what are the terminal outcomes?" — without requiring a separate operator for each.

## Security considerations

Runners SHOULD bound traversal depth to prevent pathological expansion from malicious or buggy event chains. The mechanism is implementation-defined but SHOULD be documented.

Runners MUST detect traversal cycles per-item (see Cycles) and terminate affected branches without failing the expression.

Runners MUST NOT perform fetches not implied by the traversal semantics defined here. Any fetches required to resolve children or descendants are declared by the operator itself.

All other security considerations from NIP-EX apply unchanged.

