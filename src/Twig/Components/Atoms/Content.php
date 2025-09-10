<?php

namespace App\Twig\Components\Atoms;

use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Content
{
    public string $parsed = '';
    public function __construct(
        private readonly Converter $converter
    ) {
    }

    /**
     */
    public function mount($content): void
    {
        try {
            $this->parsed = $this->converter->convertToHTML($content);
        } catch (CommonMarkException) {
            $this->parsed = $content;
        }
    }
}
