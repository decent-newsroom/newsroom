<?php

namespace App\Message;

class FetchCommentsMessage
{
    private string $coordinate;
    private ?string $authorPubkey;

    /**
     * @param string $coordinate either an addressable coordinate "kind:pubkey:identifier"
     *                            or a 64-char lowercase hex event id (for non-addressable parents).
     * @param string|null $authorPubkey optional author pubkey hint for non-addressable parents,
     *                                   used to resolve the right relay set.
     */
    public function __construct(string $coordinate, ?string $authorPubkey = null)
    {
        $this->coordinate = $coordinate;
        $this->authorPubkey = $authorPubkey;
    }

    public function getCoordinate(): string
    {
        return $this->coordinate;
    }

    public function getAuthorPubkey(): ?string
    {
        return $this->authorPubkey;
    }
}

