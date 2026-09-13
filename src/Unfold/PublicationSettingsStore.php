<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Entity\UnfoldPublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicationSettingsStore implements PublicationSettingsStoreInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(string $coordinate): ?PublicationSettings
    {
        $coordinate = PublicationSettings::normalizeCoordinate($coordinate);

        return $this->entityManager->find(UnfoldPublicationSettings::class, $coordinate)?->toSettings();
    }

    public function save(PublicationSettings $settings): void
    {
        $record = $this->entityManager->find(UnfoldPublicationSettings::class, $settings->coordinate);
        if ($record === null) {
            $record = new UnfoldPublicationSettings($settings);
            $this->entityManager->persist($record);
        } else {
            $record->update($settings);
        }
        $this->entityManager->flush();
    }
}
