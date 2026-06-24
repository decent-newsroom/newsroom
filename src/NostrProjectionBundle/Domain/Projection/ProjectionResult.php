<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Domain\Projection;

final readonly class ProjectionResult
{
    private function __construct(
        public ProjectionStatus $status,
        public ?string $message = null,
    ) {
    }

    public static function projected(?string $message = null): self
    {
        return new self(ProjectionStatus::Projected, $message);
    }

    public static function skipped(?string $message = null): self
    {
        return new self(ProjectionStatus::Skipped, $message);
    }

    public static function failed(?string $message = null): self
    {
        return new self(ProjectionStatus::Failed, $message);
    }
}
