<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Contract;

interface EventInterface
{
    public function getId(): string;

    public function getKind(): int;

    public function getPubkey(): string;

    public function getContent(): string;

    public function getCreatedAt(): int;

    /** @return array<int, array<int, string>> */
    public function getTags(): array;

    public function getSig(): string;
}
