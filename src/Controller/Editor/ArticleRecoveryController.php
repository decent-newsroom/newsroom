<?php

declare(strict_types=1);

namespace App\Controller\Editor;

use App\Entity\ArticleRecovery;
use App\Service\Nostr\NostrKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/article-editor/recovery', name: 'editor_recovery_')]
#[IsGranted('ROLE_USER')]
final class ArticleRecoveryController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $em, NostrKeyService $keys): JsonResponse
    {
        $copies = $em->getRepository(ArticleRecovery::class)->findBy(
            ['pubkey' => $this->owner($keys)],
            ['updatedAt' => 'DESC'],
        );

        return $this->privateJson(array_map(static fn (ArticleRecovery $copy) => [
            'id' => $copy->getId(),
            'snapshot' => $copy->getSnapshot(),
            'updatedAt' => $copy->getUpdatedAt()->format(DATE_ATOM),
        ], $copies));
    }

    #[Route('/{id}', name: 'save', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['PUT'])]
    public function save(string $id, Request $request, EntityManagerInterface $em, NostrKeyService $keys): JsonResponse
    {
        if (!$this->isCsrfTokenValid('article_recovery', $request->headers->get('X-CSRF-Token'))) {
            return $this->privateJson(['error' => 'Invalid CSRF token'], 403);
        }
        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            return $this->privateJson(['error' => 'Recovery copy exceeds the size limit'], 413);
        }
        $snapshot = json_decode($body, true);
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
            || !is_array($snapshot['fields'] ?? null) || !is_array($snapshot['editorState'] ?? null)) {
            return $this->privateJson(['error' => 'Invalid recovery copy'], 400);
        }

        $owner = $this->owner($keys);
        $repository = $em->getRepository(ArticleRecovery::class);
        $copy = $repository->find($id);
        if ($copy && !$repository->findOneBy(['id' => $id, 'pubkey' => $owner])) {
            return $this->privateJson(['error' => 'Recovery copy not found'], 404);
        }
        if ($copy) {
            $copy->update($snapshot);
        } else {
            $copy = new ArticleRecovery($id, $owner, $snapshot);
            $em->persist($copy);
        }
        $em->flush();

        return $this->privateJson(['updatedAt' => $copy->getUpdatedAt()->format(DATE_ATOM)]);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['DELETE'])]
    public function delete(string $id, Request $request, EntityManagerInterface $em, NostrKeyService $keys): JsonResponse
    {
        if (!$this->isCsrfTokenValid('article_recovery', $request->headers->get('X-CSRF-Token'))) {
            return $this->privateJson(['error' => 'Invalid CSRF token'], 403);
        }
        $copy = $em->getRepository(ArticleRecovery::class)->findOneBy(['id' => $id, 'pubkey' => $this->owner($keys)]);
        if (!$copy) {
            return $this->privateJson(['error' => 'Recovery copy not found'], 404);
        }
        $em->remove($copy);
        $em->flush();

        return $this->privateJson(['deleted' => true]);
    }

    private function owner(NostrKeyService $keys): string
    {
        return $keys->convertToHex($this->getUser()->getUserIdentifier());
    }

    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
