<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrClient;
use App\Util\NostrKeyUtil;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;
use Twig\Environment;

class NostrSchemeExtension  implements ExtensionInterface
{

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly NostrClient $nostrClient,
        private readonly Environment $twig,
        private readonly NostrKeyUtil $nostrKeyUtil,
        private readonly ArticleFactory $articleFactory,
        private readonly ArticleRepository $articleRepository,
        private readonly ?NostrPrefetchedData $prefetchedData = null,
        private readonly ?EventRepository $eventRepository = null,
    ) {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new NostrMentionParser($this->redisCacheService, $this->nostrKeyUtil, $this->prefetchedData), 200)
            ->addInlineParser(new NostrSchemeParser($this->redisCacheService, $this->nostrClient, $this->twig, $this->nostrKeyUtil, $this->articleFactory, $this->articleRepository, $this->prefetchedData, $this->eventRepository), 199)
            ->addInlineParser(new NostrRawNpubParser($this->redisCacheService, $this->nostrKeyUtil, $this->prefetchedData), 198)

            ->addRenderer(NostrSchemeData::class, new NostrEventRenderer(), 3)
            ->addRenderer(NostrEmbeddedCard::class, new NostrEmbeddedCardRenderer(), 2)
            ->addRenderer(NostrMentionLink::class, new NostrMentionRenderer(), 1)
        ;
    }
}
