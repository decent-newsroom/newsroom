<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Parsed `payto` entry from a NIP-A3 kind 10133 event.
 *
 * Each entry models one payment target — a (type, authority) pair —
 * along with display metadata for recognized RFC-8905 schemes.
 */
final class PaymentTarget
{
    public function __construct(
        public readonly string $type,
        public readonly string $authority,
        public readonly bool $recognized,
        public readonly string $label,
        public readonly string $symbol,
        public readonly string $shortLabel,
        /** @var array<int, string> Any optional extra tag elements beyond type+authority. */
        public readonly array $extra = [],
    ) {}

    /**
     * The full RFC-8905 payto URI for this target.
     */
    public function uri(): string
    {
        // authority is expected to already be URL-safe per NIP-A3; do not double-encode.
        return sprintf('payto://%s/%s', $this->type, $this->authority);
    }

    /**
     * Stable identifier for Live Component selection.
     */
    public function key(): string
    {
        return hash('sha256', $this->type . "\0" . $this->authority);
    }

    /**
     * Whether this target should open the Geyser project page.
     */
    public function isGeyser(): bool
    {
        return $this->type === 'geyser';
    }

    /**
     * Human-facing destination for this target.
     */
    public function href(): string
    {
        if ($this->isGeyser()) {
            return sprintf('https://geyser.fund/project/%s', rawurlencode($this->authority));
        }

        return $this->uri();
    }

    /**
     * Whether this target can flow through the existing Lightning / NIP-57 zap pipeline.
     */
    public function isLightning(): bool
    {
        return $this->type === 'lightning';
    }

    /**
     * @return array{type:string,authority:string,uri:string,href:string,key:string,recognized:bool,label:string,symbol:string,shortLabel:string,extra:array<int,string>}
     */
    public function toArray(): array
    {
        return [
            'type'       => $this->type,
            'authority'  => $this->authority,
            'uri'        => $this->uri(),
            'href'       => $this->href(),
            'key'        => $this->key(),
            'recognized' => $this->recognized,
            'label'      => $this->label,
            'symbol'     => $this->symbol,
            'shortLabel' => $this->shortLabel,
            'extra'      => $this->extra,
        ];
    }
}

