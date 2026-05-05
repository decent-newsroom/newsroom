<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FetchRelayInformationMessage;
use App\Service\Nostr\RelayInformationFetcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchRelayInformationHandler
{
    public function __construct(
        private readonly RelayInformationFetcher $fetcher,
    ) {}

    public function __invoke(FetchRelayInformationMessage $message): void
    {
        $this->fetcher->fetch($message->relayUrl);
    }
}

