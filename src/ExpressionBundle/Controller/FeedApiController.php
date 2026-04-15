<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Controller;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Exception\CycleException;
use App\ExpressionBundle\Exception\ExpressionException;
use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Exception\TimeoutException;
use App\ExpressionBundle\Exception\TypeError;
use App\ExpressionBundle\Exception\UnknownOpException;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\ExpressionBundle\Exception\UnresolvedVariableException;
use App\ExpressionBundle\Exception\UnsupportedFeatureException;
use App\ExpressionBundle\Service\ExpressionService;
use App\Repository\EventRepository;
use App\Util\NostrKeyUtil;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
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
        EventRepository $eventRepository,
        Request $request,
    ): JsonResponse {
        try {
            // 1. Get authenticated user's hex pubkey
            $user = $this->getUser();
            $userIdentifier = $user->getUserIdentifier();
            $userPubkey = NostrKeyUtil::isNpub($userIdentifier)
                ? NostrKeyUtil::npubToHex($userIdentifier)
                : $userIdentifier;

            // 2. Decode naddr
            $decoded = new Bech32($naddr);
            if ($decoded->type !== 'naddr') {
                return $this->errorResponse('Invalid naddr: expected naddr type', 400);
            }

            /** @var NAddr $data */
            $data = $decoded->data;
            $kind = $data->kind;
            $pubkey = $data->pubkey;
            $identifier = $data->identifier;

            // 3. Fetch expression event from DB
            $expression = $eventRepository->findByNaddr($kind, $pubkey, $identifier);
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

