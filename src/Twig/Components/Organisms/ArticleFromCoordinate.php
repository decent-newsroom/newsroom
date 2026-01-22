<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ArticleFromCoordinate
{
    public string $coordinate;
    public ?Article $article = null;
    public ?string $error = null;
    public array $authorsMetadata = [];
    public ?string $mag = null; // magazine slug (optional)
    public ?string $cat = null; // category slug (optional)

    public function __construct(
        private readonly ArticleRepository $articleRepository
    ) {}

    public function mount($coordinate): void
    {
        $this->coordinate = $coordinate;
        // Parse coordinate (format: kind:pubkey:slug)
        $parts = explode(':', $this->coordinate, 3);

        if (count($parts) !== 3) {
            $this->error = 'Invalid coordinate format. Expected kind:pubkey:slug';
            return;
        }

        [$kind, $pubkey, $slug] = $parts;

        // Validate kind is numeric
        if (!is_numeric($kind)) {
            $this->error = 'Invalid kind value in coordinate';
            return;
        }

        // Query the database for the article
        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->where('a.pubkey = :pubkey')
            ->andWhere('a.slug = :slug')
            ->setParameter('pubkey', $pubkey)
            ->setParameter('slug', $slug)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1);

        $result = $qb->getQuery()->getOneOrNullResult();

        if ($result === null) {
            $this->error = 'Article not found';
            return;
        }

        $this->article = $result;
    }
}
