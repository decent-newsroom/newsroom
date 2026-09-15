<?php

declare(strict_types=1);

namespace App\Twig;

use App\Helper\NavigationBuilderTrait;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NavigationExtension extends AbstractExtension
{
    use NavigationBuilderTrait;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('build_main_nav', $this->buildMainNav(...)),
        ];
    }
}
