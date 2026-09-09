<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\ServerFactory;
use PHPUnit\Framework\TestCase;

final class ServerFactoryTest extends TestCase
{
    public function testDiscoversArticleAndBookToolsAndResources(): void
    {
        putenv('INTERNAL_API_TOKEN=test-token');

        $server = ServerFactory::build();

        self::assertCount(9, $server->getRegistry()->getTools());
        self::assertCount(2, $server->getRegistry()->getResourceTemplates());
    }
}
