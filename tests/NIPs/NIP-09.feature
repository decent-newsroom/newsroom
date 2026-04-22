Feature: NIP-09 Event Deletion Requests
  As a nostr user
  I want kind:5 deletion requests to remove or hide the targeted events
  So that deletions published by authors propagate into the client

  Background:
    Given the local database stores events, articles, highlights, and magazines
    And the application listens for kind:5 deletion requests

  Scenario: Deletion request removes a referenced event by id
    Given a note event with id "abc...123" authored by pubkey "PK1" is stored locally
    When a kind:5 event authored by "PK1" with an "e" tag referencing "abc...123" is ingested
    Then the event "abc...123" is removed from the local event store
    And a deletion tombstone for "abc...123" is recorded

  Scenario: Deletion request removes all versions of an addressable coordinate up to created_at
    Given two revisions of article "30023:PK1:my-slug" exist locally with created_at 100 and 200
    When a kind:5 event authored by "PK1" with an "a" tag "30023:PK1:my-slug" and created_at 250 is ingested
    Then both revisions are removed from the event store
    And the corresponding Article rows are removed
    And a coordinate tombstone for "30023:PK1:my-slug" is recorded with deletion_created_at 250

  Scenario: Deletion request with mismatched pubkey is ignored
    Given an event "xyz...789" authored by pubkey "PK1" is stored locally
    When a kind:5 event authored by "PK2" with an "e" tag referencing "xyz...789" is ingested
    Then the event "xyz...789" remains in the local event store
    And no tombstone is recorded for "xyz...789"

  Scenario: Deletion request targeting another kind:5 is a no-op
    Given a kind:5 event "del1" authored by "PK1" is stored locally
    When another kind:5 event authored by "PK1" with an "e" tag referencing "del1" is ingested
    Then "del1" remains in the local event store

  Scenario: Tombstone suppresses a later re-ingest of the deleted event
    Given a tombstone exists for event id "abc...123" by pubkey "PK1"
    When the event "abc...123" authored by "PK1" arrives from a relay
    Then it is not persisted to the local event store

  Scenario: Tombstone suppresses older revisions of a replaceable coordinate
    Given a coordinate tombstone exists for "30023:PK1:my-slug" with deletion_created_at 250
    When an article event at "30023:PK1:my-slug" with created_at 150 arrives
    Then it is not persisted to the local event store

  Scenario: Tombstone does NOT suppress a newer revision of a replaceable coordinate
    Given a coordinate tombstone exists for "30023:PK1:my-slug" with deletion_created_at 250
    When an article event at "30023:PK1:my-slug" with created_at 300 arrives
    Then it is persisted normally

  Scenario: Reason text is preserved in the tombstone
    When a kind:5 event with content "published by accident" references "abc...123"
    Then the recorded tombstone stores the reason "published by accident"

  Scenario: Longform article deletion cascades to the Article table
    Given an Article row with slug "my-slug" and pubkey "PK1" exists
    When a kind:5 deletion request by "PK1" with "a" tag "30023:PK1:my-slug" is ingested
    Then the Article row is removed

  Scenario: Highlight deletion cascades to the Highlight table
    Given a Highlight row with event id "hi...l1" and pubkey "PK1" exists
    When a kind:5 deletion request by "PK1" with "e" tag "hi...l1" is ingested
    Then the Highlight row is removed

  Scenario: Magazine (kind 30040) deletion cascades to the Magazine table
    Given a Magazine with slug "my-mag" authored by pubkey "PK1" exists
    When a kind:5 deletion request by "PK1" with "a" tag "30040:PK1:my-mag" is ingested
    Then the Magazine row is removed

