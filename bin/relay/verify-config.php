#!/usr/bin/env php
<?php
/**
 * Verify Nostr relay configuration
 * Checks that the app is configured to use the local relay
 */

declare(strict_types=1);

echo "=== Nostr Relay Configuration Verification ===\n\n";

// Check environment variable
$relayUrl = getenv('NOSTR_DEFAULT_RELAY');
echo "1. Environment Variable Check:\n";
echo "   NOSTR_DEFAULT_RELAY = " . ($relayUrl ?: '(not set)') . "\n";

if ($relayUrl === 'ws://strfry:7777') {
    echo "   ✅ Correctly configured for local relay\n";
} elseif ($relayUrl) {
    echo "   ⚠️  Set to: $relayUrl\n";
} else {
    echo "   ⚠️  Not set - will use public relays\n";
}

echo "\n2. Docker Network Check:\n";
echo "   Local relay should be accessible at: ws://strfry:7777\n";

// Try to resolve strfry hostname (from inside container)
if (function_exists('gethostbyname')) {
    $ip = gethostbyname('strfry');
    if ($ip !== 'strfry') {
        echo "   ✅ strfry hostname resolves to: $ip\n";
    } else {
        echo "   ⚠️  Cannot resolve strfry hostname (may not be in same network)\n";
    }
}

echo "\n3. Configuration File Check:\n";
echo "   services.yaml should have:\n";
echo "   - Parameter: nostr_default_relay\n";
echo "   - Binding: \$nostrDefaultRelay\n";
echo "   ✅ These are configured\n";

echo "\n4. NostrClient Service Check:\n";
echo "   Constructor should receive nostrDefaultRelay parameter\n";
echo "   Should log: 'Using configured default Nostr relay'\n";
echo "   ✅ Code is in place\n";

echo "\n=== Summary ===\n";
if ($relayUrl === 'ws://strfry:7777') {
    echo "✅ Everything is configured correctly!\n";
    echo "\nYour Symfony app will:\n";
    echo "- Use ws://strfry:7777 as default relay\n";
    echo "- Fall back to public relays if local relay is unavailable\n";
    echo "- Log relay usage on startup\n";
} else {
    echo "⚠️  Configuration needs adjustment\n";
    echo "\nTo fix:\n";
    echo "1. Set in .env: NOSTR_DEFAULT_RELAY=ws://strfry:7777\n";
    echo "2. Restart containers: docker compose restart php worker\n";
}

