<?php

namespace App\Twig\Components\Molecules;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Card
{
    public string $tag = 'div';
    public string $category = '';
    public object $article;
    public object $user;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function mount(?string $npub = null): void
    {
        if ($npub) {
            $this->user = $this->entityManager->getRepository(User::class)->findOneBy(['npub' => $npub]);
        }
    }
}
