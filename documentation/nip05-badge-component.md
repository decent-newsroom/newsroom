# NIP-05 Badge Component

## Overview

The NIP-05 Badge component is a live Twig component that verifies NIP-05 identifiers (DNS-based internet identifiers for Nostr keys) and displays them as a verified badge when the verification succeeds.

## Features

- ✅ **Automatic verification**: Fetches and validates `.well-known/nostr.json` from the specified domain
- ✅ **Security compliant**: Follows NIP-05 security constraints (no redirect following)
- ✅ **Cached results**: Verification results are cached for 1 hour to reduce network requests
- ✅ **Relay discovery**: Extracts and stores relay information when available
- ✅ **Display formatting**: Automatically formats root identifiers (`_@domain.com` → `domain.com`)
- ✅ **Graceful failures**: Shows nothing when verification fails (no badge displayed)
- ✅ **Character validation**: Only accepts valid NIP-05 local-part characters (a-z0-9-_.)

## Usage

### Basic Usage

```twig
{# In any Twig template #}
<twig:Atoms:Nip05Badge 
    nip05="{{ author.nip05 }}" 
    pubkeyHex="{{ author.pubkey }}" 
/>
```

### With Author Metadata

```twig
<div class="author-info">
    <strong>{{ author.name ?? 'Anonymous' }}</strong>
    {% if author.nip05 is defined and author.pubkey is defined %}
        <twig:Atoms:Nip05Badge 
            nip05="{{ author.nip05 }}" 
            pubkeyHex="{{ author.pubkey }}" 
        />
    {% endif %}
</div>
```

### In a Card Component

```twig
<div class="profile-card">
    <img src="{{ author.image }}" alt="{{ author.name }}" />
    <div class="profile-info">
        <h3>{{ author.name }}</h3>
        <twig:Atoms:Nip05Badge 
            nip05="{{ author.nip05 }}" 
            pubkeyHex="{{ author.pubkey }}" 
        />
    </div>
</div>
```

## Props

| Prop | Type | Required | Description |
|------|------|----------|-------------|
| `nip05` | `string` | Yes | The NIP-05 identifier (e.g., "bob@example.com") |
| `pubkeyHex` | `string` | Yes | The public key in hex format (64 characters) |

## Verification Process

The component performs the following verification steps:

1. **Validates identifier format**: Checks that the local part contains only `a-z0-9-_.`
2. **Splits identifier**: Extracts local part and domain from the identifier
3. **Fetches well-known document**: Makes a GET request to `https://<domain>/.well-known/nostr.json?name=<local-part>`
4. **Rejects redirects**: Any HTTP redirect causes verification to fail (NIP-05 security requirement)
5. **Validates response**: Checks for valid JSON with required `names` field
6. **Matches public key**: Compares the returned pubkey with the expected pubkey
7. **Validates hex format**: Ensures pubkey is in hex format (not npub)
8. **Extracts relays**: Optionally retrieves relay information if present

## Display Behavior

### When Verified ✅
Shows a green badge with a checkmark icon and the identifier:
- Regular identifier: `bob@example.com`
- Root identifier: `_@example.com` displays as `example.com`
- Tooltip shows relay count when available

### When Not Verified ❌
Shows nothing (no badge rendered)

## Cache Behavior

- Verification results are cached for **1 hour** in Redis
- Cache key format: `nip05_{md5(identifier)}`
- Failed verifications are also cached to prevent repeated failed requests

## Security Features

- ✅ **No redirect following**: HTTP redirects are blocked per NIP-05 spec
- ✅ **Timeout protection**: 5-second timeout on HTTP requests
- ✅ **Format validation**: Strict validation of identifier format
- ✅ **Hex-only pubkeys**: Rejects npub format keys
- ✅ **Case-insensitive matching**: Handles case variations properly

## Styling

The component includes built-in styles that can be customized:

```css
.nip05-badge {
    /* Green badge with checkmark */
    background-color: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: rgb(22, 163, 74);
}

.nip05-identifier {
    /* Truncates long identifiers */
    max-width: 200px;
    text-overflow: ellipsis;
}
```

## Service Layer

The `Nip05VerificationService` can also be used independently:

```php
use App\Service\Nip05VerificationService;

class MyController
{
    public function __construct(
        private Nip05VerificationService $nip05Service
    ) {}
    
    public function verifyIdentifier(string $nip05, string $pubkey): void
    {
        $result = $this->nip05Service->verify($nip05, $pubkey);
        
        if ($result['verified']) {
            // Identifier is verified!
            $relays = $result['relays']; // Available relay URLs
        }
    }
}
```

## Testing

Comprehensive test scenarios are defined in `tests/NIPs/NIP-05.feature` covering:
- Successful verification flows
- User discovery
- Validation rules
- Security constraints
- Edge cases and error handling

## Examples

### Valid Identifiers
- `bob@example.com`
- `alice_123@example.com`
- `user-name@example.com`
- `test.user@example.com`
- `_@example.com` (root identifier)

### Invalid Identifiers
- `bob+tag@example.com` (+ not allowed)
- `bob space@example.com` (spaces not allowed)
- `bob#hash@example.com` (# not allowed)
- `npub1...` (not an identifier format)

## Troubleshooting

### Badge not showing
- Check that both `nip05` and `pubkeyHex` props are provided
- Verify the pubkey is in **hex format** (64 characters), not npub format
- Check logs for verification failures
- Ensure the domain serves `.well-known/nostr.json` with CORS headers

### CORS Issues
The server must return:
```
Access-Control-Allow-Origin: *
```

### Verification failures
Check application logs for specific error messages:
- Invalid identifier format
- Missing names field
- Pubkey mismatch
- Invalid hex format
- Network timeout

## Related Documentation

- [NIP-05 Specification](https://github.com/nostr-protocol/nips/blob/master/05.md)
- [Test Definitions](../../../tests/NIPs/NIP-05.feature)

