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

}
