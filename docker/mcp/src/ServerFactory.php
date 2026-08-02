<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp;

use DecentNewsroom\Mcp\Client\NewsroomApiClient;
use DecentNewsroom\Mcp\Resource\ArticleResources;
use DecentNewsroom\Mcp\Tool\ArticleTools;
use PhpMcp\Server\Server;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Builds the configured MCP Server with its dependencies wired.
 *
 * Both entrypoints (bin/mcp-stdio, bin/mcp-http) call build() and then attach
 * a transport, keeping element registration and DI in one place.
 */
final class ServerFactory
{
    public static function build(): Server
    {
        $baseUrl = self::env('NEWSROOM_INTERNAL_API_BASE', 'http://php');
        $internalToken = self::env('INTERNAL_API_TOKEN', '');

        if ($internalToken === '') {
            fwrite(STDERR, "[WARN] INTERNAL_API_TOKEN is empty; newsroom internal API will reject requests.\n");
        }

        $client = new NewsroomApiClient(
            HttpClient::create(['timeout' => 15]),
            $baseUrl,
            $internalToken,
        );

        $container = new ArrayContainer([
            NewsroomApiClient::class => $client,
            ArticleTools::class => new ArticleTools($client),
            ArticleResources::class => new ArticleResources($client),
        ]);

        $server = Server::make()
            ->withServerInfo('Decent Newsroom Articles', '1.0.0')
            ->withContainer($container)
            ->build();

        // Discover #[McpTool]/#[McpResourceTemplate] elements under src/.
        $server->discover(basePath: dirname(__DIR__), scanDirs: ['src']);

        return $server;
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }
}
