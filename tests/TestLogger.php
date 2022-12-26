<?php

declare(strict_types=1);

namespace Tests;

use Psr\Log\AbstractLogger;

class TestLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
    }
}
