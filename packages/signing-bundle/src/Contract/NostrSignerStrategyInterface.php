<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

interface NostrSignerStrategyInterface
{
    public function getMethod(): string;

    public function supports(string $ownerId): bool;

    /**
     * @param array<string, mixed> $unsignedEvent
     * @return array<string, mixed>|null
     */
    public function sign(string $ownerId, array $unsignedEvent, ?int $timeoutSeconds = null): ?array;
}
