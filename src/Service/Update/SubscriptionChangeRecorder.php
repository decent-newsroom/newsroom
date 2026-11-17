<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\SubscriptionChange;
use App\Message\FanOutUpdateMessage;
use App\Repository\SubscriptionChangeRepository;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SubscriptionChangeRecorder
{
    public function __construct(
        private SubscriptionChangeRepository $changes,
        private SubscriptionDiffer $differ,
        private MessageBusInterface $bus,
    ) {}

    public function recordArticle(Article $article, EntityManagerInterface $em, ?Article $previous = null): void
    {
        if ($article->getKind()?->value !== 30023) {
            return;
        }
        $snapshot = static function (Article $article): Event {
            $event = new Event();
            $event->setId($article->getEventId() ?? throw new \LogicException('An ingested article needs an event ID.'));
            $event->setKind(30023);
            $event->setPubkey($article->getPubkey() ?? throw new \LogicException('An ingested article needs an author.'));
            $event->setCreatedAt($article->getCreatedAt()?->getTimestamp() ?? throw new \LogicException('An ingested article needs a timestamp.'));
            $event->setDTag($article->getSlug() ?? '');
            $event->setTags($article->getRaw()['tags'] ?? []);
            $event->setContent($article->getContent() ?? '');
            return $event;
        };
        $this->record($snapshot($article), $em, $previous === null ? null : $snapshot($previous));
    }

    public function record(Event $event, EntityManagerInterface $em, ?Event $predecessor = null): void
    {
        if (!UpdateMatcher::isNotifiedKind($event->getKind())) {
            return;
        }
        $coordinate = $event->getKind() . ':' . $event->getPubkey() . ':'
            . ($event->getKind() >= 30000 ? ($event->getDTag() ?? '') : '');
        $change = $em->getConnection()->transactional(function () use ($event, $coordinate, $em, $predecessor): ?SubscriptionChange {
            if ($em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:source))', ['source' => $coordinate]);
            }
            $existing = $this->changes->find($event->getId());
            if ($existing instanceof SubscriptionChange) {
                return $existing;
            }
            $previous = $this->changes->latest($coordinate);
            if ($previous !== null && ($previous->getCreatedAt() > $event->getCreatedAt()
                || ($previous->getCreatedAt() === $event->getCreatedAt() && strcmp($previous->getEventId(), $event->getId()) < 0))) {
                return null;
            }

            $qb = $em->createQueryBuilder()->select('e')->from(Event::class, 'e')
                    ->where('e.kind = :kind')->andWhere('e.pubkey = :pubkey')
                    ->setParameter('kind', $event->getKind())->setParameter('pubkey', $event->getPubkey())
                    ->orderBy('e.created_at', 'DESC')->addOrderBy('e.id', 'ASC');
            if ($event->getKind() >= 30000) {
                $qb->andWhere('e.dTag = :d')->setParameter('d', $event->getDTag() ?? '');
            }
            $latest = (clone $qb)->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if ($latest instanceof Event && ($latest->getCreatedAt() > $event->getCreatedAt()
                || ($latest->getCreatedAt() === $event->getCreatedAt() && strcmp($latest->getId(), $event->getId()) < 0))) {
                return null;
            }
            // Capture the predecessor before cleanup, including deployment
            // into a database which has not recorded local change history yet.
            if ($previous === null) {
                $old = $qb->andWhere('e.id != :id')->setParameter('id', $event->getId())
                    ->setMaxResults(1)->getQuery()->getOneOrNullResult();
                if ($predecessor !== null && (!$old instanceof Event || $predecessor->getCreatedAt() > $old->getCreatedAt()
                    || ($predecessor->getCreatedAt() === $old->getCreatedAt() && strcmp($predecessor->getId(), $old->getId()) < 0))) {
                    $old = $predecessor;
                }
                if ($old instanceof Event) {
                    $previous = new SubscriptionChange($old, $coordinate, null, []);
                    $em->persist($previous);
                }
            }
            $delta = $previous === null ? [] : $this->differ->compare(
                $event->getKind(), $previous->getTags(), $previous->getContentHash(),
                $event->getTags(), hash('sha256', $event->getContent()),
            );
            $change = new SubscriptionChange($event, $coordinate, $previous, $delta);
            $em->persist($change);
            $em->flush();
            return $change;
        });
        if ($change !== null && ($change->hasChanges()
            || ($change->getKind() === 30023 && $change->getPreviousEventId() === null))) {
            $this->bus->dispatch(new FanOutUpdateMessage($change->getEventId()));
        }
    }
}
