<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Kernel;

/** Separate build cache avoids interference with a running development server. */
final class WorkspaceMetricsKernel extends Kernel
{
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/workspace-metrics-fixture/' . $this->environment;
    }
}
