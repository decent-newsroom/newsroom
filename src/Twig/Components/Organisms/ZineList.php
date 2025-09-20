<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Entity\Nzine;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ZineList
{
    public array $nzines = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function mount(): void
    {

        $nzines = $this->entityManager->getRepository(Event::class)->findBy(['kind' => KindsEnum::PUBLICATION_INDEX]);

        // filter, only keep type === magazine
        $this->nzines = array_filter($nzines, function ($index) {
            // look for tags
            $tags = $index->getTags();
            $isMagType = false;
            $isTopLevel = false;
            foreach ($tags as $tag) {
                // only if tag 'type' with value 'magazine'
                if ($tag[0] === 'type' && $tag[1] === 'magazine') {
                    $isMagType = true;
                }
                // and only contains other indices:
                // a tags with kind 30040
                if ($tag[0] === 'a' && $isTopLevel === false) {
                    // tag format: ['a', 'kind:pubkey:slug']
                    $parts = explode(':', $tag[1]);
                    if ($parts[0] == (string)KindsEnum::PUBLICATION_INDEX->value) {
                        $isTopLevel = true;
                    }
                }
            }
            return $isMagType && $isTopLevel;
        });


    }
}
