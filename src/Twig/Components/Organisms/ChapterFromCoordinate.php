<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\BooksChapterLookup;
use App\Service\Nostr\NostrNip19Service;
use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ChapterFromCoordinate
{
    public string $coordinate;
    public ?Event $chapter = null;
    public bool $stored = false;
    public bool $useSharedPreview = false;
    public ?string $previewBech = null;
    public ?string $error = null;
    public array $relayHints = [];
    public ?string $mag = null;
    public ?string $parsedKind = null;
    public ?string $parsedPubkey = null;
    public ?string $parsedSlug = null;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly BooksChapterLookup $booksChapterLookup,
        private readonly NostrNip19Service $nip19,
        private readonly LoggerInterface $logger,
    ) {}

    public function mount(string $coordinate, array $relayHints = [], ?string $mag = null): void
    {
        $this->coordinate = $coordinate;
        $this->relayHints = $relayHints;
        $this->mag = $mag;
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

        if ($this->chapter instanceof Event) {
            $this->stored = true;
            $this->useSharedPreview = $this->mag === null || $this->mag === '';
            if ($this->useSharedPreview) {
                try {
                    $this->previewBech = $this->nip19->encodeAddr(
                        $pubkey,
                        $slug,
                        KindsEnum::PUBLICATION_CONTENT->value,
                        array_values(array_filter($relayHints, 'is_string')),
                    );
                } catch (\Throwable $exception) {
                    $this->logger->error('Chapter coordinate could not be encoded for shared preview.', [
                        'coordinate' => $coordinate,
                        'exception' => $exception,
                    ]);
                }
            }
        } else {
            $this->chapter = $this->booksChapterLookup->find($pubkey, $slug);
        }

        if (!$this->chapter instanceof Event) {
            $this->error = 'chapter.error.not_found';
        }
    }
}
