<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\ExpressionBundle\Service\ExpressionService;
use App\Message\EvaluateSpellMessage;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Util\NostrKeyUtil;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NEvent;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Browse and execute NIP-A7 spells (kind 777).
 *
 * Spells are regular (non-replaceable) events addressed by event id;
 * the canonical URL is /spell/{nevent}. Execution requires an authenticated
 * user because spell evaluation is performed against the viewer's runtime
 * context (contacts, interests, relay list).
 */
final class SpellController extends AbstractController
{
    #[Route('/spells', name: 'spell_list')]
    public function list(): Response
    {
        return $this->render('spells/index.html.twig');
    }

    #[Route('/spell/{nevent}', name: 'spell_view', requirements: ['nevent' => '^nevent1[a-z0-9]+$'])]
    public function view(
        string $nevent,
        Request $request,
        EventRepository $eventRepository,
        ExpressionService $expressionService,
        RedisCacheService $redisCacheService,
        MessageBusInterface $messageBus,
    ): Response {
        $eventId = $this->decodeNevent($nevent);
        if ($eventId === null) {
            throw $this->createNotFoundException('Invalid nevent.');
        }

        $spell = $eventRepository->find($eventId);
        if (!$spell || $spell->getKind() !== KindsEnum::SPELL->value) {
            throw $this->createNotFoundException('Spell not found.');
        }

        [$title, $description] = $this->extractMeta($spell);

        try {
            $authorNpub = (new Key())->convertPublicKeyToBech32($spell->getPubkey());
        } catch (\Throwable) {
            $authorNpub = $spell->getPubkey();
        }

        // Spell execution is always evaluated against the viewer's context
        // (per NIP-A7 rules around $me / $contacts), so an authenticated
        // user is required even for spells that don't reference variables.
        $user = $this->getUser();
        if (!$user) {
            return $this->render('spells/view.html.twig', [
                'title' => $title,
                'description' => $description,
                'authorNpub' => $authorNpub,
                'nevent' => $nevent,
                'articles' => [],
                'authorsMetadata' => [],
                'needsLogin' => true,
            ]);
        }

        $userIdentifier = $user->getUserIdentifier();
        $userPubkey = NostrKeyUtil::isNpub($userIdentifier)
            ? NostrKeyUtil::npubToHex($userIdentifier)
            : $userIdentifier;

        $cachedResults = $expressionService->getCachedSpellResults($spell, $userPubkey);
        if ($cachedResults !== null) {
            return $this->renderResults(
                $cachedResults, $title, $description, $authorNpub, $nevent, $request, $redisCacheService
            );
        }

        $cacheKey = $expressionService->buildSpellCacheKey($spell, $userPubkey);
        $messageBus->dispatch(new EvaluateSpellMessage(
            spellEventId: $spell->getId(),
            userPubkey: $userPubkey,
            cacheKey: $cacheKey,
        ));

        return $this->render('spells/view_loading.html.twig', [
            'title' => $title,
            'description' => $description,
            'authorNpub' => $authorNpub,
            'nevent' => $nevent,
            'mercureTopic' => '/spell-eval/' . $cacheKey,
        ]);
    }

    /**
     * JSON endpoint powering the click-to-use spell picker in the expression
     * builder. Returns a compact list of spells with their event id so the
     * caller can write it into an `input`/`e` clause.
     */
    #[Route('/api/spells', name: 'api_spells_list', methods: ['GET'])]
    public function apiList(
        Request $request,
        EventRepository $eventRepository,
        RedisCacheService $redisCacheService,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));
        $topic = trim((string) $request->query->get('t', ''));
        $limit = max(1, min(200, (int) $request->query->get('limit', 100)));

        $events = $eventRepository->findBy(
            ['kind' => KindsEnum::SPELL->value],
            ['created_at' => 'DESC'],
            $limit * 2, // overfetch a bit so filtering still leaves headroom
        );

        $pubkeys = array_unique(array_map(fn(Event $e) => $e->getPubkey(), $events));
        $metadataMap = !empty($pubkeys) ? $redisCacheService->getMultipleMetadata($pubkeys) : [];

        $keyHelper = new Key();
        $out = [];

        foreach ($events as $event) {
            [$name, $description] = $this->extractMeta($event);

            $topics = [];
            $kinds = [];
            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || !isset($tag[0], $tag[1])) {
                    continue;
                }
                if ($tag[0] === 't') {
                    $topics[] = $tag[1];
                } elseif ($tag[0] === 'k') {
                    $kinds[] = (int) $tag[1];
                }
            }

            // Filters
            if ($query !== '' && stripos($name . ' ' . ($description ?? ''), $query) === false) {
                continue;
            }
            if ($topic !== '' && !in_array($topic, $topics, true)) {
                continue;
            }

            $pubkey = $event->getPubkey();
            $meta = $metadataMap[$pubkey] ?? null;
            $std = $meta instanceof UserMetadata ? $meta->toStdClass() : $meta;

            try {
                $npub = $keyHelper->convertPublicKeyToBech32($pubkey);
            } catch (\Throwable) {
                $npub = $pubkey;
            }

            try {
                $nevent = (string) Bech32::nevent([
                    'id' => $event->getId(),
                    'relays' => [],
                    'author' => $pubkey,
                    'kind' => KindsEnum::SPELL->value,
                ]);
            } catch (\Throwable) {
                $nevent = $event->getId();
            }

            $out[] = [
                'id' => $event->getId(),
                'nevent' => $nevent,
                'name' => $name,
                'description' => $description,
                'kinds' => array_values(array_unique($kinds)),
                'topics' => array_values(array_unique($topics)),
                'author_npub' => $npub,
                'author_name' => $std->display_name ?? $std->name ?? '',
                'author_picture' => $std->picture ?? '',
                'created_at' => $event->getCreatedAt(),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return new JsonResponse(['spells' => $out]);
    }

    /**
     * @param array $results NormalizedItem[]
     */
    private function renderResults(
        array $results,
        string $title,
        ?string $description,
        string $authorNpub,
        string $nevent,
        Request $request,
        RedisCacheService $redisCacheService,
    ): Response {
        $allArticles = array_map(fn($item) => $item->getEvent(), $results);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $pager = new Pagerfanta(new ArrayAdapter($allArticles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage(min($page, max(1, $pager->getNbPages())));

        $articles = array_slice($allArticles, ($pager->getCurrentPage() - 1) * $perPage, $perPage);

        $articlePubkeys = array_unique(array_map(fn($a) => $a->getPubkey(), $articles));
        $articleMetaMap = !empty($articlePubkeys)
            ? $redisCacheService->getMultipleMetadata($articlePubkeys)
            : [];

        $authorsMetadataStd = [];
        foreach ($articleMetaMap as $pk => $meta) {
            $authorsMetadataStd[$pk] = $meta instanceof UserMetadata
                ? $meta->toStdClass() : $meta;
        }

        return $this->render('spells/view.html.twig', [
            'title' => $title,
            'description' => $description,
            'authorNpub' => $authorNpub,
            'nevent' => $nevent,
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadataStd,
            'pager' => $pager,
        ]);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function extractMeta(Event $spell): array
    {
        $title = '';
        $description = null;
        foreach ($spell->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0])) {
                continue;
            }
            if (($tag[0] === 'name' || $tag[0] === 'title') && !empty($tag[1])) {
                $title = $tag[1];
            } elseif ($tag[0] === 'summary' && !empty($tag[1])) {
                $description = $tag[1];
            } elseif ($tag[0] === 'alt' && $description === null && !empty($tag[1])) {
                $description = $tag[1];
            }
        }
        if ($title === '') {
            $title = substr($spell->getId(), 0, 12);
        }
        if ($description === null && $spell->getContent() !== '') {
            $description = $spell->getContent();
        }
        return [$title, $description];
    }

    private function decodeNevent(string $nevent): ?string
    {
        try {
            $decoded = new Bech32($nevent);
            if ($decoded->type !== 'nevent') {
                return null;
            }
            /** @var NEvent $data */
            $data = $decoded->data;
            return $data->id;
        } catch (\Throwable) {
            return null;
        }
    }
}

