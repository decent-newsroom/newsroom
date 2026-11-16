<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

/** A policy lookup failed; the entire inventory must be withheld. */
final class InventoryPolicyException extends \RuntimeException
{
}
