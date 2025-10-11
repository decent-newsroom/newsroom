<?php

namespace App\Twig\Components\Atoms;

use App\Util\ForumTopics;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ForumAside
{
    public array $topics = ForumTopics::TOPICS;
}
