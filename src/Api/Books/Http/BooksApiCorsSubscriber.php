<?php

declare(strict_types=1);

namespace App\Api\Books\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class BooksApiCorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/Books/api/')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Access-Control-Allow-Origin', '*');
        $headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $headers->set('Access-Control-Max-Age', '600');
        $headers->set('Vary', 'Origin');
    }
}
