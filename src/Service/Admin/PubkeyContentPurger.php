<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Elastica\Index;
use Elastica\Query\Terms;
use Psr\Log\LoggerInterface;

class PubkeyContentPurger
{
    private const TABLES = ['article', 'highlight', 'magazine', 'event'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Index $articleIndex,
        private readonly bool $elasticsearchEnabled,
        private readonly AuthorContentCacheInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param list<string> $pubkeys
     *  @return array<string, int>
     */
    public function counts(array $pubkeys): array
    {
        $counts = array_fill_keys(self::TABLES, 0);
        foreach (array_chunk($pubkeys, 100) as $batch) {
            foreach (self::TABLES as $table) {
                $counts[$table] += (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM $table WHERE LOWER(pubkey) IN (:pubkeys)",
                    ['pubkeys' => $batch], ['pubkeys' => ArrayParameterType::STRING],
                );
            }
        }
        return $counts;
    }

    /** @param list<string> $pubkeys
     *  @return array<string, int>
     */
    public function purge(array $pubkeys): array
    {
        $counts = array_fill_keys([...self::TABLES, 'parsed_reference', 'current_record', 'elasticsearch'], 0);
        foreach (array_chunk($pubkeys, 100) as $batch) {
            // Delete by author even when an earlier attempt already removed local rows.
            if ($this->elasticsearchEnabled) {
                $response = $this->articleIndex->deleteByQuery(new Terms('pubkey', $batch), ['refresh' => true]);
                $result = $response->getData();
                if (!empty($result['failures']) || !empty($result['timed_out']) || !empty($result['version_conflicts'])) {
                    throw new \RuntimeException('Elasticsearch author purge reported failures; local rows were retained for retry.');
                }
                $counts['elasticsearch'] += (int) ($result['deleted'] ?? 0);
            }

            $this->cacheInvalidator->invalidate();
            $deleted = $this->connection->transactional(function () use ($batch): array {
                $parameters = ['pubkeys' => $batch];
                $types = ['pubkeys' => ArrayParameterType::STRING];
                $deleted = [
                    'parsed_reference' => $this->connection->executeStatement(
                        'DELETE FROM parsed_reference WHERE source_event_id IN (
                            SELECT id FROM event WHERE LOWER(pubkey) IN (:pubkeys)
                            UNION SELECT event_id FROM article WHERE LOWER(pubkey) IN (:pubkeys)
                            UNION SELECT event_id FROM highlight WHERE LOWER(pubkey) IN (:pubkeys)
                            UNION SELECT current_event_id FROM current_record WHERE LOWER(pubkey) IN (:pubkeys)
                        )',
                        $parameters, $types,
                    ),
                    'current_record' => $this->connection->executeStatement(
                        'DELETE FROM current_record WHERE LOWER(pubkey) IN (:pubkeys)', $parameters, $types,
                    ),
                ];
                foreach (self::TABLES as $table) {
                    $deleted[$table] = $this->connection->executeStatement(
                        "DELETE FROM $table WHERE LOWER(pubkey) IN (:pubkeys)",
                        ['pubkeys' => $batch], ['pubkeys' => ArrayParameterType::STRING],
                    );
                }
                return $deleted;
            });
            foreach ($deleted as $table => $count) {
                $counts[$table] += $count;
            }
            // Remove views that readers might have repopulated during database cleanup.
            $this->cacheInvalidator->invalidate();
            $this->logger->warning('Purged local content and search documents by author', ['pubkeys' => $batch, 'deleted' => $deleted]);
        }
        return $counts;
    }
}
