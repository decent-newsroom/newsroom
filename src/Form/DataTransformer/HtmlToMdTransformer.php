<?php

namespace App\Form\DataTransformer;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class HtmlToMdTransformer implements DataTransformerInterface
{

    private HtmlConverter $htmlToMd;
    private MarkdownConverter $mdToHtml;

    public function __construct()
    {
        $this->htmlToMd = new HtmlConverter();

        // Create a minimal Environment for Markdown -> HTML conversion used by the editor
        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $this->mdToHtml = new MarkdownConverter($environment);
    }

    /**
     * Transforms Markdown into HTML (for displaying in the form).
     *  @inheritDoc
     */
    public function transform(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            // Convert Markdown to HTML for the editor field
            return (string) $this->mdToHtml->convert((string) $value);
        } catch (\Throwable $e) {
            // If conversion fails, fall back to raw value to avoid breaking the form
            return (string) $value;
        }
    }

    /**
     * Transforms a HTML string to Markdown.
     * @inheritDoc
     */
    public function reverseTransform(mixed $value): mixed
    {
        if (!$value) {
            return '';
        }

        try {
            // Convert HTML (from the editor) to Markdown for storage
            return $this->htmlToMd->convert((string) $value);
        } catch (\Exception $e) {
            throw new TransformationFailedException('Failed to convert HTML to Markdown');
        }
    }
}
