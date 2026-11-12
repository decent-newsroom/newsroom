<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

/**
 * Stable target identity, separate from the source record that exposed it.
 * Address identities may pin an exact event without changing the coordinate.
 */
final readonly class EventWorkspaceIdentity
{
    public EventWorkspaceIdentityType $type;
    public string $value;
    public ?string $versionEventId;

    private function __construct(
        EventWorkspaceIdentityType $type,
        string $value,
        ?string $versionEventId = null,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('Workspace identities cannot be empty.');
        }

        if ($type === EventWorkspaceIdentityType::EventId || $type === EventWorkspaceIdentityType::Profile) {
            if (!self::isHex32($value)) {
                throw new \InvalidArgumentException('Event and profile identities must be 32-byte hexadecimal values.');
            }
            $value = strtolower($value);
        } elseif ($type === EventWorkspaceIdentityType::Address) {
            $value = self::canonicalizeAddress($value);
        }

        if ($type !== EventWorkspaceIdentityType::Address && $versionEventId !== null) {
            throw new \InvalidArgumentException('Only address identities can pin a version event.');
        }

        if ($versionEventId !== null && !self::isHex32($versionEventId)) {
            throw new \InvalidArgumentException('Pinned event identities must be 32-byte hexadecimal values.');
        }

        $this->type = $type;
        $this->value = $value;
        $this->versionEventId = $versionEventId === null ? null : strtolower($versionEventId);
    }

    public static function eventId(string $eventId): self
    {
        return new self(EventWorkspaceIdentityType::EventId, $eventId);
    }

    public static function address(string $coordinate, ?string $versionEventId = null): self
    {
        return new self(
            EventWorkspaceIdentityType::Address,
            $coordinate,
            $versionEventId,
        );
    }

    public static function profile(string $pubkey): self
    {
        return new self(EventWorkspaceIdentityType::Profile, $pubkey);
    }

    private static function canonicalizeAddress(string $address): string
    {
        $parts = explode(':', $address, 3);
        if (count($parts) < 2 || !ctype_digit($parts[0]) || !self::isHex32($parts[1])) {
            throw new \InvalidArgumentException('Address identities must contain a valid kind and pubkey.');
        }

        $kind = (int) $parts[0];
        $pubkey = strtolower($parts[1]);
        if ($kind >= 30000 && $kind <= 39999 && count($parts) === 3) {
            return $kind . ':' . $pubkey . ':' . $parts[2];
        }

        if (($kind === 0 || $kind === 3 || ($kind >= 10000 && $kind <= 19999))
            && count($parts) === 2) {
            return $kind . ':' . $pubkey;
        }

        throw new \InvalidArgumentException('Address identities must use a canonical replaceable coordinate.');
    }

    private static function isHex32(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}
