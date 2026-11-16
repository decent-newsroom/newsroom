<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\InventoryPolicyException;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class InventoryPolicyFailureTest extends TestCase
{
    /** @dataProvider failureStages */
    public function testPolicyFailuresAreLoggedAndPropagated(string $stage): void
    {
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $mutes = $this->createMock(UserMuteListService::class);
        $error = new \RuntimeException('policy storage offline');
        match ($stage) {
            'strict_mutes' => $mutes->method('getMutedPubkeysStrict')->willThrowException($error),
            'authors' => $authors->method('suppressedPubkeys')->willThrowException($error),
            'deletions' => $deletions->method('findSuppressedEventIds')->willThrowException($error),
            'hidden' => $hidden->method('findHiddenCoordinates')->willThrowException($error),
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Reading Nook inventory policy lookup failed.',
            self::callback(static fn (array $context): bool =>
                $context['policy_stage'] === ($stage === 'strict_mutes' ? 'strict_mutes' : 'batch_access')
                && $context['exception'] === $error
            ),
        );
        $policy = new EventPreviewAccessPolicy(
            $authors, $deletions, $hidden, new RecordIdentityService(),
            $this->createMock(Security::class), $mutes, new NostrKeyService(), $logger,
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        $this->expectException(InventoryPolicyException::class);
        if ($stage === 'strict_mutes') {
            $policy->inventoryMuteSet(str_repeat('a', 64));
            return;
        }
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setPubkey(str_repeat('a', 64));
        $event->setKind(30004);
        $event->setTags([['d', 'list']]);
        $policy->readableInventoryEvents([$event], []);
    }

    public static function failureStages(): iterable
    {
        yield ['strict_mutes'];
        yield ['authors'];
        yield ['deletions'];
        yield ['hidden'];
    }
}
