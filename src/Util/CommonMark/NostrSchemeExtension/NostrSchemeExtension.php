<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\Cache\RedisCacheService;
use App\Util\NostrKeyUtil;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class NostrSchemeExtension implements ExtensionInterface
{

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly NostrKeyUtil $nostrKeyUtil,
        private readonly ?NostrPrefetchedData $prefetchedData = null,
    ) {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new NostrMentionParser($this->redisCacheService, $this->nostrKeyUtil, $this->prefetchedData), 200)
            ->addInlineParser(new NostrSchemeParser($this->redisCacheService, $this->nostrKeyUtil, $this->prefetchedData), 199)
            ->addInlineParser(new NostrRawNpubParser($this->redisCacheService, $this->nostrKeyUtil, $this->prefetchedData), 198)

            ->addRenderer(NostrSchemeData::class, new NostrEventRenderer(), 3)
            ->addRenderer(NostrMentionLink::class, new NostrMentionRenderer(), 1)
        ;
    }
}
