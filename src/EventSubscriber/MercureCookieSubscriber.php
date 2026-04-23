<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Mercure\MercureSubscriberTokenService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches a short-lived, HttpOnly Mercure subscriber JWT cookie
 * (`mercureAuthorization`) to authenticated HTML responses so the browser can
 * open an EventSource to `/.well-known/mercure` for the user's private
 * notifications topic without any Authorization header plumbing in JS.
 *
 * Cookie is scoped to the Mercure path and refreshed when its remaining TTL
 * drops below one hour.
 */
class MercureCookieSubscriber implements EventSubscriberInterface
{
    private const REFRESH_THRESHOLD_SECONDS = 3600;
    private const COOKIE_PATH = '/.well-known/mercure';

    public function __construct(
        private readonly Security $security,
        private readonly MercureSubscriberTokenService $tokenService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -16]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Only touch HTML responses — no point setting a cookie on JSON/API/asset traffic.
        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            return;
        }

        // Refresh only if no cookie present or the existing one is near expiry.
        $existing = $request->cookies->get(MercureSubscriberTokenService::COOKIE_NAME);
        if ($existing !== null && !$this->needsRefresh($existing)) {
            return;
        }

        $token = $this->tokenService->mintForUser($user);

        $cookie = Cookie::create(MercureSubscriberTokenService::COOKIE_NAME)
            ->withValue($token)
            ->withPath(self::COOKIE_PATH)
            ->withHttpOnly(true)
            ->withSecure($request->isSecure())
            ->withSameSite(Cookie::SAMESITE_STRICT)
            ->withExpires(time() + MercureSubscriberTokenService::TTL_SECONDS);

        $response->headers->setCookie($cookie);
    }

    private function needsRefresh(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return true;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            return true;
        }
        return ((int) $payload['exp']) - time() < self::REFRESH_THRESHOLD_SECONDS;
    }
}

