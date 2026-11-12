<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventWorkspaceIdentityType;
use App\Dto\EventWorkspace\EventWorkspaceSource;
use App\Entity\Article;
use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\Graph\RecordIdentityService;
use PHPUnit\Framework\TestCase;

final class EventWorkspaceSourceAdapterTest extends TestCase
{
    private EventWorkspaceSourceAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new EventWorkspaceSourceAdapter(new RecordIdentityService());
    }

    public function testOwnedCollectionEventRetainsExactIdentityCoordinateAndOrderedTags(): void
    {
        $event = $this->event(KindsEnum::PUBLICATION_INDEX->value, [
            ['title', 'Queue'],
            ['a', '30023:' . str_repeat('b', 64) . ':first'],
            ['a', '30023:' . str_repeat('b', 64) . ':first'],
        ]);
        $record = $this->adapter->fromOwnedCollectionEvent($event);

        self::assertSame(EventWorkspaceSource::OwnedCollectionEvent, $record->source);
        self::assertSame(str_repeat('a', 64), $record->ownerScope);
        self::assertSame(EventWorkspaceIdentityType::Address, $record->identity->type);
        self::assertSame('30040:' . str_repeat('a', 64) . ':', $record->identity->value);
        self::assertSame(str_repeat('1', 64), $record->identity->versionEventId);
        self::assertSame($event->getTags(), $record->getRawTags());
        self::assertSame($event->getId(), $record->eventId);
    }

    public function testAuthoredArticleUsesCoordinateAndKeepsProjectionTagsWhenAvailable(): void
    {
        $article = (new Article())
            ->setKind(KindsEnum::LONGFORM)
            ->setEventId(str_repeat('2', 64))
            ->setPubkey(str_repeat('c', 64))
            ->setSlug('stale-projection-slug');
        $article->setRaw(['tags' => [['d', 'essay:with:colons'], ['e', str_repeat('d', 64)]]]);
        $record = $this->adapter->fromAuthoredArticle($article);

        self::assertSame(EventWorkspaceSource::AuthoredArticle, $record->source);
        self::assertSame(EventWorkspaceIdentityType::Address, $record->identity->type);
        self::assertSame('30023:' . str_repeat('c', 64) . ':essay:with:colons', $record->identity->value);
        self::assertSame(str_repeat('2', 64), $record->identity->versionEventId);
        self::assertSame($article->getRaw()['tags'], $record->getRawTags());
    }

    public function testBookshelfAdapterRejectsOtherEventKinds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->adapter->fromBookshelfDirectory($this->event(KindsEnum::LONGFORM->value, []));
    }

    public function testBookshelfDirectoryIsAdaptedAsAnAddressableEvent(): void
    {
        $record = $this->adapter->fromBookshelfDirectory(
            $this->event(KindsEnum::DIRECTORY->value, [['d', 'books']]),
        );

        self::assertSame(EventWorkspaceSource::BookshelfDirectory, $record->source);
        self::assertSame(EventWorkspaceIdentityType::Address, $record->identity->type);
        self::assertSame('30045:' . str_repeat('a', 64) . ':books', $record->identity->value);
    }

    public function testLocalSubscriptionTargetsAnIdentityWithoutInventingAnEvent(): void
    {
        $user = new User();
        $user->setId(123);
        $user->setNpub('owner');
        $subscription = new UpdateSubscription(
            $user,
            UpdateSourceTypeEnum::NPUB,
            str_repeat('e', 64),
        );
        $record = $this->adapter->fromLocalSubscription($subscription);

        self::assertSame(EventWorkspaceSource::LocalSubscription, $record->source);
        self::assertSame('123', $record->ownerScope);
        self::assertSame(EventWorkspaceIdentityType::Profile, $record->identity->type);
        self::assertSame(str_repeat('e', 64), $record->identity->value);
        self::assertNull($record->eventId);
        self::assertNull($record->kind);
        self::assertNull($record->getRawTags());
    }

    public function testArticleWithoutProjectionTagsMarksThemUnavailableRatherThanEmpty(): void
    {
        $article = (new Article())
            ->setKind(KindsEnum::LONGFORM)
            ->setPubkey(str_repeat('c', 64))
            ->setSlug('essay');
        $record = $this->adapter->fromAuthoredArticle($article);

        self::assertNull($record->eventId);
        self::assertNull($record->getRawTags());
        self::assertNull($record->identity->versionEventId);
    }

    public function testMalformedSignedIdentifierDoesNotUseProjectionSlugAsCoordinate(): void
    {
        $article = (new Article())
            ->setKind(KindsEnum::LONGFORM)
            ->setEventId(str_repeat('2', 64))
            ->setPubkey(str_repeat('c', 64))
            ->setSlug('projection-slug');
        $article->setRaw(['tags' => [['d']]]);

        $record = $this->adapter->fromAuthoredArticle($article);

        self::assertSame(EventWorkspaceIdentityType::EventId, $record->identity->type);
        self::assertSame(str_repeat('2', 64), $record->identity->value);
        self::assertSame([['d']], $record->getRawTags());
    }

    public function testLocalPublicationSubscriptionUsesCanonicalAddress(): void
    {
        $user = new User();
        $user->setNpub('owner');
        $subscription = new UpdateSubscription(
            $user,
            UpdateSourceTypeEnum::PUBLICATION,
            '30040:' . str_repeat('F', 64) . ':queue:with:colons',
        );
        $record = $this->adapter->fromLocalSubscription($subscription);

        self::assertSame(
            '30040:' . str_repeat('f', 64) . ':queue:with:colons',
            $record->identity->value,
        );
    }

    public function testRawTagSnapshotCannotBeMutatedThroughReturnedArray(): void
    {
        $record = $this->adapter->fromOwnedCollectionEvent(
            $this->event(KindsEnum::BOOKMARKS->value, [['e', str_repeat('a', 64)]]),
        );
        $tags = $record->getRawTags();
        $tags[0][1] = 'changed';

        self::assertSame(str_repeat('a', 64), $record->getRawTags()[0][1]);
    }

    /**
     * @param array<array-key, mixed> $tags
     */
    private function event(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat('1', 64));
        $event->setKind($kind);
        $event->setPubkey(str_repeat('a', 64));
        $event->setCreatedAt(1);
        $event->setContent('');
        $event->setTags($tags);

        return $event;
    }
}
