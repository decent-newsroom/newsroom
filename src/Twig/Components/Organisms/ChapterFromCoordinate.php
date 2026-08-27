<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ChapterFromCoordinate
{
    public string $coordinate;
    public ?Event $chapter = null;
    public ?string $error = null;
    public ?string $parsedKind = null;
    public ?string $parsedPubkey = null;
    public ?string $parsedSlug = null;

    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

    public function mount(string $coordinate): void
    {
        $this->coordinate = $coordinate;
        $parts = explode(':', $coordinate, 3);

        if (count($parts) !== 3) {
            $this->error = 'chapter.error.invalid_coordinate';
            return;
        }

        [$kind, $pubkey, $slug] = $parts;
        $this->parsedKind = $kind;
        $this->parsedPubkey = $pubkey;
        $this->parsedSlug = $slug;

        if ((int) $kind !== KindsEnum::PUBLICATION_CONTENT->value || $pubkey === '' || $slug === '') {
            $this->error = 'chapter.error.not_found';
            return;
        }

        $this->chapter = $this->eventRepository->findByNaddr(
            KindsEnum::PUBLICATION_CONTENT->value,
            $pubkey,
            $slug,
        );

        if (!$this->chapter instanceof Event) {
            $this->error = 'chapter.error.not_found';
        }
    }
}
