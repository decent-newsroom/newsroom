# NIP-FX: Scoring Extension for Publishable Feed Expressions

`draft` `optional`

## Abstract

This NIP extends NIP-EX (Publishable Feed Expressions, `kind:30880`) with a declarative scoring stage.

A scoring stage computes a temporary derived numeric property, `score`, for each item using a weighted sum of simple inspectable terms. This enables graded multi-factor ranking while preserving the core design goals of NIP-EX: inspectability, forkability, portability, and deterministic evaluation under explicit runtime rules.

Scores are derived runner state. They do not mutate input events, and the output of an expression remains the original matching events.

This NIP defines only local scoring over data available during expression evaluation. Scoring terms may inspect item properties and tags, reference explicitly declared comparison sources (runtime variables and list/set event references), or count external events of declared kinds that reference each item. External models, machine-learning systems, hidden provider-specific ranking, and non-declared fetches are out of scope.

## Motivation

NIP-EX intentionally keeps scoring out of scope in order to keep the base expression format minimal and portable. That is useful, but many practical feeds require more than binary filtering or direct field sorting.

Examples include combining:

* freshness
* social proximity
* presence of selected tags
* engagement signals (reactions, comments, reposts, highlights)
* explicit penalties

This extension adds a transparent scoring stage without introducing hidden ranking behavior. All scoring logic is published in the expression itself and can be inspected, forked, and modified.

## Extension to `kind:30880`

A `kind:30880` event MAY contain a scoring stage.

A scoring stage computes a temporary derived numeric property named `score` for each item in the current pipeline. That property exists only for the remainder of expression evaluation and is overwritten by any subsequent scoring stage. It does not propagate when the expression result is used as an input to another expression — NIP-EX requires the final result to be original full events.

A scoring stage MUST appear before any clause or stage that references `score` by name. The `score` property may be referenced by:

* `sort` — to order items by score
* `cmp` — to apply numeric threshold filters on score (e.g., `["cmp","","score","gte","0.3"]`)

`score` MUST NOT be used with `match` or `not` clauses, because those use string equality semantics which are not well-defined for floating-point values.

A scoring stage does not reorder items by itself. Reordering remains explicit and is performed by a later stage such as `sort`.

## Scoring stage

A scoring stage begins with:

```json
["op", "score"]
```

It:

* consumes exactly one input list
* follows the same implicit-previous or explicit-input rules as other single-list operations in NIP-EX
* attaches a temporary derived numeric property `score` to each item
* leaves the underlying events unchanged

A scoring stage MUST contain at least one `term` tag.

All `term` tags following `["op", "score"]` belong to that scoring stage until the next `["op", ...]` tag or the end of the event.

## Term syntax

Each scoring term is encoded as a flat tag:

```json
["term", <source>, <selector>, <normalizer>, <weight>, <value1?>, <value2?>, ...]
```

Fields:

* `<source>`: one of `"prop"`, `"tag"`, or `""` (empty string)
* `<selector>`:

    * for `"prop"`: a normalized property defined by NIP-EX
    * for `"tag"`: a tag selector defined by NIP-EX
    * for `""`: derived runner state — currently only `score` (when produced by a preceding scoring stage)
* `<normalizer>`: one of the built-in normalizers defined in this NIP
* `<weight>`: a decimal value parseable as float, in the range `[-1000, 1000]`
* `<value1?>`, `<value2?>`, ...: zero or more additional string values required by some normalizers

All numeric arguments are encoded as strings in tags and parsed by the runner.

### Numeric parsing rules

When a normalizer requires a numeric value (`identity`, `log`, `recency`), the selected value is parsed as follows:

* decimal integers (e.g., `"42"`, `"-3"`) and decimal floats (e.g., `"3.14"`, `"-0.5"`) are valid
* negative values are valid where the normalizer formula handles them
* values that cannot be parsed as a decimal number are treated as absent and contribute `0`
* the weight field follows the same parsing rules and MUST additionally fall within `[-1000, 1000]`

Each term is evaluated independently. The final `score` is the sum of all weighted term outputs for that item.

Formally:

```text
score(item) = Σ_i ( weight_i × term_i(item) )
```

## Weight bounds

Weights MUST be in the range `[-1000, 1000]`. A runner MUST reject a term whose weight falls outside this range with `invalid_argument`.

The bounded range prevents float overflow from malicious expressions while remaining expressive. Because most normalizers output values in `[0, 1]`, the weight acts as a per-mille-style scaling factor. Stage-level scaling can be adjusted to achieve any desired result within the bounded interval.

## Selector rules

Term selectors address the `prop` and `tag` namespaces as defined by NIP-EX.

* `"prop"` selectors address normalized properties: `id`, `pubkey`, `kind`, `created_at`, `content`
* `"tag"` selectors address tag values: `t`, `p`, `e`, `a`, `d`, `title`, `summary`, `description`, `alt`, `image`, `published_at`, and any other tag selector supported by the runner
* `""` (empty string) selectors address derived runner state: currently only `score` (when produced by a preceding scoring stage)

`title`, `summary`, and `description` are tag selectors, not properties. A runner MUST NOT synthesize properties from tags.

For example:

* title text: `["term", "tag", "title", "contains-ci", ...]`
* content text: `["term", "prop", "content", "contains-ci", ...]`

## Built-in normalizers

The following built-in normalizers are defined.

### `identity`

Applies to numeric values selected from a property or tag.

Behavior before weighting:

* if the selected value is numeric, returns that numeric value
* otherwise returns `0`

Extra values after weight:

* none

Notes:

* `identity` passes the raw value through unchanged; the term weight acts as a scale factor
* its purpose is rescaling: multiplying a raw numeric value by the weight to bring it into a desired scale relative to other terms in the scoring stage
* it is intended for already-normalized numeric values or values where the weight provides the necessary scaling

### `recency`

Applies to timestamp values selected from a property or tag.

Behavior before weighting:

```text
1 / (1 + (hours_since(now) / half_life_hours))
```

clamped to `[0,1]`.

Extra values after weight:

* optional: one half-life value in relative time syntax (e.g., `"1h"`, `"24h"`, `"7d"`)
* if omitted, defaults to `1h`

The half-life is the age at which recency returns `0.5`. A small half-life (e.g., `"1h"`) creates steep decay suitable for real-time or short-form feeds. A large half-life (e.g., `"7d"`) creates gentle decay suitable for long-form article feeds where week-old content is still relevant.

The relative time syntax follows the same units defined by NIP-EX (`s`, `m`, `h`, `d`, `w`, `mo`, `y`). The value is converted to hours before use in the formula.

Notes:

* `now` is the runner evaluation timestamp
* all `recency` terms within one expression evaluation MUST use the same `now`
* the runner MUST NOT recompute `now` per item

If the selected timestamp is absent or invalid, the result is `0`.

### `log`

Applies to numeric values selected from a property or tag.

Behavior before weighting:

```text
sign(value) * log10(1 + abs(value))
```

The base-10 logarithm is used for readability: `log10(11) ≈ 1`, `log10(101) ≈ 2`, `log10(1001) ≈ 3`.

Extra values after weight:

* none

If the selected value is absent or non-numeric, the result is `0`.

### `in`

Applies to values selected from a property or tag.

Behavior before weighting:

* returns `1.0` if any selected value equals any comparison value
* otherwise returns `0.0`

Extra values after weight:

* one or more required comparison values

Comparison values MAY be provided as:

* literal values
* runtime variables defined by NIP-EX (values starting with `$`, e.g. `$me`, `$contacts`, `$interests`)
* references to Nostr list or set events whose contents expand into comparison values

Matching is exact unless NIP-EX defines a more specific comparison rule for the selected field.

If the selected field is absent, the result is `0`.

### `contains-ci`

Applies to string values selected from a property or tag.

Behavior before weighting:

* returns `1.0` if any selected string contains, case-insensitively, any listed value
* otherwise returns `0.0`

Extra values after weight:

* one or more required values

If the selected field is absent, or no selected value is a string, the result is `0`.

This normalizer uses the same matching semantics as the `contains-ci` text mode defined by NIP-EX, but returns a numeric value for use in scoring rather than a boolean filter result.

### `count`

Counts external events of specified kinds that reference the current item.

Unlike other normalizers, `count` does not read a value from the item's properties or tags. It queries for events that reference the item and returns a count. Because it operates on the item's identity rather than a selected field, source and selector MUST both be `""` (empty string).

Syntax:

```json
["term", "", "", "count", "<weight>", "<kind1>", "<kind2>", ...]
```

Behavior before weighting:

* determines the item's referenceable identities — event `id` (for `e`-tag lookups) and, for parameterized replaceable events (kinds 30000–39999), the `a` coordinate (`kind:pubkey:d`)
* queries for events whose kind matches any of the specified kinds and that reference the item via an `e` tag (by event ID) or an `a` tag (by coordinate)
* returns the total count as a numeric value

Extra values after weight:

* one or more required: event kinds to count (as string integers)

If no events of the specified kinds reference the item, the result is `0`.

Common kinds for engagement scoring:

| Kind | Type |
| ---- | ---- |
| 7 | Reactions (NIP-25) |
| 6 | Reposts (NIP-18) |
| 16 | Generic reposts (NIP-18) |
| 1111 | Comments (NIP-22) |
| 9802 | Highlights (NIP-84) |

Zaps (`kind:9735`) are explicitly out of scope. Zap verification requires validating embedded bolt11 invoices and matching sender/receiver pubkeys, which introduces complexity and external dependencies beyond what a deterministic scoring normalizer should handle.

Notes:

* a runner MAY batch count queries across all items in the pipeline for efficiency
* a runner MAY cache count results for the duration of one expression evaluation
* the count includes all matching events visible to the runner (local database and, where applicable, relay queries); the result is best-effort and depends on what events the runner has access to
* because raw counts can span orders of magnitude (0 to thousands+), pairing `count` with a small weight or using a two-stage pattern (count → carry forward via `log`) is recommended for balanced scoring

## Extra values summary

| Normalizer     | Extra values after weight             | Count |
| -------------- | ------------------------------------- | ----- |
| `identity`     | none                                  | 0     |
| `recency`      | optional half-life (`"24h"`, `"7d"`)  | 0–1   |
| `log`          | none                                  | 0     |
| `in`           | required comparison values            | 1+    |
| `contains-ci`  | required comparison values            | 1+    |
| `count`        | required event kinds to count         | 1+    |

## Comparison value expansion for `in`

For the `in` normalizer, values appearing after `<weight>` are interpreted as a comparison set.

The comparison set is formed by concatenating, in order, all values obtained from:

* literal arguments
* resolved runtime variables
* resolved list or set references

The result is the set of comparison values used by the membership test.

A runner MAY deduplicate comparison values before evaluation.

## Nostr list and set references

When used with `in`, a comparison value MAY be a reference to a Nostr event whose contents define a list or set of values.

### Syntax disambiguation

Each value after `<weight>` in an `in` term is interpreted as follows:

* **Runtime variable**: values starting with `$` (e.g., `$me`, `$contacts`, `$interests`) are resolved as defined by NIP-EX
* **Parameterized replaceable reference**: values in `<kind>:<pubkey>:<d>` format are resolved by fetching the referenced event and expanding its contents into comparison values
* **Literal**: all other values are treated as literal comparison strings

Replaceable events (e.g., `kind:3` contacts) cannot be reliably addressed in this format. For those, use a runtime variable (e.g., `$contacts`) or a `kind:777` spell as a source.


### Resolution scope

The allowed event kinds for references depend on the value domain being tested.

#### Pubkey domain

When the term selector addresses pubkeys (e.g., `["term", "prop", "pubkey", "in", ...]`), the following reference kinds are supported:

| Kind         | Event type    | Address format           | Values extracted      |
| ------------ | ------------- | ------------------------ | --------------------- |
| `kind:39089` | Follow pack   | `39089:<pubkey>:<d>`     | pubkeys from `p` tags |

`kind:39089` refers to follow packs — parameterized replaceable events that expose pubkeys via `p` tags. Runners MAY support additional event kinds that follow the same pattern (parameterized replaceable events exposing pubkeys via `p` tags) if those kinds are standardized elsewhere.

For contacts lists (`kind:3`), use the `$contacts` runtime variable instead of an event reference. For more complex contacts-based candidate generation, use a `kind:777` spell at the expression-source layer.

#### Tag domain

When the term selector addresses tags (e.g., `["term", "tag", "t", "in", ...]`), the following reference kinds are supported:

| Kind         | Event type     | Address format           | Values extracted         |
| ------------ | -------------- | ------------------------ | ------------------------ |
| `kind:30015` | Interest set   | `30015:<pubkey>:<d>`     | tag values from `t` tags |

For the user's own interests list (`kind:10015`), use the `$interests` runtime variable instead of an event reference. For more complex interest-based candidate generation, use a `kind:777` spell at the expression-source layer.

### Address format

Parameterized replaceable events (`kind:30015`, `kind:39089`) use the standard NIP-33 address format: `<kind>:<pubkey>:<d>`.

Replaceable events without a `d` tag (e.g., `kind:3`, `kind:10015`) cannot be reliably addressed in this format. For those, use runtime variables (`$contacts`, `$interests`). For more complex candidate generation from these event types, use a `kind:777` spell at the expression-source layer.

### Domain compatibility

A runner MUST NOT coerce one value domain into another. A pubkey list is valid for pubkey membership tests, but not for tag membership tests. A tag-interest list or set is valid for tag membership tests, but not for pubkey membership tests.

If a reference resolves to a kind not listed in the allowed table for the current domain, the runner MUST fail with `invalid_argument`.

### Resolution rules

If a referenced event resolves successfully but yields no compatible values, it contributes no comparison values.

If a referenced event cannot be resolved, is malformed, or is not supported by the runner, evaluation MUST fail cleanly.

A runner MAY cache resolved references for the duration of one expression evaluation.

## Value selection rules

Where NIP-EX permits a selector to yield multiple values, the following rules apply.

For `in`:

* the term returns `1.0` if any selected value matches any comparison value
* otherwise `0.0`

For `contains-ci`:

* the term returns `1.0` if any selected string contains, case-insensitively, any listed value
* otherwise `0.0`

For numeric normalizers (`identity`, `log`, `recency`):

* if the selector yields multiple values, the runner MUST use the first value in selector order
* if no value is present, the result is `0`

For `count`:

* the source and selector are not used for value extraction — the normalizer operates on the item's canonical identity
* the result is the total count of referencing events matching any of the specified kinds

## Absence semantics

This NIP extends the explicit absence model of NIP-EX.

For any term:

* absent normalized properties
* absent selected tags
* invalid numeric values for numeric normalizers
* non-string values for `contains-ci`
* zero referencing events for `count`

MUST be treated as absence and MUST contribute `0`.

A runner MUST NOT synthesize values, coerce absence to alternate sentinel values, or infer missing fields.

Absence inside a term is not an error. It only affects that term’s contribution.

## Runtime variables and relative time

Runtime variables defined by NIP-EX (`$me`, `$contacts`, `$interests`) work exactly as defined there.

When such variables are used in scoring terms, the runner MUST resolve them before term evaluation.

For the `in` normalizer, comparison values may also include references to Nostr list or set events, resolved according to the reference resolution rules defined in this NIP.

Unresolved runtime variables are a hard error.

Relative-time behavior used by `recency` follows NIP-EX evaluation rules, with the additional requirement that a single evaluation uses one fixed `now`.

## Integration with the pipeline

The scoring stage attaches a temporary derived numeric property `score` to each pipeline item.

Subsequent stages MAY reference `score` through:

* `sort` — to order items by score
* `cmp` — to filter items by numeric threshold on score (via `["cmp","","score",...]`)

`score` MUST NOT be used with `match` or `not` clauses. Those clauses use string equality semantics, which are not well-defined for floating-point values.

A common pattern is:

```json
["op", "score"],
["term", ...],
["term", ...],
["op", "sort", "", "score", "desc"]
```

The scoring stage does not itself sort or filter items.

## Multiple scoring stages

An expression MAY contain more than one `["op", "score"]` stage.

Each scoring stage **overwrites** the `score` property. The new `score` replaces the previous value entirely. There is no implicit accumulation.

Between two scoring stages, intermediate stages that reference `score` (via `cmp` in filter clauses, or `sort`) see the `score` from the most recent preceding scoring stage.

### Referencing a previous score

Within a scoring stage, `score` is a valid `""` (empty) selector. A term MAY reference the previous `score` value, for example:

```json
["term", "", "score", "identity", "1.0"]
```

This carries forward the previous score at full weight. Scaling it down (e.g., `"0.5"`) or applying `log` is also valid. This makes additive behavior opt-in and explicit: the intent is visible in the expression itself.

If no preceding scoring stage has produced a `score`, referencing it via `""` follows the standard absence rules: the value is absent and the term contributes `0`.

### Common multi-stage pattern

The most useful pattern is: score → threshold filter → re-score survivors:

1. First scoring stage: broad ranking (social proximity, freshness)
2. Filter stage: keep items above a quality floor (`cmp` on `score`)
3. Second scoring stage: refined ranking (topic relevance), optionally carrying forward the first score at reduced weight
4. Sort and slice on the final score

## Examples

### Example 1: Graded social + freshness + keyword feed

```json
["op", "score"],
["term", "prop", "pubkey", "in", "0.40", "$contacts", "$me"],
["term", "tag", "t", "in", "0.25", "nostr", "longform", "bitcoin"],
["term", "prop", "pubkey", "in", "-0.30", "deadbeef...", "spamkey..."],
["term", "tag", "title", "contains-ci", "0.15", "nostr"],
["term", "prop", "created_at", "recency", "0.35", "24h"],

["op", "sort", "", "score", "desc"],
["op", "slice", "0", "50"]
```

### Example 2: Boost authors from follows and a follow pack

```json
["op", "score"],
["term", "prop", "pubkey", "in", "0.50", "$contacts", "39089:<pubkey>:<d>"],
["term", "prop", "created_at", "recency", "0.50", "7d"],

["op", "sort", "", "score", "desc"]
```

### Example 3: Boost matching interests from the user's interests and an interest set

```json
["op", "score"],
["term", "tag", "t", "in", "0.40", "$interests", "30015:<pubkey>:<d>"],
["term", "prop", "created_at", "recency", "0.60", "7d"],

["op", "sort", "", "score", "desc"]
```

### Example 4: Content match plus freshness

```json
["op", "score"],
["term", "prop", "content", "contains-ci", "0.25", "nostr", "relay", "longform"],
["term", "prop", "created_at", "recency", "0.75", "3d"],

["op", "sort", "", "score", "desc"]
```

### Example 5: Threshold filtering with score

```json
["op", "score"],
["term", "prop", "pubkey", "in", "0.50", "$contacts"],
["term", "prop", "created_at", "recency", "0.50", "24h"],

["op", "all"],
["cmp", "", "score", "gte", "0.3"],

["op", "sort", "", "score", "desc"],
["op", "slice", "0", "30"]
```

### Example 6: Multi-stage scoring with carry-forward

```json
["op", "score"],
["term", "prop", "pubkey", "in", "0.60", "$contacts"],
["term", "prop", "created_at", "recency", "0.40", "24h"],

["op", "all"],
["cmp", "", "score", "gte", "0.2"],

["op", "score"],
["term", "", "score", "identity", "0.30"],
["term", "tag", "t", "in", "0.40", "$interests"],
["term", "tag", "title", "contains-ci", "0.30", "nostr", "bitcoin"],

["op", "sort", "", "score", "desc"],
["op", "slice", "0", "50"]
```

Stage 1 scores by social proximity and freshness. The filter keeps items above 0.2. Stage 2 re-scores survivors by topic relevance, carrying forward 30% of the first score. The final sort and slice operate on the second score.

### Example 7: Engagement-weighted feed

```json
["op", "score"],
["term", "", "", "count", "0.30", "7", "1111"],
["term", "", "", "count", "0.10", "6", "16"],
["term", "", "", "count", "0.10", "9802"],
["term", "prop", "created_at", "recency", "0.50", "7d"],

["op", "sort", "", "score", "desc"],
["op", "slice", "0", "50"]
```

Reactions and comments contribute 30%, reposts 10%, highlights 10%, and freshness 50%. Because raw counts can vary widely, small weights keep engagement from dominating.

### Example 8: Two-stage engagement with log compression

```json
["op", "score"],
["term", "", "", "count", "1.0", "7", "1111", "9802", "6", "16"],

["op", "score"],
["term", "", "score", "log", "0.40"],
["term", "prop", "pubkey", "in", "0.30", "$contacts"],
["term", "prop", "created_at", "recency", "0.30", "3d"],

["op", "sort", "", "score", "desc"],
["op", "slice", "0", "50"]
```

Stage 1 scores by total engagement count across all tracked kinds. Stage 2 carries forward that count through `log` compression (dampening outliers), combines it with social proximity and freshness, then sorts and slices.

## Validation and errors

A runner MUST reject a scoring stage with:

* `invalid_argument` if:

    * a `term` tag is malformed
    * a required field is missing
    * a normalizer name is unknown
    * a normalizer requiring extra values receives none
    * a weight value is outside the allowed range `[-1000, 1000]`
    * a referenced value source expands into a value domain incompatible with the selector being tested
    * a `count` term uses a source or selector other than `""` (empty string)
    * a `count` term specifies a kind value that is not a valid integer
* `arity_error` if the stage receives the wrong number of inputs
* `unresolved_variable` if a runtime variable used in a scoring term (`$me`, `$contacts`, `$interests`) cannot be resolved
* `unresolved_ref` if a referenced comparison source (list or set event) cannot be resolved, is malformed, or is not supported by the runner
* `unsupported_feature` if:

    * the runner does not implement scoring
    * the runner does not implement a referenced value-source type used by the expression

Missing properties, tags, or string fields inside otherwise valid terms do not produce errors. They contribute `0` according to the absence rules.

If a later stage consumes `score` but no earlier scoring stage has produced it, the runner MUST reject the expression with `invalid_argument`.

## Determinism

Scoring is deterministic with respect to:

* the input lists
* the fetched item data
* the expression tags
* the resolved runtime variables
* the evaluation timestamp `now`
* the set of referencing events visible to the runner (for `count`)

Two evaluations performed at different times MAY produce different scores when `recency` or `count` is used. This is expected behavior.

## Security considerations

* Runners MUST treat unresolved runtime variables as hard errors.
* Scoring is pure computation over input data and explicitly declared comparison sources. It introduces no new side effects beyond the reference-count queries required by `count` terms.
* All fetches required by scoring terms (runtime variables, list/set references, reference-count queries) are explicitly declared in the expression itself. A runner MUST NOT perform hidden or non-declared fetches during scoring.
* Malicious expressions can degrade ranking quality but should not cause crashes, leaks, or privilege escalation in a correct runner implementation.

## Observability and published score artifacts

This NIP does not standardize the publication of computed scores.

Computed scores are derived runner state used during expression evaluation. They are not part of the underlying events and MUST NOT be treated as event mutation.

A runner MAY keep intermediate scores internal.

A runner MAY also expose debugging or observability information through implementation-specific means, including temporary or ephemeral artifacts, but such behavior is out of scope for this NIP.

## Rationale

* Keeps scoring fully declarative and inspectable.
* Preserves the pipeline design of NIP-EX.
* Keeps filtering, scoring, and sorting as distinct operations.
* Re-uses normalized properties, tag selection, variables, and source resolution already defined in NIP-EX.
* Makes nuanced ranking publishable and forkable without requiring hidden backends or proprietary models.

NIP-EX intentionally kept scoring out of scope, citing concerns about provider contracts, normalization rules, and missing-data behavior. This extension addresses those concerns by restricting normalizers to pure math over input data and explicitly declared comparison sources. Every normalizer (`identity`, `recency`, `log`, `in`, `contains-ci`, `count`) is a deterministic function with no hidden dependencies, no model calls, and no provider-specific contracts. The `count` normalizer queries for referencing events, but the kinds to count are explicitly declared in the term itself — there are no hidden fetches. Comparison sources (list/set references) are explicitly declared in the expression itself. Missing-data behavior is handled by the same explicit absence semantics defined in NIP-EX: absent values contribute `0`, never synthesized sentinels. This keeps scoring portable across runners while enabling graded multi-factor ranking.

