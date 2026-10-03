<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Content;

final class ContentKindPolicy
{
    public const KINDS = [30023, 30041, 30818, 30817];

    public static function supports(int $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    public static function format(int $kind): string
    {
        return match ($kind) {
            30023, 30817 => 'markdown',
            30041, 30818 => 'asciidoc',
            default => throw new \InvalidArgumentException('Unsupported publication content kind.'),
        };
    }

    public static function segment(int $kind): string
    {
        return match ($kind) {
            30023 => 'a',
            30041 => 'chapter',
            30818 => 'wiki',
            30817 => 'spec',
            default => throw new \InvalidArgumentException('Unsupported publication content kind.'),
        };
    }

    public static function kindForSegment(string $segment): ?int
    {
        return match ($segment) {
            'a' => 30023,
            'chapter' => 30041,
            'wiki' => 30818,
            'spec' => 30817,
            default => null,
        };
    }
}
