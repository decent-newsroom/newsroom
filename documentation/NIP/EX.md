NIP-EX
======

Publishable Feed Expressions
-----------------------------

`draft` `optional`

## Abstract

This NIP defines `kind:30880` for publishable feed expressions.

A feed expression is an ordered pipeline of built-in operations over one or more input lists. Expressions are intended to be shared, reused, forked, and inspected, so feed logic does not remain trapped inside one application backend or one dominant ranking model.

This NIP defines operations with tight, portable semantics:

* filters
* text matching
* property and tag sorting (numeric and alphabetical)
* slicing
* set operations

Regex is out of scope. Scoring is defined by [NIP-FX](FX.md) as a companion extension.

## Motivation

Nostr already transports content well. It does not yet transport feed logic in a compact and reusable way.

Today, most feed logic lives inside application code:

* hidden ranking rules
* hardcoded exclusions
* opaque post-processing
* unshareable editorial recipes

That creates a choke point. Once one feed becomes dominant, creators are pushed to optimize for that feed's hidden incentives.

This NIP aims to make feed construction:

* publishable
* inspectable
* forkable
* composable

The goal is not one universal feed. The goal is to make plural feed creation cheap.

## Conventions

The key words "MUST", "MUST NOT", "REQUIRED", "SHOULD", "SHOULD NOT", and "MAY" are to be interpreted as described in RFC 2119.

A runner is any implementation that evaluates `kind:30880` expressions.

A normalized item is the evaluation unit. It exposes normalized scalar properties and tag selectors.

A source is anything that resolves to a list of normalized items, such as:

* a NIP-51 list event
* a `kind:777` spell ([NIP-A7](A7.md))
* another `kind:30880` expression
* an implementation-defined materialized list

This NIP does not define source kinds.

## Event kind

| kind  | meaning                     |
| ----- | --------------------------- |
| 30880 | publishable feed expression |

## Core model

A `kind:30880` event is an ordered pipeline.

Tag order is significant.

An expression consists of one or more stages. A stage begins with an `op` tag and includes any following stage-local tags until the next `op` tag or the end of the event.

The built-in operations defined by this NIP are:

Filter ops:

* `all`
* `any`
* `none`

Sorter ops:

* `sort`
* `slice`

Set ops:

* `distinct`
* `union`
* `intersect`
* `difference`

These ops are defined by this NIP. They are not separate definition events.

## Event format

A `kind:30880` event MUST include exactly one `d` tag:

* `["d", "<identifier>"]` — as a parameterized replaceable event, the `d` tag determines the expression's addressable identity (`30880:<pubkey>:<d>`). Publishing a new event with the same `d` tag replaces the previous version.

A `kind:30880` event MAY include:

* `content`
* `["alt", "<text>"]`
* `["t", "<topic>"]`

These are descriptive.

A valid expression MUST contain at least one `op` tag.

## Stage-local tags

The following tags are stage-local and belong to the most recent preceding `op` tag:

| tag     | form                                    | meaning                                     |
| ------- | --------------------------------------- | ------------------------------------------- |
| `input` | `["input","e","<event-id>"]`            | stage input by event id                     |
| `input` | `["input","a","<kind>:<pubkey>:<d>"]`   | stage input by address                      |
| `match` | `["match","prop","<field>","<v1>",...]` | property equals any listed value            |
| `match` | `["match","tag","<selector>","<v1>",...]` | has tag `<selector>` with any listed value    |
| `not`   | `["not","prop","<field>","<v1>",...]`     | property equals none of the listed values     |
| `not`   | `["not","tag","<selector>","<v1>",...]`   | has no tag `<selector>` with any listed value |
| `cmp`   | `["cmp","prop","<field>","<cmp>","<value>"]`  | scalar comparison on a normalized property    |
| `cmp`   | `["cmp","tag","<selector>","<cmp>","<value>"]` | scalar comparison on a tag selector value     |
| `cmp`   | `["cmp","","<name>","<cmp>","<value>"]`        | scalar comparison on derived runner state     |
| `text`  | `["text","prop","<field>","<mode>","<value>"]`    | text comparison on a normalized text property |
| `text`  | `["text","tag","<selector>","<mode>","<value>"]`  | text comparison on a tag selector             |

## Input resolution

Each stage consumes one or more input lists.

For the first stage, explicit `input` tags are REQUIRED.

For later stages:

* if the stage has no explicit `input` tags, it implicitly consumes the previous stage result
* if the stage is `union`, `intersect`, or `difference` and also has explicit `input` tags, the previous stage result is prepended to the explicit inputs

Single-input ops (`all`, `any`, `none`, `sort`, `slice`, `distinct`, `score`) always consume exactly one input list — the previous stage result. They MUST NOT have explicit `input` tags. A runner MUST reject a single-input stage that contains explicit `input` tags with `invalid_argument`.

> **Rationale:** Allowing explicit `input` tags on single-input ops would silently discard the previous stage result. This would create confusing behavior where earlier pipeline stages appear to have no effect. If a different source is needed mid-pipeline, use a set operation (`union`, `intersect`, `difference`) to combine it with the current result, or start a separate expression and reference it as an input to the first stage.

A runner MUST fail with `arity_error` if an operation does not receive the required number of input lists.

A runner MUST fail with `cycle_error` if resolving input references creates a circular dependency.

## Normalized properties

This NIP defines the following normalized scalar properties. These are the fields a runner exposes for use in clauses and sort operations. They are derived from the top-level fields and tags of Nostr events.

### Scalar properties

| field        | type    | usage                                                                                                                                                                                                                                                              |
| ------------ | ------- |--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`         | string  | Canonical identity for non-replaceable events (`e:{id}`), deduplication key in set operations.                                                                                                                                                                     |
| `pubkey`     | string  | Authorship filtering (`match` against `$contacts`, `$me`) and exclusion (`not`).                                                                                                                                                          |
| `kind`       | integer | Content-type filtering (e.g., keep only `kind:30023` longform articles).                                                                                                                                                                        |
| `created_at` | integer | For replaceable events this is the last update time. For regular events it is the creation time. Used for recency filtering, freshness scoring (NIP-FX), and sort ordering. |


> `created_at` vs `published_at`:
> * `created_at` is a top-level event field (normalized property). It advances on every edit.
> * `published_at` is a tag (accessed via tag selectors). Per NIP-23 it represents the first publication time and is stable across edits. Many event kinds do not have it.
> * For article feeds, sort by `published_at` for publication order, `created_at` for "recently updated" order.

### Text properties

| field     | type   | usage                                                                                          |
| --------- | ------ | ---------------------------------------------------------------------------------------------- |
| `content` | string | Used in `text` clauses for substring/prefix matching. Absent if the event has no `.content`. |

A runner MAY support additional normalized text properties.

### Absence rules

A normalized property is either present with a value or absent. A runner MUST distinguish absent from the empty string. A runner MUST NOT synthesize missing property values unless this NIP explicitly defines that behavior.


## Tag selectors

Tag selectors address event tags by their first element.
Any single-letter or named tag selector MAY be used.

For selector evaluation, a runner collects the second element of each tag whose first element matches the selector string. Tags that do not contain a second element contribute no value. This rule applies identically to single-letter and named tags.

If no values are present for a selector, that selector is absent.

A runner MUST distinguish an absent selector from a selector whose value is the empty string.

## Missing properties and missing tags

This NIP defines explicit absence semantics.

For any clause evaluated against one item:

* an absent normalized property is not an error
* an absent tag selector is not an error
* absence is handled by the clause semantics below
* a runner MUST NOT coerce absence to `""`, `0`, `false`, or any other sentinel value

This is required for portability.

## Runtime variables

The following variables MAY appear in value positions of `match` and `not` clauses:

| variable     | resolves to                                                        |
| ------------ | ------------------------------------------------------------------ |
| `$me`        | executing user pubkey                                              |
| `$contacts`  | pubkeys from the executing user's contact list                     |
| `$interests` | `t` tag values from the executing user's interests list (kind:10015) |

A runner MUST resolve these variables before evaluation.

A variable that resolves to multiple values (e.g., `$contacts` expanding to a list of pubkeys, or `$interests` expanding to a list of tag values) MUST be treated as though each expanded value were an additional listed value in the clause. For example, `["match","prop","pubkey","$contacts"]` after resolution behaves identically to `["match","prop","pubkey","<pk1>","<pk2>",...]` with every contact pubkey listed explicitly.

If a required variable cannot be resolved, the runner MUST fail with `unresolved_variable`.

## Relative time values

For `cmp` on timestamp fields (`created_at` via `prop`, `published_at` via `tag`), the comparison value MAY be:

* a unix timestamp in decimal seconds
* `now`
* a relative shorthand such as `12h`, `7d`, `2w`, `3mo`, `1y`

| unit | meaning                  |
| ---- | ------------------------ |
| `s`  | seconds                  |
| `m`  | minutes                  |
| `h`  | hours                    |
| `d`  | days                     |
| `w`  | weeks                    |
| `mo` | months, fixed as 30 days |
| `y`  | years, fixed as 365 days |

Resolution rules:

* `now` resolves to the evaluation timestamp (unix seconds). A runner MUST use a single fixed `now` for the entire expression evaluation.
* A relative shorthand resolves to `now - duration`, where the duration is converted to seconds using the table above. For example, `7d` resolves to `now - 604800`.
* A bare integer is treated as a literal unix timestamp.

A runner MUST resolve all relative time values to absolute unix timestamps before evaluation.

## Filter stages

Filter stages consume exactly one input list and return one filtered list.

### Syntax

```json
["op","all"]
["op","any"]
["op","none"]
```

Each filter stage MUST contain at least one `match`, `not`, `cmp`, or `text` clause.

### Membership semantics

Inside one `match` clause, listed values are ORed.

Inside one `not` clause, listed values are ORed and then negated.

For each item:

* `all` keeps the item if every clause matches
* `any` keeps the item if at least one clause matches
* `none` keeps the item if no clause matches

The stage returns the kept items in original order.

### Clause semantics with missing values

A runner MUST evaluate missing values as follows:

#### `match` on normalized properties

`["match","prop","<field>","<v1>",...]`

* if the property is present, match if its value equals any listed value
* if the property is absent, the clause is false

#### `match` on tag selectors

`["match","tag","<selector>","<v1>",...]`

* if the selector is present, match if any selector value equals any listed value
* if the selector is absent, the clause is false

#### `not` on normalized properties

`["not","prop","<field>","<v1>",...]`

* if the property is present, match if its value equals none of the listed values
* if the property is absent, the clause is true

#### `not` on tag selectors

`["not","tag","<selector>","<v1>",...]`

* if the selector is present, match if none of its values equals any listed value
* if the selector is absent, the clause is true

#### `cmp`

`["cmp","prop","<field>","<cmp>","<value>"]`

* if the property is present, apply the comparator
* if the property is absent, the clause is false

`["cmp","tag","<selector>","<cmp>","<value>"]`

* if the tag selector is present, apply the comparator to the first selector value
* if the tag selector is absent, the clause is false

`["cmp","","<name>","<cmp>","<value>"]`

* addresses derived runner state (e.g., `score` from [NIP-FX](FX.md))
* the empty namespace `""` distinguishes derived values from native event properties and tags
* if the named value is present, apply the comparator numerically
* if the named value is absent, the clause is false
* a runner MUST reject unknown names with `invalid_argument`

#### `text`

`["text","prop","<field>","<mode>","<value>"]`

* if the text property is present, apply the text mode
* if the text property is absent, the clause is false

`["text","tag","<selector>","<mode>","<value>"]`

* if the tag selector is present, apply the text mode; match if any selector value satisfies the mode
* if the tag selector is absent, the clause is false

These rules are item-level evaluation rules. They do not make the expression invalid.

### Comparators

| cmp   | meaning               |
| ----- | --------------------- |
| `eq`  | equal                 |
| `neq` | not equal             |
| `gt`  | greater than          |
| `gte` | greater than or equal |
| `lt`  | less than             |
| `lte` | less than or equal    |

A runner MUST reject `cmp` on properties or tag selectors it does not recognize as scalar-comparable.

### Property comparison typing

All property values and comparison literals are strings in the Nostr event and tag format. For `match` and `not` clauses, comparison is always **string equality** — the property value is compared to the literal string as-is. For example, `["match","prop","kind","30023"]` matches if the stringified kind equals `"30023"`.

Numeric properties (`kind`, `created_at`) are stringified in canonical decimal form without leading zeros when used in `match` or `not` clauses. A runner MUST use this canonical form for comparison.

For `cmp` on `prop`, numeric fields (`kind`, `created_at`) are compared **numerically**. Both the property value and the comparison literal are parsed as decimal numbers before applying the comparator. If either side fails to parse as a number, the clause is false.

For `cmp` on `prop` with string fields (`id`, `pubkey`), comparison is **lexicographic**.

For `cmp` on `tag`, the first selector value and the comparison literal are both parsed as decimal numbers. If either side fails to parse, the clause is false. This enables numeric comparison on tag values such as `published_at` timestamps.

For `cmp` with the empty namespace `""`, comparison is always **numeric**. This namespace addresses derived runner state such as `score` ([NIP-FX](FX.md)), which is not part of the original event. If the named value or the comparison literal fails to parse as a number, the clause is false.

## Text clauses

A text clause applies text matching to a string value addressed by namespace.

* `["text","prop","<field>","<mode>","<value>"]` — matches against a normalized text property (currently only `content`)
* `["text","tag","<selector>","<mode>","<value>"]` — matches against a tag selector (`title`, `summary`, `alt`, or any other tag selector that yields string values)

For tag selectors that yield multiple values, the clause matches if any value satisfies the text mode.

### Syntax

```json
["text","prop","<field>","<mode>","<value>"]
["text","tag","<selector>","<mode>","<value>"]
```

This NIP defines the following text modes:

| mode          | meaning                             |
| ------------- | ----------------------------------- |
| `contains-ci` | case-insensitive substring match    |
| `eq-ci`       | case-insensitive exact string match |
| `prefix-ci`   | case-insensitive prefix match       |

### Text semantics

For `contains-ci`:

* match if the field value contains the search value as a substring, case-insensitively
* this includes partial-word matches such as `test` matching `testing`

For `eq-ci`:

* match if the field value equals the search value, case-insensitively

For `prefix-ci`:

* match if the field value begins with the search value, case-insensitively

If the target field is absent, the clause is false.

A runner SHOULD normalize case using Unicode-aware case folding when available. A runner MAY use a simpler case-insensitive normalization if it documents that behavior.

Regex is out of scope for this NIP.

## Sorter stages

### Sort

A `sort` stage consumes exactly one input list and returns one ordered list.

### Syntax

```json
["op","sort","prop","<field>","<direction>"]
["op","sort","tag","<selector>","<direction>"]
["op","sort","","<name>","<direction>"]
```

The namespace prefix follows the same convention as `cmp` and `match`:

* `prop` — a normalized property
* `tag` — a tag selector
* `""` — derived runner state (e.g., `score` from [NIP-FX](FX.md))

Allowed sort targets:

* `prop` — `created_at`, `kind`
* `tag` — any tag selector (e.g., `published_at`, `title`, `d`, `alt`, or any other tag)
* `""` — `score` (available only when a preceding scoring stage has produced it)

`id` and `pubkey` are not valid sort targets. Sorting by hex identifiers produces no meaningful order. A runner MUST reject a sort stage that references `id` or `pubkey` with `invalid_argument`.

A runner MAY support additional `prop` sort targets.

### Sort mode

An optional sixth element specifies the comparison mode:

```json
["op","sort","tag","title","asc","alpha"]
["op","sort","tag","published_at","desc","num"]
```

| mode    | meaning                                   |
| ------- | ----------------------------------------- |
| `num`   | numeric comparison (parse as decimal)     |
| `alpha` | lexicographic comparison, case-insensitive |

Default mode when omitted:

* `prop` — `num`
* `tag` — `num`
* `""` — `num`

When mode is `num`, both values are parsed as decimal numbers before comparison. If either side fails to parse, the item with the non-parseable value is ordered after items with parseable values.

When mode is `alpha`, values are compared lexicographically using Unicode-aware case folding. A runner MAY use simpler case-insensitive normalization if it documents that behavior.

### Sort ordering

A runner MUST order items by the following strict procedure:

1. Compare by the requested sort field in the requested direction using the resolved mode. Items with a present value are ordered before items with an absent value.
2. If the requested sort field ties (including when both values are absent), compare by `created_at` descending
3. If all compared keys tie, preserve original relative order.

A runner MUST NOT invent a missing sort value.

### Slice

A `slice` stage consumes exactly one input list and returns one subrange.

### Syntax

```json
["op","slice","<offset>","<limit>"]
```

Semantics:

* start at `offset`
* return at most `limit` items
* preserve current order

`offset` and `limit` MUST be non-negative integers.

## Set-operation stages

Set-operation stages consume one or more input lists and return one list.

### Canonical item identity

For `distinct`, `union`, `intersect`, and `difference`, item identity is:

* `a:<kind>:<pubkey>:<d>` when the item has an addressable identity
* otherwise `e:<id>`

### Distinct

### Syntax

```json
["op","distinct"]
```

Consumes exactly one input list. Traverses the list left to right, keeping the first occurrence of each canonical identity. Output order follows the first-seen order.

If keeping latest is required, sort first by `created_at` descending, then apply `distinct` to keep the most recent item for each identity. Do the same in other set-ops where a source may contain multiple versions of the same item and the latest version is desired.

### Union

### Syntax

```json
["op","union"]
```

Consumes two or more input lists. Traverses all inputs in order (first input left to right, then second input left to right, etc.), keeping the first occurrence of each canonical identity. Output order follows the first-seen order across all inputs.

### Intersect

### Syntax

```json
["op","intersect"]
```

Consumes two or more input lists. Traverses the first input left to right, keeping the first occurrence of each canonical identity that also appears in every subsequent input. Output order follows the first input's order. Duplicates within the first input are not repeated.

### Difference

### Syntax

```json
["op","difference"]
```

Consumes two or more input lists and returns:

`input1 - (input2 union input3 union ...)`

Traverses the first input left to right, keeping the first occurrence of each canonical identity that does not appear in any subsequent input. Output order follows the first input's order. Duplicates within the first input are not repeated.

## Evaluation

A runner MUST evaluate an expression in tag order.

For each stage:

1. determine stage-local tags
2. resolve stage inputs
3. resolve runtime variables
4. resolve relative time values
5. apply the stage op
6. store the stage result as the current result

The final stage result is the expression result.

A runner MUST NOT guess missing semantics.

The final result of a `kind:30880` expression MUST be a list of events. Normalized properties are used only for evaluation.

## Validation

### Structural validity

An expression is structurally valid if and only if:

1. it contains exactly one `d` tag
2. it contains at least one `op` tag
3. the first stage has at least one explicit input
4. every stage has the required tags for its op
5. every filter stage has at least one clause
6. `sort` has exactly one namespace, one field, one direction, and at most one mode
7. `slice` has exactly one offset and one limit
8. set operations receive valid arity

A runner SHOULD validate structural validity before evaluation.

### Runtime resolution

During evaluation, the following runtime conditions may cause failure:

* referenced inputs that cannot be resolved → `unresolved_ref`
* runtime variables that cannot be resolved → `unresolved_variable`
* circular input references detected during resolution → `cycle_error`

These are runtime failures, not structural invalidity.

An item missing a property or tag used by a clause does not make the expression invalid. It is evaluated according to this NIP's absence semantics.

## Error vocabulary

A runner SHOULD use the following errors:

| error                 | meaning                                    |
| --------------------- | ------------------------------------------ |
| `unknown_op`          | operation name not recognized              |
| `invalid_argument`    | malformed tag or invalid value             |
| `arity_error`         | wrong number of input lists                |
| `type_error`          | wrong input or output shape                |
| `unresolved_variable` | `$me`, `$contacts`, or `$interests` could not be resolved |
| `unresolved_ref`      | referenced input could not be resolved     |
| `cycle_error`         | circular input reference detected          |
| `unsupported_feature` | recognized but not implemented             |

A runner MUST NOT use an error merely because a specific item lacks a property or tag referenced by a clause. Missing values are handled during evaluation.

## Examples

### Example 1: union two sources, keep recent items, newest first, top 20

```json
{
  "kind": 30880,
  "content": "Combine two sources, keep only recent items, sort newest first, and take the top 20.",
  "tags": [
    ["op", "union"],
    ["input", "e", "<source1>"],
    ["input", "e", "<source2>"],

    ["op", "all"],
    ["cmp", "tag", "published_at", "gte", "7d"],

    ["op", "sort", "tag", "published_at", "desc"],
    ["op", "slice", "0", "20"],

    ["alt", "Expression: Recent top 20 feed"]
  ]
}
```

### Example 2: contacts-only longform, excluding my own items

The spell fetches longform articles by the user's contacts in a single REQ:

```json
{
  "kind": 777,
  "id": "a1b2c3d4e5f6...",
  "content": "Longform articles from my contacts",
  "tags": [
    ["cmd", "REQ"],
    ["name", "Contacts longform"],
    ["alt", "Spell: longform articles from contacts"],
    ["k", "30023"],
    ["authors", "$contacts"],
    ["since", "30d"],
    ["limit", "200"],
    ["close-on-eose"]
  ]
}
```

The expression references the spell by its event ID as its sole input. Because the spell already constrains kind and authors, the expression only needs the exclusion predicate:

```json
{
  "kind": 30880,
  "content": "Longform items by my contacts, excluding my own.",
  "tags": [
    ["op", "all"],
    ["input", "e", "a1b2c3d4e5f6..."],
    ["not", "prop", "pubkey", "$me"],

    ["op", "sort", "tag", "published_at", "desc"],

    ["alt", "Expression: Contacts longform"]
  ]
}
```

### Example 3: articles matching my interests

The spell fetches recent longform articles from any author:

```json
{
  "kind": 777,
  "id": "b2c3d4e5f6a7...",
  "content": "Recent longform articles",
  "tags": [
    ["cmd", "REQ"],
    ["name", "Recent longform"],
    ["alt", "Spell: recent longform articles"],
    ["k", "30023"],
    ["since", "30d"],
    ["limit", "500"],
    ["close-on-eose"]
  ]
}
```

The expression references the spell and keeps only articles tagged with the user's interests:

```json
{
  "kind": 30880,
  "content": "Longform items tagged with any of my interests, newest first.",
  "tags": [
    ["op", "all"],
    ["input", "e", "b2c3d4e5f6a7..."],
    ["match", "tag", "t", "$interests"],

    ["op", "sort", "tag", "published_at", "desc"],
    ["op", "slice", "0", "50"],

    ["alt", "Expression: Articles matching my interests"]
  ]
}
```

### Example 4: subtract one source from another, then slice

```json
{
  "kind": 30880,
  "content": "Take source1, subtract source2, and take the first 50.",
  "tags": [
    ["op", "difference"],
    ["input", "e", "<source1>"],
    ["input", "e", "<source2>"],

    ["op", "slice", "0", "50"],

    ["alt", "Expression: Source difference top 50"]
  ]
}
```

### Example 5: reuse another expression as input, then further filter it

```json
{
  "kind": 30880,
  "content": "Start from another expression, then exclude spam-like tags.",
  "tags": [
    ["op", "all"],
    ["input", "a", "30880:<pubkey>:<d>"],
    ["not", "tag", "t", "spam", "promo", "ads"],

    ["alt", "Expression: Expression with spam excluded"]
  ]
}
```

### Example 6: title contains test in any case, including inside larger words

```json
{
  "kind": 30880,
  "content": "Keep items whose title contains test in any case.",
  "tags": [
    ["op", "all"],
    ["input", "e", "<source1>"],
    ["text", "tag", "title", "contains-ci", "test"],

    ["alt", "Expression: Title contains test"]
  ]
}
```

### Example 7: title starts with nostr, case-insensitively

```json
{
  "kind": 30880,
  "content": "Keep items whose title starts with nostr.",
  "tags": [
    ["op", "all"],
    ["input", "e", "<source1>"],
    ["text", "tag", "title", "prefix-ci", "nostr"],

    ["alt", "Expression: Title starts with nostr"]
  ]
}
```

### Example 8: union of a NIP-51 curated list and a kind:777 spell, then filter, sort, and slice

```json
{
  "kind": 30880,
  "content": "Union of my curated long-form list (NIP-51) and latest posts from follows (spell), keep only long-form, newest first, top 30",
  "tags": [
    ["op", "union"],
    ["input", "e", "<nip51-event-id>"],
    ["input", "e", "<kind777-spell-event-id>"],

    ["op", "all"],
    ["match", "prop", "kind", "30023"],

    ["op", "sort", "tag", "published_at", "desc"],
    ["op", "slice", "0", "30"],

    ["alt", "Expression: Curated long-form + latest from follows"]
  ]
}
```


## Rationale

This NIP keeps only the operations that remain crisp and portable:

* filters for membership
* text matching for content and tag values
* property and tag sort for ordering (numeric and alphabetical)
* slice for windowing
* set operations for list combination

Scoring is defined by the companion extension [NIP-FX](FX.md). The base NIP intentionally keeps scoring out of scope so that the core pipeline remains minimal and self-contained. NIP-FX adds a declarative scoring stage whose normalizers are pure math over input data and explicitly declared comparison sources — no external models, hidden provider-specific contracts, or non-declared fetches — preserving the portability and inspectability guarantees of this NIP while enabling graded multi-factor ranking when needed.

Regex is omitted because it would create engine-specific ambiguity in a layer that is supposed to stay inspectable and portable.

Spells ([NIP-A7](A7.md)) fit naturally into this model as sources: a spell can supply the candidate set, and a `kind:30880` expression can then perform deterministic post-query filtering, set combination, sorting, and slicing on top of that source.

Explicit absence semantics are included because missing tags and missing normalized properties are common in Nostr data. Portable feed behavior requires these cases to be handled predictably instead of by implementation-specific coercion.

## Security considerations

Runners MUST treat unresolved variables as hard errors.

Runners MUST NOT execute unknown operations with guessed fallback behavior.

Runners MUST detect circular input references and fail with `cycle_error`.

Runners SHOULD validate stage structure before evaluation.

Runners SHOULD document any additional `prop` sort targets or text fields they support.

