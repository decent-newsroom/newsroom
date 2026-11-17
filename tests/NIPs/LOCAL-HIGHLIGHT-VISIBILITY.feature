Feature: Guest visibility of highlight sources
  Anonymous readers should only see a highlight when its referenced source is
  available for preview from the local database.

  Scenario: A guest sees a highlight with a locally readable source
    Given a highlight references a locally stored readable event
    When an anonymous reader opens a highlight feed
    Then the highlight is displayed with its source

  Scenario: A guest does not see a highlight with a missing source
    Given a highlight references an event missing from the local database
    When an anonymous reader opens a highlight feed
    Then the highlight is omitted

  Scenario: An exact reference does not fall forward to a coordinate revision
    Given a highlight references a missing event by its "e" tag
    And the highlight also references a locally stored newer revision by its "a" tag
    When an anonymous reader opens a highlight feed
    Then the highlight is omitted

  Scenario: A guest cannot open a highlight whose source is missing
    Given a highlight references an event missing from the local database
    When an anonymous reader opens the highlight event page
    Then the response is not found

  Scenario: A logged-in reader keeps the existing missing-source behavior
    Given a highlight references an event missing from the local database
    When a logged-in reader opens a highlight feed
    Then the highlight remains visible

  Scenario: An embedded highlight is checked when cached content is rendered
    Given cached content embeds a highlight whose source is missing locally
    When an anonymous reader opens that content
    Then the highlight is omitted
    When its source is stored locally
    And the same cached content is rendered for an anonymous reader
    Then the highlight is displayed

  Scenario: A missing source hides a quoted highlight but not its reply
    Given a reply has a highlight as its root event
    And the highlight source is missing from the local database
    When an anonymous reader opens the reply
    Then the reply is displayed without the highlight card
