<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Util\NostrKeyUtil;
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
        private readonly UserEntityRepository $userRepository,
        #[Autowire(env: 'ESSAYIST_POLICY_TOKEN')]
        private readonly string $policyToken,
    ) {
    }

    /**
     * Check whether a hex pubkey belongs to an Essayist author (has ROLE_ESSAYIST_AUTHOR).
     *
     * Called by docker/strfry-essayist/write-policy.sh on every incoming EVENT.
     *
     * GET /api/internal/essayist/writer/{pubkey}
     * Authorization: Bearer <ESSAYIST_POLICY_TOKEN>
     *
     * Response:
     *   {"approved": true}  — writer has ROLE_ESSAYIST_AUTHOR, accept the event
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

        // Convert hex pubkey to npub to query the user
        try {
            $npub = NostrKeyUtil::hexToNpub($pubkey);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['approved' => false, 'reason' => 'invalid pubkey']);
        }

        // Check if user with this npub has ROLE_ESSAYIST_AUTHOR
        $user = $this->userRepository->findOneBy(['npub' => $npub]);
        $approved = $user && in_array(RolesEnum::ESSAYIST_AUTHOR->value, $user->getRoles(), true);

        return new JsonResponse(['approved' => $approved]);
    }
}
