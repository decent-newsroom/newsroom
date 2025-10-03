<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use App\Security\NostrAuthenticator;
use App\Entity\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Unit tests for NostrAuthenticator focusing on individual methods
 * without requiring a full Symfony application context.
 */
class NostrAuthenticatorUnitTest extends TestCase
{
    private NostrAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = new NostrAuthenticator();
    }

    /**
     * Test that supports() method correctly identifies Nostr authentication requests.
     */
    public function testSupportsValidNostrRequest(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Nostr eyJpZCI6InRlc3QifQ==');

        $this->assertTrue($this->authenticator->supports($request));
    }

    /**
     * Test that supports() method rejects non-Nostr authentication requests.
     */
    public function testSupportsRejectsBearerToken(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer token123');

        $this->assertFalse($this->authenticator->supports($request));
    }

    /**
     * Test that supports() method rejects requests without Authorization header.
     */
    public function testSupportsRejectsRequestsWithoutAuthHeader(): void
    {
        $request = new Request();

        $this->assertFalse($this->authenticator->supports($request));
    }

    /**
     * Test authentication with invalid base64 encoding.
     */
    public function testAuthenticateWithInvalidBase64(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Nostr invalid_base64!@#');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid base64 encoding');

        $this->authenticator->authenticate($request);
    }

    /**
     * Test authentication with invalid JSON.
     */
    public function testAuthenticateWithInvalidJson(): void
    {
        $request = new Request();
        $invalidJson = base64_encode('{"invalid": json}');
        $request->headers->set('Authorization', 'Nostr ' . $invalidJson);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->authenticator->authenticate($request);
    }

    /**
     * Test that authentication failure returns proper error response.
     */
    public function testOnAuthenticationFailureReturnsJsonError(): void
    {
        $request = new Request();
        $exception = new AuthenticationException('Test error message');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication failed', $content['error']);
        $this->assertEquals('Test error message', $content['message']);
    }

    /**
     * Test that successful authentication returns null to continue request.
     */
    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $request = new Request();
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);

        $response = $this->authenticator->onAuthenticationSuccess($request, $token, 'main');

        $this->assertNull($response);
    }

    /**
     * Test that authenticator is marked as interactive.
     */
    public function testIsInteractive(): void
    {
        $this->assertTrue($this->authenticator->isInteractive());
    }
}
