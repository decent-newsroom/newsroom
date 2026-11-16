<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Dto\UserMetadata;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Organisms:FeaturedWritersCarousel')]
final class FeaturedWritersCarousel
{
    /** @var list<array{npub: string, metadata: UserMetadata}> */
    public array $members = [];
    public string $packNpub = '';
    public string $packDtag = '';
    public bool $personal = false;
    public int $breakId = 0;
}
