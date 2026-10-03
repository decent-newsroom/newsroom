Feature: Public reader interactions on hosted Unfold content
  A signed public publication index determines content membership.
  Readers use their own Nostr identities, not publication-owner privileges.

  Scenario Outline: Comment on each supported addressable content kind
    Given a registered Unfold publication contains public kind <kind> content
    And the content is available in local Article or Event storage
    And the reader is authenticated with a matching Nostr signer
    When the reader signs and submits a kind 1111 comment
    Then uppercase A, K and P tags identify the content root
    And lowercase a, e, k and p tags identify the referenced content revision
    And the verified comment is stored locally before relay delivery
    And queued delivery is not reported as published

    Examples:
      | kind  |
      | 30023 |
      | 30041 |
      | 30818 |
      | 30817 |

  Scenario: Reply preserves the content root
    Given a locally stored comment belongs to this publication content thread
    When the reader signs a reply to that comment
    Then uppercase root tags still identify the publication content
    And lowercase e, k and p tags identify the parent comment
    And a foreign or conflicting parent is rejected before persistence

  Scenario: Positive reactions remain stable across content revisions
    Given a reader has already liked an addressable content coordinate
    When the content is revised or the same reaction is ingested again
    Then the reader's like remains active
    And the distinct-reader count does not increase

  Scenario: Reposts use the non-note protocol
    Given public addressable content has an available original signed event
    When the reader confirms and signs a repost
    Then the event kind is 16, not 6
    And e, a, p and k tags identify the original event and coordinate
    And the original signed event is embedded without reconstructing its content
    But a NIP-70-protected original has empty repost content

  Scenario: Gateway failure does not block existing discussion reads
    Given comments and reactions are already stored locally
    And all external relays are unavailable
    When a reader opens the discussion or their own interaction state
    Then no relay request runs synchronously
    And existing locally stored interactions remain visible

  Scenario: Relay failure preserves accepted actions and retry identity
    Given an interaction and its delivery job were committed locally
    When all selected relays reject the event
    Then delivery is explicitly failed, not published
    And retry uses the same signed event ID
    And no duplicate local interaction is created

  Scenario: Queued work cannot publish newly scoped content
    Given a public interaction is queued for relay delivery
    When its source becomes scoped or is removed from the publication
    Then the worker rejects delivery before contacting relays
    And no broader content lookup or public fan-out occurs

  Scenario: Reader state is private and writes require identity and CSRF
    Given two readers view the same publication content
    Then public content contains neither reader's private interaction state
    And own-state responses are private and not stored in shared caches
    And anonymous writes, mismatched signers and invalid CSRF tokens are rejected
