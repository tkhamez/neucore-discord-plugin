<?php

declare(strict_types=1);

namespace Neucore\Plugin\Discord;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

class Logger
{
    private LoggerInterface $logger;

    private int $serviceId;

    public function __construct(LoggerInterface $logger, int $serviceId)
    {
        $this->logger = $logger;
        $this->serviceId = $serviceId;
    }

    public function logException(Throwable $e, string $function): void
    {
        $this->logError('Exception: "' . $e->getMessage() . "\" in $function() at " . __FILE__);
    }

    public function logError(string $message): void
    {
        $this->log($message, LogLevel::ERROR);
    }

    /**
     * @param string|array|object $message
     */
    public function log($message, string $logLevel = LogLevel::INFO): void
    {
        if (!is_scalar($message)) {
            $message = print_r($message, true);
        }
        $this->logger->log($logLevel, Service::PLUGIN_NAME." ($this->serviceId): $message");
    }
}
