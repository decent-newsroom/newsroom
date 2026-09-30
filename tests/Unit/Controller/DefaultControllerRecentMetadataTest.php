<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DefaultController;
use App\Entity\Event;
use App\Repository\EventRepository;
use PHPUnit\Framework\TestCase;

final class DefaultControllerRecentMetadataTest extends TestCase
{
    public function testMissingLocalMetadataReturnsNoProfile(): void
    {
        $pubkey = str_repeat('a', 64);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestMetadataByPubkeys')
            ->with([$pubkey])
            ->willReturn([]);

        self::assertSame([], $this->resolveMetadata($repository, [$pubkey]));
    }

    public function testPersistedMetadataIsAvailableForRecentFallback(): void
    {
        $pubkey = str_repeat('b', 64);
        $event = new Event();
        $event->setPubkey($pubkey);
        $event->setKind(0);
        $event->setContent('{"name":"Local author"}');

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestMetadataByPubkeys')
            ->with([$pubkey])
            ->willReturn([$pubkey => $event]);

        $metadata = $this->resolveMetadata($repository, [$pubkey]);
        self::assertSame('Local author', $metadata[$pubkey]->name);
    }

    /** @return array<string, \stdClass> */
    private function resolveMetadata(EventRepository $repository, array $pubkeys): array
    {
        $method = new \ReflectionMethod(DefaultController::class, 'findPersistedMetadata');

        return $method->invoke(new DefaultController(), $repository, $pubkeys);
    }
}
