<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\FollowPackPurpose;
use App\Service\FollowPackService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Internal policy API used by the strfry-essayist write-policy.sh script.
 *
 * Not intended for public consumption — access is guarded by a shared
 * secret token (ESSAYIST_POLICY_TOKEN env var) and the endpoint is on
 * the internal Docker network only.
 */
#[Route('/api/internal/essayist')]
final class EssayistWriterPolicyController extends AbstractController
{
    public function __construct(
        private readonly FollowPackService $followPackService,
        #[Autowire(env: 'ESSAYIST_POLICY_TOKEN')]
        private readonly string $policyToken,
    ) {
    }

    /**
     * Check whether a hex pubkey belongs to the approved Essayist writers pack.
     *
     * Called by docker/strfry-essayist/write-policy.sh on every incoming EVENT.
     *
     * GET /api/internal/essayist/writer/{pubkey}
     * Authorization: Bearer <ESSAYIST_POLICY_TOKEN>
     *
     * Response:
     *   {"approved": true}  — writer is in the approved pack, accept the event
     *   {"approved": false} — writer is not approved, reject the event
     */
    #[Route('/writer/{pubkey}', name: 'api_essayist_writer_policy', methods: ['GET'])]
    public function check(string $pubkey, Request $request): JsonResponse
    {
        // Validate shared secret token
        $authHeader = $request->headers->get('Authorization', '');
        $token = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';

        if (!hash_equals($this->policyToken, $token)) {
            return new JsonResponse(['approved' => false], Response::HTTP_UNAUTHORIZED);
        }

        // Validate pubkey format (64-char hex)
        if (!preg_match('/^[0-9a-f]{64}$/', $pubkey)) {
            return new JsonResponse(['approved' => false, 'reason' => 'invalid pubkey format']);
        }

        $approvedPubkeys = $this->followPackService->getPubkeysForPurpose(
            FollowPackPurpose::ESSAYIST_WRITERS
        );

        $approved = in_array($pubkey, $approvedPubkeys, true);

        return new JsonResponse(['approved' => $approved]);
    }
}
