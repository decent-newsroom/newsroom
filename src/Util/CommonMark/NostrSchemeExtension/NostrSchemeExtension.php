<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\RedisCacheService;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class NostrSchemeExtension  implements ExtensionInterface
{

    public function __construct(private readonly RedisCacheService $redisCacheService)
    {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addInlineParser(new NostrMentionParser($this->redisCacheService), 200)
            ->addInlineParser(new NostrSchemeParser($this->redisCacheService), 199)
            ->addInlineParser(new NostrRawNpubParser($this->redisCacheService), 198)

            ->addRenderer(NostrSchemeData::class, new NostrEventRenderer(), 2)
            ->addRenderer(NostrMentionLink::class, new NostrMentionRenderer(), 1)
        ;
    }
}
