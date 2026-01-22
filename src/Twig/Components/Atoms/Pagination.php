<?php

namespace App\Twig\Components\Atoms;

use Pagerfanta\Pagerfanta;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Pagination
{
    public Pagerfanta $pager;
    public string $route;
    public array $routeParams = [];
}
