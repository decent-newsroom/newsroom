<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\Article;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Molecules:ArticleSocialActions')]
final class ArticleSocialActions
{
    public Article $article;

    public string $coordinate = '';

    public string $canonicalUrl = '';

    public string $naddrEncoded = '';

    public string $commentFormId = 'article-comment-form';

    public ?string $recipientLud16 = null;

    public ?string $recipientLud06 = null;

    public bool $isProtected = false;

    /** @var string[]|null */
    public ?array $relays = null;
}
