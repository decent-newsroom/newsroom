<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

final readonly class PublicationOnboardingContext
{
    public function __construct(public string $ownerPubkey) {}
}
