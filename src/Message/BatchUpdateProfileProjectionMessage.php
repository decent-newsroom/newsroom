<?php

namespace App\Message;

/**
 * Message to trigger batch profile projection updates for multiple users.
 *
 * This is used by the background profile refresh worker to update
 * profiles in batches, coalescing update requests.
 */
class BatchUpdateProfileProjectionMessage
{
    /**
     * @param string[] $pubkeyHexList Array of pubkey hex strings
     */
    public function __construct(
        private readonly array $pubkeyHexList
    ) {
    }

    /**
     * @return string[]
     */
    public function getPubkeyHexList(): array
    {
        return $this->pubkeyHexList;
    }
}
