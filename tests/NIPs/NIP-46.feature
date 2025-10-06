Feature: NIP-46 Remote Signing (Nostr Connect)
  As a Nostr user
  I want to use a remote signer (bunker) to sign on my behalf
  So that my private keys remain on a minimal, secure surface while clients interact safely

  Background:
    Given the newsroom application is running
    And a Nostr test relay is available
    And I have a client keypair with public key "eff37350d839ce3707332348af4549a96051bd695d3223af4aabce4993531d86"
    And a remote-signer-pubkey "fa984bd7dbb282f07e16e7ae87b26a2a7b9b90b7246a44771f0cf5ae58018f52"
    And a user-pubkey "fa984bd7dbb282f07e16e7ae87b26a2a7b9b90b7246a44771f0cf5ae58018f52"

  # ===== Connection Initiation =====

  Scenario: Client-initiated connection using nostrconnect URI
    Given I construct a nostrconnect URI with:
      | Field  | Value                                                                                                                |
      | origin | nostrconnect://eff37350d839ce3707332348af4549a96051bd695d3223af4aabce4993531d86                                      |
      | relay  | wss://relay1.example.com                                                                                             |
      | relay  | wss://relay2.example2.com                                                                                            |
      | secret | 0s8j2djs                                                                                                            |
      | perms  | nip44_encrypt,nip44_decrypt,sign_event:13,sign_event:14,sign_event:1059                                             |
      | name   | My Client                                                                                                           |
    When the client sends a kind 24133 request event "connect" encrypted with NIP-44 and p-tagged to the remote-signer-pubkey
    Then I should receive a kind 24133 response from the remote-signer-pubkey p-tagged to the client pubkey
    And the response content should decrypt to a JSON with the same id
    And the result should equal the provided secret "0s8j2djs"

  Scenario: Remote-signer-initiated connection using bunker URL
    Given the remote signer provides token:
      | bunker_url | bunker://fa984bd7dbb282f07e16e7ae87b26a2a7b9b90b7246a44771f0cf5ae58018f52?relay=wss://relay.example.com&secret=abc123 |
    When the client sends a kind 24133 request event "connect" with optional secret "abc123"
    Then the remote signer should respond with a kind 24133 "ack" (or secret echo) addressed to the client pubkey

  Scenario: Secret validation prevents spoofed connections
    Given I initiate a connection with secret "nonce-secret-1"
    And the remote signer responds with a different secret "nonce-secret-2"
    Then the client must reject the connection due to secret mismatch

  Scenario: Secret is single-use
    Given I successfully establish a connection using secret "one-time-nonce"
    When I attempt to establish another connection using the same secret "one-time-nonce"
    Then the remote signer should ignore or reject the new connection attempt

  # ===== Key roles and discovery =====

  Scenario: Client distinguishes remote-signer-pubkey from user-pubkey
    Given the remote signer completes the handshake
    When the client calls method "get_public_key"
    Then the response should contain the user-pubkey
    And the client must not assume user-pubkey == remote-signer-pubkey without calling get_public_key

  Scenario: Client keypair integrity check
    Given the client generated a local client keypair
    And the nostrconnect URI origin includes the client-pubkey
    Then the derived pubkey from the local secret must match the URI origin pubkey

  # ===== Methods and Permissions =====

  Scenario: Permissions requested during connect
    Given the client requested permissions "nip44_encrypt,sign_event:4"
    When the remote signer presents an approval UI to the user
    And the user approves the requested permissions
    Then subsequent calls to nip44_encrypt and sign_event with kind 4 should succeed

  Scenario: Permission denied for unapproved method
    Given the client requested permissions "sign_event:14"
    And the user approved only "sign_event:14"
    When the client attempts to call method "nip44_encrypt"
    Then the remote signer should return an error "permission denied"

  Scenario: Permission denied for disallowed kind
    Given the client requested permissions "sign_event:1"
    And the user approved only "sign_event:1"
    When the client attempts to call method "sign_event" with kind 7
    Then the remote signer should return an error "permission denied"

  # ===== Signing Flow (Happy Path) =====

  Scenario: Sign event via remote signer (happy path)
    Given there is an established connection between client and remote signer
    And the client knows the user-pubkey via get_public_key
    When the client sends a kind 24133 request with method "sign_event" and params:
      | Field      | Value                                |
      | kind       | 1                                    |
      | content    | Hello, I'm signing remotely          |
      | tags       | []                                   |
      | created_at | current timestamp                    |
    Then the remote signer returns a kind 24133 response to the client pubkey
    And the response contains the same id and a signed_event in result
    And the signed_event must verify against the user-pubkey

  # ===== Other Methods =====

  Scenario: Ping/Pong
    Given there is an established connection
    When the client sends method "ping"
    Then the remote signer should respond with result "pong"

  Scenario Outline: NIP-04 and NIP-44 encrypt/decrypt
    Given there is an established connection
    When the client calls "<method>" with parameters "<params>"
    Then the remote signer should return a valid "<result_type>"

    Examples:
      | method         | params                                 | result_type        |
      | nip04_encrypt  | [third_party_pubkey, plaintext]         | nip04_ciphertext   |
      | nip04_decrypt  | [third_party_pubkey, nip04_ciphertext]  | plaintext          |
      | nip44_encrypt  | [third_party_pubkey, plaintext]         | nip44_ciphertext   |
      | nip44_decrypt  | [third_party_pubkey, nip44_ciphertext]  | plaintext          |

  # ===== Error Handling and Security =====

  Scenario Outline: Connection and messaging validation failures
    Given there is a pending or established connection
    When I send a request with "<invalid_condition>"
    Then I should receive an error "<error_message>"

    Examples:
      | invalid_condition                 | error_message                             |
      | missing p tag                     | missing required p tag                    |
      | response missing or wrong id      | response id mismatch                      |
      | response not addressed to client  | p tag does not contain client pubkey      |
      | wrong author on response          | response not from remote-signer-pubkey    |
      | invalid NIP-44 ciphertext         | decryption failed                         |
      | malformed JSON-RPC payload        | invalid request payload                   |
      | missing method field              | missing required field                     |
      | unknown method                    | method not supported                      |
      | replayed request id               | duplicate or stale request id             |

  Scenario: Auth challenge flow
    Given there is an established connection
    And the remote signer requires out-of-band authentication
    When the client sends method "sign_event"
    Then the remote signer first responds with result "auth_url" and error "https://remote.example.com/auth?id=<request_id>"
    And the client opens the URL for the user and waits for completion
    And the remote signer later responds again with the same id and a valid signed_event in result

  # ===== Interop and Discovery =====

  Scenario: Remote signer metadata via NIP-05 and NIP-89
    Given the remote signer publishes nip05 and nip89 metadata announcing relays and nostrconnect_url
    When the client discovers the remote signer
    Then the client may pre-generate nostrconnect URIs and must verify nip05 names includes the remote signer app pubkey

  # ===== Regressions: Removed legacy features =====

  Scenario: NIP-05 login is removed
    Given a client attempts legacy nip05 login flow
    Then the server should reject it and instruct to use NIP-46 remote signing

  Scenario: create_account moved to another NIP
    Given there is an established connection
    When the client calls method "create_account"
    Then the remote signer should return an error "method not supported"
