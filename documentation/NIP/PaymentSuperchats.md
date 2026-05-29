Payment Superchats
======

Payto and Zaps
--------------

This spec defines the method for notifying another npub that you have made a monetary payment to one of their payto URI addresses or their zap wallet. It also defines the method for the recipient to attest to the receipt of the payment or zap. The attested-to payment notification or zap receipt can then be rendered in threads as superchats.

The payment notification can address the user's profile (and be rendered on their profile wall) or a note that they published (and be rendered in the related thread as a promoted response). If there is no event listed as a reference, assume the profile was the reference.

### Implementation Suggestions

Instead of rendering the payment type or amount, superchats might be prominently displayed at the top of the appropriate thread (note or wall), sorted from highest amount to lowest amount. They can also be classified and highlighted according to amount (gold, silver, bronze status, for instance). Alternatively, the amount could affect how long the superchat remains at the top of the thread before sinking into the normal reply section or disappearing.

## Primary payto flow

1. The recipient has listed a Lightning wallet or other payment target on their `kind 0` profile event and/or `kind 10133` payment target event. (Or has otherwise communicated the relevant target address to the sender.)
2. The sender then pays money toward that payment target.
3. The sender publishes a `kind 9740` payment notification, addressed to the recipient, signifying that they have paid a particular sum of money to some particular payto address in relation to some particular event. They also include a comment that they would like to have rendered in the thread as a superchat.
4. If the recipient wishes to confirm this payment and see the notification published, they attest that they have received the payment by publishing a `kind 9741` payment attestation addressing the notification. They can add PoW to the attestation to stress its authenticity. 
6. The comment that was included appears as a superchat under the referenced event.

### Alternative zap flow

1. The recipient has listed a zap-enabled wallet on their `kind 0` profile event.
2. The sender zaps them on that wallet with a `kind 9734` and requests the Lightning node publish a `kind 9735` zap receipt, according to [[NIP-57]].
3. The recipient receives the zap receipt and wishes to have the message published in the related thread as a superchat.
4. They attest that they have received the payment, same as in the primary flow above.

## Examples

### Payment Notification Event

A `payment notification` is an event of kind `9740` that a sender of payment sends to the recipient. The event MUST include the following tags:

- `amount` is the value of the payment, in _millisats_, formatted as a string. If the money paid was not Bitcoin, it will be approximate. This is recommended, but optional.
- `payto` is the payment target address that the payment was sent to. The "payto://" prefix SHOULD be left off. This is recommended, but optional.
- `p` is the hex-encoded pubkey of the recipient.

In addition, the event MAY include the following tags:

- `e` is an optional hex-encoded event ID. Clients MUST include this if zapping an event rather than a person.
- `a` is an optional event coordinate that allows tipping addressable events such as NIP-23 long-form notes.
- `k` is the stringified kind of the target event.

Examples:

```json
{
  "kind": 9740,
  "content": "Thank you for this article. I run a rescue mission, so I also have a passion for helping housepets in need. We need more awareness about this.",
  "tags": [
    ["amount", "21000"],
    ["payto", "lightning/bob%40primal.net"],
    ["p", "f07e0b1af066b2102730273400a1a2cbb374429a9fbaab593027f3fcd3bd3b5c367"],
    ["e", "9ae37aa68f48645127299e9453eb5d908a0cbb6058ff340d528ed4d37c8994fb"],
    ["k", "1"]
  ],
  "pubkey": "97c70a44366a6535c145b333f973ea86dfdc2d7a99da618c40c64705ad98e322",
  "created_at": 1679673265,
  "id": "30efed56a035b2549fcaeec0bf2c1595f9a9b3bb4b1a38abaf8ee9041c4b7d93",
  "sig": "f2cb581a84ed10e4dc84937bd98e27acac71ab057255f6aa8dfa561808c981fe8870f4a03c1e3666784d82a9c802d3704e174371aa13d63e2aeaf24ff5374d9d"
}
```

```json
{
  "kind": 9740,
  "content": "I'm so glad to see that you are feeling better!",
  "tags": [
    ["amount", "100000"],
    ["payto", "monero/47R4NpvudmrLkLxaf4Uyiq56weFDZko1KeFrY5qUgnJ95X3D1YWYRVASAnMLBgpB5BeSViAVaxLXuFDuup15j3f45NC2WUp"],
    ["p", "f07e0b1af066b2102730273400a1a2cbb374429a9fbaab593027f3fcd3bd3b5c367"],
    ["a", "30023:97c70a44366a6535c145b333f973ea86dfdc2d7a99da618c40c64705ad98e322:slowly-back-on-my-feet"],
    ["k", "30023"]
  ],
  "pubkey": "97c70a44366a6535c145b333f973ea86dfdc2d7a99da618c40c64705ad98e322",
  "created_at": 1679673265,
  "id": "30efed56a035b2549fcaeec0bf2c1595f9a9b3bb4b1a38abaf8ee9041c4b7d93",
  "sig": "f2cb581a84ed10e4dc84937bd98e27acac71ab057255f6aa8dfa561808c981fe8870f4a03c1e3666784d82a9c802d3704e174371aa13d63e2aeaf24ff5374d9d"
}
```

### Payment Attestation Event

A `payment attestation` is an event of kind `9741` that a recipient of payment publishes. The event MUST include the following tags, derived from the payment notification or from the zap receipt:

- `e` is the original payment notification event ID or zap receipt event ID, hex-encoded.
- `k` is the stringified kind of the target event, in this case always "9740" (payment notification) or "9735" (zap receipt).

In addition, the event MAY include the following tags:

- `nonce` is evidence of proof of work; see [[NIP-13]].

Examples:

_Attestion of a payment notification received_

```json
{
  "kind": 9741,
  "content": "",
  "tags": [
    ["e", "9ae37aa68f48645127299e9453eb5d908a0cbb6058ff340d528ed4d37c8994fb"],
    ["k", "9740"]
    ["nonce", "776797", "20"]
  ],
  "pubkey": "97c70a44366a6535c145b333f973ea86dfdc2d7a99da618c40c64705ad98e322",
  "created_at": 1679673265,
  "id": "30efed56a035b2549fcaeec0bf2c1595f9a9b3bb4b1a38abaf8ee9041c4b7d93",
  "sig": "f2cb581a84ed10e4dc84937bd98e27acac71ab057255f6aa8dfa561808c981fe8870f4a03c1e3666784d82a9c802d3704e174371aa13d63e2aeaf24ff5374d9d"
}
```

_Attestion of a zap receipt received_

```json
{
  "kind": 9741,
  "content": "",
  "tags": [
    ["e", "9ae37aa68f48645127299e9453eb5d908a0cbb6058ff340d528ed4d37c8994fb"],
    ["k", "9735"]
  ],
  "pubkey": "97c70a44366a6535c145b333f973ea86dfdc2d7a99da618c40c64705ad98e322",
  "created_at": 1679673265,
  "id": "30efed56a035b2549fcaeec0bf2c1595f9a9b3bb4b1a38abaf8ee9041c4b7d93",
  "sig": "f2cb581a84ed10e4dc84937bd98e27acac71ab057255f6aa8dfa561808c981fe8870f4a03c1e3666784d82a9c802d3704e174371aa13d63e2aeaf24ff5374d9d"
}
```