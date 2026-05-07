# Follow pack author-filter expression template

## What this does

Follow-pack coordinates (`39089:pubkey:d-tag`) are expanded like `$contacts`: into pubkey lists used by clause value matching.

The template is an **author filter** over an existing event source. It is not a direct "follow-pack as article source" template.

## Expression builder template

The expression builder includes a template named **Longform filtered by follow-pack authors**.

Template tags:

```json
[
  ["op", "all"],
  ["input", "e", "nevent1qqs9sv8skzupa9s9dfss273lkw05l3dwne4wve5x0xy048fxnjnwklqzyr28tnjt89m4qufs7sk8lp35dmundqq08tn56hk0szyjsrxury37jqcyqqqqxzgv05wdu"],
  ["match", "prop", "pubkey", "39089:<pubkey>:<d-tag>"],
  ["match", "prop", "kind", "30023"],
  ["op", "distinct"],
  ["op", "sort", "prop", "created_at", "desc"],
  ["op", "slice", "0", "30"]
]
```

The default input is the Decent Newsroom "recent articles" spell (kind `777`) referenced by `nevent`.

Sorting uses the required `created_at` property, so ordering is stable even when `published_at` tags are absent.

## Runtime behavior

- The `input` clause should point to an event source (for example an expression, spell, or list that yields articles/events).
- In `match`/`not` clauses on `prop pubkey`, values like `39089:pubkey:d-tag` resolve to `p`-tag pubkeys from the follow-pack event.
- Matching then behaves exactly like `$contacts`: item `pubkey` matches any expanded pubkey value.
- If `input` points directly to `39089:...` or `3:<pubkey>:`, the source expands to pubkey-list items (not longform article events), which is usually not what this template intends.

## Notes

- If the follow pack cannot be resolved locally, it contributes no pubkeys.
- If the follow pack has no `p` tags, it contributes no pubkeys.



