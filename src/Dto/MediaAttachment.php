<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MediaAttachment
{
    #[Assert\NotBlank(message: 'URL is required')]
    #[Assert\Url(message: 'Must be a valid URL')]
    public string $url = '';

    #[Assert\NotBlank(message: 'MIME type is required')]
    #[Assert\Regex(
        pattern: '/^[\w\-]+\/[\w\-\+\.]+$/',
        message: 'Must be a valid MIME type (e.g., audio/mpeg, image/png)'
    )]
    public string $mimeType = '';

    public function __construct(
        string $url = '',
        string $mimeType = ''
    ) {
        $this->url = $url;
        $this->mimeType = $mimeType;
    }

    /**
     * Build the imeta tag array for this attachment.
     *
     * @return array e.g. ['imeta', 'm audio/mpeg', 'url https://example.com/file.mp3']
     */
    public function toTag(): array
    {
        return [
            'imeta',
            'm ' . $this->mimeType,
            'url ' . $this->url,
        ];
    }

    /**
     * Parse an imeta tag array into a MediaAttachment.
     *
     * @param array $tag e.g. ['imeta', 'm audio/mpeg', 'url https://example.com/file.mp3']
     * @return self|null
     */
    public static function fromTag(array $tag): ?self
    {
        if (empty($tag) || $tag[0] !== 'imeta') {
            return null;
        }

        $url = '';
        $mimeType = '';

        for ($i = 1; $i < count($tag); $i++) {
            $part = $tag[$i];
            if (str_starts_with($part, 'url ')) {
                $url = substr($part, 4);
            } elseif (str_starts_with($part, 'm ')) {
                $mimeType = substr($part, 2);
            }
        }

        if (empty($url) && empty($mimeType)) {
            return null;
        }

        return new self($url, $mimeType);
    }

    /**
     * Guess MIME type from a URL based on file extension.
     *
     * @return string The guessed MIME type, or empty string if unknown.
     */
    public static function guessMimeType(string $url): string
    {
        // Strip query string and fragment
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (empty($extension)) {
            return '';
        }

        $map = [
            // Audio
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'wav'  => 'audio/wav',
            'flac' => 'audio/flac',
            'aac'  => 'audio/aac',
            'm4a'  => 'audio/mp4',
            'opus' => 'audio/opus',
            'weba' => 'audio/webm',
            // Video
            'mp4'  => 'video/mp4',
            'm4v'  => 'video/mp4',
            'webm' => 'video/webm',
            'ogv'  => 'video/ogg',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'mkv'  => 'video/x-matroska',
            // Image
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'avif' => 'image/avif',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            // Document
            'pdf'  => 'application/pdf',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'zip'  => 'application/zip',
            'gz'   => 'application/gzip',
            'tar'  => 'application/x-tar',
            // Text
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'htm'  => 'text/html',
            'css'  => 'text/css',
            'csv'  => 'text/csv',
            'md'   => 'text/markdown',
        ];

        return $map[$extension] ?? '';
    }
}
