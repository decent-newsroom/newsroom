<?php

namespace App\Factory;

use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use InvalidArgumentException;

/**
 * Map nostr events of kind 30023 to local article entity
 */
class ArticleFactory
{
    public function createFromLongFormContentEvent($source): Article
    {
        if ($source->kind !== KindsEnum::LONGFORM->value) {
            throw new InvalidArgumentException('Source event kind should be 30023');
        }
        $entity = new Article();
        $entity->setRaw($source);
        $entity->setEventId($source->id);
        $entity->setCreatedAt(\DateTimeImmutable::createFromFormat('U', (string)$source->created_at));
        $entity->setContent($source->content);
        $entity->setKind(KindsEnum::from($source->kind));
        $entity->setPubkey($source->pubkey);
        $entity->setSig($source->sig);
        $entity->setEventStatus(EventStatusEnum::PUBLISHED);
        $entity->setRatingNegative(0);
        $entity->setRatingPositive(0);
        // process tags
        foreach ($source->tags as $tag) {
            switch ($tag[0]) {
                case 'd':
                    $entity->setSlug($tag[1]);
                    break;
                case 'title':
                    $entity->setTitle($tag[1]);
                    break;
                case 'summary':
                    $entity->setSummary($tag[1]);
                    break;
                case 'image':
                    $entity->setImage($tag[1]);
                    break;
                case 'published_at':
                    $entity->setPublishedAt(\DateTimeImmutable::createFromFormat('U', (string)$tag[1]));
                    break;
                case 't':
                    $entity->addTopic($tag[1]);
                    break;
                case 'client':
                    // used to signal where it was created, ignored for now
                    break;
            }
        }
        return $entity;
    }
}
