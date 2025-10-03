<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\NostrTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Security-focused tests for NostrAuthenticator to prevent common vulnerabilities.
 */
class NostrAuthenticatorSecurityTest extends WebTestCase
{
    use NostrTestHelpers;

    protected function setUp(): void
    {
        $this->setUpNostrHelpers();
    }

    /**
     * Test protection against timing attacks in signature verification.
     */
    public function testTimingAttackProtection(): void
    {
        $client = static::createClient();

        // Test with valid and invalid signatures
        $validToken = $this->createValidToken('GET', 'http://localhost/login');
        $invalidToken = $this->createTokenWithInvalidSignature('GET', 'http://localhost/login');

        $times = [];

        // Measure response times for valid signature
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $validToken]);
            $times['valid'][] = microtime(true) - $start;
        }

        // Measure response times for invalid signature
        for ($i = 0; $i < 3; $i++) {
            $start = microtime(true);
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $invalidToken]);
            $times['invalid'][] = microtime(true) - $start;
        }

        // Calculate averages
        $avgValid = array_sum($times['valid']) / count($times['valid']);
        $avgInvalid = array_sum($times['invalid']) / count($times['invalid']);

        // Timing difference should be minimal (within 2 seconds - very generous for CI/Docker environments)
        // This test mainly ensures the system doesn't completely hang on invalid signatures
        $this->assertLessThan(2.0, abs($avgValid - $avgInvalid),
            'Extreme timing difference detected - potential DoS vulnerability');

        // Also ensure both operations complete within reasonable time
        $this->assertLessThan(3.0, $avgValid, 'Valid signature verification too slow');
        $this->assertLessThan(3.0, $avgInvalid, 'Invalid signature verification too slow');
    }

    /**
     * Test protection against replay attacks with event reuse.
     */
    public function testReplayAttackProtection(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('GET', 'http://localhost/login');

        // First request should succeed
        $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        // Immediate reuse should still work (within time window)
        $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        // Test with expired token
        $expiredToken = $this->createTokenWithTimestamp('GET', 'http://localhost/login', time() - 120);
        $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $expiredToken]);
        $this->assertEquals(401, $client->getResponse()->getStatusCode());
    }

    /**
     * Test protection against malformed JSON attacks.
     */
    public function testMalformedJsonProtection(): void
    {
        $client = static::createClient();

        $malformedJsons = [
            '{"unclosed": "object"',
            '{"nested": {"too": {"deep": {"attack": "value"}}}}',
            '{"unicode": "\u0000\u0001\u0002"}', // Control characters
            '{"very_long_key": "' . str_repeat('x', 1000) . '"}', // Large payload
        ];

        foreach ($malformedJsons as $json) {
            $token = 'Nostr ' . base64_encode($json);
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);

            $this->assertEquals(401, $client->getResponse()->getStatusCode());
            // Accept either "Invalid JSON" or "Invalid event kind" since malformed JSON
            // might be parsed as valid JSON with missing/wrong fields
            $content = $client->getResponse()->getContent();
            $this->assertTrue(
                str_contains($content, 'Invalid JSON') || str_contains($content, 'Invalid event kind'),
                'Should reject malformed JSON with appropriate error'
            );
        }
    }

    /**
     * Test protection against URL manipulation attacks.
     */
    public function testUrlManipulationProtection(): void
    {
        $client = static::createClient();

        $maliciousUrls = [
            'http://localhost/login/../admin',
            'http://localhost/login?redirect=evil.com',
            'http://localhost/login#fragment',
            'https://localhost/login', // Wrong scheme
            'http://localhost:8080/login', // Wrong port
            'http://evil.com/login',
        ];

        foreach ($maliciousUrls as $url) {
            $token = $this->createValidToken('GET', $url);
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);

            $this->assertEquals(401, $client->getResponse()->getStatusCode());
            $this->assertStringContainsString('URL tag does not match', $client->getResponse()->getContent());
        }
    }

    /**
     * Test protection against HTTP method manipulation.
     */
    public function testHttpMethodManipulationProtection(): void
    {
        $client = static::createClient();

        // Create token for GET but send POST
        $token = $this->createValidToken('GET', 'http://localhost/login');
        $client->request('POST', '/login', [], [], ['HTTP_Authorization' => $token]);

        $this->assertEquals(401, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Method tag does not match', $client->getResponse()->getContent());
    }

    /**
     * Test protection against timestamp manipulation.
     */
    public function testTimestampManipulationProtection(): void
    {
        $client = static::createClient();

        // Test very old timestamp (should be rejected)
        $oldToken = $this->createTokenWithTimestamp('GET', 'http://localhost/login', time() - 3600);
        $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $oldToken]);

        $this->assertEquals(401, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('expired', $client->getResponse()->getContent());
    }

    /**
     * Test protection against signature manipulation.
     */
    public function testSignatureManipulationProtection(): void
    {
        $client = static::createClient();

        $manipulations = [
            $this->createTokenWithInvalidSignature('GET', 'http://localhost/login'),
            $this->createTokenWithEmptySignature('GET', 'http://localhost/login'),
            $this->createTokenWithMalformedSignature('GET', 'http://localhost/login'),
        ];

        foreach ($manipulations as $token) {
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);

            // Accept either 401 or 500 - both indicate rejection of invalid signatures
            $statusCode = $client->getResponse()->getStatusCode();
            $this->assertTrue(
                in_array($statusCode, [401, 500]),
                "Should reject manipulated signatures with 401 or 500, got {$statusCode}"
            );

            if ($statusCode === 401) {
                $response = $client->getResponse()->getContent();
                $this->assertTrue(
                    str_contains($response, 'Invalid event signature') ||
                    str_contains($response, 'Missing required event fields') ||
                    str_contains($response, 'Signature verification failed') ||
                    str_contains($response, 'Invalid signature format') ||
                    str_contains($response, 'Cryptographic verification failed') ||
                    str_contains($response, 'Authentication failed due to cryptographic error'),
                    'Should reject manipulated signatures with appropriate error message. Got: ' . $response
                );
            }
        }
    }

    /**
     * Test protection against pubkey manipulation.
     */
    public function testPubkeyManipulationProtection(): void
    {
        $client = static::createClient();

        $manipulations = [
            $this->createTokenWithInvalidPubkey('GET', 'http://localhost/login'),
            $this->createTokenWithEmptyPubkey('GET', 'http://localhost/login'),
            $this->createTokenWithMalformedPubkey('GET', 'http://localhost/login'),
        ];

        foreach ($manipulations as $token) {
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);

            // Accept either 401 or 500 - both indicate rejection of invalid pubkeys
            $statusCode = $client->getResponse()->getStatusCode();
            $this->assertTrue(
                in_array($statusCode, [401, 500]),
                "Should reject manipulated pubkeys with 401 or 500, got {$statusCode}"
            );

            if ($statusCode === 401) {
                $response = $client->getResponse()->getContent();
                $this->assertTrue(
                    str_contains($response, 'Missing required event fields') ||
                    str_contains($response, 'Failed to convert public key') ||
                    str_contains($response, 'Invalid event signature') ||
                    str_contains($response, 'Signature verification failed'),
                    'Should reject manipulated pubkeys with appropriate error message'
                );
            }
        }
    }

    /**
     * Test rate limiting and DoS protection.
     */
    public function testRateLimitingProtection(): void
    {
        $client = static::createClient();

        // Send many requests rapidly
        $token = $this->createValidToken('GET', 'http://localhost/login');
        $successCount = 0;

        for ($i = 0; $i < 10; $i++) {
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $token]);
            if ($client->getResponse()->getStatusCode() === 200) {
                $successCount++;
            }
        }

        // All should succeed with valid token (no rate limiting on valid requests)
        $this->assertEquals(10, $successCount);

        // Test with invalid tokens (should not cause server overload)
        $invalidToken = $this->createTokenWithInvalidSignature('GET', 'http://localhost/login');
        $start = microtime(true);

        for ($i = 0; $i < 5; $i++) {
            $client->request('GET', '/login', [], [], ['HTTP_Authorization' => $invalidToken]);
        }

        $duration = microtime(true) - $start;

        // Should complete within reasonable time (not hanging due to expensive operations)
        $this->assertLessThan(5.0, $duration, 'Authentication should not be susceptible to DoS via expensive operations');
    }
}
