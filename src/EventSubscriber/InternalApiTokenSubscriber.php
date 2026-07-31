<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Guards the internal read-only API (/internal/api/*) with a shared-secret
 * header. The internal API is intended to be reachable only from other
 * containers on the Docker network (e.g. the standalone MCP service) and must
 * never be exposed through the public site.
 *
 * Fails closed: if no token is configured, every internal request is rejected.
 */
class InternalApiTokenSubscriber implements EventSubscriberInterface
{
    private const HEADER = 'X-Internal-Token';
    private const PREFIX = '/internal/api';

    public function __construct(
        private readonly string $internalApiToken,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority high enough to run before the controller is resolved.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, self::PREFIX)) {
            return;
        }

        $provided = (string) $event->getRequest()->headers->get(self::HEADER, '');

        if ($this->internalApiToken === '' || !hash_equals($this->internalApiToken, $provided)) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Unauthorized'],
                JsonResponse::HTTP_UNAUTHORIZED
            ));
        }
    }
}
