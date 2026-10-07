<?php

declare(strict_types=1);

namespace App\Service\Admin;

final class ElasticsearchAuthorBanList
{
    /** @return list<string> */
    public function extractPubkeys(array $response): array
    {
        if (!isset($response['hits']['hits']) || !is_array($response['hits']['hits'])
            || !array_is_list($response['hits']['hits'])) {
            throw new \InvalidArgumentException('Expected an Elasticsearch response with a hits.hits array.');
        }
        if (!empty($response['timed_out']) || !empty($response['_shards']['failed'])) {
            throw new \InvalidArgumentException('Refusing an incomplete response with timeouts or failed shards.');
        }

        $pubkeys = [];
        foreach ($response['hits']['hits'] as $i => $hit) {
            $pubkey = $hit['_source']['pubkey'] ?? null;
            if (!is_string($pubkey) || preg_match('/^[a-f0-9]{64}$/iD', trim($pubkey)) !== 1) {
                throw new \InvalidArgumentException(sprintf('Hit %d is missing a valid _source.pubkey; nothing was imported.', $i));
            }
            $pubkeys[strtolower(trim($pubkey))] = true;
        }

        return array_keys($pubkeys);
    }
}
