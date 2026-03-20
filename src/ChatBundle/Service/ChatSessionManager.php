<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatSession;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Repository\ChatSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ChatSessionManager
{
    private const COOKIE_NAME = 'chat_session';
    private const SESSION_TTL_DAYS = 90;

    public function __construct(
        private readonly ChatSessionRepository $sessionRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Create a session and return the plaintext token (to be stored in the cookie).
     */
    public function createSession(ChatUser $user, ChatCommunity $community): string
    {
        $plaintextToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plaintextToken);

        $session = new ChatSession();
        $session->setUser($user);
        $session->setCommunity($community);
        $session->setSessionToken($tokenHash);
        $session->setExpiresAt(new \DateTimeImmutable('+' . self::SESSION_TTL_DAYS . ' days'));

        $this->em->persist($session);
        $this->em->flush();

        return $plaintextToken;
    }

    public function validateSession(string $plaintextToken): ?ChatSession
    {
        $tokenHash = hash('sha256', $plaintextToken);
        $session = $this->sessionRepo->findByTokenHash($tokenHash);

        if ($session === null || !$session->isValid()) {
            return null;
        }

        return $session;
    }

    public function touchLastSeen(ChatSession $session): void
    {
        $session->touchLastSeen();
        $this->em->flush();
    }

    public function revokeSession(ChatSession $session): void
    {
        $session->setRevokedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function revokeAllForUser(ChatUser $user): void
    {
        $sessions = $this->sessionRepo->findActiveByUser($user);
        foreach ($sessions as $session) {
            $session->setRevokedAt(new \DateTimeImmutable());
        }
        $this->em->flush();
    }

    /**
     * Set the session cookie on a response.
     */
    public function setCookie(Response $response, string $plaintextToken, string $subdomain): void
    {
        $cookie = Cookie::create(self::COOKIE_NAME)
            ->withValue($plaintextToken)
            ->withExpires(new \DateTimeImmutable('+' . self::SESSION_TTL_DAYS . ' days'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite('lax');

        $response->headers->setCookie($cookie);
    }

    /**
     * Read the session token from the request cookie.
     */
    public static function getTokenFromCookies(array $cookies): ?string
    {
        return $cookies[self::COOKIE_NAME] ?? null;
    }

    public static function getCookieName(): string
    {
        return self::COOKIE_NAME;
    }
}

