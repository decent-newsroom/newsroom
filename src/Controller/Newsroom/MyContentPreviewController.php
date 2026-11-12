<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MyContentPreviewController extends AbstractController
{
    public function __construct(
        #[Autowire('%event_workspace_expansion_enabled%')]
        private readonly bool $expansionEnabled = true,
    ) {}

    #[Route('/my-content/preview/{eventId}', name: 'my_content_preview', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function preview(
        string $eventId,
        Request $request,
        ArticleRepository $articles,
        EventRepository $events,
        AuthoredArticlePreview $previews,
        NostrKeyService $keys,
        ContentAuthorAccessPolicy $authors,
        DeletedEventRepository $deletions,
        HiddenCoordinateRepository $hidden,
    ): Response {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser() ?? throw $this->createAccessDeniedException();
        $rawPage = $request->query->all()['page'] ?? '1';
        $page = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) : false;
        if ($page === false) {
            throw new BadRequestHttpException('Invalid preview page.');
        }
        $pubkey = $keys->convertToHex($user->getUserIdentifier());
        $authors->assertReadable($pubkey);
        $article = $articles->findOneBy(['eventId' => $eventId, 'pubkey' => $pubkey]);
        if ($article === null || !in_array($article->getKind(), [KindsEnum::LONGFORM, KindsEnum::LONGFORM_DRAFT], true)) {
            throw $this->createNotFoundException();
        }
        $coordinate = $article->getKind()->value . ':' . $pubkey . ':' . $article->getSlug();
        $presentation = $previews->fromAuthorizedArticle($article, $events->find($eventId));
        $createdAt = $presentation !== null
            ? $presentation->createdAt
            : ($article->getCreatedAt()?->getTimestamp() ?? 0);
        if ($hidden->isHidden($coordinate) || $deletions->isSuppressed(
            $eventId, $article->getKind()->value, $pubkey, $article->getSlug(),
            $createdAt,
        )) {
            throw $this->createNotFoundException();
        }
        $references = $presentation !== null && !$presentation->isSensitive ? $presentation->references : [];
        $totalPages = max(1, (int) ceil(count($references) / 50));
        $page = min($page, $totalPages);

        return $this->render('my_content/_preview.html.twig', [
            'eventId' => $eventId,
            'presentation' => $presentation,
            'references' => array_slice($references, ($page - 1) * 50, 50),
            'page' => $page,
            'totalPages' => $totalPages,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
