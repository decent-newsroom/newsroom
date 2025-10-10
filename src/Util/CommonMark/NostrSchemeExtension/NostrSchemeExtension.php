<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\NostrClient;
use App\Service\RedisCacheService;
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
        private readonly NostrKeyUtil $nostrKeyUtil
    ) {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new NostrMentionParser($this->redisCacheService, $this->nostrKeyUtil), 200)
            ->addInlineParser(new NostrSchemeParser($this->redisCacheService, $this->nostrClient, $this->twig, $this->nostrKeyUtil), 199)
            ->addInlineParser(new NostrRawNpubParser($this->redisCacheService, $this->nostrKeyUtil), 198)

            ->addRenderer(NostrSchemeData::class, new NostrEventRenderer(), 2)
            ->addRenderer(NostrEmbeddedCard::class, new NostrEmbeddedCardRenderer(), 3)
            ->addRenderer(NostrMentionLink::class, new NostrMentionRenderer(), 1)
        ;
    }
}
