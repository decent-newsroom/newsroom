<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Admin\McpAnalytics;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class McpAnalyticsSubscriber implements EventSubscriberInterface
{
    private const OPERATIONS = [
        'GET /internal/api/articles/search' => 'search_articles',
        'GET /internal/api/articles/get' => 'get_article',
        'GET /internal/api/articles/latest' => 'list_latest',
        'GET /internal/api/articles/by-author' => 'list_by_author',
        'GET /internal/api/articles/by-topic' => 'list_by_topic',
        'GET /internal/api/articles/topics' => 'list_topics',
        'POST /books/api/publications/search' => 'search_books',
        'POST /books/api/publications/sections/search' => 'search_book_sections',
    ];

    public function __construct(
        private readonly McpAnalytics $analytics,
        #[Autowire('%internal_api.token%')]
        private readonly string $internalApiToken,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 30],
            KernelEvents::RESPONSE => ['onResponse', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ($request->headers->get('X-Newsroom-Client') !== 'mcp'
            || $this->internalApiToken === ''
            || !hash_equals($this->internalApiToken, (string) $request->headers->get('X-Internal-Token', ''))) {
            return;
        }

        $operation = self::OPERATIONS[$request->getMethod() . ' ' . $request->getPathInfo()] ?? null;
        if ($operation === null && $request->isMethod('GET')
            && preg_match('#^/books/api/events/[^/]+$#D', $request->getPathInfo()) === 1) {
            $operation = 'get_book';
        }
        if ($operation !== null) {
            $request->attributes->set('_mcp_analytics', [$operation, hrtime(true)]);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $sample = $event->getRequest()->attributes->get('_mcp_analytics');
        if ($sample === null) {
            return;
        }
        $event->getRequest()->attributes->remove('_mcp_analytics');
        [$operation, $startedAt] = $sample;
        $this->analytics->record(
            $operation,
            $event->getResponse()->getStatusCode(),
            (int) ((hrtime(true) - $startedAt) / 1000),
        );
    }
}
