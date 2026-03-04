<?php

declare(strict_types=1);

namespace App\Doctrine\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a PHP array to a PostgreSQL `jsonb` column.
 *
 * Doctrine's built-in JsonType maps to `json`. This subclass overrides the
 * SQL declaration so the column is created as `jsonb`, which supports the @>
 * containment operator and GIN indexing. This also prevents auto-generated
 * migrations from reverting the column type back to `json`.
 */
final class JsonbType extends JsonType
{
    public const NAME = 'jsonb';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    public function getName(): string
    {
        return self::NAME;
    }
}

