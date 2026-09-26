<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller\Admin;

use DecentNewsroom\UnfoldBundle\Admin\AboutIndexMutation;
use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Config\AboutArticleReference;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class AboutIndexController
{
    public function __construct(
        private EventReadGatewayInterface $events,
        private SignedPublicationIndexPublisherInterface $publisher,
        private PublicationSettingsManager $settings,
        private SiteConfigLoader $loader,
        private CsrfTokenManagerInterface $csrf,
        private LoggerInterface $logger,
    ) {}

    public function prepare(Request $request, PublicationContext $publication): JsonResponse
    {
        $data = $this->requestData($request);
        if ($data === null) {
            return $this->error('Invalid request.', 400);
        }
        if (!$this->validCsrf($data, $publication)) {
            return $this->error('Invalid CSRF token.', 403);
        }

        try {
            [$reference, $root, $tags] = $this->resolveChange($publication, $data);
            $coordinate = $reference?->coordinate;
            $effective = AboutIndexMutation::currentCoordinate(
                $root,
                $this->settings->get($publication->coordinate)->aboutArticleCoordinate,
            );
            if ($tags === $root->tags && $effective === $coordinate) {
                return new JsonResponse(['unchanged' => true, 'about_article_coordinate' => $coordinate]);
            }
            $createdAt = max(time(), $root->createdAt + 1);
            if ($createdAt > time() + 300) {
                return $this->error('Magazine index timestamp is too far in the future.', 409);
            }

            return new JsonResponse([
                'event' => [
                    'pubkey' => $publication->ownerPubkey,
                    'kind' => 30040,
                    'created_at' => $createdAt,
                    'tags' => $tags,
                    'content' => $root->content,
                ],
                'base_event_id' => $root->id,
                'about_article_coordinate' => $coordinate,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to prepare signed About index update', [
                'coordinate' => $publication->coordinate,
                'exception' => $e,
            ]);
            return $this->error('Publication lookup unavailable.', 503);
        }
    }

    public function commit(Request $request, PublicationContext $publication): JsonResponse
    {
        $data = $this->requestData($request);
        if ($data === null) {
            return $this->error('Invalid request.', 400);
        }
        if (!$this->validCsrf($data, $publication)) {
            return $this->error('Invalid CSRF token.', 403);
        }
        $signed = $data['event'] ?? null;
        $baseId = $data['base_event_id'] ?? null;
        if (!is_array($signed) || !is_string($baseId) || preg_match('/^[a-f0-9]{64}$/D', $baseId) !== 1) {
            return $this->error('Invalid signed event request.', 400);
        }

        try {
            [$reference, $root, $tags] = $this->resolveChange($publication, $data);
            $coordinate = $reference?->coordinate;

            // Re-submitting a locally committed event is a safe relay retry.
            $retry = is_string($signed['id'] ?? null) && hash_equals($root->id, $signed['id'])
                && $publication->settings->aboutArticleCoordinate === $coordinate;
            if (!$retry && !hash_equals($root->id, $baseId)) {
                return $this->error('Magazine index changed. Reload settings and try again.', 409);
            }
            $effective = AboutIndexMutation::currentCoordinate(
                $root,
                $this->settings->get($publication->coordinate)->aboutArticleCoordinate,
            );
            if (!$retry && $tags === $root->tags && $effective === $coordinate) {
                return $this->error('About article is already up to date.', 409);
            }

            $expectedTags = $retry ? $root->tags : $tags;
            $expectedContent = $root->content;
            $createdAt = $signed['created_at'] ?? null;
            if (($signed['kind'] ?? null) !== 30040
                || ($signed['pubkey'] ?? null) !== $publication->ownerPubkey
                || ($signed['tags'] ?? null) !== $expectedTags
                || ($signed['content'] ?? null) !== $expectedContent
                || !is_int($createdAt)
                || (!$retry && $createdAt <= $root->createdAt)
                || $createdAt > time() + 300
                || !is_string($signed['id'] ?? null)
                || !is_string($signed['sig'] ?? null)) {
                return $this->error('Signed event differs from the permitted About change.', 422);
            }

            $relayHints = $reference === null ? [] : $reference->relayHints;
            if ($reference !== null && $relayHints === []) {
                $currentSettings = $this->settings->get($publication->coordinate);
                if ($reference->coordinate === $currentSettings->aboutArticleCoordinate) {
                    $relayHints = $currentSettings->aboutRelayHints;
                }
            }
            $result = $this->publisher->publish(
                $signed,
                $publication->coordinate,
                $baseId,
                $coordinate,
                $relayHints,
                !$retry,
            );
            $this->loader->invalidateFromCoordinate($publication->coordinate);

            return new JsonResponse([
                'ok' => true,
                'event_id' => $result['event_id'],
                'published' => $result['published'],
                'retryable' => !$result['published'],
                'relay_results' => $result['relay_results'],
            ]);
        } catch (PublicationIndexConflictException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to commit signed About index update', [
                'coordinate' => $publication->coordinate,
                'exception' => $e,
            ]);
            return $this->error('Signed magazine index update failed.', 503);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: ?AboutArticleReference, 1: NostrEvent, 2: list<list<string>>}
     */
    private function resolveChange(PublicationContext $publication, array $data): array
    {
        $input = $data['about_article'] ?? null;
        if (!is_string($input)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
        }
        $reference = trim($input) === '' ? null : AboutArticleReference::fromInput($input);
        if ($reference !== null) {
            $article = $this->events->findByCoordinate($reference->coordinate, $reference->relayHints);
            [, $author, $identifier] = explode(':', $reference->coordinate, 3);
            if ($article === null || $article->kind !== 30023
                || strtolower($article->pubkey) !== $author
                || $this->dTag($article->tags) !== $identifier) {
                throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
            }
        }

        $root = $this->events->findByCoordinate($publication->coordinate);
        if ($root === null || $root->kind !== 30040
            || strtolower($root->pubkey) !== $publication->ownerPubkey
            || $this->dTag($root->tags) !== $publication->dtag
            || preg_match('/^[a-f0-9]{64}$/D', $root->id) !== 1) {
            throw new \InvalidArgumentException('Magazine index is unavailable or invalid.');
        }
        $current = $this->settings->get($publication->coordinate);
        $tags = AboutIndexMutation::replace($root, $current->aboutArticleCoordinate, $reference?->coordinate);

        return [$reference, $root, $tags];
    }

    /** @param list<list<string>> $tags */
    private function dTag(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'd') {
                return $tag[1] ?? null;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function requestData(Request $request): ?array
    {
        if (strlen($request->getContent()) > 1_000_000) {
            return null;
        }
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function validCsrf(array $data, PublicationContext $publication): bool
    {
        $value = $data['_token'] ?? null;
        return is_string($value) && $this->csrf->isTokenValid(
            new CsrfToken('unfold_settings:' . $publication->coordinate, $value),
        );
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}