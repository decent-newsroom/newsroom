<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\NostrTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class NostrAuthenticatorTest extends WebTestCase
{
    use NostrTestHelpers;

    protected function setUp(): void
    {
        $this->setUpNostrHelpers();
    }

    public function testValidAuthentication(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('GET', 'http://localhost/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), 'Valid Nostr authentication should succeed');
    }

    public function testInvalidScheme(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => 'Bearer invalid_token',
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Invalid scheme should return 401');
        // Check for either the specific error message or the generic unauthenticated message
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'Full authentication is required') || str_contains($content, 'Unauthenticated'),
            'Response should contain either specific error or unauthenticated message'
        );
    }

    public function testInvalidBase64(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => 'Nostr invalid_base64!@#',
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Invalid base64 should return 401');
        $this->assertStringContainsString('Invalid base64 encoding', $response->getContent());
    }

    public function testInvalidJson(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => 'Nostr ' . base64_encode('{"invalid": json}'),
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Invalid JSON should return 401');
        $this->assertStringContainsString('Invalid JSON', $response->getContent());
    }

    public function testWrongEventKind(): void
    {
        $client = static::createClient();
        $token = $this->createTokenWithKind(1, 'GET', 'http://localhost/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Wrong event kind should return 401');
        $this->assertStringContainsString('Invalid event kind', $response->getContent());
    }

    public function testExpiredToken(): void
    {
        $client = static::createClient();
        $token = $this->createTokenWithTimestamp('GET', 'http://localhost/login', time() - 120);

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Expired token should return 401');
        $this->assertStringContainsString('Authentication event has expired', $response->getContent());
    }

    public function testWrongUrl(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('GET', 'https://wrong.com/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Wrong URL should return 401');
        $this->assertStringContainsString('URL tag does not match request URL', $response->getContent());
    }

    public function testWrongMethod(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('POST', 'http://localhost/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Wrong method should return 401');
        $this->assertStringContainsString('Method tag does not match request method', $response->getContent());
    }

    public function testMissingUrlTag(): void
    {
        $client = static::createClient();
        $token = $this->createTokenWithoutTag('u', 'GET', 'http://localhost/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Missing URL tag should return 401');
        $this->assertStringContainsString('Missing required \"u\" (URL) tag', $response->getContent());
    }

    public function testMissingMethodTag(): void
    {
        $client = static::createClient();
        $token = $this->createTokenWithoutTag('method', 'GET', 'http://localhost/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'Missing method tag should return 401');
        $this->assertStringContainsString('Missing required \"method\" tag', $response->getContent());
    }

    public function testValidPostAuthentication(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('POST', 'http://localhost/login');

        $client->request('POST', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), 'Valid POST authentication should succeed');
    }

    public function testPostWithGetMethodTag(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('GET', 'http://localhost/login');

        $client->request('POST', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'POST with GET method tag should fail');
    }

    /**
     * Test that authenticator doesn't interfere with routes that don't require auth.
     */
    public function testNonAuthRoutesAreNotAffected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        // Should not be 401 - either 200 or redirect, but not unauthorized
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 301, 302, 304]),
            "Expected successful response or redirect, got {$statusCode}"
        );
    }

    /**
     * Test missing Authorization header on a protected route.
     */
    public function testMissingAuthorizationHeaderOnProtectedRoute(): void
    {
        $client = static::createClient();

        // Test on protected route - should require authentication
        // Using /admin which requires ROLE_ADMIN per access_control in security.yaml
        $client->request('GET', '/admin');
        $statusCode = $client->getResponse()->getStatusCode();

        // Should be 401 or 403 when no Authorization header is provided on protected route
        // 401 if not authenticated, 403 if authenticated but without permission
        $this->assertTrue(
            in_array($statusCode, [401, 403]),
            "Protected route should require authentication, got status {$statusCode}"
        );
    }
}
