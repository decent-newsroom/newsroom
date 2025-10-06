Feature: NIP-05 Mapping Nostr Keys to DNS-based Internet Identifiers
  As a Nostr client
  I want to map Nostr public keys to DNS-based internet identifiers
  So that users can be identified by human-readable email-like addresses

  Background:
    Given the newsroom application is running
    And I have a valid Nostr keypair

  # ===== Verification Flow =====

  Scenario: Successful verification of NIP-05 identifier
    Given I see a kind 0 event with pubkey "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
    And the event content includes nip05 identifier "bob@example.com"
    When I split the identifier into local part "bob" and domain "example.com"
    And I make a GET request to "https://example.com/.well-known/nostr.json?name=bob"
    And the response contains:
      """json
      {
        "names": {
          "bob": "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
        }
      }
      """
    Then the pubkey should match the one in the names mapping
    And the NIP-05 identifier should be marked as valid
    And the identifier should be displayed to the user

  Scenario: Verification with relay information
    Given I see a kind 0 event with pubkey "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
    And the event content includes nip05 identifier "bob@example.com"
    When I make a GET request to "https://example.com/.well-known/nostr.json?name=bob"
    And the response contains relay information:
      """json
      {
        "names": {
          "bob": "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
        },
        "relays": {
          "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9": [
            "wss://relay.example.com",
            "wss://relay2.example.com"
          ]
        }
      }
      """
    Then the pubkey should match the one in the names mapping
    And the client should learn the user's preferred relays
    And the relay list should be saved for future connections

  # ===== User Discovery =====

  Scenario: Finding a user by NIP-05 identifier
    Given a user wants to find "bob@example.com"
    When I fetch "https://example.com/.well-known/nostr.json?name=bob"
    And the response contains pubkey "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
    Then I should fetch the kind 0 event for that pubkey
    And verify it has a matching nip05 field "bob@example.com"
    And suggest the user profile to the searcher

  Scenario: User search box implementation
    Given a search box is available in the client
    When a user types "bob@example.com"
    And the client recognizes the email-like format
    Then the client should perform NIP-05 lookup
    And retrieve the associated pubkey
    And display the user profile in search results

  # ===== Validation Rules =====

  Scenario: Valid local-part characters
    Given I have identifiers with various local parts
    When I validate "<identifier>"
    Then it should be "<valid_or_invalid>"

    Examples:
      | identifier              | valid_or_invalid |
      | bob@example.com         | valid            |
      | alice_123@example.com   | valid            |
      | user-name@example.com   | valid            |
      | test.user@example.com   | valid            |
      | User@Example.com        | valid            |
      | bob+tag@example.com     | invalid          |
      | bob space@example.com   | invalid          |
      | bob#hash@example.com    | invalid          |

  Scenario: Case-insensitive local-part matching
    Given I have identifier "Bob@Example.com"
    When I make a request to the well-known endpoint
    Then the query should be normalized to lowercase
    And "bob" should match "Bob" in the response

  # ===== Public Key Format =====

  Scenario: Public keys must be in hex format
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the response contains pubkey "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
    Then the pubkey should be in hex format
    And the pubkey should NOT be in npub format
    And the client should accept it for verification

  Scenario: Rejecting npub format in well-known response
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the response contains pubkey starting with "npub1"
    Then the client should reject the identifier as invalid
    And display an error about incorrect key format

  # ===== Root Domain Identifier =====

  Scenario: Displaying root identifier without redundancy
    Given I see a kind 0 event with nip05 identifier "_@bob.com"
    When the client processes the identifier
    Then it should display as "bob.com" only
    And treat it as the root identifier for the domain

  Scenario: Regular identifier display
    Given I see a kind 0 event with nip05 identifier "bob@bob.com"
    When the client processes the identifier
    Then it should display as "bob@bob.com"
    And not apply root identifier special handling

  # ===== Multiple NIP-05 Identifiers =====

  Scenario: User with multiple NIP-05 identifiers in tags
    Given I see a kind 0 event with pubkey "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9"
    And the event has a tag ["nip05", "bob@example.com", "bob@business.com", "bob@personal.org"]
    When the client processes the metadata
    Then all three identifiers should be collected into an array
    And each identifier should be verified independently
    And all verified identifiers should be displayed as badges

  Scenario: Displaying multiple verified NIP-05 badges
    Given I see a kind 0 event with multiple nip05 identifiers
    And "bob@example.com" verification succeeds
    And "bob@business.com" verification succeeds
    And "bob@personal.org" verification fails
    When the profile is displayed
    Then I should see a badge for "bob@example.com"
    And I should see a badge for "bob@business.com"
    And I should NOT see a badge for "bob@personal.org"

  Scenario: Multiple NIP-05 from tags override content field
    Given I see a kind 0 event with content field nip05 "old@example.com"
    And the event has a tag ["nip05", "bob@example.com", "bob@business.com"]
    When the client processes the metadata
    Then the nip05 field should be ["bob@example.com", "bob@business.com"]
    And "old@example.com" should be ignored
    And tags take priority over content

  Scenario: Single NIP-05 in content is converted to array
    Given I see a kind 0 event with only content field nip05 "bob@example.com"
    And the event has no nip05 tags
    When the client processes the metadata
    Then the nip05 field should be converted to array ["bob@example.com"]
    And it should be displayed consistently with multi-value cases

  Scenario: Duplicate NIP-05 values are removed
    Given I see a kind 0 event with tag ["nip05", "bob@example.com", "bob@example.com", "bob@business.com"]
    When the client processes the metadata
    Then duplicates should be removed
    And the nip05 field should contain ["bob@example.com", "bob@business.com"]
    And each identifier should only be verified once

  Scenario: Multiple lightning addresses in tags
    Given I see a kind 0 event with tag ["lud16", "bob@getalby.com", "bob@primal.net", "bob@wallet.com"]
    When the client processes the metadata
    Then all three addresses should be collected into an array
    And the profile about page should display "Lightning Addresses" (plural)
    And all addresses should be listed

  Scenario: Single lightning address displays singular form
    Given I see a kind 0 event with tag ["lud16", "bob@getalby.com"]
    When the client processes the metadata
    Then the lud16 field should be array ["bob@getalby.com"]
    And the profile about page should display "Lightning Address" (singular)

  # ===== Following and Key Management =====

  Scenario: Client must follow public keys, not identifiers
    Given I find that "bob@bob.com" has pubkey "abc...def"
    When the user follows this profile
    Then the client should store a reference to pubkey "abc...def"
    And NOT store a primary reference to "bob@bob.com"
    And use the NIP-05 identifier only for display

  Scenario: Handling identifier changes
    Given I am following pubkey "abc...def" displayed as "bob@bob.com"
    When "https://bob.com/.well-known/nostr.json?name=bob" starts returning pubkey "1d2...e3f"
    Then the client should continue following "abc...def"
    And stop displaying "bob@bob.com" for that user
    And mark the NIP-05 identifier as invalid

  # ===== Verification Failures =====

  Scenario: Pubkey mismatch in verification
    Given I see a kind 0 event with pubkey "abc...def"
    And the event content includes nip05 identifier "bob@example.com"
    When I fetch "https://example.com/.well-known/nostr.json?name=bob"
    And the response contains a different pubkey "xyz...123"
    Then the verification should fail
    And the NIP-05 identifier should not be displayed
    And the user should be shown without verification

  Scenario: Well-known endpoint not found
    Given I see a kind 0 event with nip05 identifier "bob@example.com"
    When I fetch "https://example.com/.well-known/nostr.json?name=bob"
    And the response is 404 Not Found
    Then the verification should fail
    And the identifier should be marked as unverified

  Scenario: Network timeout during verification
    Given I see a kind 0 event with nip05 identifier "bob@example.com"
    When I fetch "https://example.com/.well-known/nostr.json?name=bob"
    And the request times out
    Then the verification should fail gracefully
    And the client should retry after a reasonable delay
    And display the user without verification in the meantime

  # ===== CORS Support =====

  Scenario: Successful CORS-enabled request from JavaScript app
    Given a JavaScript Nostr app is running in a browser
    When I fetch "https://example.com/.well-known/nostr.json?name=bob"
    And the response includes header "Access-Control-Allow-Origin: *"
    Then the JavaScript app should successfully receive the response
    And complete the verification process

  Scenario: CORS policy blocking JavaScript request
    Given a JavaScript Nostr app is running in a browser
    When I fetch "https://example.com/.well-known/nostr.json?name=bob"
    And the server does not include CORS headers
    Then the browser should block the request
    And the app should see it as a network failure
    And recommend the user check their server's CORS policy

  # ===== Security Constraints =====

  Scenario: Rejecting HTTP redirects
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the server responds with a 301 or 302 redirect
    Then the client MUST ignore the redirect
    And the verification should fail
    And the identifier should be marked as invalid

  Scenario: Following redirect attempt should be blocked
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the server responds with redirect to "https://malicious.com/nostr.json"
    Then the client MUST NOT follow the redirect
    And MUST NOT make a request to the redirected URL
    And treat this as a verification failure

  # ===== Dynamic vs Static Server Support =====

  Scenario: Dynamic server with query string support
    Given the server generates JSON on-demand
    When I request "https://example.com/.well-known/nostr.json?name=bob"
    Then the server should return data specific to "bob"
    And optionally include relay information
    When I request "https://example.com/.well-known/nostr.json?name=alice"
    Then the server should return different data for "alice"

  Scenario: Static server with multiple names
    Given the server has a static nostr.json file
    When I request "https://example.com/.well-known/nostr.json?name=bob"
    Then the response should contain multiple names:
      """json
      {
        "names": {
          "bob": "b0635d6a9851d3aed0cd6c495b282167acf761729078d975fc341b22650b07b9",
          "alice": "1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef"
        }
      }
      """
    And the client should extract only the "bob" mapping

  # ===== Edge Cases =====

  Scenario: Empty response from well-known endpoint
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the response is empty or malformed JSON
    Then the verification should fail
    And log the error for debugging

  Scenario: Missing names field in response
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the response is valid JSON but missing the "names" field
    Then the verification should fail
    And the identifier should not be displayed

  Scenario: Name not found in response
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the response contains names but not "bob"
    Then the verification should fail
    And the identifier should be marked as invalid

  Scenario: Invalid hex format in response
    Given I fetch "https://example.com/.well-known/nostr.json?name=bob"
    When the response contains pubkey with invalid hex characters
    Then the verification should fail
    And log a format error
