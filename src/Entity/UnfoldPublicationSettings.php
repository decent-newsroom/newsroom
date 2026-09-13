<?php

declare(strict_types=1);

namespace App\Entity;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'unfold_publication_settings')]
class UnfoldPublicationSettings
{
    #[ORM\Id]
    #[ORM\Column(length: 500)]
    private string $coordinate;

    #[ORM\Column(length: 255)]
    private string $theme = 'default';

    public function __construct(PublicationSettings $settings)
    {
        $this->coordinate = $settings->coordinate;
        $this->update($settings);
    }

    public function update(PublicationSettings $settings): void
    {
        if ($settings->coordinate !== $this->coordinate) {
            throw new \InvalidArgumentException('unfold_setup.immutable_coordinate');
        }
        $this->theme = $settings->theme;
    }

    public function toSettings(): PublicationSettings
    {
        return new PublicationSettings($this->coordinate, $this->theme);
    }
}
