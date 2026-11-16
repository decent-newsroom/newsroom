<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Dto\EventWorkspace\EventPresentation;
use App\Util\ImetaParser;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Molecules:PictureGallery')]
final class PictureGallery
{
    /** @var list<array{url: string, alt: ?string, dimensions: ?string, blurhash: ?string, fallbacks: list<string>, annotations: list<array{ident: string, x: float, y: float}>}> */
    public array $images = [];
    public string $content = '';
    public ?string $eventUrl = null;
    public ?string $creditPubkey = null;
    public bool $sensitive = false;
    public ?string $warning = null;

    /** @param array<array-key, mixed> $tags */
    public function mount(array $tags, string $content = '', ?string $eventUrl = null, ?string $creditPubkey = null): void
    {
        $this->content = $content;
        $this->eventUrl = $eventUrl;
        $this->creditPubkey = $creditPubkey;
        $this->sensitive = EventPresentation::hasSensitiveTagEvidence($tags);
        $alt = null;
        foreach ($tags as $tag) {
            if (!is_array($tag) || !is_string($tag[1] ?? null)) {
                continue;
            }
            if (($tag[0] ?? null) === 'alt' && trim($tag[1]) !== '') {
                $alt ??= $tag[1];
            } elseif (($tag[0] ?? null) === 'content-warning') {
                $this->warning ??= trim($tag[1]) ?: null;
            }
        }
        foreach (ImetaParser::parseAll($tags) as $image) {
            $url = ImetaParser::getString($image, 'url');
            $mime = ImetaParser::getString($image, 'm');
            if (!$this->isImageUrl($url) || ($mime !== null && !str_starts_with(strtolower($mime), 'image/'))) {
                continue;
            }
            $annotations = [];
            foreach (ImetaParser::getArray($image, 'annotate-user') as $annotation) {
                $parts = explode(':', $annotation);
                if (count($parts) !== 3) {
                    continue;
                }
                $x = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
                $y = filter_var($parts[2], FILTER_VALIDATE_FLOAT);
                if ($x === false || $y === false || $x < 0 || $x > 100 || $y < 0 || $y > 100
                    || (PublicKey::fromHex($parts[0]) === null && PublicKey::fromBech32($parts[0]) === null)) {
                    continue;
                }
                $annotations[] = ['ident' => $parts[0], 'x' => $x, 'y' => $y];
            }
            $this->images[] = [
                'url' => $url,
                'alt' => ImetaParser::getString($image, 'alt') ?: $alt,
                'dimensions' => ImetaParser::getString($image, 'dim'),
                'blurhash' => ImetaParser::getString($image, 'blurhash'),
                'fallbacks' => array_values(array_filter(ImetaParser::getArray($image, 'fallback'), $this->isImageUrl(...))),
                'annotations' => $annotations,
            ];
        }
    }

    private function isImageUrl(?string $url): bool
    {
        return $url !== null && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
