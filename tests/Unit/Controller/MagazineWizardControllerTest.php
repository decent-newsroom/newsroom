<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Newsroom\MagazineWizardController;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrSigner;
use App\Service\PublicationSubdomainService;
use App\Service\ReadingListManager;
use App\Service\UserRolePromoter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class MagazineWizardControllerTest extends TestCase
{
    /**
     * @dataProvider revisionCases
     */
    public function testPublishChecksRootRevisionBeforePersistence(
        bool $hasBase,
        ?string $baseId,
        ?string $currentId,
        int $expectedStatus,
    ): void {
        $signedId = str_repeat('c', 64);
        $pubkey = str_repeat('a', 64);
        $event = [
            'id' => $signedId,
            'pubkey' => $pubkey,
            'created_at' => 1_700_000_000,
            'kind' => 30040,
            'tags' => [['d', 'magazine']],
            'content' => '',
            'sig' => str_repeat('d', 128),
        ];
        $payload = ['event' => $event];
        if ($hasBase) {
            $payload['base_event_id'] = $baseId;
        }

        $signer = $this->createMock(NostrSigner::class);
        $signer->expects(self::once())->method('verify')->willReturn(true);
        $events = $this->createMock(EventRepository::class);
        $validBase = $hasBase && ($baseId === null || preg_match('/^[0-9a-f]{64}$/', $baseId) === 1);
        if ($validBase) {
            $current = $currentId === null ? null : new Event();
            if ($current !== null) {
                $current->setId($currentId);
            }
            $events->expects(self::once())->method('findByNaddr')
                ->with(30040, $pubkey, 'magazine')->willReturn($current);
        } else {
            $events->expects(self::never())->method('findByNaddr');
        }

        $allowed = $expectedStatus === 200;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($allowed ? self::once() : self::never())->method('persist');
        $entityManager->expects($allowed ? self::once() : self::never())->method('flush');
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects($allowed ? self::once() : self::never())
            ->method('publishEvent')->willReturn([]);
        $ingestion = $this->createMock(EventIngestionListener::class);
        $ingestion->expects($allowed ? self::once() : self::never())->method('processEvent');
        $promoter = $this->createMock(UserRolePromoter::class);
        $promoter->expects($allowed ? self::once() : self::never())->method('promoteToEditor');

        $logger = $this->createMock(LoggerInterface::class);
        $controller = new MagazineWizardController(
            $this->createMock(ReadingListManager::class),
            $this->createMock(PublicationSubdomainService::class),
            $logger,
        );
        $response = $controller->publishIndexEvent(
            new Request(content: json_encode($payload, JSON_THROW_ON_ERROR)),
            $entityManager,
            $nostrClient,
            new NostrEventVerifier($signer),
            $logger,
            $promoter,
            $ingestion,
            $events,
        );

        self::assertSame($expectedStatus, $response->getStatusCode());
    }

    public static function revisionCases(): iterable
    {
        $base = str_repeat('b', 64);
        $newer = str_repeat('e', 64);
        yield 'stale root is rejected' => [true, $base, $newer, 409];
        yield 'new root now exists' => [true, null, $newer, 409];
        yield 'malformed revision is rejected' => [true, 'not-an-event-id', null, 400];
        yield 'initial publication' => [true, null, null, 200];
        yield 'current revision' => [true, $base, $base, 200];
        yield 'already committed retry' => [true, $base, str_repeat('c', 64), 200];
        yield 'category publication without root guard' => [false, null, null, 200];
    }
}
