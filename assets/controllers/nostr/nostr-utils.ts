/**
 * Nostr utilities for handling pubkeys, relays, and tag building
 */

// Bech32 character set
const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

/**
 * Decode bech32 string to hex
 * Simplified implementation for npub decoding
 */
function bech32Decode(str: string): { prefix: string; data: Uint8Array } | null {
    const lowered = str.toLowerCase();

    // Find the separator
    let sepIndex = lowered.lastIndexOf('1');
    if (sepIndex < 1) return null;

    const prefix = lowered.substring(0, sepIndex);
    const dataStr = lowered.substring(sepIndex + 1);

    if (dataStr.length < 6) return null;

    // Decode the data
    const values: number[] = [];
    for (let i = 0; i < dataStr.length; i++) {
        const c = dataStr[i];
        const v = BECH32_CHARSET.indexOf(c);
        if (v === -1) return null;
        values.push(v);
    }

    // Remove checksum (last 6 chars)
    const data = values.slice(0, -6);

    // Convert from 5-bit to 8-bit
    const bytes: number[] = [];
    let acc = 0;
    let bits = 0;

    for (const value of data) {
        acc = (acc << 5) | value;
        bits += 5;

        if (bits >= 8) {
            bits -= 8;
            bytes.push((acc >> bits) & 0xff);
        }
    }

    return {
        prefix,
        data: new Uint8Array(bytes)
    };
}

/**
 * Convert Uint8Array to hex string
 */
function bytesToHex(bytes: Uint8Array): string {
    return Array.from(bytes)
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Convert npub to hex pubkey
 */
export function npubToHex(npub: string): string | null {
    if (!npub.startsWith('npub1')) {
        // Check if it's already hex
        if (/^[0-9a-f]{64}$/i.test(npub)) {
            return npub.toLowerCase();
        }
        return null;
    }

    try {
        const decoded = bech32Decode(npub);
        if (!decoded || decoded.prefix !== 'npub') {
            return null;
        }

        return bytesToHex(decoded.data);
    } catch (e) {
        console.error('Error decoding npub:', e);
        return null;
    }
}

/**
 * Validate if a string is a valid npub or hex pubkey
 */
export function isValidPubkey(pubkey: string): boolean {
    if (!pubkey) return false;

    // Check if hex (64 chars)
    if (/^[0-9a-f]{64}$/i.test(pubkey)) {
        return true;
    }

    // Check if npub
    if (pubkey.startsWith('npub1')) {
        return npubToHex(pubkey) !== null;
    }

    return false;
}

/**
 * Validate relay URL
 */
export function isValidRelay(relay: string): boolean {
    if (!relay) return true; // Empty is valid (optional)

    try {
        const url = new URL(relay);
        return url.protocol === 'wss:';
    } catch {
        return false;
    }
}

/**
 * ZapSplit interface
 */
export interface ZapSplit {
    recipient: string;
    relay?: string;
    weight?: number;
    sharePercent?: number;
}

/**
 * Calculate share percentages for zap splits
 */
export function calculateShares(splits: ZapSplit[]): number[] {
    if (splits.length === 0) {
        return [];
    }

    // Check if any weights are specified
    const hasWeights = splits.some(s => s.weight !== undefined && s.weight !== null && s.weight > 0);

    if (!hasWeights) {
        // Equal distribution
        const equalShare = 100 / splits.length;
        return splits.map(() => equalShare);
    }

    // Calculate total weight
    const totalWeight = splits.reduce((sum, s) => sum + (s.weight || 0), 0);

    if (totalWeight === 0) {
        return splits.map(() => 0);
    }

    // Calculate weighted shares
    return splits.map(s => {
        const weight = s.weight || 0;
        return (weight / totalWeight) * 100;
    });
}

/**
 * Build a zap tag for Nostr event
 */
export function buildZapTag(split: ZapSplit): (string | number)[] {
    const hexPubkey = npubToHex(split.recipient);
    if (!hexPubkey) {
        throw new Error(`Invalid recipient pubkey: ${split.recipient}`);
    }

    const tag: (string | number)[] = ['zap', hexPubkey];

    // Add relay (even if empty, to maintain position)
    tag.push(split.relay || '');

    // Add weight if specified
    if (split.weight !== undefined && split.weight !== null) {
        tag.push(split.weight);
    }

    return tag;
}

/**
 * Advanced metadata interface
 */
export interface AdvancedMetadata {
    doNotRepublish: boolean;
    license: string;
    customLicense?: string;
    zapSplits: ZapSplit[];
    contentWarning?: string;
    expirationTimestamp?: number;
    isProtected: boolean;
}

/**
 * Build advanced metadata tags for Nostr event
 */
export function buildAdvancedTags(metadata: AdvancedMetadata): any[][] {
    const tags: any[][] = [];

    // Policy: Do not republish
    if (metadata.doNotRepublish) {
        tags.push(['L', 'rights.decent.newsroom']);
        tags.push(['l', 'no-republish', 'rights.decent.newsroom']);
    }

    // License
    const license = metadata.license === 'custom' ? metadata.customLicense : metadata.license;
    if (license && license !== 'All rights reserved') {
        tags.push(['L', 'spdx.org/licenses']);
        tags.push(['l', license, 'spdx.org/licenses']);
    } else if (license === 'All rights reserved') {
        tags.push(['L', 'rights.decent.newsroom']);
        tags.push(['l', 'all-rights-reserved', 'rights.decent.newsroom']);
    }

    // Zap splits
    for (const split of metadata.zapSplits) {
        try {
            tags.push(buildZapTag(split));
        } catch (e) {
            console.error('Error building zap tag:', e);
        }
    }

    // Content warning
    if (metadata.contentWarning) {
        tags.push(['content-warning', metadata.contentWarning]);
    }

    // Expiration
    if (metadata.expirationTimestamp) {
        tags.push(['expiration', metadata.expirationTimestamp.toString()]);
    }

    // Protected event
    if (metadata.isProtected) {
        tags.push(['-']);
    }

    return tags;
}

/**
 * Validate advanced metadata before publishing
 */
export function validateAdvancedMetadata(metadata: AdvancedMetadata): { valid: boolean; errors: string[] } {
    const errors: string[] = [];

    // Validate zap splits
    for (let i = 0; i < metadata.zapSplits.length; i++) {
        const split = metadata.zapSplits[i];

        if (!isValidPubkey(split.recipient)) {
            errors.push(`Zap split ${i + 1}: Invalid recipient pubkey`);
        }

        if (split.relay && !isValidRelay(split.relay)) {
            errors.push(`Zap split ${i + 1}: Invalid relay URL (must start with wss://)`);
        }

        if (split.weight !== undefined && split.weight !== null && split.weight < 0) {
            errors.push(`Zap split ${i + 1}: Weight must be 0 or greater`);
        }
    }

    // Validate expiration is in the future
    if (metadata.expirationTimestamp) {
        const now = Math.floor(Date.now() / 1000);
        if (metadata.expirationTimestamp <= now) {
            errors.push('Expiration date must be in the future');
        }
    }

    return {
        valid: errors.length === 0,
        errors
    };
}

