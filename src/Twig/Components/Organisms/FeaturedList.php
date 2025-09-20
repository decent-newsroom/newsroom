<?php

namespace App\Twig\Components\Organisms;

use Elastica\Query;
use Elastica\Query\Terms;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
use swentel\nostr\Event\Event;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedList
{
    public string $mag;
    public string $category;
    public string $catSlug;
    public string $title;
    public array $list = [];

    public function __construct(
        private readonly CacheInterface $redisCache,
        private readonly FinderInterface $finder)
    {
    }

    /**
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    public function mount($category): void
    {
        $parts = explode(':', $category[1]);
        /** @var Event $catIndex */
        $catIndex = $this->redisCache->get('magazine-' . $parts[2], function (){
            throw new \Exception('Not found');
        });

        $slugs = [];
        foreach ($catIndex->getTags() as $tag) {
            if ($tag[0] === 'title') {
                $this->title = $tag[1];
            }
            if ($tag[0] === 'd') {
                $this->catSlug = $tag[1];
            }
            if ($tag[0] === 'a') {
                $parts = explode(':', $tag[1], 3);
                $slugs[] = end($parts);
                if (count($slugs) >= 5) {
                    break; // Limit to 4 items
                }
            }
        }

        $termsQuery = new Terms('slug', array_values($slugs));
        $query = new Query($termsQuery);
        $query->setSize(200); // Set size to exceed the number of articles we expect
        $articles = $this->finder->find($query);

        // Create a map of slug => item
        $slugMap = [];
        foreach ($articles as $article) {
            $slug = $article->getSlug();
            if ($slug !== '') {
                if (!isset($slugMap[$slug])) {
                    $slugMap[$slug] = $article;
                } elseif ($article->getCreatedAt() > $slugMap[$slug]->getCreatedAt()) {
                    $slugMap[$slug] = $article;
                }
            }
        }

        // Build ordered list based on original slugs order
        $orderedList = [];
        foreach ($slugs as $slug) {
            if (isset($slugMap[$slug])) {
                $orderedList[] = $slugMap[$slug];
            }
        }

        $this->list = array_slice($orderedList, 0, 4);
    }
}
