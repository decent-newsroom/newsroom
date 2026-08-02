<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp;

use Psr\Container\ContainerInterface;

/**
 * Minimal PSR-11 container mapping service ids to pre-built instances.
 * Sufficient for wiring the MCP element classes (ArticleTools, ArticleResources)
 * with their NewsroomApiClient dependency.
 */
final class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(private array $services = [])
    {
    }

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new class ("Service not found: $id") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
