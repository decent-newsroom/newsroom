<?php

namespace App\Twig\Components\Atoms;

use App\Repository\UserEntityRepository;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedWriters
{
    public array $writers = [];

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly UserEntityRepository $userRepository
    ) {}

    public function mount(): void
    {
        // Get top 3 featured writers ordered by most recent article
        $featuredUsers = $this->userRepository->findTopFeaturedWriters(3);

        if (empty($featuredUsers)) {
            return;
        }

        // Convert npubs to hex pubkeys for metadata lookup
        $hexPubkeys = [];
        $npubToHex = [];
        foreach ($featuredUsers as $user) {
            $npub = $user->getNpub();
            if (NostrKeyUtil::isNpub($npub)) {
                $hex = NostrKeyUtil::npubToHex($npub);
                $hexPubkeys[] = $hex;
                $npubToHex[$npub] = $hex;
            }
        }

        if (empty($hexPubkeys)) {
            return;
        }

        // Batch fetch metadata for all featured writers
        $metadataMap = $this->redisCacheService->getMultipleMetadata($hexPubkeys);

        // Build writers array with npub and metadata
        foreach ($featuredUsers as $user) {
            $npub = $user->getNpub();
            $hex = $npubToHex[$npub] ?? null;
            if ($hex && isset($metadataMap[$hex])) {
                $this->writers[] = [
                    'npub' => $npub,
                    'pubkey' => $hex,
                    'metadata' => $metadataMap[$hex],
                    'user' => $user,
                ];
            }
        }
    }
}

