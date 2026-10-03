<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller;

use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Contract\InteractionReaderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\ReaderIdentityInterface;
use DecentNewsroom\UnfoldBundle\Contract\ReaderWriteLimiterInterface;
use DecentNewsroom\UnfoldBundle\Contract\SignedInteractionPublisherInterface;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionPolicy;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionView;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ReaderInteractionController
{
    public function __construct(
        private InteractionReaderInterface $reader,
        private SignedInteractionPublisherInterface $publisher,
        private ReaderIdentityInterface $identity,
        private ReaderWriteLimiterInterface $limiter,
        private InteractionPolicy $policy,
        private InteractionView $view,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $target = $this->target($request, $request->query->all());
            $cursor = $request->query->get('cursor');
            if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 256)) {
                throw new \InvalidArgumentException('unfold_interactions.invalid');
            }
            $page = $this->reader->thread($target, $cursor);
            $state = $this->reader->state($target);
            $refreshStatus = 'queued';
            try {
                $this->reader->refresh($target);
            } catch (\RuntimeException $e) {
                $refreshStatus = 'unavailable';
                $this->logger->warning('Discussion refresh could not be queued', ['coordinate' => $target->post->coordinate, 'exception' => $e]);
            }
            return [
                'comments' => $this->view->comments($page->comments),
                'comments_count' => $page->count, 'next_cursor' => $page->nextCursor,
                'likes' => $state->likes, 'reposts' => $state->reposts,
                'refresh_status' => $refreshStatus,
            ];
        });
    }

    public function me(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $target = $this->target($request, $request->query->all());
            $pubkey = $this->identity->pubkey();
            $state = $this->reader->state($target, $pubkey);
            return [
                ...$state->toArray(), 'pubkey' => $pubkey,
                'login_url' => $this->identity->loginUrl($request, PublicationUrlGenerator::postPath($target->post)),
                'csrf_token' => $pubkey === null ? null : $this->csrf->getToken($this->tokenId($target, $pubkey))->getValue(),
                'repost_available' => $target->original !== null && $target->relayHint !== null,
            ];
        });
    }

    public function prepare(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            [$data, $target, $pubkey] = $this->writeInput($request);
            $action = $this->action($data);
            $state = $this->reader->state($target, $pubkey);
            if (($action === 'like' && $state->liked) || ($action === 'repost' && $state->reposted)) {
                return ['unchanged' => true, ...$state->toArray()];
            }
            $content = $data['content'] ?? '';
            if (!is_string($content)) {
                throw new \InvalidArgumentException('unfold_interactions.invalid_comment');
            }
            return [
                'event' => $this->policy->prepare($target, $pubkey, $action, $content, $this->parent($data, $target, $action)),
                'pubkey' => $pubkey, 'target_event_id' => $target->post->eventId,
            ];
        });
    }

    public function publish(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            [$data, $target, $pubkey] = $this->writeInput($request);
            $action = $this->action($data);
            $event = $data['event'] ?? null;
            if (!is_array($event)) {
                throw new \InvalidArgumentException('unfold_interactions.invalid');
            }
            $this->policy->assertSignedIntent($event, $target, $pubkey, $action, $this->parent($data, $target, $action));
            return $this->publisher->publish($event, $target, $pubkey)->toArray();
        });
    }

    public function status(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $target = $this->target($request, $request->query->all());
            $pubkey = $this->requireReader();
            $eventId = $this->eventId($request->query->all());
            $result = $this->publisher->status($eventId, $target, $pubkey);
            if ($result === null) {
                throw new HttpException(404, 'unfold_interactions.delivery_unavailable');
            }
            return $result->toArray();
        });
    }

    public function retry(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            [$data, $target, $pubkey] = $this->writeInput($request);
            return $this->publisher->retry($this->eventId($data), $target, $pubkey)->toArray();
        });
    }

    /** @param array<string, mixed> $data */
    private function target(Request $request, array $data): InteractionTarget
    {
        $site = $request->attributes->get('_unfold_site');
        if (!$site instanceof PublicationSite || !is_string($data['coordinate'] ?? null)) {
            throw new HttpException(404, 'unfold_interactions.target_unavailable');
        }
        $coordinate = ContentReference::fromInput($data['coordinate'])->coordinate;
        $target = $this->reader->target($site->coordinate, $coordinate);
        if ($target === null) {
            throw new HttpException(404, 'unfold_interactions.target_unavailable');
        }
        return $target;
    }

    /** @return array{array<string, mixed>, InteractionTarget, string} */
    private function writeInput(Request $request): array
    {
        $pubkey = $this->requireReader();
        $origin = $request->headers->get('Origin');
        if (($origin !== null && $origin !== $request->getSchemeAndHttpHost())
            || $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw new HttpException(403, 'unfold_interactions.access_denied');
        }
        $body = $request->getContent();
        if (strlen($body) > 1_000_000) {
            throw new HttpException(413, 'unfold_interactions.invalid');
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('unfold_interactions.invalid', 0, $e);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }
        $target = $this->target($request, $data);
        if (!is_string($data['_token'] ?? null)
            || !$this->csrf->isTokenValid(new CsrfToken($this->tokenId($target, $pubkey), $data['_token']))) {
            throw new HttpException(403, 'unfold_interactions.access_denied');
        }
        if (!$this->limiter->consume($pubkey)) {
            throw new HttpException(429, 'unfold_interactions.rate_limited');
        }
        return [$data, $target, $pubkey];
    }

    private function requireReader(): string
    {
        return $this->identity->pubkey() ?? throw new HttpException(401, 'unfold_interactions.login_required');
    }

    private function tokenId(InteractionTarget $target, string $pubkey): string
    {
        return 'unfold_reader:' . hash('sha256', $target->publicationCoordinate . "\0" . $target->post->coordinate . "\0" . $pubkey);
    }

    /** @param array<string, mixed> $data */
    private function action(array $data): string
    {
        if (!is_string($data['action'] ?? null) || !in_array($data['action'], ['comment', 'reply', 'like', 'repost'], true)) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }
        return $data['action'];
    }

    /** @param array<string, mixed> $data */
    private function parent(array $data, InteractionTarget $target, string $action): ?\DecentNewsroom\UnfoldBundle\Contract\Comment
    {
        if ($action !== 'reply') {
            return null;
        }
        if (!is_string($data['parent_id'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data['parent_id']) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.invalid_parent');
        }
        return $this->reader->parent($target, $data['parent_id'])
            ?? throw new \InvalidArgumentException('unfold_interactions.invalid_parent');
    }

    /** @param array<string, mixed> $data */
    private function eventId(array $data): string
    {
        if (!is_string($data['event_id'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data['event_id']) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }
        return $data['event_id'];
    }

    /** @param callable(): array<string, mixed> $operation */
    private function respond(callable $operation): JsonResponse
    {
        try {
            $data = $operation();
            if (is_string($data['error'] ?? null) && str_starts_with($data['error'], 'unfold_interactions.')) {
                $data['error'] = $this->translator->trans($data['error']);
            }
            $response = new JsonResponse($data);
        } catch (HttpException $e) {
            $response = new JsonResponse(['error' => $this->translator->trans($e->getMessage()), 'error_key' => $e->getMessage()], $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            $key = str_starts_with($e->getMessage(), 'unfold_') ? $e->getMessage() : 'unfold_interactions.invalid';
            $response = new JsonResponse(['error' => $this->translator->trans($key), 'error_key' => $key], in_array($key, ['unfold_interactions.stale_target', 'unfold_interactions.conflict'], true) ? 409 : 422);
        } catch (\RuntimeException $e) {
            $this->logger->error('Reader interaction unavailable', ['exception' => $e]);
            $response = new JsonResponse(['error' => $this->translator->trans('unfold_interactions.unavailable'), 'error_key' => 'unfold_interactions.unavailable'], 503);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
