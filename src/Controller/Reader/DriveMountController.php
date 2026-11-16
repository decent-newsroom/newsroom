<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Entity\User;
use App\Service\EventWorkspace\DriveMountAccess;
use App\Service\EventWorkspace\DriveMountEventAccess;
use App\Service\EventWorkspace\DriveMountEventOpenSigner;
use App\Service\EventWorkspace\DriveMountPage;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Repository\EventRepository;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Enum\KindsEnum;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DriveMountController extends AbstractController
{
    public function __construct(
        #[Autowire('%event_workspace_expansion_enabled%')]
        private readonly bool $expansionEnabled = true,
    ) {}

    #[Route('/reading-nook/drives/{driveId}', name: 'reading_nook_drive_mount', requirements: ['driveId' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mount(
        string $driveId,
        Request $request,
        DriveMountAccess $access,
        DriveMountPage $pages,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $reader = $this->readerPubkey();
        $page = $this->pageNumber($request);
        $drive = $access->readableDrive($driveId, $reader);
        if (!$drive instanceof Event) {
            throw $this->createNotFoundException();
        }

        $mountPage = $pages->fromAuthorizedEvent($drive, $page, readerPubkey: $reader);
        $response = $this->render('reader/reading_nook/drive.html.twig', [
            'drive' => $drive,
            'driveId' => strtolower($driveId),
            'mountPage' => $mountPage,
            'directory' => null,
            'directoryPath' => [],
            'directoryOccurrenceId' => null,
            'directorySignature' => null,
            'description' => $this->firstTag($drive, 'description'),
            'mountTitle' => $this->firstTag($drive, 'title')
                ?? $this->firstTag($drive, 'name')
                ?? $this->firstTag($drive, 'd'),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(
        '/reading-nook/drives/{driveId}/directories/{directoryId}',
        name: 'reading_nook_drive_directory',
        requirements: ['driveId' => '[0-9a-f]{64}', 'directoryId' => '[0-9a-f]{64}'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    public function directory(
        string $driveId,
        string $directoryId,
        Request $request,
        DriveMountAccess $access,
        DriveMountPage $pages,
        PublicationTocTraversalSigner $signer,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $reader = $this->readerPubkey();
        $page = $this->pageNumber($request);
        $driveId = strtolower($driveId);
        $directoryId = strtolower($directoryId);
        $drive = $access->readableDrive($driveId, $reader);
        $rawPath = $request->query->all('path');
        $path = is_array($rawPath) ? array_values($rawPath) : [];
        $occurrenceId = $request->query->get('occurrenceId');
        $signature = $request->query->get('signature');
        if (!$drive instanceof Event
            || $path === []
            || count($path) > DriveMountPage::MAX_EXPANSION_DEPTH
            || strtolower((string) ($path[0] ?? '')) !== $driveId
            || array_filter($path, static fn (mixed $id): bool => !is_string($id)
                || preg_match('/^[0-9a-f]{64}$/iD', $id) !== 1) !== []
            || !is_string($occurrenceId)
            || preg_match('/^[0-9a-f]{64}$/', $occurrenceId) !== 1) {
            throw new BadRequestHttpException('Invalid drive traversal.');
        }
        $path = array_map('strtolower', $path);
        if (count($path) !== count(array_unique($path)) || in_array($directoryId, $path, true)) {
            throw new BadRequestHttpException('Invalid drive traversal path.');
        }
        $pathEvents = [$drive];
        foreach (array_slice($path, 1) as $ancestorId) {
            $ancestor = $access->readableDirectory($ancestorId, $reader);
            if (!$ancestor instanceof Event) {
                throw $this->createNotFoundException();
            }
            $pathEvents[] = $ancestor;
        }
        $directory = $access->readableDirectory($directoryId, $reader);
        if (!$directory instanceof Event) {
            throw $this->createNotFoundException();
        }
        $pathEvents[] = $directory;
        for ($index = 0; $index < count($pathEvents) - 1; ++$index) {
            if (!$access->referencesDirectory($pathEvents[$index], $pathEvents[$index + 1])) {
                throw $this->createNotFoundException();
            }
        }
        $coordinate = $this->coordinate($directory);
        if ($coordinate === null || !$signer->isValid(
            $directoryId,
            $page,
            $path,
            $occurrenceId,
            $coordinate,
            is_string($signature) ? $signature : null,
        )) {
            throw new BadRequestHttpException('Invalid drive traversal signature.');
        }

        $mountPage = $pages->fromAuthorizedEvent($directory, $page, $path, $occurrenceId, $driveId, $reader);
        $response = $this->render('reader/reading_nook/drive.html.twig', [
            'drive' => $drive,
            'driveId' => $driveId,
            'mountPage' => $mountPage,
            'directory' => $directory,
            'directoryPath' => $path,
            'directoryOccurrenceId' => $occurrenceId,
            'directorySignature' => $signature,
            'description' => $this->firstTag($directory, 'description'),
            'mountTitle' => $this->firstTag($directory, 'title')
                ?? $this->firstTag($directory, 'name')
                ?? $this->firstTag($directory, 'd'),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(
        '/reading-nook/drives/{driveId}/events/{targetId}',
        name: 'reading_nook_drive_event',
        requirements: ['driveId' => '[0-9a-f]{64}', 'targetId' => '[0-9a-f]{64}'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    public function openEvent(
        string $driveId,
        string $targetId,
        Request $request,
        DriveMountAccess $access,
        DriveMountEventAccess $targetAccess,
        DriveMountEventOpenSigner $signer,
        EventPresentationFactory $presentations,
        EventRepository $events,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $reader = $this->readerPubkey();
        $driveId = strtolower($driveId);
        $targetId = strtolower($targetId);
        $drive = $access->readableDrive($driveId, $reader);
        $rawPath = $request->query->all('path');
        $path = is_array($rawPath) ? array_values($rawPath) : [];
        $position = filter_var($request->query->get('position'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $occurrenceId = $request->query->get('occurrenceId');
        $signature = $request->query->get('signature');
        if (!$drive instanceof Event
            || count($path) < 2
            || count($path) > DriveMountPage::MAX_EXPANSION_DEPTH + 1
            || strtolower((string) ($path[0] ?? '')) !== $driveId
            || array_filter($path, static fn (mixed $id): bool => !is_string($id)
                || preg_match('/^[0-9a-f]{64}$/iD', $id) !== 1) !== []
            || count($path) !== count(array_unique(array_map('strtolower', $path)))
            || $position === false
            || !is_string($occurrenceId)
            || !is_string($signature)) {
            throw new BadRequestHttpException('Invalid drive event opening request.');
        }
        $path = array_map('strtolower', $path);
        $pathEvents = [$drive];
        foreach (array_slice($path, 1) as $directoryId) {
            $directory = $access->readableDirectory($directoryId, $reader);
            if (!$directory instanceof Event
                || !$access->referencesDirectory($pathEvents[array_key_last($pathEvents)], $directory)) {
                throw $this->createNotFoundException();
            }
            $pathEvents[] = $directory;
        }
        /** @var Event $source */
        $source = $pathEvents[array_key_last($pathEvents)];
        $tags = $source->getTags();
        $rawTag = $tags[$position] ?? null;
        $reference = null;
        foreach ($presentations->fromAuthorizedEvent($source)->references as $candidate) {
            if ($candidate->position === $position) {
                $reference = $candidate;
                break;
            }
        }
        if (!is_array($rawTag)
            || !$signer->isValid(
                $driveId,
                $path,
                $targetId,
                $position,
                $rawTag,
                $occurrenceId,
                $signature,
            )
            || $reference === null
            || $reference->role !== EventReferenceRole::Content
            || $reference->status !== EventReferenceStatus::Unresolved
            || !in_array($reference->type, [EventReferenceType::Address, EventReferenceType::EventId], true)) {
            throw new BadRequestHttpException('Invalid drive event opening signature.');
        }

        if ($reference->type === EventReferenceType::EventId) {
            if ($reference->target === null || strtolower($reference->target) !== $targetId) {
                throw $this->createNotFoundException();
            }
            $target = $events->findById($targetId);
        } elseif (in_array($reference->targetKind, [
            KindsEnum::LONGFORM->value,
            KindsEnum::PUBLICATION_INDEX->value,
            KindsEnum::PUBLICATION_CONTENT->value,
        ], true)) {
            if ($reference->targetKind === null
                || $reference->targetPubkey === null
                || $reference->targetIdentifier === null
                || $reference->target === null) {
                throw $this->createNotFoundException();
            }
            if ($reference->versionEventId !== null) {
                $target = $events->findById($reference->versionEventId);
            } else {
                $resolved = $events->findWorkspaceDriveOpenTargets([$reference->target], []);
                $target = $resolved['coordinates'][$reference->target] ?? null;
            }
        } else {
            throw $this->createNotFoundException();
        }
        if (!$target instanceof Event
            || strtolower($target->getId()) !== $targetId
            || !$this->matchesAddressReference($target, $reference)
            || !$targetAccess->isReadableTarget($target)) {
            throw $this->createNotFoundException();
        }

        $presentation = $presentations->fromAuthorizedEvent($target);
        $isMedia = in_array($target->getKind(), [20, 21, 22], true);
        $mediaMetadata = [];
        if ($isMedia) {
            foreach ($target->getTags() as $tag) {
                if (!is_array($tag) || ($tag[0] ?? null) !== 'imeta') {
                    continue;
                }
                foreach (array_slice($tag, 1, 20) as $value) {
                    if (is_string($value) && preg_match('/^(?:url|m|dim|alt) /', $value) === 1) {
                        $mediaMetadata[] = mb_substr($value, 0, 512);
                        if (count($mediaMetadata) >= 20) {
                            break 2;
                        }
                    }
                }
            }
        }
        $response = $this->render('reader/reading_nook/drive_event.html.twig', [
            'driveId' => $driveId,
            'sourceId' => strtolower($source->getId()),
            'presentation' => $presentation,
            'mediaTextContent' => $isMedia ? $target->getContent() : null,
            'mediaMetadata' => $mediaMetadata,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function readerPubkey(): string
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return PublicKey::fromBech32($user->getUserIdentifier())?->toHex()
            ?? throw $this->createAccessDeniedException();
    }

    private function pageNumber(Request $request): int
    {
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($page === false) {
            throw new BadRequestHttpException('Invalid drive page.');
        }

        return $page;
    }

    private function firstTag(Event $event, string $name): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && is_string($tag[1] ?? null)) {
                return $tag[1];
            }
        }

        return null;
    }

    private function coordinate(Event $event): ?string
    {
        $dTag = $this->firstTag($event, 'd');

        return $dTag === null ? null : $event->getKind() . ':' . strtolower($event->getPubkey()) . ':' . $dTag;
    }

    private function matchesAddressReference(Event $event, \App\Dto\EventWorkspace\EventReference $reference): bool
    {
        if ($reference->type !== EventReferenceType::Address) {
            return true;
        }
        if ($reference->targetKind !== $event->getKind()
            || strtolower((string) $reference->targetPubkey) !== strtolower($event->getPubkey())) {
            return false;
        }
        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                continue;
            }

            return is_string($tag[1] ?? null)
                && $tag[1] === $reference->targetIdentifier
                && ($reference->versionEventId === null
                    || strtolower($reference->versionEventId) === strtolower($event->getId()));
        }

        return false;
    }

}
