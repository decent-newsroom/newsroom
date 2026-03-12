<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Dto\NormalizedMedia;
use App\Util\ImetaBuilder;
use Psr\Log\LoggerInterface;

/**
 * Builds event drafts for media posts (kinds 20, 21, 22).
 *
 * Generates unsigned event structures following NIP-68 (pictures)
 * and NIP-71 (videos) conventions, using imeta tags from ImetaBuilder.
 *
 * Signing and relay publishing are handled by the frontend signer flow
 * or by passing the draft to NostrClient::publishEvent().
 *
 * @see §9 of multimedia-manager spec
 */
class MediaPublisher
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Build an unsigned kind 20 (Picture) event draft.
     *
     * @param string            $pubkey     Author hex pubkey
     * @param string            $title      Required title
     * @param string            $content    Description/content
     * @param NormalizedMedia[] $images     One or more image assets
     * @param array             $options    Optional: hashtags, alt, content_warning, add_client_tag
     * @return array Unsigned event structure
     */
    public function buildPictureDraft(
        string $pubkey,
        string $title,
        string $content,
        array $images,
        array $options = [],
    ): array {
        if (empty($images)) {
            throw new \InvalidArgumentException('At least one image is required for a picture event');
        }

        $tags = ImetaBuilder::buildEventTags(20, $title, $images, $options);

        return $this->buildEventDraft(20, $pubkey, $content, $tags);
    }

    /**
     * Build an unsigned kind 21 (Video) event draft.
     *
     * @param string            $pubkey   Author hex pubkey
     * @param string            $title    Required title
     * @param string            $content  Summary/description
     * @param NormalizedMedia[] $variants One or more video variants
     * @param array             $options  Optional: hashtags, alt, published_at, content_warning
     * @return array Unsigned event structure
     */
    public function buildVideoDraft(
        string $pubkey,
        string $title,
        string $content,
        array $variants,
        array $options = [],
    ): array {
        if (empty($variants)) {
            throw new \InvalidArgumentException('At least one video variant is required');
        }

        $tags = ImetaBuilder::buildEventTags(21, $title, $variants, $options);

        return $this->buildEventDraft(21, $pubkey, $content, $tags);
    }

    /**
     * Build an unsigned kind 22 (Short Video) event draft.
     *
     * Same as video but for short-form portrait content.
     * Warns if dimensions suggest landscape but does not reject.
     *
     * @param string            $pubkey   Author hex pubkey
     * @param string            $title    Required title
     * @param string            $content  Summary/description
     * @param NormalizedMedia[] $variants Video variants
     * @param array             $options  Optional: hashtags, alt, published_at, content_warning
     * @return array Unsigned event structure with optional 'warnings' key
     */
    public function buildShortVideoDraft(
        string $pubkey,
        string $title,
        string $content,
        array $variants,
        array $options = [],
    ): array {
        if (empty($variants)) {
            throw new \InvalidArgumentException('At least one video variant is required');
        }

        $warnings = [];

        // Check orientation — warn but don't reject landscape
        foreach ($variants as $variant) {
            if ($variant->dimensions) {
                $parts = explode('x', $variant->dimensions);
                if (count($parts) === 2) {
                    $width = (int) $parts[0];
                    $height = (int) $parts[1];
                    if ($width > $height) {
                        $warnings[] = 'Video dimensions (' . $variant->dimensions . ') suggest landscape orientation. Short videos are typically portrait.';
                    }
                }
            }
        }

        $tags = ImetaBuilder::buildEventTags(22, $title, $variants, $options);
        $draft = $this->buildEventDraft(22, $pubkey, $content, $tags);

        if (!empty($warnings)) {
            $draft['warnings'] = $warnings;
            $this->logger->info('Short video draft has orientation warnings', ['warnings' => $warnings]);
        }

        return $draft;
    }

    /**
     * Build the base unsigned event structure.
     */
    private function buildEventDraft(int $kind, string $pubkey, string $content, array $tags): array
    {
        return [
            'kind' => $kind,
            'pubkey' => $pubkey,
            'content' => $content,
            'tags' => $tags,
            'created_at' => time(),
        ];
    }
}

