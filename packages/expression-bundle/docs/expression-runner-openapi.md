# Expression Runner OpenAPI Specification (NIP-EX / NIP-FX / NIP-GX)

This document defines an OpenAPI-first HTTP contract for evaluating and validating publishable feed expressions that mirror the behavior of `kind:30880` expression pipelines from `NIP-EX`, with scoring from `NIP-FX` and traversal from `NIP-GX`.

It is intentionally **spec-only** and implementation-agnostic.

## Scope

- Covers expression ingestion payload shape, validation, and evaluation APIs.
- Preserves NIP semantics:
  - stage ordering
  - stage-local tags
  - single-input arity rules
  - absence semantics
  - deterministic `now` for one evaluation
  - NIP error vocabulary (`invalid_argument`, `arity_error`, `unresolved_ref`, etc.)
- Exposes result events only; derived values (`score`) are optional debug metadata.

## OpenAPI 3.1 (YAML)

```yaml
openapi: 3.1.0
info:
  title: Publishable Feed Expression API
  version: 1.0.0
  summary: HTTP contract for validating and evaluating NIP-EX/NIP-FX/NIP-GX expressions.
  description: |
    This API mirrors Nostr kind:30880 expression semantics:
    - NIP-EX core pipeline (filter/sort/slice/set ops)
    - NIP-FX score stage and term model
    - NIP-GX traversal operators

servers:
  - url: https://api.example.com

security:
  - bearerAuth: []

paths:
  /v1/expressions/validate:
    post:
      operationId: validateExpression
      summary: Validate expression structure and optionally resolve references
      description: |
        Performs structural validation. When resolve=true, the runner may also
        resolve refs/variables and return runtime failures without executing stages.
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/ValidateRequest'
      responses:
        '200':
          description: Validation outcome
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ValidateResponse'

  /v1/expressions/evaluate:
    post:
      operationId: evaluateExpression
      summary: Evaluate expression pipeline and return events
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/EvaluateRequest'
      responses:
        '200':
          description: Successful evaluation
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/EvaluateResponse'
        '400':
          description: Semantic or runtime error
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ErrorResponse'

  /v1/expressions/{address}:
    get:
      operationId: getExpression
      summary: Resolve a published expression by address
      parameters:
        - in: path
          name: address
          required: true
          schema:
            type: string
          description: Parameterized address `30880:<pubkey>:<d>`
      responses:
        '200':
          description: Expression envelope
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/ExpressionEnvelope'
        '404':
          description: Not found

components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
      bearerFormat: JWT

  schemas:
    ExpressionEnvelope:
      type: object
      required: [kind, pubkey, created_at, tags]
      properties:
        id:
          type: string
          description: Event id for stored expression event
        kind:
          type: integer
          const: 30880
        pubkey:
          type: string
        created_at:
          type: integer
        content:
          type: string
        tags:
          type: array
          items:
            $ref: '#/components/schemas/NostrTag'

    NostrTag:
      type: array
      minItems: 1
      items:
        type: string

    ValidateRequest:
      type: object
      required: [expression]
      properties:
        expression:
          $ref: '#/components/schemas/ExpressionEnvelope'
        options:
          $ref: '#/components/schemas/EvalOptions'
        resolve:
          type: boolean
          default: false

    ValidateResponse:
      type: object
      required: [valid, diagnostics]
      properties:
        valid:
          type: boolean
        diagnostics:
          type: array
          items:
            $ref: '#/components/schemas/Diagnostic'
        normalized:
          $ref: '#/components/schemas/NormalizedExpression'

    EvaluateRequest:
      type: object
      required: [expression]
      properties:
        expression:
          $ref: '#/components/schemas/ExpressionEnvelope'
        options:
          $ref: '#/components/schemas/EvalOptions'

    EvalOptions:
      type: object
      properties:
        now:
          type: integer
          description: Optional fixed unix timestamp for deterministic tests.
        include_debug:
          type: boolean
          default: false
        include_score:
          type: boolean
          default: false
        max_depth:
          type: integer
          minimum: 1
          default: 64
          description: Traversal depth guard for ancestor/descendant.

    EvaluateResponse:
      type: object
      required: [status, meta, events]
      properties:
        status:
          type: string
          enum: [ok]
        meta:
          $ref: '#/components/schemas/EvalMeta'
        events:
          type: array
          items:
            $ref: '#/components/schemas/EventResult'
        debug:
          $ref: '#/components/schemas/DebugInfo'

    EvalMeta:
      type: object
      required: [evaluated_at, stage_count, result_count]
      properties:
        evaluated_at:
          type: integer
        stage_count:
          type: integer
        result_count:
          type: integer

    EventResult:
      type: object
      required: [event]
      properties:
        event:
          $ref: '#/components/schemas/NostrEvent'
        score:
          type: number
          description: Included only when include_score=true and score exists.

    NostrEvent:
      type: object
      required: [id, pubkey, kind, created_at, tags, content, sig]
      properties:
        id: { type: string }
        pubkey: { type: string }
        kind: { type: integer }
        created_at: { type: integer }
        tags:
          type: array
          items:
            $ref: '#/components/schemas/NostrTag'
        content: { type: string }
        sig: { type: string }

    NormalizedExpression:
      type: object
      properties:
        d:
          type: string
        stages:
          type: array
          items:
            $ref: '#/components/schemas/Stage'

    Stage:
      type: object
      required: [op]
      properties:
        op:
          type: string
          enum:
            - all
            - any
            - none
            - sort
            - slice
            - distinct
            - union
            - intersect
            - difference
            - score
            - parent
            - child
            - ancestor
            - descendant
        args:
          type: array
          items: { type: string }
        input:
          type: array
          items:
            $ref: '#/components/schemas/StageInput'
        clauses:
          type: array
          items:
            $ref: '#/components/schemas/Clause'
        terms:
          type: array
          items:
            $ref: '#/components/schemas/ScoreTerm'

    StageInput:
      type: object
      required: [type, ref]
      properties:
        type:
          type: string
          enum: [e, a]
        ref:
          type: string

    Clause:
      oneOf:
        - $ref: '#/components/schemas/MatchClause'
        - $ref: '#/components/schemas/NotClause'
        - $ref: '#/components/schemas/CmpClause'
        - $ref: '#/components/schemas/TextClause'

    MatchClause:
      type: object
      required: [type, ns, selector, values]
      properties:
        type: { type: string, const: match }
        ns: { type: string, enum: [prop, tag] }
        selector: { type: string }
        values:
          type: array
          minItems: 1
          items: { type: string }

    NotClause:
      type: object
      required: [type, ns, selector, values]
      properties:
        type: { type: string, const: not }
        ns: { type: string, enum: [prop, tag] }
        selector: { type: string }
        values:
          type: array
          minItems: 1
          items: { type: string }

    CmpClause:
      type: object
      required: [type, ns, selector, cmp, value]
      properties:
        type: { type: string, const: cmp }
        ns: { type: string, enum: [prop, tag, ''] }
        selector: { type: string }
        cmp:
          type: string
          enum: [eq, neq, gt, gte, lt, lte]
        value: { type: string }

    TextClause:
      type: object
      required: [type, ns, selector, mode, value]
      properties:
        type: { type: string, const: text }
        ns: { type: string, enum: [prop, tag] }
        selector: { type: string }
        mode:
          type: string
          enum: [contains-ci, eq-ci, prefix-ci]
        value: { type: string }

    ScoreTerm:
      type: object
      required: [source, selector, normalizer, weight]
      properties:
        source:
          type: string
          enum: [prop, tag, '']
        selector:
          type: string
        normalizer:
          type: string
          enum: [identity, recency, log, in, contains-ci, count]
        weight:
          type: number
          minimum: -1000
          maximum: 1000
        values:
          type: array
          items: { type: string }

    Diagnostic:
      type: object
      required: [code, message]
      properties:
        code:
          type: string
          enum:
            - unknown_op
            - invalid_argument
            - arity_error
            - type_error
            - unresolved_variable
            - unresolved_ref
            - cycle_error
            - unsupported_feature
        message:
          type: string
        stage_index:
          type: integer
          minimum: 0

    ErrorResponse:
      type: object
      required: [status, error]
      properties:
        status:
          type: string
          const: error
        error:
          $ref: '#/components/schemas/Diagnostic'

    DebugInfo:
      type: object
      properties:
        stage_outputs:
          type: array
          items:
            type: object
            properties:
              stage_index: { type: integer }
              op: { type: string }
              result_count: { type: integer }
              elapsed_ms: { type: integer }
```

## Contract Notes

- `validate` and `evaluate` both accept a full `kind:30880`-like envelope so callers can reuse signed payloads directly.
- `now` is injectable for deterministic tests, but if omitted the runner must use one fixed timestamp for full evaluation.
- `score` is treated as derived runner state and only returned when explicitly requested.
- Traversal (`parent`, `child`, `ancestor`, `descendant`) is modeled as regular stage ops and uses NIP-GX constraints.
- Error codes intentionally mirror NIP wording for interoperability between runners.

## Example Request (Evaluate)

```json
{
  "expression": {
    "kind": 30880,
    "pubkey": "<hex>",
    "created_at": 1760000000,
    "content": "Recent contacts longform",
    "tags": [
      ["d", "contacts-recent-longform"],
      ["op", "all"],
      ["input", "e", "<spell-event-id>"],
      ["match", "prop", "kind", "30023"],
      ["match", "prop", "pubkey", "$contacts"],
      ["op", "score"],
      ["term", "prop", "created_at", "recency", "0.6", "7d"],
      ["term", "tag", "t", "in", "0.4", "$interests"],
      ["op", "sort", "", "score", "desc"],
      ["op", "slice", "0", "30"]
    ]
  },
  "options": {
    "include_score": true
  }
}
```

## Example Request (Evaluate: kind 30040 all descendants)

```json
{
  "expression": {
    "kind": 30880,
    "pubkey": "<hex>",
    "created_at": 1760001000,
    "content": "Flatten a publication index into all descendants.",
    "tags": [
      ["d", "publication-all-descendants"],
      ["op", "descendant"],
      ["input", "a", "30040:<pubkey>:<publication-d>"],
      ["op", "distinct"],
      ["op", "sort", "prop", "created_at", "desc"],
      ["alt", "Expression: publication all descendants"]
    ]
  },
  "options": {
    "include_debug": false
  }
}
```

## Related NIPs

- [NIP-EX](NIP/EX.md)
- [NIP-FX](NIP/FX.md)
- [NIP-GX](NIP/GX.md)

