Feature: Raw-event presentations for the event workspace
  As a reader
  I want an event preview and its references to come from signed event data
  So that they do not depend on an article projection or rendered HTML

  Scenario: A long-form event can be presented without an article projection
    Given a readable kind:30023 event has a title and summary in its tags
    And no Article row or rendered body is available
    When the event is prepared for presentation
    Then its identity, title, and summary remain available
    And no relay lookup or persistence is needed

  Scenario: An unknown event with an empty body uses its alt tag
    Given a readable event of an unknown kind has an empty body and an "alt" tag
    And it has no "summary" or "description" tag
    When the event is prepared for presentation
    Then the alt text is available as its description
    And the event kind and identity remain available

  Scenario: Reference occurrences retain order and duplicates
    Given an event contains two equal reference tags and one malformed reference tag
    When its references are enumerated
    Then both occurrences retain their original tag positions and order
    And the malformed reference remains visible as invalid

  Scenario: Exact event references are distinct from address references
    Given a publication index contains an "e" reference and an "a" reference
    When its references are enumerated
    Then the "e" reference retains the exact event id
    And the "a" reference retains the addressable coordinate

  Scenario: A note preserves the root and relay in the alternate NIP-10 layout
    Given a kind:1 note has an "e" tag with "root" before its relay hint
    When its references are enumerated
    Then the reference is identified as the root
    And its relay hint is retained without treating "root" as a relay

  Scenario: A report classification is not a relay hint
    Given a kind:1984 report has a report type in an "e" or "p" tag
    When its references are enumerated
    Then the report type is not interpreted as a relay hint

  Scenario: A supporting report reference retains its relay hint
    Given a kind:1984 profile report includes an "e" reference to supporting evidence
    And the evidence reference includes a WebSocket relay hint
    When its references are enumerated
    Then the evidence reference retains that relay hint

  Scenario: A note retains a mixed-case WebSocket relay scheme
    Given a kind:1 note has a thread reference with a relay hint using "WSS"
    When its references are enumerated
    Then the original relay hint remains available

  Scenario: A publication reference retains its version binding
    Given a kind:30040 index contains an "a" tag with an event id in its version position
    When its references are enumerated
    Then the coordinate and version event id are retained separately

  Scenario: A follow list identifies its public members
    Given a kind:3 follow list has public "p" tags
    When its references are enumerated
    Then the "p" references are identified as followed profiles
    And a "p" tag on an unrelated kind is not assumed to be a follow

  Scenario: A follow pack identifies members without following them
    Given a kind:39089 follow pack has public "p" tags
    When its references are enumerated
    Then the "p" references are identified as pack members in their original order
    And they are not treated as profiles the viewer already follows

  Scenario: The graph indexes profile occurrences without resolving them
    Given a kind:3 follow list has repeated lowercase "p" tags and an uppercase "P" tag
    When the graph indexes its public references
    Then each valid lowercase occurrence retains its source tag position
    And the profile relationships are non-structural follows
    And the uppercase tag is not indexed as a lowercase profile reference

  Scenario: Encrypted list contents are not used as a text excerpt
    Given a NIP-51 list contains encrypted private entries in its content
    When the event is prepared for presentation
    Then its encrypted content is not displayed as a description

  Scenario: A zap receipt does not expose its embedded request as a description
    Given a kind:9735 receipt has a JSON-encoded zap request in its "description" tag
    When the generic preview is rendered
    Then the encoded request is not displayed as description text

  Scenario: A sensitive preview hides content by default
    Given a readable event has a content-warning or NSFW tag
    When the generic preview is rendered
    Then its kind and event identity remain visible
    And its title, description, body, and media are not displayed

  Scenario: Warning evidence takes precedence over inconsistent preview flags
    Given a presentation has a signed content-warning tag or a warning reason
    And its sensitivity flags incorrectly say the event is not sensitive
    When the generic preview is rendered
    Then its title and description are not displayed

  Scenario: A qualified NSFW label hides a preview without a namespace tag
    Given an event has an "l" tag qualified by the "nsfw" namespace
    And the event has no separate "L" namespace tag
    When the generic preview is rendered
    Then its title and description are not displayed
