# Skill: Write a NIP Feature Spec (Gherkin)

Use this skill to document and verify protocol compliance for a NIP (Nostr Implementation Possibility). Specs live in `tests/NIPs/` and use `.feature` Gherkin syntax — they serve as executable specifications.

---

## File location and naming

```
tests/NIPs/NIP-{number}.feature          # Standard NIP
tests/NIPs/LOCAL-{CONCEPT}.feature       # Local application behaviour (not a NIP)
```

---

## Template

```gherkin
Feature: NIP-{number} {Short Title}
  As a nostr user
  I want {what the user wants}
  So that {the benefit / why it matters}

  Background:
    Given the local database stores events, articles, highlights, and magazines
    And the application processes kind:{kind} events

  # --- Ingestion: happy paths ---

  Scenario: Basic ingestion stores the event
    Given no existing event with id "abc...001" in the local store
    When a kind:{kind} event authored by "PK1" with id "abc...001" is ingested
    Then the event "abc...001" is persisted to the local event store
    And any derived entity is created or updated

  # --- Ingestion: idempotency ---

  Scenario: Duplicate ingestion is idempotent
    Given a kind:{kind} event "abc...001" is already stored
    When the same event "abc...001" arrives again
    Then only one row exists for "abc...001"

  # --- Replaceable event semantics (for kinds 0, 3, 10xxx, 30xxx) ---

  Scenario: Newer event replaces the older one
    Given a kind:{kind} event by "PK1" with created_at 100 is stored
    When a kind:{kind} event by "PK1" with created_at 200 arrives
    Then only the created_at 200 version is kept

  Scenario: Older event does not replace a newer one
    Given a kind:{kind} event by "PK1" with created_at 200 is stored
    When a kind:{kind} event by "PK1" with created_at 100 arrives
    Then the created_at 200 version is still the current one

  # --- Author enforcement ---

  Scenario: Event from wrong pubkey is rejected / ignored
    Given a resource owned by "PK1"
    When an event from "PK2" attempts to modify it
    Then the modification is not applied

  # --- Edge cases ---

  Scenario: Missing required tag is handled gracefully
    When a kind:{kind} event with no "{tag}" tag is ingested
    Then no exception is thrown
    And the event is stored with a null / default value for that field

  # --- UI / retrieval (optional) ---

  Scenario: Controller returns the stored event
    Given a kind:{kind} event "abc...001" by "PK1" is stored
    When the application resolves the event for "PK1"
    Then "abc...001" is included in the result set
```

---

## Good practices for scenarios

| Do | Avoid |
|---|---|
| Use short, readable placeholders like `"PK1"`, `"abc...001"` | Real pubkeys / event IDs in the spec |
| One behaviour per scenario | Multiple behaviours in one scenario |
| Use `Background` for shared preconditions | Repeating `Given` blocks in every scenario |
| Describe observable outcomes in `Then` | Implementation details in `Then` |

---

## Covering your NIP checklist

Before writing scenarios, read the NIP carefully and extract:

1. **Happy path** — event is valid and ingested normally.
2. **Idempotency** — same event arriving twice.
3. **Replaceable semantics** — if the kind is `0`, `3`, `10000–19999`, or `30000–39999`.
4. **Pubkey enforcement** — author must match for mutations.
5. **Tombstone / deletion** — if kind `5` interacts with this kind (check `EventDeletionService`).
6. **Cascades** — what happens to derived entities (Article, Highlight, Magazine) when the event is deleted or replaced.
7. **Late arrivals** — stale event arriving after a tombstone exists.
8. **Missing/malformed tags** — graceful degradation.

---

## After writing the spec

Add a line to `documentation/Nostr/` or `documentation/NIP/` explaining the implementation approach, and add a `CHANGELOG.md` entry:

```
- [Feature] Added NIP-XX Gherkin spec (tests/NIPs/NIP-XX.feature) covering ingestion, idempotency, and replaceable semantics.
```

---

## Example — NIP-51 Interests List (kind 10015)

```gherkin
Feature: NIP-51 Interests (kind 10015)
  As a nostr user
  I want my interest list stored and resolved locally
  So that personalised feeds can use my declared topics

  Background:
    Given the local database stores events

  Scenario: Interest list is stored on ingestion
    When a kind:10015 event by "PK1" with "t" tags ["bitcoin","nostr"] is ingested
    Then an event row for "PK1" of kind 10015 exists in the local store
    And the raw tags include ["t","bitcoin"] and ["t","nostr"]

  Scenario: Newer list replaces the old one
    Given a kind:10015 event by "PK1" with created_at 100 is stored
    When a kind:10015 event by "PK1" with created_at 200 arrives
    Then only the created_at 200 version is kept for "PK1"
```

