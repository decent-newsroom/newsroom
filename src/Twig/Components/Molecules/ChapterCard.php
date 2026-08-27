<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\Event;
use nostriphant\NIP19\Bech32;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Molecules:ChapterCard')]
final class ChapterCard
{
    public Event $chapter;
    public ?string $mag = null;
    public string $slug = '';
    public string $title = '';
    public ?string $summary = null;
    public string $authorPubkey = '';
    public int $createdAt = 0;
    public string $link = '#';
    public ?string $naddr = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function mount(Event $chapter, ?string $mag = null): void
    {
        $this->chapter = $chapter;
        $this->mag = $mag;

        $this->slug = (string) ($this->chapter->getDTag() ?? '');
        if ($this->slug === '') {
            $this->slug = (string) ($this->chapter->getSlug() ?? '');
        }

        $this->title = trim((string) ($this->chapter->getTitle() ?? ''));
        if ($this->title === '') {
            $this->title = $this->slug !== '' ? $this->slug : substr($this->chapter->getId(), 0, 12);
        }

        $this->summary = trim((string) ($this->chapter->getSummary() ?? ''));
        if ($this->summary === '') {
            $this->summary = $this->excerptFromAsciiDoc($this->chapter->getContent());
        }
        if ($this->summary === '') {
            $this->summary = null;
        }

        $this->authorPubkey = $this->chapter->getPubkey();
        $this->createdAt = $this->chapter->getCreatedAt();

        $this->naddr = $this->encodeNaddr();
        if ($this->mag !== null && $this->mag !== '' && $this->slug !== '') {
            $this->link = $this->urlGenerator->generate('magazine-chapter', [
                'mag' => $this->mag,
                'slug' => $this->slug,
            ]);
            return;
        }

        if ($this->naddr !== null) {
            $this->link = $this->urlGenerator->generate('chapter', ['naddr' => $this->naddr]);
        }
    }

    private function encodeNaddr(): ?string
    {
        if ($this->slug === '' || $this->authorPubkey === '') {
            return null;
        }

        try {
            return (string) Bech32::naddr(
                kind: $this->chapter->getKind(),
                pubkey: $this->authorPubkey,
                identifier: $this->slug,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function excerptFromAsciiDoc(string $content): string
    {
        $text = preg_replace('~^\s*(=+|#+)\s*~m', '', $content) ?? $content;
        $text = preg_replace('~\b(?:image|video)::[^\[]+\[[^\]]*\]~i', ' ', $text) ?? $text;
        $text = preg_replace('~(?:\[\[[^\]]+\]\]|\[[^\]]+\])~', ' ', $text) ?? $text;
        $text = preg_replace('~[`*_+#\~|>-]+~', ' ', $text) ?? $text;
        $text = preg_replace('~\s+~u', ' ', strip_tags($text)) ?? $text;
        $text = trim($text);

        if (strlen($text) <= 220) {
            return $text;
        }

        return rtrim(substr($text, 0, 217)) . '…';
    }
}
