<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Dto\EventWorkspace\EventPresentation;
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
use App\Service\EventWorkspace\DriveMountAccess;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\EventWorkspace\InventoryPolicyException;
use App\Service\EventWorkspace\FollowPackMemberPage;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationChapterPreview;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\EventWorkspace\PublicationTocDiscovery;
use App\Service\EventWorkspace\PublicationTocSourceAccess;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use AsciiDocConverter;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\LoggerInterface;

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
    private const SECTION_DRIVES = 'drives';
    private const SECTION_SUBSCRIPTIONS = 'subscriptions';

    /**
     * @var array<string, string>
     */
    private const SECTION_LABELS = [
        self::SECTION_BOOKMARKS => 'reading_nook.section.bookmarks',
        self::SECTION_INTERESTS => 'reading_nook.section.interests',
        self::SECTION_READING_LISTS => 'reading_nook.section.reading_lists',
        self::SECTION_FOLLOW_PACKS => 'reading_nook.section.follow_packs',
        self::SECTION_DRIVES => 'reading_nook.section.drives',
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
        DriveMountAccess $driveAccess,
        UpdateSubscriptionRepository $subscriptionRepository,
        EventPreviewAccessPolicy $eventAccess,
    ): Response {
        $user = $this->getUser();
        if ($user === null) {
            return $this->redirectToRoute('home');
        }

        /** @var User $user */

        $pubkeyHex = $this->userPubkeyHex($user);
        $filters = [
            'q' => trim((string) $request->query->get('q', '')),
            'section' => (string) $request->query->get('section', 'all'),
            'timespan' => (string) $request->query->get('timespan', 'all'),
            'tag' => strtolower(trim((string) $request->query->get('tag', ''))),
        ];

        $requestedPage = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 25)],
        ]) ?: 1;
        try {
            $muted = $eventAccess->inventoryMuteSet($pubkeyHex);
            $subscriptions = $this->buildSubscriptionEntries($user, $subscriptionRepository, $eventRepository, $eventAccess, $muted);
            $eventItems = (function () use ($eventRepository, $pubkeyHex, $driveAccess, $eventAccess, $muted): \Generator {
                $batch = [];
                foreach ($eventRepository->iterateReadingNookEvents($pubkeyHex, self::OWNED_LIST_KINDS, $this->expansionEnabled) as $event) {
                    if ($event->getKind() === KindsEnum::DRIVE->value
                        ? !$driveAccess->hasReadableDriveStructure($event)
                        : !$this->isSafeInventoryEvent($event)) {
                        continue;
                    }
                    $batch[] = $event;
                    if (count($batch) === EventPreviewAccessPolicy::INVENTORY_BATCH_SIZE) {
                        foreach ($eventAccess->readableInventoryEvents($batch, $muted) as $readable) {
                            yield $this->buildEntriesFromEvents([$readable])[0];
                        }
                        $batch = [];
                    }
                }
                foreach ($eventAccess->readableInventoryEvents($batch, $muted) as $readable) {
                    yield $this->buildEntriesFromEvents([$readable])[0];
                }
            })();
            $inventory = $this->paginateInventory($eventItems, $subscriptions, $filters, $requestedPage);
        } catch (InventoryPolicyException) {
            return $this->render('reader/reading_nook/inventory_error.html.twig', [
                'readingNookNav' => $this->buildReadingNookNav(),
            ], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE, headers: ['Cache-Control' => 'private, no-store']));
        }

        return $this->render('reader/reading_nook/index.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'workspacePubkey' => $pubkeyHex,
            'filters' => $filters,
            'items' => $inventory['items'],
            'itemsBySection' => $this->groupItemsBySection($inventory['items']),
            'sectionKeys' => array_keys(self::SECTION_LABELS),
            'sectionLabels' => self::SECTION_LABELS,
            'sectionCounts' => $inventory['sectionCounts'],
            'filteredSectionCounts' => $inventory['filteredSectionCounts'],
            'allTags' => $inventory['allTags'],
            'timespanOptions' => $this->timespanOptions(),
            'totalCount' => $inventory['totalCount'],
            'filteredCount' => $inventory['filteredCount'],
            'page' => $inventory['page'],
            'totalPages' => $inventory['totalPages'],
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
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
        if (!$event instanceof Event
            || EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())
            || $deletions->isSuppressed(
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
            'tocDiscoveryEnabled' => true,
            'tocChapterPreviewEnabled' => true,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/reading-nook/publication-indexes/{eventId}/toc/{position}/chapter-preview', name: 'reading_nook_publication_toc_chapter_preview', requirements: ['eventId' => '[0-9a-f]{64}', 'position' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function publicationTocChapterPreview(
        string $eventId,
        string $position,
        Request $request,
        PublicationTocSourceAccess $sourceAccess,
        PublicationChapterPreview $chapterPreview,
        PublicationTocTraversalSigner $traversalSigner,
        UserMuteListService $userMuteList,
        AsciiDocConverter $asciidocConverter,
        LoggerInterface $logger,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $tagPosition = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($tagPosition === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid tag position.');
        }
        $page = $this->tocPageNumber($request);
        $path = $this->tocAncestorPath($request);
        $occurrence = $request->query->get('occurrence');
        $coordinate = $request->query->get('coordinate');
        $signature = $request->query->get('signature');
        if ($path !== [] && (!is_string($occurrence)
            || preg_match('/^[0-9a-f]{64}$/', $occurrence) !== 1
            || !is_string($coordinate)
            || !$this->isCanonicalPublicationCoordinate($coordinate)
            || !$traversalSigner->isValid(
                $eventId,
                $page,
                $path,
                $occurrence,
                $coordinate,
                is_string($signature) ? $signature : null,
            ))) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid nested TOC path.');
        }
        if ($path === [] && ($occurrence !== null || $coordinate !== null || $signature !== null)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Unexpected nested TOC state.');
        }

        $targetEventId = $request->query->get('target');
        $targetOccurrence = $request->query->get('targetOccurrence');
        $targetCoordinate = $request->query->get('targetCoordinate');
        $targetSignature = $request->query->get('targetSignature');
        if (!is_string($targetEventId)
            || preg_match('/^[0-9a-f]{64}$/', $targetEventId) !== 1
            || !is_string($targetOccurrence)
            || preg_match('/^[0-9a-f]{64}$/', $targetOccurrence) !== 1
            || !is_string($targetCoordinate)
            || !$this->isCanonicalChapterCoordinate($targetCoordinate)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid chapter preview identity.');
        }
        $expectedTargetOccurrence = hash(
            'sha256',
            ($occurrence ?? 'root:' . strtolower($eventId))
                . ':chapter:' . $tagPosition . ':' . strtolower($targetEventId),
        );
        if (!hash_equals($expectedTargetOccurrence, $targetOccurrence)
            || !$traversalSigner->isValid(
                strtolower($targetEventId),
                1,
                [...$path, strtolower($eventId)],
                $targetOccurrence,
                $targetCoordinate,
                is_string($targetSignature) ? $targetSignature : null,
            )) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid chapter preview signature.');
        }

        $reader = $this->userPubkeyHex($user);
        $muted = $userMuteList->getMutedPubkeysStrict($reader);
        $source = $sourceAccess->authorize($reader, $eventId, $path, $muted);
        if ($path !== [] && $this->publicationCoordinate($source) !== $coordinate) {
            throw $this->createNotFoundException();
        }
        $chapter = $chapterPreview->fromAuthorizedIndexOccurrence(
            $source,
            $tagPosition,
            strtolower($targetEventId),
            $targetCoordinate,
            $muted,
        );

        $contentHtml = null;
        try {
            // Use the leaf AsciiDoc renderer, not the general embed converter:
            // chapter references in a chapter body are not expanded here.
            $contentHtml = $asciidocConverter->convert($chapter['content']);
        } catch (\Throwable $exception) {
            $logger->warning('Reading Nook chapter preview conversion failed.', [
                'event_id' => $chapter['eventId'],
                'error' => $exception->getMessage(),
            ]);
        }

        $response = $this->render('reader/reading_nook/_publication_chapter_preview.html.twig', [
            'chapter' => $chapter,
            'contentHtml' => $contentHtml,
            'frameId' => 'nook-chapter-' . ($occurrence ?? $eventId) . '-' . $tagPosition,
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
        PublicationTocSourceAccess $sourceAccess,
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
            || EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())
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
        // A signed path is not a lasting grant. Re-read the owner root and
        // every exact edge, including their current scope, on each fragment.
        $event = $sourceAccess->authorize($readerPubkey, $eventId, $path, $mutedPubkeys);
        if ($this->publicationCoordinate($event) !== $coordinate) {
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
            'tocDiscoveryEnabled' => true,
            'tocChapterPreviewEnabled' => true,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/reading-nook/publication-indexes/{eventId}/toc/{position}/discovery', name: 'reading_nook_publication_toc_discovery', requirements: ['eventId' => '[0-9a-f]{64}', 'position' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function publicationTocDiscovery(
        string $eventId,
        string $position,
        Request $request,
        PublicationTocPage $toc,
        PublicationTocSourceAccess $sourceAccess,
        PublicationTocTraversalSigner $signer,
        TargetPreviewResolver $targets,
        PublicationTocDiscovery $discovery,
        UserMuteListService $mutes,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')
            && !$this->isCsrfTokenValid('toc-discovery:' . $eventId . ':' . $position, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }
        $tagPosition = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($tagPosition === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid tag position.');
        }
        $reader = $this->userPubkeyHex($user);
        $muted = $mutes->getMutedPubkeysStrict($reader);
        $page = $this->tocPageNumber($request);
        $path = $this->tocAncestorPath($request);
        $event = $sourceAccess->authorize($reader, $eventId, $path, $muted);
        $occurrence = $request->query->get('occurrence');
        if ($path !== []) {
            $signature = $request->query->get('signature');
            if (!is_string($occurrence) || preg_match('/^[0-9a-f]{64}$/', $occurrence) !== 1
                || !$signer->isValid($eventId, $page, $path, $occurrence, $this->publicationCoordinate($event), is_string($signature) ? $signature : null)) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid nested TOC path.');
            }
        } else {
            $occurrence = null;
        }
        $tocPage = $toc->fromAuthorizedEvent(
            $event, $page, $path, $occurrence,
            $path === [] ? 'reading_nook_publication_toc' : 'reading_nook_nested_publication_toc', $muted,
            $tagPosition,
        );
        $entry = null;
        foreach ($tocPage['entries'] as $candidate) {
            if ($candidate['reference']->position === $tagPosition) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null || $entry['invalidVersionPin']
            || $entry['reference']->type->value !== 'address'
            || $entry['reference']->status->value !== 'unresolved'
            || !in_array($entry['reference']->targetKind, [30040, 30041], true)) {
            throw $this->createNotFoundException();
        }
        $reference = $entry['reference'];
        // Derivation is exclusively from the re-read signed tag position.
        // Browser target/relay/body fields are never read.
        $resolution = $targets->resolvePage([$reference])[$tagPosition] ?? null;
        if ($resolution === null || !in_array($resolution->status->value, ['available', 'missing'], true)) {
            throw $this->createNotFoundException();
        }
        if ($resolution->status->value === 'available') {
            if ($reference->targetKind === 30040 && $entry['targetResolution'] !== 'available') {
                // A row may have arrived between the two local reads. Re-read
                // only this occurrence to obtain its cycle/depth/signature data.
                $updated = $toc->fromAuthorizedEvent(
                    $event, $page, $path, $occurrence,
                    $tocPage['routeName'], $muted, $tagPosition,
                );
                foreach ($updated['entries'] as $candidate) {
                    if ($candidate['reference']->position === $tagPosition) {
                        $entry = $candidate;
                        break;
                    }
                }
                if ($entry['targetResolution'] !== 'available') {
                    throw $this->createNotFoundException();
                }
            }
            if ($reference->targetKind === 30041) {
                $preview = $resolution->presentation;
                if ($preview->coordinate === null) {
                    throw $this->createNotFoundException();
                }
                $entry['targetResolution'] = 'available';
                $entry['target'] = [
                    'eventId' => $preview->eventId, 'pubkey' => $preview->authorPubkey,
                    'coordinate' => $preview->coordinate, 'title' => $preview->title,
                    'summary' => null, 'sensitive' => $preview->isSensitive,
                ];
                $entry['chapterPreviewOccurrenceId'] = hash(
                    'sha256',
                    ($occurrence ?? 'root:' . strtolower($reference->sourceEventId))
                        . ':chapter:' . $reference->position . ':' . strtolower($preview->eventId),
                );
                $entry['chapterPreviewSignature'] = $signer->sign(
                    strtolower($preview->eventId),
                    1,
                    $tocPage['path'],
                    $entry['chapterPreviewOccurrenceId'],
                    $preview->coordinate,
                );
            }
            $data = [
                'status' => 'available', 'retryAfter' => 0,
                'html' => $this->renderView('reader/reading_nook/_publication_toc_entry.html.twig', [
                    'entry' => $entry,
                    'tocPage' => $tocPage,
                    'tocDiscoveryEnabled' => true,
                    'tocChapterPreviewEnabled' => true,
                ]),
            ];
        } else {
            // Missing targets still undergo known author/mute/hidden/pinned
            // tombstone policy before any work is scheduled. Coordinate
            // tombstones conservatively block cold discovery.
            $sourceAccess->assertTargetReadable(
                $reference->targetPubkey, $reference->target,
                $reference->versionEventId ?? '', 0, $muted,
            );
            $data = $request->isMethod('POST')
                ? $discovery->request($reader, $reference)
                : $discovery->status($reader, $reference);
        }
        $response = $this->json($data);
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

    private function isCanonicalChapterCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);

        return count($parts) === 3
            && $parts[0] === (string) KindsEnum::PUBLICATION_CONTENT->value
            && preg_match('/^[0-9a-f]{64}$/', $parts[1]) === 1
            && $coordinate === $parts[0] . ':' . strtolower($parts[1]) . ':' . $parts[2];
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
     * @param Event[] $events
     * @return array<int, array<string, mixed>>
     */
    private function buildEntriesFromEvents(array $events): array
    {
        $entries = [];
        $dedupe = [];

        foreach ($events as $event) {
            $kind = $event->getKind();
            if ($kind === KindsEnum::DRIVE->value && !$this->expansionEnabled) {
                continue;
            }
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
            $slug = $this->inventorySlug($tags);
            $title = $this->firstTagValue($tags, ['title', 'name'])
                ?? ($kind === KindsEnum::DRIVE->value ? $this->firstTagValue($tags, ['description']) : null)
                ?? $slug
                ?? $this->defaultTitleForKind($kind);
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
                'url' => $this->buildEventUrl($section, $kind, $event->getPubkey(), $slug, $event->getId()),
                'manageUrl' => $this->buildManageUrl($section, $kind, $slug),
                'meta' => $this->buildEventMeta($event, $section, $slug),
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
     * Merge subscriptions into the ordered event stream. Only the requested
     * page and the last page are retained; all authorized items still contribute
     * to counts and unfiltered tag facets.
     *
     * @param \Generator<int, array<string, mixed>> $events
     * @param list<array<string, mixed>> $subscriptions
     * @param array{q:string, section:string, timespan:string, tag:string} $filters
     * @return array<string, mixed>
     */
    private function paginateInventory(\Generator $events, array $subscriptions, array $filters, int $requestedPage): array
    {
        $pageSize = 25;
        $offset = ($requestedPage - 1) * $pageSize;
        $sectionCounts = $this->countBySection([]);
        $filteredSectionCounts = $sectionCounts;
        $query = mb_strtolower($filters['q']);
        $cutoff = $this->resolveTimespanCutoff($filters['timespan']);
        $total = $filtered = $subscriptionIndex = $sequence = 0;
        $items = $tail = $tags = [];

        while ($events->valid() || isset($subscriptions[$subscriptionIndex])) {
            $subscription = $subscriptions[$subscriptionIndex] ?? null;
            if ($events->valid() && ($subscription === null || $events->current()['createdAt'] >= $subscription['createdAt'])) {
                $item = $events->current();
                $source = $item['section'] === self::SECTION_DRIVES ? 1 : 0;
                $events->next();
            } else {
                $item = $subscription;
                $source = 2;
                ++$subscriptionIndex;
            }
            ++$total;
            ++$sectionCounts[$item['section']];
            foreach ($item['tags'] as $tag) {
                if ($tag === '') {
                    continue;
                }
                if (!isset($tags[$tag])) {
                    $tags[$tag] = ['count' => 0, 'source' => $source, 'position' => $sequence];
                } elseif ($source < $tags[$tag]['source']) {
                    $tags[$tag]['source'] = $source;
                    $tags[$tag]['position'] = $sequence;
                }
                ++$tags[$tag]['count'];
            }
            ++$sequence;
            if (($filters['section'] !== 'all' && $item['section'] !== $filters['section'])
                || ($filters['tag'] !== '' && !in_array($filters['tag'], $item['tags'], true))
                || ($cutoff !== null && $item['createdAt'] < $cutoff)
                || ($query !== '' && !str_contains((string) $item['search'], $query))) {
                continue;
            }
            ++$filteredSectionCounts[$item['section']];
            if ($filtered >= $offset && $filtered - $offset < $pageSize) {
                $items[] = $item;
            }
            $tail[] = $item;
            if (count($tail) > $pageSize) {
                array_shift($tail);
            }
            ++$filtered;
        }

        uasort($tags, static fn (array $a, array $b): int => ($b['count'] <=> $a['count'])
            ?: ($a['source'] <=> $b['source']) ?: ($a['position'] <=> $b['position']));
        $totalPages = max(1, (int) ceil($filtered / $pageSize));
        $page = min($requestedPage, $totalPages);
        if ($page !== $requestedPage) {
            $lastPageSize = $filtered % $pageSize ?: min($pageSize, $filtered);
            $items = $lastPageSize === 0 ? [] : array_slice($tail, -$lastPageSize);
        }

        return [
            'items' => $items,
            'sectionCounts' => $sectionCounts,
            'filteredSectionCounts' => $filteredSectionCounts,
            'allTags' => array_keys($tags),
            'totalCount' => $total,
            'filteredCount' => $filtered,
            'page' => $page,
            'totalPages' => $totalPages,
        ];
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
            KindsEnum::DRIVE->value => self::SECTION_DRIVES,
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

        if ($kind === KindsEnum::DRIVE->value) {
            foreach ($event->getTags() as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                    return sprintf('%d:%s:%s', $kind, $event->getPubkey(), is_string($tag[1] ?? null) ? $tag[1] : '');
                }
            }

            return sprintf('%d:%s:', $kind, $event->getPubkey());
        }

        if ($kind >= 10000 && $kind <= 19999) {
            return sprintf('%d:%s', $kind, $event->getPubkey());
        }

        if ($kind >= 30000 && $kind <= 39999) {
            return sprintf('%d:%s:%s', $kind, $event->getPubkey(), $event->getDTag() ?? '');
        }

        return $event->getId();
    }

    private function buildEventUrl(string $section, int $kind, string $pubkey, ?string $slug, string $eventId): ?string
    {
        if ($kind === KindsEnum::DRIVE->value) {
            return $this->generateUrl('reading_nook_drive_mount', ['driveId' => strtolower($eventId)]);
        }
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
        EventPreviewAccessPolicy $eventAccess,
        array $muted,
    ): array
    {
        $entries = [];

        foreach (array_chunk($subscriptionRepository->findActiveForUser($user), EventPreviewAccessPolicy::INVENTORY_BATCH_SIZE) as $chunk) {
            $identities = $keys = [];
            foreach ($chunk as $index => $subscription) {
                $identity = $this->subscriptionIdentity($subscription->getSourceType(), $subscription->getSourceValue());
                if ($identity !== null) {
                    $keys[$index] = $identity['key'];
                    unset($identity['key']);
                    $identities[] = $identity;
                }
            }
            $targets = $eventRepository->findReadingNookSubscriptionTargets($identities);
            $eligible = [];
            foreach ($targets as $target) {
                if ($this->isSafeInventoryEvent($target)) {
                    $eligible[] = $target;
                }
            }
            $readable = [];
            foreach ($eventAccess->readableInventoryEvents($eligible, $muted) as $event) {
                $readable[strtolower($event->getId())] = true;
            }
            foreach ($chunk as $index => $subscription) {
                $type = $subscription->getSourceType();
                $sourceValue = $subscription->getSourceValue();
                $target = isset($keys[$index]) ? ($targets[$keys[$index]] ?? null) : null;
                $title = $target instanceof Event && isset($readable[strtolower($target->getId())])
                    ? $this->subscriptionTitleFromEvent($type, $target)
                    : null;
                $title ??= $subscription->getLabel() ?? $this->subscriptionFallbackTitle($type, $sourceValue);
                $summary = $type->label();

                $entries[] = [
                    'section' => self::SECTION_SUBSCRIPTIONS,
                    'kind' => $type->value,
                    'title' => $title,
                    'summary' => $summary,
                    'tags' => ['subscription', $type->value],
                    'createdAt' => $subscription->getCreatedAt(),
                    'updatedAt' => null,
                    'url' => $this->generateUrl('updates_index'),
                    'manageUrl' => $this->generateUrl('updates_subscriptions'),
                    'meta' => [
                        'sourceTypeLabel' => $type->label(),
                    ],
                    'search' => $this->buildSearchBlob([$title, $summary, $sourceValue, $type->value]),
                ];
            }
        }

        return $entries;
    }

    /** @return array{kind:int, pubkey:string, dTag:?string, key:string}|null */
    private function subscriptionIdentity(UpdateSourceTypeEnum $type, string $sourceValue): ?array
    {
        if ($type === UpdateSourceTypeEnum::NPUB) {
            $value = strtolower(trim($sourceValue));
            if (str_starts_with($value, 'nostr:')) {
                $value = substr($value, 6);
            }
            $pubkey = str_starts_with($value, 'npub1')
                ? PublicKey::fromBech32($value)?->toHex()
                : PublicKey::fromHex($value)?->toHex();
            if ($pubkey === null || PublicKey::fromHex($pubkey) === null) {
                return null;
            }
            return ['kind' => 0, 'pubkey' => $pubkey, 'dTag' => null, 'key' => '0:' . $pubkey];
        }
        $parts = explode(':', $sourceValue, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$kindRaw, $pubkey, $dTag] = $parts;
        $pubkey = strtolower($pubkey);
        $kind = ctype_digit($kindRaw) ? (int) $kindRaw : 0;
        $allowedKind = match ($type) {
            UpdateSourceTypeEnum::PUBLICATION => $kind === 30040,
            UpdateSourceTypeEnum::NIP51_SET => in_array($kind, [30000, 30003, 30004, 30005, 30015, 39089], true),
            default => false,
        };
        if (!$allowedKind || PublicKey::fromHex($pubkey) === null) {
            return null;
        }
        return ['kind' => $kind, 'pubkey' => $pubkey, 'dTag' => $dTag, 'key' => $kind . ':' . $pubkey . ':' . $dTag];
    }

    private function subscriptionTitleFromEvent(UpdateSourceTypeEnum $type, Event $event): ?string
    {
        if ($type !== UpdateSourceTypeEnum::NPUB) {
            return $this->firstTagValue($event->getTags(), ['title', 'name']);
        }
        $metadata = json_decode($event->getContent(), true);
        if (is_array($metadata)) {
            foreach ([$metadata['display_name'] ?? $metadata['displayName'] ?? null, $metadata['name'] ?? null] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }
        return null;
    }

    private function isSafeInventoryEvent(Event $event): bool
    {
        $tags = $event->getTags();
        $scoped = in_array($event->getKind(), self::OWNED_LIST_KINDS, true)
            ? EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $tags)
            : EventWorkspaceScopePolicy::isScopedTarget($event->getKind(), $tags);

        return !$scoped && !EventPresentation::hasSensitiveTagEvidence($tags);
    }

    private function subscriptionFallbackTitle(UpdateSourceTypeEnum $type, string $sourceValue): string
    {
        if ($type === UpdateSourceTypeEnum::NPUB) {
            return 'npub:' . substr($sourceValue, 0, 12) . '...';
        }

        $parts = explode(':', $sourceValue, 3);

        return $parts[2] ?? $sourceValue;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEventMeta(Event $event, string $section, ?string $slug): array
    {
        $meta = [
            'slug' => $slug,
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
            if (!is_array($tag) || !is_string($tag[0] ?? null) || !is_string($tag[1] ?? null)) {
                continue;
            }

            if (in_array($tag[0], $names, true)) {
                $value = trim($tag[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $tags */
    private function inventorySlug(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
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
            KindsEnum::DRIVE->value => 'My Drive',
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
