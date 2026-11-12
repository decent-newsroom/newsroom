<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Helper\NavigationBuilderTrait;
use App\Repository\EventRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\BookmarkReferencePage;
use App\Service\EventWorkspace\FollowPackMemberPage;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ReadingNookController extends AbstractController
{
    use NavigationBuilderTrait;

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        #[Autowire('%event_workspace_expansion_enabled%')]
        private readonly bool $expansionEnabled = true,
    ) {}

    private const SECTION_BOOKMARKS = 'bookmarks';
    private const SECTION_INTERESTS = 'interests';
    private const SECTION_READING_LISTS = 'reading_lists';
    private const SECTION_FOLLOW_PACKS = 'follow_packs';
    private const SECTION_SUBSCRIPTIONS = 'subscriptions';

    /**
     * @var array<string, string>
     */
    private const SECTION_LABELS = [
        self::SECTION_BOOKMARKS => 'reading_nook.section.bookmarks',
        self::SECTION_INTERESTS => 'reading_nook.section.interests',
        self::SECTION_READING_LISTS => 'reading_nook.section.reading_lists',
        self::SECTION_FOLLOW_PACKS => 'reading_nook.section.follow_packs',
        self::SECTION_SUBSCRIPTIONS => 'reading_nook.section.subscriptions',
    ];

    /**
     * @var int[]
     */
    private const OWNED_LIST_KINDS = [
        KindsEnum::BOOKMARKS->value,
        KindsEnum::BOOKMARK_SETS->value,
        KindsEnum::INTERESTS->value,
        KindsEnum::INTEREST_SETS->value,
        KindsEnum::FOLLOW_PACK->value,
        KindsEnum::PUBLICATION_INDEX->value,
        KindsEnum::CURATION_SET->value,
        KindsEnum::CURATION_VIDEOS->value,
        KindsEnum::CURATION_PICTURES->value,
    ];

    #[Route('/reading-nook', name: 'reading_nook')]
    #[IsGranted('ROLE_USER')]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        EventRepository $eventRepository,
        UpdateSubscriptionRepository $subscriptionRepository,
    ): Response {
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('home');
        }

        /** @var User $user */

        $pubkeyHex = $this->userPubkeyHex($user);
        $events = $this->loadOwnedEvents($em, $pubkeyHex);

        $items = [
            ...$this->buildEntriesFromEvents($events),
            ...$this->buildSubscriptionEntries($user, $subscriptionRepository, $eventRepository, $em),
        ];

        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'section' => (string) $request->query->get('section', 'all'),
            'timespan' => (string) $request->query->get('timespan', 'all'),
            'tag' => strtolower(trim((string) $request->query->get('tag', ''))),
        ];

        $filteredItems = $this->applyFilters($items, $filters);

        return $this->render('reader/reading_nook/index.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'filters' => $filters,
            'items' => $filteredItems,
            'itemsBySection' => $this->groupItemsBySection($filteredItems),
            'sectionKeys' => array_keys(self::SECTION_LABELS),
            'sectionLabels' => self::SECTION_LABELS,
            'sectionCounts' => $this->countBySection($items),
            'filteredSectionCounts' => $this->countBySection($filteredItems),
            'allTags' => $this->buildTagFacets($items),
            'timespanOptions' => $this->timespanOptions(),
            'totalCount' => count($items),
            'filteredCount' => count($filteredItems),
        ]);
    }

    #[Route('/reading-nook/bookmarks/{eventId}/references', name: 'reading_nook_bookmark_references', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bookmarkReferences(
        string $eventId,
        Request $request,
        EntityManagerInterface $em,
        BookmarkReferencePage $references,
        DeletedEventRepository $deletions,
        ContentAuthorAccessPolicy $authorAccess,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($page === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid page.');
        }

        $pubkey = $this->userPubkeyHex($user);
        $authorAccess->assertReadable($pubkey);
        $event = $em->getRepository(Event::class)->findOneBy([
            'id' => $eventId,
            'pubkey' => $pubkey,
        ]);
        if (!$event instanceof Event
            || !in_array($event->getKind(), [KindsEnum::BOOKMARKS->value, KindsEnum::BOOKMARK_SETS->value], true)
            || $deletions->isSuppressed(
                $event->getId(),
                $event->getKind(),
                $event->getPubkey(),
                $event->getSlug(),
                $event->getCreatedAt(),
            )) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('reader/reading_nook/_bookmark_references.html.twig', [
            'bookmarkPage' => $references->fromAuthorizedEvent($event, $page),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/reading-nook/follow-packs/{eventId}/members', name: 'reading_nook_follow_pack_members', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function followPackMembers(
        string $eventId,
        Request $request,
        EntityManagerInterface $em,
        FollowPackMemberPage $members,
        DeletedEventRepository $deletions,
        ContentAuthorAccessPolicy $authorAccess,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($page === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid page.');
        }

        // Never accept owner or kind from the browser. Pin paging to this exact
        // signed revision, not the latest event for a mutable coordinate.
        $pubkey = $this->userPubkeyHex($user);
        $authorAccess->assertReadable($pubkey);
        $event = $em->getRepository(Event::class)->findOneBy([
            'id' => $eventId,
            'pubkey' => $pubkey,
            'kind' => KindsEnum::FOLLOW_PACK->value,
        ]);
        if (!$event instanceof Event || $deletions->isSuppressed(
            $event->getId(),
            $event->getKind(),
            $event->getPubkey(),
            $event->getSlug(),
            $event->getCreatedAt(),
        )) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('reader/reading_nook/_follow_pack_members.html.twig', [
            'memberPage' => $members->fromAuthorizedEvent($event, $page),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/reading-nook/publication-indexes/{eventId}/toc', name: 'reading_nook_publication_toc', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function publicationToc(
        string $eventId,
        Request $request,
        EntityManagerInterface $em,
        PublicationTocPage $toc,
        DeletedEventRepository $deletions,
        ContentAuthorAccessPolicy $authorAccess,
        UserMuteListService $userMuteList,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($page === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid page.');
        }

        // Only this owner's exact signed index revision grants root access.
        // Eligible nested indexes may expose allowlisted metadata after their
        // own local read-policy checks; target bodies/actions are never exposed.
        $pubkey = $this->userPubkeyHex($user);
        $authorAccess->assertReadable($pubkey);
        $event = $em->getRepository(Event::class)->findOneBy([
            'id' => $eventId,
            'pubkey' => $pubkey,
            'kind' => KindsEnum::PUBLICATION_INDEX->value,
        ]);
        if (!$event instanceof Event || $deletions->isSuppressed(
            $event->getId(),
            $event->getKind(),
            $event->getPubkey(),
            $event->getSlug(),
            $event->getCreatedAt(),
        )) {
            throw $this->createNotFoundException();
        }
        $mutedPubkeys = $userMuteList->getMutedPubkeysStrict($pubkey);

        $response = $this->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => $toc->fromAuthorizedEvent($event, $page, readerMutedPubkeys: $mutedPubkeys),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Nested kind-30040 targets are public signed events, not inventory rows.
     * The exact source ID is re-read and its author/tombstone policy is
     * rechecked on every lazy fragment; no Article or generated body is used.
     */
    #[Route('/reading-nook/publication-indexes/{eventId}/nested-toc', name: 'reading_nook_nested_publication_toc', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function nestedPublicationToc(
        string $eventId,
        Request $request,
        EntityManagerInterface $em,
        PublicationTocPage $toc,
        DeletedEventRepository $deletions,
        ContentAuthorAccessPolicy $authorAccess,
        HiddenCoordinateRepository $hiddenCoordinates,
        UserMuteListService $userMuteList,
        PublicationTocTraversalSigner $traversalSigner,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = $this->tocPageNumber($request);
        $path = $this->tocAncestorPath($request);
        $occurrenceId = $request->query->get('occurrence');
        $coordinate = $request->query->get('coordinate');
        $signature = $request->query->get('signature');
        if ($path === []
            || !is_string($occurrenceId)
            || preg_match('/^[0-9a-f]{64}$/', $occurrenceId) !== 1
            || !is_string($coordinate)
            || !$this->isCanonicalPublicationCoordinate($coordinate)
            || !$traversalSigner->isValid($eventId, $page, $path, $occurrenceId, $coordinate, is_string($signature) ? $signature : null)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid nested TOC path.');
        }

        $readerPubkey = $this->userPubkeyHex($user);
        $mutedPubkeys = $userMuteList->getMutedPubkeysStrict($readerPubkey);
        $targetPubkey = explode(':', $coordinate, 3)[1];
        if (in_array($targetPubkey, $mutedPubkeys, true)
            || $hiddenCoordinates->findHiddenCoordinates([$coordinate]) !== []) {
            throw $this->createNotFoundException();
        }

        $event = $em->getRepository(Event::class)->findOneBy([
            'id' => $eventId,
            'kind' => KindsEnum::PUBLICATION_INDEX->value,
        ]);
        if (!$event instanceof Event
            || in_array(strtolower($event->getId()), array_map('strtolower', $path), true)
            || $this->publicationCoordinate($event) !== $coordinate) {
            throw $this->createNotFoundException();
        }

        $authorAccess->assertReadable($event->getPubkey());
        if ($deletions->isSuppressed(
            $event->getId(),
            $event->getKind(),
            $event->getPubkey(),
            $event->getSlug(),
            $event->getCreatedAt(),
        )) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => $toc->fromAuthorizedEvent(
                $event,
                $page,
                $path,
                $occurrenceId,
                'reading_nook_nested_publication_toc',
                $mutedPubkeys,
            ),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function tocPageNumber(Request $request): int
    {
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($page === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid page.');
        }

        return $page;
    }

    /**
     * The signed path is integrity-protected traversal state, not an
     * authorization token. Every source event is independently authorized.
     *
     * @return list<string>
     */
    private function tocAncestorPath(Request $request): array
    {
        $rawPath = $request->query->all()['path'] ?? '';
        if (!is_string($rawPath)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid nested TOC path.');
        }
        if ($rawPath === '') {
            return [];
        }

        $path = explode(',', $rawPath);
        $invalidId = false;
        foreach ($path as $id) {
            if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
                $invalidId = true;
                break;
            }
        }
        if (count($path) >= PublicationTocPage::MAX_EXPANSION_DEPTH
            || strlen($rawPath) > PublicationTocPage::MAX_EXPANSION_DEPTH * 65
            || $invalidId
            || count(array_unique($path)) !== count($path)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid nested TOC path.');
        }

        return $path;
    }

    private function isCanonicalPublicationCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);

        return count($parts) === 3
            && $parts[0] === (string) KindsEnum::PUBLICATION_INDEX->value
            && preg_match('/^[0-9a-f]{64}$/', $parts[1]) === 1
            && $coordinate === $this->publicationCoordinateParts($parts[1], $parts[2]);
    }

    private function publicationCoordinate(Event $event): string
    {
        $dTag = $event->getDTag() ?? '';
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                $dTag = $tag[1];
                break;
            }
        }

        return $this->publicationCoordinateParts(strtolower($event->getPubkey()), $dTag);
    }

    private function publicationCoordinateParts(string $pubkey, string $dTag): string
    {
        return KindsEnum::PUBLICATION_INDEX->value . ':' . strtolower($pubkey) . ':' . $dTag;
    }

    private function userPubkeyHex(User $user): string
    {
        $npub = strtolower(trim($user->getUserIdentifier()));
        if (str_starts_with($npub, 'nostr:')) {
            $npub = substr($npub, 6);
        }

        return PublicKey::fromBech32($npub)?->toHex()
            ?? throw new \InvalidArgumentException('Not a valid npub');
    }

    /**
     * @return Event[]
     */
    private function loadOwnedEvents(EntityManagerInterface $em, string $pubkeyHex): array
    {
        /** @var Event[] $events */
        $events = $em->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind IN (:kinds)')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kinds', self::OWNED_LIST_KINDS)
            ->orderBy('e.created_at', \SortDirection::Descending)
            ->setMaxResults(400)
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * @param Event[] $events
     * @return array<int, array<string, mixed>>
     */
    private function buildEntriesFromEvents(array $events): array
    {
        $entries = [];
        $dedupe = [];

        foreach ($events as $event) {
            $kind = $event->getKind();
            $section = $this->resolveEventSection($kind);
            if ($section === null) {
                continue;
            }

            $dedupeKey = $this->eventDedupeKey($event);
            if (isset($dedupe[$dedupeKey])) {
                continue;
            }
            $dedupe[$dedupeKey] = true;

            $tags = $event->getTags();
            $slug = $event->getSlug();
            $title = $this->firstTagValue($tags, ['title', 'name']) ?? $slug ?? $this->defaultTitleForKind($kind);
            $summary = $this->firstTagValue($tags, ['summary', 'description']) ?? $this->contentSnippet($event->getContent(), 220);
            $topicTags = $this->extractTagValues($tags, 't');

            $entries[] = [
                'eventId' => $event->getId(),
                'section' => $section,
                'kind' => $kind,
                'title' => $title,
                'summary' => $summary,
                'tags' => $topicTags,
                'createdAt' => (new \DateTimeImmutable())->setTimestamp($event->getCreatedAt()),
                'updatedAt' => null,
                'url' => $this->buildEventUrl($section, $kind, $event->getPubkey(), $slug),
                'manageUrl' => $this->buildManageUrl($section, $kind, $slug),
                'meta' => $this->buildEventMeta($event, $section),
                'search' => $this->buildSearchBlob([
                    $title,
                    $summary,
                    $event->getContent(),
                    implode(' ', $topicTags),
                    $slug,
                ]),
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array{q:string, section:string, timespan:string, tag:string} $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $items, array $filters): array
    {
        $query = mb_strtolower($filters['q']);
        $selectedSection = $filters['section'];
        $selectedTag = $filters['tag'];
        $cutoff = $this->resolveTimespanCutoff($filters['timespan']);

        $filtered = array_filter($items, function (array $item) use ($query, $selectedSection, $selectedTag, $cutoff): bool {
            if ($selectedSection !== 'all' && $item['section'] !== $selectedSection) {
                return false;
            }

            if ($selectedTag !== '' && !in_array($selectedTag, $item['tags'], true)) {
                return false;
            }

            if ($cutoff !== null && $item['createdAt'] < $cutoff) {
                return false;
            }

            if ($query !== '' && !str_contains((string) $item['search'], $query)) {
                return false;
            }

            return true;
        });

        usort($filtered, static function (array $a, array $b): int {
            /** @var \DateTimeImmutable $left */
            $left = $a['createdAt'];
            /** @var \DateTimeImmutable $right */
            $right = $b['createdAt'];

            return $right <=> $left;
        });

        return array_values($filtered);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupItemsBySection(array $items): array
    {
        $grouped = [];
        foreach (array_keys(self::SECTION_LABELS) as $section) {
            $grouped[$section] = [];
        }

        foreach ($items as $item) {
            $grouped[$item['section']][] = $item;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    private function countBySection(array $items): array
    {
        $counts = [];
        foreach (array_keys(self::SECTION_LABELS) as $section) {
            $counts[$section] = 0;
        }

        foreach ($items as $item) {
            $counts[$item['section']]++;
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function buildTagFacets(array $items): array
    {
        $weights = [];

        foreach ($items as $item) {
            foreach ($item['tags'] as $tag) {
                if ($tag === '') {
                    continue;
                }
                $weights[$tag] = ($weights[$tag] ?? 0) + 1;
            }
        }

        arsort($weights);

        return array_keys($weights);
    }

    /**
     * @return array<string, string>
     */
    private function timespanOptions(): array
    {
        return [
            'all' => 'reading_nook.timespan.all',
            '7d' => 'reading_nook.timespan.7d',
            '30d' => 'reading_nook.timespan.30d',
            '90d' => 'reading_nook.timespan.90d',
            '365d' => 'reading_nook.timespan.365d',
        ];
    }

    private function resolveTimespanCutoff(string $timespan): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match ($timespan) {
            '7d' => $now->sub(new \DateInterval('P7D')),
            '30d' => $now->sub(new \DateInterval('P30D')),
            '90d' => $now->sub(new \DateInterval('P90D')),
            '365d' => $now->sub(new \DateInterval('P365D')),
            default => null,
        };
    }

    private function resolveEventSection(int $kind): ?string
    {
        return match ($kind) {
            KindsEnum::BOOKMARKS->value, KindsEnum::BOOKMARK_SETS->value => self::SECTION_BOOKMARKS,
            KindsEnum::INTERESTS->value, KindsEnum::INTEREST_SETS->value => self::SECTION_INTERESTS,
            KindsEnum::FOLLOW_PACK->value => self::SECTION_FOLLOW_PACKS,
            KindsEnum::PUBLICATION_INDEX->value,
            KindsEnum::CURATION_SET->value,
            KindsEnum::CURATION_VIDEOS->value,
            KindsEnum::CURATION_PICTURES->value => self::SECTION_READING_LISTS,
            default => null,
        };
    }

    private function eventDedupeKey(Event $event): string
    {
        $kind = $event->getKind();

        if ($kind >= 10000 && $kind <= 19999) {
            return sprintf('%d:%s', $kind, $event->getPubkey());
        }

        if ($kind >= 30000 && $kind <= 39999) {
            return sprintf('%d:%s:%s', $kind, $event->getPubkey(), $event->getDTag() ?? '');
        }

        return $event->getId();
    }

    private function buildEventUrl(string $section, int $kind, string $pubkey, ?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        try {
            $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($pubkey));
        } catch (\Throwable) {
            return null;
        }

        return match ($section) {
            self::SECTION_READING_LISTS => $kind === KindsEnum::PUBLICATION_INDEX->value
                ? $this->generateUrl('reading-list', ['npub' => $npub, 'slug' => $slug])
                : $this->generateUrl('curation-set', ['npub' => $npub, 'kind' => $kind, 'slug' => $slug]),
            self::SECTION_FOLLOW_PACKS => $this->generateUrl('follow_pack_view', ['npub' => $npub, 'dtag' => $slug]),
            self::SECTION_INTERESTS => $kind === KindsEnum::INTEREST_SETS->value
                ? $this->generateUrl('interest_set_view', ['dTag' => $slug])
                : $this->generateUrl('my_interests'),
            self::SECTION_BOOKMARKS => $this->generateUrl('my_bookmarks'),
            default => null,
        };
    }

    private function buildManageUrl(string $section, int $kind, ?string $slug): ?string
    {
        if ($section === self::SECTION_READING_LISTS && $slug !== null && $slug !== '' && $kind === KindsEnum::PUBLICATION_INDEX->value) {
            return $this->generateUrl('read_wizard_articles', ['load' => $slug]);
        }

        if ($section === self::SECTION_FOLLOW_PACKS) {
            return $this->generateUrl('follow_pack_setup');
        }

        if ($section === self::SECTION_INTERESTS) {
            if ($kind === KindsEnum::INTEREST_SETS->value && $slug !== null && $slug !== '') {
                return $this->generateUrl('interest_set_edit', ['dTag' => $slug]);
            }

            return $this->generateUrl('my_interests');
        }

        if ($section === self::SECTION_BOOKMARKS) {
            return $this->generateUrl('my_bookmarks');
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSubscriptionEntries(
        User $user,
        UpdateSubscriptionRepository $subscriptionRepository,
        EventRepository $eventRepository,
        EntityManagerInterface $em,
    ): array
    {
        $entries = [];

        foreach ($subscriptionRepository->findActiveForUser($user) as $subscription) {
            $type = $subscription->getSourceType();
            $sourceValue = $subscription->getSourceValue();
            $title = $this->resolveSubscriptionTitle($type, $sourceValue, $eventRepository, $em)
                ?? $subscription->getLabel()
                ?? $this->subscriptionFallbackTitle($type, $sourceValue);
            $summary = $this->subscriptionSummary($type);

            $entries[] = [
                'section' => self::SECTION_SUBSCRIPTIONS,
                'kind' => $type->value,
                'title' => $title,
                'summary' => $summary,
                'tags' => ['subscription', $type->value],
                'createdAt' => $subscription->getCreatedAt(),
                'updatedAt' => null,
                'url' => $this->generateUrl('updates_subscriptions'),
                'manageUrl' => $this->generateUrl('updates_subscriptions'),
                'meta' => [
                    'sourceTypeLabel' => $type->label(),
                ],
                'search' => $this->buildSearchBlob([
                    $title,
                    $summary,
                    $sourceValue,
                    $type->value,
                ]),
            ];
        }

        return $entries;
    }

    private function resolveSubscriptionTitle(
        UpdateSourceTypeEnum $type,
        string $sourceValue,
        EventRepository $eventRepository,
        EntityManagerInterface $em,
    ): ?string {
        if ($type === UpdateSourceTypeEnum::NPUB) {
            try {
                $pubkey = str_starts_with(strtolower(trim((string) ($sourceValue))), 'npub1')
                    ? (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($sourceValue))
                    : $sourceValue;

                if (PublicKey::fromHex(strtolower(trim((string) ($pubkey)))) === null) {
                    return null;
                }

                $metadata = $this->redisCacheService->getMetadata($pubkey);
                foreach ([$metadata->displayName, $metadata->name] as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        return trim($name);
                    }
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        }

        $parts = explode(':', $sourceValue, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$kindRaw, $pubkey, $dTag] = $parts;
        $kind = (int) $kindRaw;

        if ($type === UpdateSourceTypeEnum::PUBLICATION) {
            $magazine = $em->getRepository(Magazine::class)->findOneBy(['pubkey' => $pubkey, 'slug' => $dTag]);
            if ($magazine instanceof Magazine && $magazine->getTitle() !== null) {
                return $magazine->getTitle();
            }
        }

        $event = $eventRepository->findByNaddr($kind, $pubkey, $dTag);
        if ($event === null) {
            return null;
        }

        $title = $event->getTitle();
        if ($title !== null) {
            return $title;
        }

        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === 'name' && isset($tag[1])) {
                return (string) $tag[1];
            }
        }

        return null;
    }

    private function subscriptionFallbackTitle(UpdateSourceTypeEnum $type, string $sourceValue): string
    {
        if ($type === UpdateSourceTypeEnum::NPUB) {
            return 'npub:' . substr($sourceValue, 0, 12) . '...';
        }

        $parts = explode(':', $sourceValue, 3);

        return $parts[2] ?? $sourceValue;
    }

    private function subscriptionSummary(UpdateSourceTypeEnum $type): string
    {
        return match ($type) {
            UpdateSourceTypeEnum::NPUB => 'Author updates subscription',
            UpdateSourceTypeEnum::PUBLICATION => 'Publication updates subscription',
            UpdateSourceTypeEnum::NIP51_SET => 'NIP-51 set updates subscription',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventMeta(Event $event, string $section): array
    {
        $meta = [
            'slug' => $event->getSlug(),
        ];

        $tags = $event->getTags();

        if ($section === self::SECTION_FOLLOW_PACKS) {
            $meta['memberCount'] = count($this->extractTagValues($tags, 'p'));
        }

        if ($section === self::SECTION_INTERESTS) {
            $meta['topicCount'] = count($this->extractTagValues($tags, 't'));
        }

        if ($section === self::SECTION_READING_LISTS) {
            $meta['itemCount'] = count($this->extractTagValues($tags, 'a')) + count($this->extractTagValues($tags, 'e'));
        }

        if ($section === self::SECTION_BOOKMARKS) {
            $meta['itemCount'] = count($this->extractTagValues($tags, 'a'))
                + count($this->extractTagValues($tags, 'e'))
                + count($this->extractTagValues($tags, 'p'))
                + count($this->extractTagValues($tags, 't'));
        }

        return $meta;
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @return array<int, string>
     */
    private function extractTagValues(array $tags, string $name): array
    {
        $values = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== $name || !isset($tag[1])) {
                continue;
            }

            $values[] = strtolower(trim((string) $tag[1]));
        }

        return $this->normalizeTags($values);
    }

    /**
     * @param string[] $names
     */
    private function firstTagValue(array $tags, array $names): ?string
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1])) {
                continue;
            }

            if (in_array((string) $tag[0], $names, true)) {
                $value = trim((string) $tag[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function contentSnippet(string $content, int $maxLen): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');

        if ($plain === '') {
            return '';
        }

        if (mb_strlen($plain) <= $maxLen) {
            return $plain;
        }

        return mb_substr($plain, 0, $maxLen - 1) . '...';
    }

    private function defaultTitleForKind(int $kind): string
    {
        return match ($kind) {
            KindsEnum::BOOKMARKS->value => 'Bookmarks',
            KindsEnum::INTERESTS->value => 'My interests',
            default => 'Untitled',
        };
    }

    /**
     * @param array<int, mixed> $topics
     * @return array<int, string>
     */
    private function normalizeTags(array $topics): array
    {
        $normalized = [];

        foreach ($topics as $tag) {
            $value = strtolower(trim((string) $tag));
            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        ksort($normalized);

        return array_keys($normalized);
    }


    /**
     * @param array<int, string|null> $parts
     */
    private function buildSearchBlob(array $parts): string
    {
        $joined = implode(' ', array_filter(array_map(static fn(?string $value): string => (string) $value, $parts)));

        return mb_strtolower($joined);
    }
}
