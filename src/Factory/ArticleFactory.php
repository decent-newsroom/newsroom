<?php

namespace App\Factory;

use App\Entity\Article;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use InvalidArgumentException;
use Mdanter\Ecc\Crypto\Signature\SchnorrSigner;

/**
 * Map nostr events of kind 30023, 30024 to local article entity
 */
class ArticleFactory
{
    public function createFromLongFormContentEvent($source): Article
    {
        if (!($source->kind === KindsEnum::LONGFORM->value ||
            $source->kind === KindsEnum::LONGFORM_DRAFT->value)) {
            throw new InvalidArgumentException('Source event kind should be 30023 or 30024');
        }

        $validity = (new SchnorrSigner())->verify($source->pubkey, $source->sig, $source->id);
        if (!$validity) {
            throw new InvalidArgumentException('Invalid event signature');
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
            if (!is_array($tag) || !isset($tag[0])) {
                continue;
            }
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
                    if ($time = \DateTimeImmutable::createFromFormat('U', (string)$tag[1])) {
                        $entity->setPublishedAt($time);
                    }
                    break;
                case 't':
                    $entity->addTopic($tag[1]);
                    break;
                case 'client':
                    // used to signal where it was created, ignored for now
                    break;
                // NIP-70 ["-"] (protected): intentionally NOT mapped here.
                // The factory has no knowledge of which relay an event came
                // from, and the `-` tag is a generic "please do not
                // re-broadcast" marker that any author can use for any
                // reason. Deciding whether an article is Essayist-exclusive
                // requires the relay-URL context (was it received from
                // strfry-essayist?) and is therefore handled higher up, in
                // ArticleEventProjector::projectArticleFromEvent() and the
                // dedicated essayist:subscribe-relay worker.
            }
        }
        return $entity;
    }
}
