<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class AdvancedMetadata
{
    public bool $doNotRepublish = false;

    #[Assert\Choice(choices: [
        '',
        'CC0-1.0',
        'CC-BY-4.0',
        'CC-BY-SA-4.0',
        'CC-BY-NC-4.0',
        'CC-BY-NC-SA-4.0',
        'CC-BY-ND-4.0',
        'CC-BY-NC-ND-4.0',
        'MIT',
        'Apache-2.0',
        'GPL-3.0',
        'AGPL-3.0',
        'All rights reserved',
        'custom'
    ])]
    public string $license = '';

    public ?string $customLicense = null;

    /** @var ZapSplit[] */
    #[Assert\Valid]
    public array $zapSplits = [];

    public ?string $contentWarning = null;

    public ?int $expirationTimestamp = null;

    public bool $isProtected = false;

    /** @var array<string, mixed> Additional tags to preserve on re-publish */
    public array $extraTags = [];

    public function addZapSplit(ZapSplit $split): void
    {
        $this->zapSplits[] = $split;
    }

    public function removeZapSplit(int $index): void
    {
        if (isset($this->zapSplits[$index])) {
            unset($this->zapSplits[$index]);
            $this->zapSplits = array_values($this->zapSplits);
        }
    }

    public function getLicenseValue(): ?string
    {
        if ($this->license === 'custom') {
            return $this->customLicense;
        }
        return $this->license !== '' ? $this->license : null;
    }
}

