<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace\Metrics;

use App\Message\FetchEventFromRelaysMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\MessageSentToTransportsEvent;

final class WorkspaceMetricsSubscriber implements EventSubscriberInterface
{
    private const WARNING = 'Workspace metrics aggregation failed; some samples may be lost.';
    private const WARNING_INTERVAL_NS = 60_000_000_000;

    private const ROUTES = [
        'reading_nook_bookmark_references', 'reading_nook_follow_pack_members',
        'reading_nook_publication_toc', 'reading_nook_nested_publication_toc',
        'reading_nook_publication_toc_chapter_preview', 'reading_nook_publication_toc_discovery',
        'reading_nook_drive_mount', 'reading_nook_drive_directory', 'reading_nook_drive_event',
        'my_content_preview', 'bookshelf_directory_references', 'bookshelf_index_toc',
        'nostr_preview',
    ];

    private ?int $lastWarning = null;

    public function __construct(
        private readonly WorkspaceMetrics $metrics,
        private readonly WorkspaceMetricsStore $store,
        #[Autowire(service: 'monolog.logger.workspace_metrics')]
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 31],
            KernelEvents::EXCEPTION => ['onException', 128],
            KernelEvents::RESPONSE => ['onResponse', -2048],
            KernelEvents::FINISH_REQUEST => ['onFinish', -2048],
            MessageSentToTransportsEvent::class => 'onMessageSent',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->metrics->reset();
        if (in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            $this->metrics->begin();
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->metrics->exception();
        }
    }

    public function onMessageSent(MessageSentToTransportsEvent $event): void
    {
        if ($event->getEnvelope()->getMessage() instanceof FetchEventFromRelaysMessage) {
            $this->metrics->queuedFetch();
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->store($this->metrics->finish($event->getResponse()->getStatusCode()));
        }
    }

    public function onFinish(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->metrics->exception();
            $this->store($this->metrics->finish(null));
        }
    }

    /** @param array<string, int>|null $sample */
    private function store(?array $sample): void
    {
        if ($sample === null) {
            return;
        }
        try {
            $success = $this->store->add($sample);
        } catch (\Throwable) {
            $success = false;
        }
        if ($success) {
            return;
        }
        $now = hrtime(true);
        if ($this->lastWarning !== null && $now - $this->lastWarning < self::WARNING_INTERVAL_NS) {
            return;
        }
        $this->lastWarning = $now;
        try {
            $this->logger->warning(self::WARNING);
        } catch (\Throwable) {
            @error_log(self::WARNING);
        }
    }
}
