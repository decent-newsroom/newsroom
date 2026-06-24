<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Repository;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\EventReferenceStoreInterface;
use Doctrine\DBAL\Connection;

final readonly class DoctrineEventReferenceStore implements EventReferenceStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function replaceForEvent(EventId $eventId, iterable $references): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'DELETE FROM nostr_event_reference WHERE source_event_id = :event_id',
                ['event_id' => $eventId->value],
            );

            foreach ($references as $reference) {
                $this->connection->insert('nostr_event_reference', [
                    'source_event_id' => $eventId->value,
                    'type' => $this->readString($reference, 'type') ?? 'unknown',
                    'value' => $this->readString($reference, 'value') ?? '',
                    'marker' => $this->readNullableString($reference, 'marker'),
                    'relay' => $this->readNullableString($reference, 'relay'),
                    'raw_tag' => json_encode($this->readArray($reference, 'rawTag'), JSON_THROW_ON_ERROR),
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function readString(object $object, string $name): ?string
    {
        $value = $this->read($object, $name);

        if (is_object($value) && property_exists($value, 'value') && is_string($value->value)) {
            return $value->value;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function readNullableString(object $object, string $name): ?string
    {
        return $this->readString($object, $name);
    }

    /**
     * @return array<int, string>
     */
    private function readArray(object $object, string $name): array
    {
        $value = $this->read($object, $name);

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function read(object $object, string $name): mixed
    {
        if (property_exists($object, $name)) {
            return $object->{$name};
        }

        if (method_exists($object, $name)) {
            return $object->{$name}();
        }

        $getter = 'get' . ucfirst($name);
        if (method_exists($object, $getter)) {
            return $object->{$getter}();
        }

        return null;
    }
}
