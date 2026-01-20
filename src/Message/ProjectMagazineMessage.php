<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to trigger magazine projection
 */
class ProjectMagazineMessage
{
    private string $slug;
    private bool $force;

    public function __construct(string $slug, bool $force = false)
    {
        $this->slug = $slug;
        $this->force = $force;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function isForce(): bool
    {
        return $this->force;
    }
}
