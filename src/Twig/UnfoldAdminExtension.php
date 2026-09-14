<?php

declare(strict_types=1);

namespace App\Twig;

use App\Helper\NavigationBuilderTrait;
use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UnfoldAdminExtension extends AbstractExtension
{
    use NavigationBuilderTrait;

    public function __construct(private readonly RequestStack $requestStack) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('unfold_admin_nav', $this->publicationNavigation(...))];
    }

    public function publicationNavigation(PublicationContext $publication): array
    {
        return $this->buildUnfoldAdminNav(
            $publication->adminPathPrefix,
            $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '',
        );
    }
}
