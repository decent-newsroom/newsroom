<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Entity\Event;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\BookshelfReferencePage;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BookshelfReferencesController extends AbstractController
{
    public function __construct(
        #[Autowire('%event_workspace_expansion_enabled%')]
        private readonly bool $expansionEnabled = true,
    ) {}

    #[Route('/bookshelf/my-books/references', name: 'bookshelf_directory_references', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function references(
        Request $request,
        EventRepository $events,
        BookshelfReferencePage $pages,
        DeletedEventRepository $deletions,
        ContentAuthorAccessPolicy $authorAccess,
        HiddenCoordinateRepository $hiddenCoordinates,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $query = $request->query->all();
        $rawPage = $query['page'] ?? '1';
        $eventId = $query['eventId'] ?? null;
        $page = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) : false;
        if ($page === false
            || ($eventId !== null && (!is_string($eventId) || preg_match('/^[0-9a-f]{64}$/', $eventId) !== 1))
            || ($page > 1 && $eventId === null)) {
            throw new BadRequestHttpException('Invalid directory page.');
        }

        $pubkey = PublicKey::fromBech32($user->getUserIdentifier())?->toHex()
            ?? throw $this->createAccessDeniedException();
        $authorAccess->assertReadable($pubkey);
        $identifier = BookshelfDirectoryService::IDENTIFIER;
        if ($hiddenCoordinates->isHidden('30045:' . $pubkey . ':' . $identifier)) {
            throw $this->createNotFoundException();
        }

        $criteria = ['pubkey' => $pubkey, 'kind' => KindsEnum::DIRECTORY->value, 'dTag' => $identifier];
        if ($eventId !== null) {
            $criteria['id'] = $eventId;
        }
        $event = $events->findOneBy($criteria, ['created_at' => 'DESC', 'id' => 'ASC']);
        if ($event === null && $eventId !== null) {
            throw $this->createNotFoundException();
        }
        if ($event instanceof Event) {
            $signedIdentifier = null;
            foreach ($event->getTags() as $tag) {
                if (($tag[0] ?? null) === 'd') {
                    $signedIdentifier = $tag[1] ?? null;
                    break;
                }
            }
            if ($signedIdentifier !== $identifier || $deletions->isSuppressed(
                $event->getId(),
                $event->getKind(),
                $event->getPubkey(),
                $identifier,
                $event->getCreatedAt(),
            )) {
                throw $this->createNotFoundException();
            }
        }

        $response = $this->render('bookshelf/_directory_references.html.twig', [
            'directoryPage' => $event === null ? null : $pages->fromAuthorizedEvent($event, $page),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
