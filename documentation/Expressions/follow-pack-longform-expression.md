# Follow pack longform expression source

## What this adds

Follow-pack coordinates (`39089:pubkey:d-tag`) are now expanded the same way as `$contacts`: into pubkey lists used in clause value matching.

This keeps semantics aligned with runtime-variable expansion instead of introducing a dedicated source type.

## Expression builder template

The expression builder includes a template named **Latest longform from follow pack**.

Template tags:

```json
[
  ["op", "all"],
  ["input", "a", "<source-coordinate-or-expression>"],
  ["match", "prop", "pubkey", "39089:<pubkey>:<d-tag>"],
  ["match", "prop", "kind", "30023"],
  ["op", "distinct"],
  ["op", "sort", "prop", "created_at", "desc"],
  ["op", "slice", "0", "30"]
]
```

Sorting uses the required `created_at` property, so ordering is stable even when `published_at` tags are absent.

## Runtime behavior

- The expression source (`input`) supplies candidate events.
- In `match`/`not` clauses on `prop pubkey`, values like `39089:pubkey:d-tag` are resolved to `p` tag pubkeys from that follow-pack event.
- Matching then runs exactly like `$contacts` expansion: author pubkey equals any expanded pubkey value.

### Pubkey-list sources

- If an expression `input` points directly to a follow-pack (`a 39089:...`) or contacts list (`a 3:<pubkey>:`), the source is expanded as a pubkey list instead of a generic single event.
- Event-id inputs that resolve to kind `39089` or kind `3` are delegated through the same path.
- Kind `3` coordinates with an empty identifier segment (`3:<pubkey>:`) are supported for both clause-value expansion and `in` normalizer references.

## Notes

- If the follow pack cannot be resolved locally, it contributes no pubkeys.
- If the follow pack has no `p` tags, it contributes no pubkeys.



