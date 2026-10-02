<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\CategoryIndexMutation;
use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use DecentNewsroom\UnfoldBundle\Config\CategoryReference;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CategoryIndexController
{
    public function __construct(
        private EventReadGatewayInterface $events,
        private SignedPublicationIndexPublisherInterface $publisher,
        private SiteConfigCacheWarmer $cacheWarmer,
        private CsrfTokenManagerInterface $csrf,
    ) {}

    public function prepare(Request $request, PublicationContext $publication): JsonResponse
    {
        try {
            [$root, $reference, $action, $tags] = $this->change($request, $publication);
            return new JsonResponse([
                'event' => ['pubkey' => $publication->ownerPubkey, 'kind' => 30040, 'created_at' => max(time(), $root->createdAt + 1), 'tags' => $tags, 'content' => $root->content],
                'base_event_id' => $root->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    public function commit(Request $request, PublicationContext $publication): JsonResponse
    {
        try {
            [$root, , , $tags, $data] = $this->change($request, $publication);
            $signed = $data['event'] ?? null;
            if (!is_array($signed) || !is_string($data['base_event_id'] ?? null) || !hash_equals($root->id, $data['base_event_id'])) {
                return new JsonResponse(['error' => 'unfold_admin.category_stale'], 409);
            }
            if (($signed['kind'] ?? null) !== 30040 || ($signed['pubkey'] ?? null) !== $publication->ownerPubkey
                || ($signed['tags'] ?? null) !== $tags || ($signed['content'] ?? null) !== $root->content
                || !is_int($signed['created_at'] ?? null) || $signed['created_at'] <= $root->createdAt
                || !is_string($signed['id'] ?? null) || !is_string($signed['sig'] ?? null)) {
                return new JsonResponse(['error' => 'unfold_admin.invalid_category'], 422);
            }
            $result = $this->publisher->publish($signed, $publication->coordinate, $root->id, null, [], false);
            $this->cacheWarmer->warmPublication($publication->coordinate);

            return new JsonResponse(['ok' => true, 'published' => $result['published'], 'relay_results' => $result['relay_results']]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /** @return array{0: NostrEvent, 1: CategoryReference, 2: string, 3: list<list<string>>, 4?: array<string,mixed>} */
    private function change(Request $request, PublicationContext $publication): array
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('unfold_admin.invalid_category');
        }
        if (!is_array($data) || !is_string($data['_token'] ?? null) || !$this->csrf->isTokenValid(new CsrfToken('unfold_settings:' . $publication->coordinate, $data['_token']))) {
            throw new \InvalidArgumentException('unfold_admin.category_access_denied');
        }
        $reference = isset($data['category']) && is_string($data['category']) ? CategoryReference::fromInput($data['category']) : throw new \InvalidArgumentException('unfold_admin.invalid_category');
        $action = $data['action'] ?? null;
        if (!in_array($action, ['add', 'remove'], true)) {
            throw new \InvalidArgumentException('unfold_admin.invalid_category');
        }
        $root = $this->events->findByCoordinate($publication->coordinate);
        if ($root === null || $root->id === '' || $root->kind !== 30040 || strtolower($root->pubkey) !== $publication->ownerPubkey) {
            throw new \InvalidArgumentException('unfold_admin.invalid_category');
        }
        if ($action === 'add') {
            $category = $this->events->findByCoordinate($reference->coordinate, $reference->relayHints);
            if ($category === null || $category->kind !== 30040) {
                throw new \InvalidArgumentException('unfold_admin.invalid_category');
            }
            $tags = CategoryIndexMutation::add($root, $reference->coordinate, $reference->relayHints);
        } else {
            $tags = CategoryIndexMutation::remove($root, $reference->coordinate);
        }

        return [$root, $reference, $action, $tags, $data];
    }
}
