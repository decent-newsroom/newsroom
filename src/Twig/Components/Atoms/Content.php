<?php

namespace App\Twig\Components\Atoms;

use App\Util\CommonMark\Converter;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Psr\Log\LoggerInterface;

#[AsTwigComponent]
class Content
{
    public string $parsed = '';
    public function __construct(
        private readonly Converter $converter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mount(?string $content, bool $renderMedia = true): void
    {
        $content ??= '';
        try {
            $this->parsed = $this->converter->convertToHTML($content, renderMedia: $renderMedia);
        } catch (\Throwable $exception) {
            $this->logger->error('Content rendering failed.', ['exception' => $exception]);
            $this->parsed = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}
