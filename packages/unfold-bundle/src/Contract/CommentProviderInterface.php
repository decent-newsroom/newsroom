<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface CommentProviderInterface
{
    /**
     * @return list<Comment>
     */
    public function findByCoordinate(string $coordinate): array;
}
