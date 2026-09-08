<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Nostr\NostrSigner;
use Innis\Nostr\Core\Domain\Entity\Event;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Simplified authentication tests that focus on core functionality.
 */
class NostrAuthenticatorSimpleTest extends WebTestCase
{
    private string $privateKey;

    protected function setUp(): void
    {
        $this->privateKey = bin2hex(random_bytes(32));
    }

    public function testValidAuthentication(): void
    {
        $client = static::createClient();
        $token = $this->createValidToken('GET', 'http://localhost/login');

        $client->request('GET', '/login', [], [], [
            'HTTP_Authorization' => $token,
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), 'Valid authentication should succeed');
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
            str_contains($content, 'Invalid Authorization scheme') || str_contains($content, 'Unauthenticated'),
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
        // Handle escaped quotes in JSON response
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'Missing required "u" (URL) tag') ||
            str_contains($content, 'Missing required \\"u\\" (URL) tag'),
            'Response should contain missing URL tag error message'
        );
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
        // Handle escaped quotes in JSON response
        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, 'Missing required "method" tag') ||
            str_contains($content, 'Missing required \\"method\\" tag'),
            'Response should contain missing method tag error message'
        );
    }

    public function testNoAuthHeaderDoesNotInterfere(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        $statusCode = $client->getResponse()->getStatusCode();

        // Should NOT be 401 when no Authorization header is provided
        $this->assertNotSame(401, $statusCode, 'Authenticator should not interfere when no auth header is present');
    }

    private function createValidToken(string $method, string $url): string
    {
        return $this->encodeToken($this->signedEvent(27235, $method, $url)->toArray());
    }

    private function createTokenWithKind(int $kind, string $method, string $url): string
    {
        return $this->encodeToken($this->signedEvent($kind, $method, $url)->toArray());
    }

    private function createTokenWithTimestamp(string $method, string $url, int $timestamp): string
    {
        return $this->encodeToken($this->signedEvent(27235, $method, $url, $timestamp)->toArray());
    }

    private function createTokenWithoutTag(string $tagToRemove, string $method, string $url): string
    {
        $tags = [];
        if ($tagToRemove !== 'u') {
            $tags[] = ['u', $url];
        }
        if ($tagToRemove !== 'method') {
            $tags[] = ['method', $method];
        }

        return $this->encodeToken($this->signedEvent(27235, $method, $url, time(), $tags)->toArray());
    }

    /** @param list<list<string>>|null $tags */
    private function signedEvent(int $kind, string $method, string $url, ?int $createdAt = null, ?array $tags = null): Event
    {
        /** @var NostrSigner $signer */
        $signer = static::getContainer()->get(NostrSigner::class);

        return $signer->signWithPrivateKey(
            $kind,
            $tags ?? [['u', $url], ['method', $method]],
            '',
            $this->privateKey,
            $createdAt ?? time(),
        );
    }

    /** @param array<string, mixed> $event */
    private function encodeToken(array $event): string
    {
        return 'Nostr '.base64_encode(json_encode($event, JSON_THROW_ON_ERROR));
    }
}
