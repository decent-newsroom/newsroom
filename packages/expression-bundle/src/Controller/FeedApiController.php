<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Controller;

use DecentNewsroom\ExpressionBundle\Exception\ArityException;
use DecentNewsroom\ExpressionBundle\Exception\CycleException;
use DecentNewsroom\ExpressionBundle\Exception\ExpressionException;
use DecentNewsroom\ExpressionBundle\Exception\InvalidArgumentException;
use DecentNewsroom\ExpressionBundle\Exception\TimeoutException;
use DecentNewsroom\ExpressionBundle\Exception\TypeError;
use DecentNewsroom\ExpressionBundle\Exception\UnknownOpException;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedVariableException;
use DecentNewsroom\ExpressionBundle\Exception\UnsupportedFeatureException;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;
use DecentNewsroom\ExpressionBundle\Service\ExpressionService;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/feed')]
#[IsGranted('ROLE_USER')]
final class FeedApiController extends AbstractController
{
    #[Route('/{naddr}', name: 'api_feed_evaluate', methods: ['GET'])]
    public function evaluate(
        string $naddr,
        ExpressionService $expressionService,
        EventResolver $eventResolver,
        Bech32EncoderInterface $bech32Encoder,
        Request $request,
    ): JsonResponse {
        try {
            // 1. Get authenticated user's hex pubkey
            $user = $this->getUser();
            $userIdentifier = $user->getUserIdentifier();
            $userPubkey = str_starts_with(strtolower(trim((string) ($userIdentifier))), 'npub1')
                ? (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($userIdentifier))
                : $userIdentifier;

            // 2. Decode naddr
            $data = $bech32Encoder->decodeComplexEntity($naddr);
            if (($data['type'] ?? null) !== 'address') {
                return $this->errorResponse('Invalid naddr: expected naddr payload', 400);
            }

            $kind = (int) ($data['kind'] ?? 0);
            $pubkey = (string) ($data['pubkey'] ?? '');
            $identifier = (string) ($data['identifier'] ?? '');

            // 3. Fetch expression event from the optional local store or relays
            $expression = $eventResolver->findByNaddr($kind, $pubkey, $identifier);
            if ($expression === null) {
                return $this->errorResponse("Expression not found: {$kind}:{$pubkey}:{$identifier}", 404);
            }

            // 4. Evaluate (cached)
            $results = $expressionService->evaluateCached($expression, $userPubkey);

            // 5. Apply optional pagination
            $offset = max(0, (int) $request->query->get('offset', 0));
            $limit = min(500, max(1, (int) $request->query->get('limit', 50)));
            $totalCount = count($results);
            $paginatedResults = array_slice($results, $offset, $limit);

            // 6. Serialize NormalizedItem[] → event JSON
            $events = [];
            foreach ($paginatedResults as $item) {
                $event = $item->getEvent();
                $eventData = [
                    'id' => $event->getId(),
                    'pubkey' => $event->getPubkey(),
                    'kind' => $event->getKind(),
                    'content' => $event->getContent(),
                    'tags' => $event->getTags(),
                    'created_at' => $event->getCreatedAt(),
                    'sig' => $event->getSig(),
                ];
                if ($item->getScore() !== null) {
                    $eventData['_score'] = $item->getScore();
                }
                $events[] = $eventData;
            }

            // 7. Return JSON response
            return new JsonResponse([
                'expression' => "{$kind}:{$pubkey}:{$identifier}",
                'count' => $totalCount,
                'offset' => $offset,
                'limit' => $limit,
                'events' => $events,
            ]);

        } catch (UnresolvedRefException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        } catch (CycleException|UnresolvedVariableException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (TimeoutException $e) {
            return $this->errorResponse($e->getMessage(), 504);
        } catch (UnsupportedFeatureException $e) {
            return $this->errorResponse($e->getMessage(), 501);
        } catch (UnknownOpException|InvalidArgumentException|ArityException|TypeError $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (ExpressionException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->errorResponse('Internal server error', 500);
        }
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
