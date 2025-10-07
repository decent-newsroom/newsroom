<?php

namespace App\Service;

use App\Entity\Event as EventEntity;
use App\Entity\Nzine;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Service for managing category index events for nzines
 */
class NzineCategoryIndexService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ensure category index events exist for all categories in a nzine
     * Creates missing category index events and returns them
     *
     * @param Nzine $nzine The nzine entity
     * @return array Map of category slug => EventEntity
     * @throws \JsonException
     */
    public function ensureCategoryIndices(Nzine $nzine): array
    {
        $categories = $nzine->getMainCategories();
        if (empty($categories)) {
            return [];
        }

        $bot = $nzine->getNzineBot();
        if (!$bot) {
            $this->logger->warning('Cannot create category indices: nzine bot not found', [
                'nzine_id' => $nzine->getId(),
            ]);
            return [];
        }

        $bot->setEncryptionService($this->encryptionService);
        $privateKey = $bot->getNsec();

        if (!$privateKey) {
            $this->logger->warning('Cannot create category indices: bot private key not found', [
                'nzine_id' => $nzine->getId(),
            ]);
            return [];
        }

        $slugger = new AsciiSlugger();
        $categoryIndices = [];

        // Load all existing category indices for this nzine at once
        $existingIndices = $this->entityManager->getRepository(EventEntity::class)
            ->findBy([
                'pubkey' => $nzine->getNpub(),
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
            ]);

        // Index existing events by their d-tag (slug)
        $existingBySlug = [];
        foreach ($existingIndices as $existingIndex) {
            $slug = $this->extractSlugFromTags($existingIndex->getTags());
            if ($slug) {
                $existingBySlug[$slug] = $existingIndex;
            }
        }

        foreach ($categories as $category) {
            if (empty($category['title'])) {
                continue;
            }

            $title = $category['title'];
            $slug =  $category['slug'];

            // Check if category index already exists
            if (isset($existingBySlug[$slug])) {
                // FIX: Add existing index to return array
                $categoryIndices[$slug] = $existingBySlug[$slug];

                $this->logger->debug('Using existing category index', [
                    'category_slug' => $slug,
                    'title' => $title,
                ]);
                continue;
            }

            // Create new category index event
            $event = new Event();
            $event->setKind(KindsEnum::PUBLICATION_INDEX->value);
            $event->addTag(['d', $slug]);
            $event->addTag(['title', $title]);
            $event->addTag(['auto-update', 'yes']);
            $event->addTag(['type', 'magazine']);

            // Add tags for RSS matching
            if (isset($category['tags']) && is_array($category['tags'])) {
                foreach ($category['tags'] as $tag) {
                    $event->addTag(['t', $tag]);
                }
            }

            $event->setPublicKey($nzine->getNpub());

            // Sign the event
            $signer = new Sign();
            $signer->signEvent($event, $privateKey);

            // Convert to EventEntity and save
            $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
            $eventEntity = $serializer->deserialize($event->toJson(), EventEntity::class, 'json');

            $this->entityManager->persist($eventEntity);
            $categoryIndices[$slug] = $eventEntity;

            $this->logger->info('Created category index event', [
                'nzine_id' => $nzine->getId(),
                'category_title' => $title,
                'category_slug' => $slug,
            ]);
        }

        $this->entityManager->flush();

        $this->logger->info('Category indices ready', [
            'nzine_id' => $nzine->getId(),
            'total_categories' => count($categories),
            'total_indices_returned' => count($categoryIndices),
            'indexed_by_slug' => array_keys($categoryIndices),
        ]);

        return $categoryIndices;
    }

    /**
     * Extract the slug (d-tag value) from event tags
     */
    private function extractSlugFromTags(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && $tag[0] === 'd' && isset($tag[1])) {
                return $tag[1];
            }
        }
        return null;
    }

    /**
     * Add an article to a category index
     * Creates a new signed event with the article added to the existing tags
     *
     * @param EventEntity $categoryIndex The category index event
     * @param string $articleCoordinate The article coordinate (kind:pubkey:slug)
     * @param Nzine $nzine The nzine entity (needed for signing)
     * @return EventEntity The new category index event (with updated article list)
     */
    public function addArticleToCategoryIndex(EventEntity $categoryIndex, string $articleCoordinate, Nzine $nzine): EventEntity
    {
        // Check if article already exists in the index
        $existingTags = $categoryIndex->getTags();
        foreach ($existingTags as $tag) {
            if ($tag[0] === 'a' && isset($tag[1]) && $tag[1] === $articleCoordinate) {
                // Article already in index, return existing event
                $this->logger->debug('Article already in category index', [
                    'article_coordinate' => $articleCoordinate,
                    'event_id' => $categoryIndex->getId(),
                ]);
                return $categoryIndex;
            }
        }

        // Get the bot and private key for signing
        $bot = $nzine->getNzineBot();
        if (!$bot) {
            throw new \RuntimeException('Cannot sign category index: nzine bot not found');
        }

        $bot->setEncryptionService($this->encryptionService);
        $privateKey = $bot->getNsec();

        if (!$privateKey) {
            throw new \RuntimeException('Cannot sign category index: bot private key not found');
        }

        // Create a new Event object with ALL existing tags PLUS the new article tag
        $event = new Event();
        $event->setKind($categoryIndex->getKind());
        $event->setContent($categoryIndex->getContent() ?? '');
        $event->setPublicKey($categoryIndex->getPubkey());

        // Add ALL existing tags first
        foreach ($existingTags as $tag) {
            $event->addTag($tag);
        }

        // Add the new article coordinate tag
        $event->addTag(['a', $articleCoordinate]);

        // Sign the event with current timestamp
        $signer = new Sign();
        $signer->signEvent($event, $privateKey);

        // Convert to JSON and deserialize to NEW EventEntity
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $newEventEntity = $serializer->deserialize($event->toJson(), EventEntity::class, 'json');

        // Persist the NEW event entity
        $this->entityManager->persist($newEventEntity);

        $articleCount = count(array_filter($newEventEntity->getTags(), fn($tag) => $tag[0] === 'a'));

        $this->logger->debug('Created new category index event with article added', [
            'category_slug' => $this->extractSlugFromTags($newEventEntity->getTags()),
            'article_coordinate' => $articleCoordinate,
            'old_event_id' => $categoryIndex->getId(),
            'new_event_id' => $newEventEntity->getId(),
            'total_tags' => count($newEventEntity->getTags()),
            'article_count' => $articleCount,
        ]);

        return $newEventEntity;
    }

    /**
     * Re-sign and save category index events
     * Should be called after all articles have been added to ensure valid signatures
     *
     * @param array $categoryIndices Map of category slug => EventEntity
     * @param Nzine $nzine The nzine entity
     */
    public function resignCategoryIndices(array $categoryIndices, Nzine $nzine): void
    {
        if (empty($categoryIndices)) {
            return;
        }

        $bot = $nzine->getNzineBot();
        if (!$bot) {
            $this->logger->warning('Cannot re-sign category indices: nzine bot not found', [
                'nzine_id' => $nzine->getId(),
            ]);
            return;
        }

        $bot->setEncryptionService($this->encryptionService);
        $privateKey = $bot->getNsec();

        if (!$privateKey) {
            $this->logger->warning('Cannot re-sign category indices: bot private key not found', [
                'nzine_id' => $nzine->getId(),
            ]);
            return;
        }

        $signer = new Sign();
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        foreach ($categoryIndices as $slug => $categoryIndex) {
            try {
                // Create a new Event from the existing EventEntity
                $event = new Event();
                $event->setKind($categoryIndex->getKind());
                $event->setContent($categoryIndex->getContent() ?? '');
                $event->setPublicKey($categoryIndex->getPubkey());

                // Add all tags from the category index
                foreach ($categoryIndex->getTags() as $tag) {
                    $event->addTag($tag);
                }

                // Sign the event with current timestamp (creates new ID)
                $signer->signEvent($event, $privateKey);

                // Deserialize to a NEW EventEntity (not updating the old one)
                $newEventEntity = $serializer->deserialize($event->toJson(), EventEntity::class, 'json');

                // Persist the NEW event entity
                $this->entityManager->persist($newEventEntity);

                $this->logger->info('Created new category index event (re-signed)', [
                    'category_slug' => $slug,
                    'old_event_id' => $categoryIndex->getId(),
                    'new_event_id' => $newEventEntity->getId(),
                    'article_count' => count(array_filter($newEventEntity->getTags(), fn($tag) => $tag[0] === 'a')),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to re-sign category index', [
                    'category_slug' => $slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Category indices re-signed and new events created', [
            'nzine_id' => $nzine->getId(),
            'count' => count($categoryIndices),
        ]);
    }
}
