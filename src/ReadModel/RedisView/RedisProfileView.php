<?php

namespace App\ReadModel\RedisView;

/**
 * Redis view model for Profile - MATCHES TEMPLATE EXPECTATIONS
 * Property names match what RedisCacheService::getMetadata() returns
 * Templates expect: user.name, user.picture, user.nip05, user.display_name
 */
final class RedisProfileView
{
    public function __construct(
        public string $pubkey,
        public ?string $name = null,                // PRIMARY - Template expects: user.name
        public ?string $display_name = null,        // Template expects: user.display_name
        public ?string $picture = null,             // Template expects: user.picture
        public ?string $nip05 = null,               // Template expects: user.nip05
        public ?string $about = null,
        public ?string $website = null,
        public ?string $lud16 = null,
        public ?string $banner = null,
    ) {}
}
