<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\CategoryContentMutation;
use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use DecentNewsroom\UnfoldBundle\Config\CategoryReference;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use DecentNewsroom\UnfoldBundle\Contract\SignedCategoryIndexPublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class CategoryContentController
{
    public function __construct(
        private EventReadGatewayInterface $events,
        private SignedCategoryIndexPublisherInterface $publisher,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
        private SiteConfigCacheWarmer $cacheWarmer,
        private LoggerInterface $logger,
    ) {}

    public function detail(Request $request, PublicationContext $publication): Response
    {
        try {
            $categoryReference = CategoryReference::fromInput((string) $request->query->get('category', ''));
            $category = $this->category($publication, $categoryReference);
            $inventory = [];
            foreach (CategoryContentMutation::references($category) as $entry) {
                $reference = $entry['reference'];
                $title = null;
                try {
                    $leaf = $this->events->findByCoordinate($reference->coordinate, $entry['relayHint'] ? [$entry['relayHint']] : []);
                    if ($leaf !== null && $reference->matches($leaf) && !ContentReference::isScoped($leaf)) {
                        $title = self::title($leaf) ?? $reference->identifier;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Category content reference unavailable', [
                        'coordinate' => $reference->coordinate,
                        'exception' => $e,
                    ]);
                }
                $inventory[] = ['reference' => $reference, 'title' => $title];
            }
            return new Response($this->twig->render('@Unfold/admin/category.html.twig', [
                'publication' => $publication,
                'categoryCoordinate' => $categoryReference->coordinate,
                'categoryTitle' => self::title($category) ?? $categoryReference->coordinate,
                'readOnly' => strtolower($category->pubkey) !== $publication->ownerPubkey,
                'inventory' => $inventory,
            ]));
        } catch (\InvalidArgumentException $e) {
            return new Response($this->twig->render('@Unfold/admin/category_error.html.twig', ['publication' => $publication, 'error' => $e->getMessage()]), 422);
        } catch (\Throwable $e) {
            $this->logger->warning('Category inventory unavailable', ['exception' => $e]);
            return new Response($this->twig->render('@Unfold/admin/category_error.html.twig', ['publication' => $publication, 'error' => 'unfold_category.refresh_required']), 503);
        }
    }

    public function prepare(Request $request, PublicationContext $publication): JsonResponse
    {
        try {
            [$data, $categoryReference, $reference, $action] = $this->input($request, $publication);
            $child = $this->category($publication, $categoryReference);
            if (strtolower($child->pubkey) !== $publication->ownerPubkey) {
                return new JsonResponse(['error' => 'unfold_category.read_only'], 403);
            }
            $tags = CategoryContentMutation::tags($child, $reference, $action);
            if ($tags === $child->tags) {
                return new JsonResponse(['unchanged' => true]);
            }
            if ($action === 'add') {
                $leaf = $this->events->findByCoordinate($reference->coordinate, $reference->relayHints);
                if ($leaf === null || !$reference->matches($leaf)) {
                    throw new \InvalidArgumentException('unfold_category.unresolved');
                }
                if (ContentReference::isScoped($leaf)) {
                    throw new \InvalidArgumentException('unfold_category.scoped');
                }
            }
            return new JsonResponse([
                'event' => ['pubkey' => $publication->ownerPubkey, 'kind' => 30040, 'created_at' => max(time(), $child->createdAt + 1), 'tags' => $tags, 'content' => $child->content],
                'base_event_id' => $child->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e);
        } catch (\Throwable $e) {
            $this->logger->warning('Category preparation unavailable', ['exception' => $e]);
            return new JsonResponse(['error' => 'unfold_category.refresh_required'], 503);
        }
    }

    public function commit(Request $request, PublicationContext $publication): JsonResponse
    {
        try {
            [$data, $category, $reference, $action] = $this->input($request, $publication);
            if (!is_array($data['event'] ?? null) || !is_string($data['base_event_id'] ?? null)) {
                throw new \InvalidArgumentException('unfold_category.invalid');
            }
            $result = $this->publisher->publish($data['event'], $publication->coordinate, $category->coordinate, $data['base_event_id'], $reference, $action);
            $cacheRefreshed = true;
            try {
                $cacheRefreshed = $this->cacheWarmer->warmCategoryMutation($publication->coordinate, $category->coordinate);
            } catch (\Throwable $e) {
                $cacheRefreshed = false;
                $this->logger->warning('Committed category cache refresh failed', ['event_id' => $result['event_id'], 'exception' => $e]);
            }
            return new JsonResponse(['ok' => true, ...$result, 'cache_refreshed' => $cacheRefreshed]);
        } catch (PublicationIndexConflictException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($e);
        } catch (\Throwable $e) {
            $this->logger->error('Category commit unavailable', ['exception' => $e]);
            return new JsonResponse(['error' => 'unfold_category.refresh_required', 'retryable' => true], 503);
        }
    }

    private function input(Request $request, PublicationContext $publication): array
    {
        $body = $request->getContent();
        if (strlen($body) > 1_000_000) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        if (!is_array($data) || !is_string($data['category'] ?? null) || !is_string($data['reference'] ?? null)) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        $category = CategoryReference::fromInput($data['category']);
        if (!is_string($data['_token'] ?? null) || !$this->csrf->isTokenValid(new CsrfToken('unfold_category:' . $publication->coordinate . ':' . $category->coordinate, $data['_token']))) {
            throw new \InvalidArgumentException('unfold_category.access_denied');
        }
        $reference = ContentReference::fromInput($data['reference']);
        if (!in_array($data['action'] ?? null, ['add', 'remove'], true)) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        return [$data, $category, $reference, $data['action']];
    }

    private function category(PublicationContext $publication, CategoryReference $reference): NostrEvent
    {
        $root = $this->events->findByCoordinate($publication->coordinate);
        if ($root === null) {
            throw new \InvalidArgumentException('unfold_category.refresh_required');
        }
        CategoryContentMutation::assertAttached($root, $publication->coordinate, $reference->coordinate);
        $child = $this->events->findByCoordinate($reference->coordinate, $reference->relayHints);
        if ($child === null || $child->id === '' || !CategoryContentMutation::matchesCategory($child, $reference->coordinate)) {
            throw new \InvalidArgumentException('unfold_category.refresh_required');
        }
        return $child;
    }

    private function invalid(\InvalidArgumentException $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->getMessage()], in_array($e->getMessage(), ['unfold_category.access_denied', 'unfold_category.read_only'], true) ? 403 : 422);
    }

    private static function title(NostrEvent $event): ?string
    {
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'title' && is_string($tag[1] ?? null)) {
                return $tag[1];
            }
        }
        return null;
    }
}
