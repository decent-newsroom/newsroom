<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type of source a user has subscribed to for notifications.
 *
 * v1 scope: only long-form content kinds (30023) and publication indices (30040)
 * are ever delivered as notifications, regardless of source type.
 */
enum NotificationSourceTypeEnum: string
{
    /** Single author pubkey. `source_value` = 64-char hex pubkey. */
    case NPUB = 'npub';

    /** Publication index coordinate. `source_value` = `30040:<pubkey>:<d>`. */
    case PUBLICATION = 'publication';

    /**
     * NIP-51 set coordinate. `source_value` = `<kind>:<pubkey>:<d>` where
     * kind ∈ {3, 10015, 30000, 30003, 30004, 30005, 30015, 39089}.
     * Kinds 3 and 10015 use empty d-tag, stored as `3:<pk>:` / `10015:<pk>:`.
     */
    case NIP51_SET = 'nip51_set';

    public function label(): string
    {
        return match ($this) {
            self::NPUB => 'notifications.subscription.sourceType.npub',
            self::PUBLICATION => 'notifications.subscription.sourceType.publication',
            self::NIP51_SET => 'notifications.subscription.sourceType.nip51Set',
        };
    }
}

