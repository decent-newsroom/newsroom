# ExpressionBundle — NIP-EX + NIP-FX Expression Runner

> **Date:** 2026-04-13
> **Status:** Implementation Plan
> **NIPs:** [NIP-EX](../NIP/EX.md) (kind:30880), [NIP-FX](../NIP/FX.md) (scoring), [NIP-A7](../NIP/A7.md) (kind:777 spells)

## Overview

A self-contained Symfony bundle at `src/ExpressionBundle/` that parses `kind:30880` feed expression events into an internal pipeline, evaluates filter/sort/set/score operations against normalized Nostr events, resolves inputs from spells (`kind:777`), other expressions, NIP-51 lists, and event IDs via both the local database and relay fetches, and exposes an authenticated API endpoint for feed serving.

**Authentication requirement:** Only logged-in users can call the expression runner. The API endpoint requires authentication, and `RuntimeContext` always has a valid user pubkey. This guarantees that runtime variables (`$me`, `$contacts`, `$interests`) can always be resolved and simplifies caching to a per-user model.

Follows the same bundle pattern as `ChatBundle` and `UnfoldBundle`.

---

## Directory Structure

```
src/ExpressionBundle/
  ExpressionBundle.php                    # Bundle class
  DependencyInjection/
    ExpressionExtension.php               # Loads Resources/config/services.yaml
    Configuration.php                     # Config tree (cache TTL, max depth, max execution time)
  Resources/config/
    services.yaml                         # Autowire all bundle classes
    routes.yaml                           # API routes

  Model/
    NormalizedItem.php                    # Evaluation unit wrapping an Event
    Pipeline.php                          # Ordered list of Stage objects (the parsed AST)
    Stage.php                             # One pipeline stage: op name + stage-local tags
    Clause/
      ClauseInterface.php                 # Marker interface for all clause types
      MatchClause.php                     # ["match","prop"|"tag",selector,...values]
      NotClause.php                       # ["not","prop"|"tag",selector,...values]
      CmpClause.php                       # ["cmp","prop"|"tag"|"",selector,comparator,value]
      TextClause.php                      # ["text","prop"|"tag",selector,mode,value]
    Term.php                              # NIP-FX scoring term
    RuntimeContext.php                    # Holds $me, $contacts, $interests, now

  Parser/
    ExpressionParser.php                  # Tags array → Pipeline
    SpellParser.php                       # kind:777 event → relay filter array
    StageParser.php                       # Single stage parsing
    ClauseParser.php                      # Clause tag parsing
    TermParser.php                        # NIP-FX term tag parsing
    TimeResolver.php                      # Relative time → absolute timestamps
    VariableResolver.php                  # $me, $contacts, $interests expansion

  Runner/
    ExpressionRunner.php                  # Orchestrator: Pipeline + Context → NormalizedItem[]
    Operation/
      OperationInterface.php              # Contract for all operations
      AllFilterOperation.php              # "all" — keep if every clause matches
      AnyFilterOperation.php              # "any" — keep if any clause matches
      NoneFilterOperation.php             # "none" — keep if no clause matches
      SortOperation.php                   # "sort" — namespace + field + direction + optional mode + fallback keys
      SliceOperation.php                  # "slice" — offset + limit
      DistinctOperation.php               # "distinct" — deduplicate by canonical identity
      UnionOperation.php                  # "union" — merge inputs
      IntersectOperation.php              # "intersect" — keep shared items
      DifferenceOperation.php             # "difference" — subtract later inputs
      ScoreOperation.php                  # NIP-FX "score" — weighted scoring
    Normalizer/
      NormalizerInterface.php             # compute(NormalizedItem, Term, RuntimeContext): float
      IdentityNormalizer.php              # Pass-through numeric value
      RecencyNormalizer.php               # 1/(1+(hours_since/half_life))
      LogNormalizer.php                   # sign(v)*log10(1+abs(v))
      InNormalizer.php                    # Membership test (1.0/0.0)
      ContainsCiNormalizer.php            # Case-insensitive substring (1.0/0.0)
      CountNormalizer.php                 # Count referencing events by kind (engagement signals)
    ClauseEvaluator.php                   # Evaluates a clause against a NormalizedItem

  Source/
    SourceResolverInterface.php           # Input reference → NormalizedItem[]
    SourceResolver.php                    # Main dispatcher
    EventIdSourceResolver.php             # ["input","e","<id>"] → DB + relay fallback
    AddressSourceResolver.php             # ["input","a","<kind>:<pk>:<d>"] → delegates by kind
    SpellSourceResolver.php               # kind:777 → execute spell → items
    ExpressionSourceResolver.php          # kind:30880 → recursive evaluation (cycle detection)
    ListSourceResolver.php                # NIP-51 lists → fetch referenced events
    ReferenceResolver.php                 # NIP-FX `in` references (follow packs, interest sets)

  Service/
    ExpressionService.php                 # Public API: evaluate(Event, string $userPubkey): NormalizedItem[]
    FeedCacheService.php                  # Redis caching layer (per-user)
    RuntimeContextFactory.php             # Builds RuntimeContext from authenticated user

  Controller/
    FeedApiController.php                 # GET /api/feed/{naddr} (requires ROLE_USER)

  Exception/
    ExpressionException.php               # Base exception
    UnknownOpException.php                # unknown_op
    InvalidArgumentException.php          # invalid_argument
    ArityException.php                    # arity_error
    TypeError.php                         # type_error
    UnresolvedVariableException.php       # unresolved_variable
    UnresolvedRefException.php            # unresolved_ref
    CycleException.php                    # cycle_error
    TimeoutException.php                  # timeout_error
    UnsupportedFeatureException.php       # unsupported_feature
```

---

## Architecture

### Data Flow

```
kind:30880 event
       │
       ▼
  ExpressionParser  ──→  Pipeline (ordered list of Stage objects)
       │
       ▼
  ExpressionRunner  ──→  iterates stages in order
       │                    │
       │                    ├─ resolve inputs (SourceResolver)
       │                    ├─ resolve runtime variables ($me, $contacts, $interests)
       │                    ├─ resolve relative timestamps
       │                    ├─ apply operation (filter/sort/slice/set/score)
       │                    └─ store result → next stage input
       │
       ▼
  NormalizedItem[]  ──→  original Event objects, preserving all fields
```

### NormalizedItem

Wraps `App\Entity\Event` and provides:

- **Normalized scalar properties**: `id`, `pubkey`, `kind` (int), `created_at` (int), `content` (string|null)
- **Tag selector access**: `getTagValues(string $selector): string[]` — collects the second element of each tag whose first element matches; `getFirstTagValue(string $selector): ?string` — convenience for single-valued tags
- **Derived state access**: `getDerived(string $name): int|string|float|null` — runner-computed values not native to the event (currently only `score`)
- **Canonical identity**: `getCanonicalId(): string` — `a:{kind}:{pubkey}:{d}` for parameterized replaceable events (kinds 30000–39999), otherwise `e:{id}`
- **Mutable score**: `?float $score` for NIP-FX scoring stages

Properties are lazily extracted on first access. The underlying Event is always preserved for the final output.

```php
final class NormalizedItem
{
    private ?float $score = null;
    private ?int $publishedAt = null;
    private bool $publishedAtResolved = false;

    public function __construct(
        private readonly Event $event,
    ) {}

    public function getEvent(): Event { return $this->event; }

    // Normalized properties
    public function getId(): string { return $this->event->getId(); }
    public function getPubkey(): string { return $this->event->getPubkey(); }
    public function getKind(): int { return $this->event->getKind(); }
    public function getCreatedAt(): int { return $this->event->getCreatedAt(); }
    public function getContent(): string { return $this->event->getContent(); }

    public function getPublishedAt(): ?int
    {
        if (!$this->publishedAtResolved) {
            $this->publishedAtResolved = true;
            $values = $this->getTagValues('published_at');
            if (!empty($values) && ctype_digit($values[0])) {
                $this->publishedAt = (int) $values[0];
            }
        }
        return $this->publishedAt;
    }

    public function getScore(): ?float { return $this->score; }
    public function setScore(float $score): void { $this->score = $score; }

    /** @return string[] */
    public function getTagValues(string $selector): array
    {
        $values = [];
        foreach ($this->event->getTags() as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === $selector) {
                $values[] = $tag[1];
            }
        }
        return $values;
    }

    public function getCanonicalId(): string
    {
        $kind = $this->getKind();
        if ($kind >= 30000 && $kind < 40000) {
            $dValues = $this->getTagValues('d');
            $d = $dValues[0] ?? '';
            return "a:{$kind}:{$this->getPubkey()}:{$d}";
        }
        return "e:{$this->getId()}";
    }

    /**
     * Get a normalized event property by name.
     * Returns null for absent properties.
     * Only native event fields — derived state (score) uses getDerived().
     */
    public function getProperty(string $name): int|string|float|null
    {
        return match ($name) {
            'id' => $this->getId(),
            'pubkey' => $this->getPubkey(),
            'kind' => $this->getKind(),
            'created_at' => $this->getCreatedAt(),
            'content' => $this->getContent(),
            default => null,
        };
    }

    /**
     * Get first tag value for a given selector, or null if absent.
     * Convenience for sort/cmp on single-valued tags.
     */
    public function getFirstTagValue(string $selector): ?string
    {
        $values = $this->getTagValues($selector);
        return $values[0] ?? null;
    }

    /**
     * Get derived runner state by name (empty namespace).
     * Currently only "score" is defined.
     */
    public function getDerived(string $name): int|string|float|null
    {
        return match ($name) {
            'score' => $this->getScore(),
            default => null,
        };
    }
}
```

### Pipeline & Stages

```php
final class Pipeline
{
    /** @param Stage[] $stages */
    public function __construct(
        public readonly string $dTag,
        public readonly array $stages,
    ) {}
}

final class Stage
{
    public function __construct(
        public readonly string $op,                    // "all", "any", "none", "sort", "slice", "distinct", "union", "intersect", "difference", "score"
        public readonly array $inputs = [],            // [["e","<id>"], ["a","<addr>"]]
        public readonly array $clauses = [],           // ClauseInterface[]
        public readonly array $terms = [],             // Term[] (for score)
        public readonly ?string $sortNamespace = null, // for "sort": "prop", "tag", or ""
        public readonly ?string $sortField = null,     // for "sort"
        public readonly ?string $sortDirection = null,  // "asc" or "desc"
        public readonly ?string $sortMode = null,      // for "sort": "num" (default) or "alpha"
        public readonly ?int $sliceOffset = null,      // for "slice"
        public readonly ?int $sliceLimit = null,       // for "slice"
    ) {}
}
```

### RuntimeContext

The runner requires an authenticated user. `$mePubkey` is non-nullable — the context is always constructed from a logged-in user's session. This guarantees `$me` resolution always succeeds and `$contacts`/`$interests` can be loaded from the user's events.

```php
final class RuntimeContext
{
    public function __construct(
        public readonly string $mePubkey,        // hex pubkey (always present, auth required)
        public readonly array $contacts,          // hex pubkeys from kind:3
        public readonly array $interests,         // tag values from kind:10015
        public readonly int $now,                 // evaluation timestamp (fixed)
        public array $visitedExpressions = [],    // cycle detection stack
    ) {}
}
```

---

## Parser Layer

### ExpressionParser

Walks the `tags` array of a kind:30880 event in order. Splits at each `["op", ...]` tag. Each segment becomes a `Stage` via `StageParser`.

**Structural validation (during parsing):**
1. Exactly one `d` tag
2. At least one `op` tag
3. First stage has at least one explicit `input` tag
4. Every filter stage (`all`, `any`, `none`) has at least one clause
5. `sort` has exactly one namespace, one field, one direction, and at most one mode; `id` and `pubkey` are rejected
6. `slice` has exactly one offset and one limit
7. Set operations receive valid arity (union/intersect/difference: 2+ inputs)
8. Term weights within [-1000, 1000]

Failures throw the appropriate exception from the error vocabulary.

### TimeResolver

```php
final class TimeResolver
{
    private const UNITS = [
        's'  => 1,
        'm'  => 60,
        'h'  => 3600,
        'd'  => 86400,
        'w'  => 604800,
        'mo' => 2592000,   // 30 days
        'y'  => 31536000,  // 365 days
    ];

    public function resolve(string $value, int $now): int
    {
        if ($value === 'now') return $now;
        if (ctype_digit($value)) return (int) $value;

        // Match relative: e.g. "7d", "24h", "2w"
        if (preg_match('/^(\d+)(s|m|h|d|w|mo|y)$/', $value, $m)) {
            return $now - ((int) $m[1] * self::UNITS[$m[2]]);
        }

        throw new InvalidArgumentException("Invalid time value: {$value}");
    }
}
```

### VariableResolver

Expands runtime variables to their resolved values. Since the runner requires authentication, `$me` always resolves. `$contacts` and `$interests` resolve to empty arrays if the user has no follow list or interests list — expressions using these will simply match nothing rather than error.

```php
final class VariableResolver
{
    /** @return string[] Expanded values */
    public function resolve(string $value, RuntimeContext $ctx): array
    {
        return match ($value) {
            '$me' => [$ctx->mePubkey],
            '$contacts' => $ctx->contacts,    // may be empty — not an error
            '$interests' => $ctx->interests,   // may be empty — not an error
            default => [$value],               // literal value, no expansion
        };
    }

    public function isVariable(string $value): bool
    {
        return str_starts_with($value, '$');
    }
}
```

> **Design note:** `$contacts` and `$interests` resolve to empty arrays when the user has no kind:3 or kind:10015 events. An empty comparison set means no items will match `match`/`in` clauses using those variables — this is correct behavior, not an error. The `unresolved_variable` error is reserved for truly unknown variable names.

### SpellParser

Converts a kind:777 event into a relay filter array:

```php
final class SpellParser
{
    public function parse(Event $spell, RuntimeContext $ctx, TimeResolver $timeResolver): array
    {
        $filter = [];
        foreach ($spell->getTags() as $tag) {
            match ($tag[0] ?? null) {
                'k'       => $filter['kinds'][] = (int) $tag[1],
                'authors' => $filter['authors'] = $this->resolveAuthors(array_slice($tag, 1), $ctx),
                'ids'     => $filter['ids'] = array_slice($tag, 1),
                'tag'     => $filter['#' . $tag[1]] = array_slice($tag, 2),
                'limit'   => $filter['limit'] = (int) $tag[1],
                'since'   => $filter['since'] = $timeResolver->resolve($tag[1], $ctx->now),
                'until'   => $filter['until'] = $timeResolver->resolve($tag[1], $ctx->now),
                'search'  => $filter['search'] = $tag[1],
                'relays'  => $filter['relays'] = array_slice($tag, 1),
                default   => null,  // ignore unknown tags
            };
        }
        return $filter;
    }
}
```

---

## Runner Layer

### ExpressionRunner

```php
final class ExpressionRunner
{
    public function __construct(
        private readonly OperationRegistry $operations,  // or direct injection of all ops
    ) {}

    /**
     * @return NormalizedItem[]
     */
    public function run(
        Pipeline $pipeline,
        RuntimeContext $ctx,
        SourceResolverInterface $resolver,
    ): array {
        $previousResult = null;

        foreach ($pipeline->stages as $stage) {
            // 1. Resolve inputs
            $inputs = $this->resolveInputs($stage, $previousResult, $resolver, $ctx);

            // 2. Get the operation
            $operation = $this->operations->get($stage->op);

            // 3. Execute
            $previousResult = $operation->execute($inputs, $stage, $ctx);
        }

        return $previousResult ?? [];
    }

    private function resolveInputs(Stage $stage, ?array $previousResult, SourceResolverInterface $resolver, RuntimeContext $ctx): array
    {
        $isSetOp = in_array($stage->op, ['union', 'intersect', 'difference'], true);
        $hasExplicit = !empty($stage->inputs);

        if ($previousResult === null && !$hasExplicit) {
            throw new ArityException("First stage must have explicit inputs");
        }

        if (!$hasExplicit) {
            return [$previousResult];
        }

        $explicitInputs = array_map(
            fn(array $ref) => $resolver->resolve($ref, $ctx),
            $stage->inputs,
        );

        if ($isSetOp && $previousResult !== null) {
            array_unshift($explicitInputs, $previousResult);
        } elseif ($previousResult !== null && !$hasExplicit) {
            return [$previousResult];
        }

        // For non-set ops with explicit inputs, explicit replaces previous
        return $hasExplicit && !$isSetOp ? $explicitInputs : $explicitInputs;
    }
}
```

### OperationInterface

```php
interface OperationInterface
{
    /**
     * @param NormalizedItem[][] $inputs One or more input lists
     * @param Stage $stage The stage configuration
     * @param RuntimeContext $ctx Runtime context
     * @return NormalizedItem[] Result list
     */
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array;
}
```

### Filter Operations (all, any, none)

Each filter op consumes exactly one input list and evaluates each item against all clauses:

```php
final class AllFilterOperation implements OperationInterface
{
    public function __construct(private readonly ClauseEvaluator $evaluator) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) throw new ArityException("all requires exactly 1 input");

        return array_values(array_filter(
            $inputs[0],
            fn(NormalizedItem $item) => $this->evaluator->allMatch($stage->clauses, $item, $ctx),
        ));
    }
}
```

`AnyFilterOperation` uses `$this->evaluator->anyMatch(...)`, `NoneFilterOperation` uses `$this->evaluator->noneMatch(...)`.

### ClauseEvaluator

Implements the full NIP-EX absence semantics:

```php
final class ClauseEvaluator
{
    public function evaluate(ClauseInterface $clause, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        return match (true) {
            $clause instanceof MatchClause => $this->evalMatch($clause, $item, $ctx),
            $clause instanceof NotClause   => $this->evalNot($clause, $item, $ctx),
            $clause instanceof CmpClause   => $this->evalCmp($clause, $item, $ctx),
            $clause instanceof TextClause  => $this->evalText($clause, $item, $ctx),
        };
    }

    private function evalMatch(MatchClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) return false;  // absent → false
            $strValue = (string) $value;
            return in_array($strValue, $c->resolvedValues, true);
        }

        // tag namespace
        $values = $item->getTagValues($c->selector);
        if (empty($values)) return false;  // absent → false
        foreach ($values as $v) {
            if (in_array($v, $c->resolvedValues, true)) return true;
        }
        return false;
    }

    private function evalNot(NotClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) return true;  // absent → true
            $strValue = (string) $value;
            return !in_array($strValue, $c->resolvedValues, true);
        }

        $values = $item->getTagValues($c->selector);
        if (empty($values)) return true;  // absent → true
        foreach ($values as $v) {
            if (in_array($v, $c->resolvedValues, true)) return false;
        }
        return true;
    }

    private function evalCmp(CmpClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        // Resolve value from the correct namespace
        $value = match ($c->namespace) {
            'prop' => $item->getProperty($c->selector),
            'tag'  => $item->getFirstTagValue($c->selector),
            ''     => $item->getDerived($c->selector),   // e.g. score
        };
        if ($value === null) return false;  // absent → false

        // Numeric comparison for known numeric fields and derived state
        $numericProps = ['kind', 'created_at'];
        $numericDerived = ['score'];
        $isNumeric = ($c->namespace === 'prop' && in_array($c->selector, $numericProps, true))
                  || ($c->namespace === '' && in_array($c->selector, $numericDerived, true))
                  || ($c->namespace === 'tag' && is_numeric($value) && is_numeric($c->value));

        if ($isNumeric) {
            $a = is_numeric($value) ? (float) $value : null;
            $b = is_numeric($c->value) ? (float) $c->value : null;
            if ($a === null || $b === null) return false;

            return match ($c->comparator) {
                'eq'  => $a == $b,
                'neq' => $a != $b,
                'gt'  => $a > $b,
                'gte' => $a >= $b,
                'lt'  => $a < $b,
                'lte' => $a <= $b,
            };
        }

        // String comparison — lexicographic
        $a = (string) $value;
        $b = $c->value;
        return match ($c->comparator) {
            'eq'  => $a === $b,
            'neq' => $a !== $b,
            'gt'  => $a > $b,
            'gte' => $a >= $b,
            'lt'  => $a < $b,
            'lte' => $a <= $b,
        };
    }

    private function evalText(TextClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        $targets = [];
        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) return false;
            $targets = [(string) $value];
        } else {
            $targets = $item->getTagValues($c->selector);
            if (empty($targets)) return false;
        }

        foreach ($targets as $target) {
            $matched = match ($c->mode) {
                'contains-ci' => str_contains(mb_strtolower($target), mb_strtolower($c->value)),
                'eq-ci'       => mb_strtolower($target) === mb_strtolower($c->value),
                'prefix-ci'   => str_starts_with(mb_strtolower($target), mb_strtolower($c->value)),
            };
            if ($matched) return true;
        }
        return false;
    }
}
```

### SortOperation

Full NIP-EX sort specification with namespace-based field resolution, `num`/`alpha` modes, and fallback chain:

```php
final class SortOperation implements OperationInterface
{
    private const BLOCKED_SORT_PROPS = ['id', 'pubkey'];

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) throw new ArityException("sort requires exactly 1 input");

        $items = $inputs[0];
        $ns = $stage->sortNamespace;     // "prop", "tag", or ""
        $field = $stage->sortField;
        $desc = $stage->sortDirection === 'desc';
        $alpha = ($stage->sortMode ?? 'num') === 'alpha';

        if ($ns === 'prop' && in_array($field, self::BLOCKED_SORT_PROPS, true)) {
            throw new InvalidArgumentException("Sorting by '$field' is not allowed");
        }

        usort($items, function (NormalizedItem $a, NormalizedItem $b) use ($ns, $field, $desc, $alpha) {
            // Primary sort
            $cmp = $this->compareField($a, $b, $ns, $field, $desc, $alpha);
            if ($cmp !== 0) return $cmp;

            // Fallback 1: published_at desc (tag, numeric)
            if (!($ns === 'tag' && $field === 'published_at')) {
                $cmp = $this->compareField($a, $b, 'tag', 'published_at', true, false);
                if ($cmp !== 0) return $cmp;
            }

            // Fallback 2: preserve original order (stable sort)
            return 0;
        });

        return $items;
    }

    private function compareField(NormalizedItem $a, NormalizedItem $b, string $ns, string $field, bool $desc, bool $alpha): int
    {
        $va = $this->resolveValue($a, $ns, $field);
        $vb = $this->resolveValue($b, $ns, $field);

        // Present before absent
        if ($va === null && $vb === null) return 0;
        if ($va === null) return 1;   // absent after present
        if ($vb === null) return -1;

        if ($alpha) {
            // Case-insensitive lexicographic comparison
            $cmp = strcasecmp($va, $vb);
        } else {
            // Numeric comparison — non-parseable after parseable
            $na = is_numeric($va) ? (float)$va : null;
            $nb = is_numeric($vb) ? (float)$vb : null;
            if ($na === null && $nb === null) return 0;
            if ($na === null) return 1;
            if ($nb === null) return -1;
            $cmp = $na <=> $nb;
        }

        return $desc ? -$cmp : $cmp;
    }

    private function resolveValue(NormalizedItem $item, string $ns, string $field): ?string
    {
        return match ($ns) {
            'prop' => $item->getProperty($field),
            'tag'  => $item->getFirstTagValue($field),
            ''     => $item->getDerived($field),
        };
    }
}
```

### ScoreOperation (NIP-FX)

```php
final class ScoreOperation implements OperationInterface
{
    public function __construct(
        private readonly NormalizerRegistry $normalizers,
    ) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) throw new ArityException("score requires exactly 1 input");

        foreach ($inputs[0] as $item) {
            $score = 0.0;
            foreach ($stage->terms as $term) {
                $normalizer = $this->normalizers->get($term->normalizer);
                $termValue = $normalizer->compute($item, $term, $ctx);
                $score += $term->weight * $termValue;
            }
            $item->setScore($score);
        }

        return $inputs[0];
    }
}
```

### Normalizers

| Normalizer | Formula | Extra values |
|-----------|---------|-------------|
| `identity` | raw numeric value (or 0) | none |
| `recency` | `1/(1+(hours_since/half_life))` clamped [0,1] | optional half-life (default "1h") |
| `log` | `sign(v)*log10(1+abs(v))` | none |
| `in` | 1.0 if any selected value ∈ comparison set, else 0.0 | 1+ comparison values (literals, `$variables`, `kind:pk:d` refs) |
| `contains-ci` | 1.0 if any selected string contains value (case-insensitive), else 0.0 | 1+ search strings |
| `count` | total count of referencing events matching specified kinds | 1+ event kinds (as string integers) |

**RecencyNormalizer example:**

```php
final class RecencyNormalizer implements NormalizerInterface
{
    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        $value = $this->extractNumeric($item, $term);
        if ($value === null) return 0.0;

        $halfLifeHours = $this->parseHalfLife($term->extraValues[0] ?? '1h');
        $hoursSince = ($ctx->now - $value) / 3600.0;
        if ($hoursSince < 0) $hoursSince = 0;

        $result = 1.0 / (1.0 + ($hoursSince / $halfLifeHours));
        return max(0.0, min(1.0, $result));
    }
}
```

**CountNormalizer example:**

```php
final class CountNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        if (empty($term->extraValues)) return 0.0;

        $kinds = array_map('intval', $term->extraValues);

        // Collect referenceable identities
        $eventId = $item->getId();
        $coordinate = null;
        $kind = $item->getKind();
        if ($kind >= 30000 && $kind < 40000) {
            $dValues = $item->getTagValues('d');
            $d = $dValues[0] ?? '';
            $coordinate = "{$kind}:{$item->getPubkey()}:{$d}";
        }

        // Query for referencing events (batched per evaluation via cache)
        return (float) $this->eventRepository->countReferencingEvents(
            eventId: $eventId,
            coordinate: $coordinate,
            kinds: $kinds,
        );
    }
}
```

---

## Source Resolution

### Resolution strategy: DB-first, relay-fallback

All source resolution follows the same pattern:
1. Check local database (`EventRepository`)
2. If not found or insufficient, fetch from relays (`NostrRequestExecutor`)
3. Wrap results in `NormalizedItem`

### SourceResolver (dispatcher)

```php
final class SourceResolver implements SourceResolverInterface
{
    /** @return NormalizedItem[] */
    public function resolve(array $inputRef, RuntimeContext $ctx): array
    {
        // $inputRef = ["e", "<id>"] or ["a", "<kind>:<pk>:<d>"]
        return match ($inputRef[0]) {
            'e' => $this->eventIdResolver->resolve($inputRef[1], $ctx),
            'a' => $this->addressResolver->resolve($inputRef[1], $ctx),
            default => throw new InvalidArgumentException("Unknown input type: {$inputRef[0]}"),
        };
    }
}
```

### AddressSourceResolver (delegates by kind)

```php
final class AddressSourceResolver
{
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $kind = (int) $kind;

        return match (true) {
            $kind === 30880 => $this->expressionResolver->resolve($address, $ctx),
            $kind === 777   => $this->spellResolver->resolve($address, $ctx),
            in_array($kind, [30003, 30004, 30005, 30006, 10003]) => $this->listResolver->resolve($address, $ctx),
            default         => $this->genericEventResolver->resolve($address, $ctx),
        };
    }
}
```

### ExpressionSourceResolver (recursive, with cycle detection)

```php
final class ExpressionSourceResolver
{
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        if (in_array($address, $ctx->visitedExpressions, true)) {
            throw new CycleException("Circular reference: {$address}");
        }

        $ctx->visitedExpressions[] = $address;

        $event = $this->findExpression($address);
        $pipeline = $this->parser->parse($event);
        return $this->runner->run($pipeline, $ctx, $this->sourceResolver);
    }
}
```

### SpellSourceResolver

```php
final class SpellSourceResolver
{
    public function resolve(string $spellAddress, RuntimeContext $ctx): array
    {
        $spellEvent = $this->findEvent($spellAddress);
        $filter = $this->spellParser->parse($spellEvent, $ctx, $this->timeResolver);

        // DB-first: query local events
        $events = $this->eventRepository->findByFilter($filter);

        // Relay fallback if needed
        if (empty($events) && isset($filter['relays'])) {
            $events = $this->fetchFromRelays($filter);
        }

        return array_map(fn(Event $e) => new NormalizedItem($e), $events);
    }
}
```

### ReferenceResolver (NIP-FX `in` term expansion)

```php
final class ReferenceResolver
{
    /**
     * Resolve a parameterized replaceable reference for the `in` normalizer.
     * @return string[] Expanded comparison values
     */
    public function resolveForDomain(string $reference, string $domain): array
    {
        [$kind, $pubkey, $d] = explode(':', $reference, 3);
        $kind = (int) $kind;

        return match ($domain) {
            'pubkey' => match ($kind) {
                39089 => $this->extractPubkeysFromFollowPack($reference),
                default => throw new InvalidArgumentException("Kind {$kind} not valid for pubkey domain"),
            },
            'tag' => match ($kind) {
                30015 => $this->extractTagsFromInterestSet($reference),
                default => throw new InvalidArgumentException("Kind {$kind} not valid for tag domain"),
            },
        };
    }
}
```

---

## Service Layer

### ExpressionService (public API)

The service always requires an authenticated user context. Controllers must provide the user's pubkey; the factory builds the full context.

```php
final class ExpressionService
{
    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly ExpressionRunner $runner,
        private readonly SourceResolver $sourceResolver,
        private readonly RuntimeContextFactory $contextFactory,
        private readonly FeedCacheService $cache,
    ) {}

    /**
     * Evaluate a kind:30880 expression event.
     *
     * @param string $userPubkey Hex pubkey of the authenticated user (required)
     * @return NormalizedItem[]
     */
    public function evaluate(Event $expression, string $userPubkey): array
    {
        $ctx = $this->contextFactory->create($userPubkey);
        $pipeline = $this->parser->parse($expression);
        return $this->runner->run($pipeline, $ctx, $this->sourceResolver);
    }

    /**
     * Evaluate with caching.
     * @return NormalizedItem[]
     */
    public function evaluateCached(Event $expression, string $userPubkey): array
    {
        $ctx = $this->contextFactory->create($userPubkey);
        $cacheKey = $this->cache->buildKey($expression, $ctx);

        return $this->cache->getOrSet($cacheKey, fn() => $this->evaluate($expression, $userPubkey));
    }
}
```

### RuntimeContextFactory

Always receives an authenticated user's pubkey. Loads their contacts (kind:3) and interests (kind:10015) from the local database. Empty lists are valid — they simply mean the user hasn't published those events yet.

```php
final class RuntimeContextFactory
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly RedisCacheService $redisCacheService,
    ) {}

    public function create(string $userPubkey): RuntimeContext
    {
        $contacts = [];
        $interests = [];

        // Load kind:3 contacts
        $contactEvent = $this->eventRepository->findOneBy([
            'pubkey' => $userPubkey,
            'kind' => 3,
        ]);
        if ($contactEvent) {
            foreach ($contactEvent->getTags() as $tag) {
                if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                    $contacts[] = $tag[1];
                }
            }
        }

        // Load kind:10015 interests
        $interestEvent = $this->eventRepository->findOneBy([
            'pubkey' => $userPubkey,
            'kind' => 10015,
        ]);
        if ($interestEvent) {
            foreach ($interestEvent->getTags() as $tag) {
                if (($tag[0] ?? '') === 't' && isset($tag[1])) {
                    $interests[] = $tag[1];
                }
            }
        }

        return new RuntimeContext(
            mePubkey: $userPubkey,
            contacts: array_unique($contacts),
            interests: array_unique($interests),
            now: time(),
        );
    }
}
```

---

## API Endpoint

### FeedApiController

The endpoint requires authentication. Unauthenticated requests receive a 401 response. The controller extracts the user's hex pubkey from the session and passes it to `ExpressionService`.

```php
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/feed')]
#[IsGranted('ROLE_USER')]
final class FeedApiController extends AbstractController
{
    #[Route('/{naddr}', name: 'api_feed_evaluate', methods: ['GET'])]
    public function evaluate(
        string $naddr,
        ExpressionService $expressionService,
        EventRepository $eventRepository,
        Request $request,
    ): JsonResponse {
        // 1. Get authenticated user's hex pubkey
        $user = $this->getUser();
        $userPubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());

        // 2. Decode naddr → kind:30880 coordinates
        // 3. Fetch expression event from DB (or relay)
        // 4. Evaluate (cached) with authenticated user context
        $results = $expressionService->evaluateCached($expression, $userPubkey);

        // 5. Apply optional ?offset=&limit= pagination
        // 6. Serialize NormalizedItem[] → Nostr event JSON
        // 7. Return JSON response

        // Error mapping:
        // ExpressionException → 400 (structural)
        // UnresolvedRefException → 404
        // CycleException → 422
        // (UnresolvedVariableException for unknown variable names → 422)
    }
}
```

**Response format:**

```json
{
    "expression": "30880:<pubkey>:<d>",
    "count": 20,
    "events": [
        { "id": "...", "pubkey": "...", "kind": 30023, "content": "...", "tags": [...], "created_at": 1712345678, "sig": "..." },
        ...
    ]
}
```

---

## EventRepository Addition

New flexible query method for spell execution:

```php
/**
 * Query events using a NIP-A7 spell filter structure.
 *
 * @param array $filter ['kinds' => int[], 'authors' => string[], '#t' => string[], 'since' => int, 'until' => int, 'limit' => int]
 * @return Event[]
 */
public function findByFilter(array $filter): array
{
    $qb = $this->createQueryBuilder('e');

    if (isset($filter['kinds'])) {
        $qb->andWhere('e.kind IN (:kinds)')
           ->setParameter('kinds', $filter['kinds']);
    }
    if (isset($filter['authors'])) {
        $qb->andWhere('e.pubkey IN (:authors)')
           ->setParameter('authors', $filter['authors']);
    }
    if (isset($filter['since'])) {
        $qb->andWhere('e.created_at >= :since')
           ->setParameter('since', $filter['since']);
    }
    if (isset($filter['until'])) {
        $qb->andWhere('e.created_at <= :until')
           ->setParameter('until', $filter['until']);
    }

    // Tag filters (#t, #p, #e, etc.)
    foreach ($filter as $key => $values) {
        if (str_starts_with($key, '#') && is_array($values)) {
            $tagName = substr($key, 1);
            // PostgreSQL JSONB: check if any tag array element matches
            foreach ($values as $i => $val) {
                $param = "tag_{$tagName}_{$i}";
                $qb->andWhere("EXISTS (SELECT 1 FROM jsonb_array_elements(e.tags) AS tag WHERE tag->>0 = :tagName_{$param} AND tag->>1 = :{$param})")
                   ->setParameter("tagName_{$param}", $tagName)
                   ->setParameter($param, $val);
            }
        }
    }

    $qb->orderBy('e.created_at', 'DESC');

    if (isset($filter['limit'])) {
        $qb->setMaxResults((int) $filter['limit']);
    } else {
        $qb->setMaxResults(500);  // safety limit
    }

    return $qb->getQuery()->getResult();
}

/**
 * Count events of specified kinds that reference a given event.
 * Used by the CountNormalizer for engagement scoring.
 *
 * Checks both e-tag (by event ID) and a-tag (by coordinate for replaceable events).
 * Results should be cached per evaluation by the caller.
 */
public function countReferencingEvents(string $eventId, ?string $coordinate, array $kinds): int
{
    $qb = $this->createQueryBuilder('e')
        ->select('COUNT(e.id)')
        ->andWhere('e.kind IN (:kinds)')
        ->setParameter('kinds', $kinds);

    // Match events referencing by e-tag or a-tag
    $refConditions = "EXISTS (SELECT 1 FROM jsonb_array_elements(e.tags) AS tag WHERE tag->>0 = 'e' AND tag->>1 = :eventId)";
    $qb->setParameter('eventId', $eventId);

    if ($coordinate !== null) {
        $refConditions .= " OR EXISTS (SELECT 1 FROM jsonb_array_elements(e.tags) AS tag WHERE tag->>0 = 'a' AND tag->>1 = :coordinate)";
        $qb->setParameter('coordinate', $coordinate);
    }

    $qb->andWhere($refConditions);

    return (int) $qb->getQuery()->getSingleScalarResult();
}
```

---

## Error Handling

All errors follow the NIP-EX error vocabulary:

| Exception | NIP-EX error | HTTP status | When |
|-----------|-------------|-------------|------|
| *(unauthenticated)* | — | 401 | User not logged in |
| `UnknownOpException` | `unknown_op` | 400 | Unrecognized operation name |
| `InvalidArgumentException` | `invalid_argument` | 400 | Malformed tags, invalid values, bad weight bounds |
| `ArityException` | `arity_error` | 400 | Wrong number of inputs |
| `TypeError` | `type_error` | 400 | Wrong input/output shape |
| `UnresolvedVariableException` | `unresolved_variable` | 422 | Unknown variable name (not `$me`/`$contacts`/`$interests`) |
| `UnresolvedRefException` | `unresolved_ref` | 404 | Referenced input not found |
| `CycleException` | `cycle_error` | 422 | Circular input reference |
| `TimeoutException` | `timeout_error` | 504 | Evaluation exceeded max execution time |
| `UnsupportedFeatureException` | `unsupported_feature` | 501 | Recognized but not implemented |

**Notes:**
- Authentication is enforced at the controller level via `#[IsGranted('ROLE_USER')]`. Unauthenticated requests never reach the runner.
- `$me` always resolves because the context is built from the authenticated user. `$contacts` and `$interests` resolve to empty arrays if the user has no corresponding events — this is correct behavior (empty match set), not an error.
- Missing properties/tags during evaluation are never errors. They are handled by absence semantics.

---

## Caching Strategy

Since only authenticated users can call the runner, all cache entries are per-user:

- Cache key: `feed:{expression_coordinate}:{user_pubkey}:{ctx_hash}`
- `ctx_hash` = hash of sorted `$contacts` + sorted `$interests` (deduplicates equivalent contexts when lists haven't changed)
- TTL: configurable via `expression.cache_ttl` (default 300s)
- Each user gets their own cache entry because runtime variables (`$me`, `$contacts`, `$interests`) personalize the result
- Cache is invalidated when the user's contacts (kind:3) or interests (kind:10015) change — detected by comparing `ctx_hash` on next request

---

## Integration Points

| Bundle service | Uses | Purpose |
|---------------|------|---------|
| `SourceResolver` | `EventRepository` | DB-first event lookup |
| `SourceResolver` | `NostrRequestExecutor` | Relay fallback for remote sources |
| `SourceResolver` | `RelayRegistry` | Get content relay URLs |
| `RuntimeContextFactory` | `EventRepository` | Load kind:3 contacts, kind:10015 interests |
| `FeedCacheService` | `RedisCacheService` | Cache evaluated feeds |
| `ReferenceResolver` | `EventRepository` | Load follow packs (39089) and interest sets (30015) |
| `CountNormalizer` | `EventRepository` | Count referencing events by kind (engagement scoring) |
| `FeedApiController` | `ExpressionService` | Evaluate expressions |

---

## KindsEnum Additions

```php
case SPELL = 777;              // NIP-A7, spells (portable query filters)
case FEED_EXPRESSION = 30880;  // NIP-EX, publishable feed expressions
```

---

## Build Phases

| Phase | Scope | Dependencies |
|-------|-------|-------------|
| **1** | Bundle skeleton + Model + Exceptions + KindsEnum update | None |
| **2** | Parser layer (ExpressionParser, StageParser, ClauseParser, TermParser, TimeResolver, VariableResolver, SpellParser) | Phase 1 |
| **3** | Runner + all filter/sort/slice operations + ClauseEvaluator | Phases 1-2 |
| **4** | Set operations (distinct, union, intersect, difference) | Phase 3 |
| **5** | NIP-FX: ScoreOperation + all 5 normalizers + ReferenceResolver | Phase 3 |
| **6** | Source resolution layer (all resolvers) + `EventRepository::findByFilter()` | Phases 3-5 |
| **7** | ExpressionService + RuntimeContextFactory + FeedCacheService | Phase 6 |
| **8** | FeedApiController + routes + integration wiring | Phase 7 |
| **9** | Tests (unit + integration + feature specs) | Phase 8 |
| **10** | CHANGELOG entry | Phase 9 |

---

## Testing Strategy

```
tests/ExpressionBundle/
  Unit/
    Model/
      NormalizedItemTest.php              # Property extraction, canonical ID, tag values
    Parser/
      ExpressionParserTest.php            # Full parse + structural validation
      TimeResolverTest.php                # All time formats, edge cases
      VariableResolverTest.php            # Variable expansion + error cases
      SpellParserTest.php                 # Spell → filter conversion
      ClauseParserTest.php                # Clause tag parsing
      TermParserTest.php                  # Term tag parsing + weight validation
    Runner/
      Operation/
        AllFilterOperationTest.php        # match/not/cmp/text with all absence cases
        AnyFilterOperationTest.php
        NoneFilterOperationTest.php
        SortOperationTest.php             # Namespace-based sort (prop/tag/"") + num/alpha modes + fallback chain + absent values + id/pubkey rejection
        SliceOperationTest.php
        DistinctOperationTest.php         # Canonical identity dedup
        UnionOperationTest.php
        IntersectOperationTest.php
        DifferenceOperationTest.php
        ScoreOperationTest.php            # Multi-term scoring, overwrite semantics
      Normalizer/
        IdentityNormalizerTest.php
        RecencyNormalizerTest.php         # Half-life configs, future timestamps
        LogNormalizerTest.php             # Negative values, zero, large values
        InNormalizerTest.php              # Literals, variables, references
        ContainsCiNormalizerTest.php      # Unicode, partial matches
        CountNormalizerTest.php           # Referencing event counts, e-tag + a-tag, missing refs, kind filtering
      ClauseEvaluatorTest.php             # All clause types + absence semantics + cmp namespace dispatch (prop/tag/"")
    Source/
      SourceResolverTest.php              # Dispatch logic (mock resolvers)
      ExpressionSourceResolverTest.php    # Cycle detection
  Integration/
    ExpressionServiceTest.php             # End-to-end: parse → resolve → run
tests/NIPs/
    NIP-EX.feature                        # Gherkin specs for protocol compliance
    NIP-FX.feature                        # Scoring extension compliance
```

---

## Resolved Design Decisions

1. **Spell relay targeting**: Spell resolution respects the `relays` tag from kind:777 events for remote queries, falling back to `RelayRegistry::getContentRelays()` when absent. Relay allowlisting is handled at the project level by the relay gateway — the expression runner does not need its own allowlist.

2. **Execution timeout**: Configurable max execution time via `expression.max_execution_time` (default 10s). If exceeded, the runner aborts with a `timeout_error`. This is added to the exception vocabulary:

| Exception | NIP-EX error | HTTP status | When |
|-----------|-------------|-------------|------|
| `TimeoutException` | `timeout_error` | 504 | Evaluation exceeded max execution time |








