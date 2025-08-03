<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NostrClient;
use App\Service\RedisCacheService;
use Elastica\Query\Terms;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    public function index($npub, NostrClient $nostrClient, RedisCacheService $redisCacheService, FinderInterface $finder): Response
    {
        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        $author = $redisCacheService->getMetadata($npub);
        // Retrieve long-form content for the author
        try {
            $list = $nostrClient->getLongFormContentForPubkey($npub);
        } catch (Exception $e) {
            $list = [];
        }
        // Also look for articles in the Elastica index
        $query = new Terms('pubkey', [$pubkey]);
        $list = array_merge($list, $finder->find($query, 25));

        $articles = [];
        // Deduplicate by slugs
        foreach ($list as $item) {
            if (!key_exists((string) $item->getSlug(), $articles)) {
                $articles[(string) $item->getSlug()] = $item;
            }
        }

        // Sort articles by date
        usort($articles, function ($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return $this->render('pages/author.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'articles' => $articles,
            'is_author_profile' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);
        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }
}
