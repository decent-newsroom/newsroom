<?php

namespace App\Util\CommonMark\ImagesExtension;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

class RawImageLinkExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addInlineParser(new RawImageLinkParser(),201);
    }
}
