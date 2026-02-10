<?php

namespace App\Twig\Components\Molecules;

use App\Entity\Article;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class BroadcastButton
{
    public Article $article;
    public ?array $relays = null;
}
