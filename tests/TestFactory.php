<?php

/** @noinspection PhpInconsistentReturnPointsInspection */

declare(strict_types=1);

namespace Tests;

use Neucore\Plugin\Core\AccountInterface;
use Neucore\Plugin\Core\DataInterface;
use Neucore\Plugin\Core\EsiClientInterface;
use Neucore\Plugin\Core\FactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Yaml\Parser;

class TestFactory implements FactoryInterface
{
    public function createHttpClient(string $userAgent = ''): ClientInterface
    {
    }

    public function createHttpRequest(
        string $method, string $url,
        array $headers = [],
        string $body = null
    ): RequestInterface {
    }

    public function createSymfonyYamlParser(): Parser
    {
        return new Parser();
    }

    public function getEsiClient(): EsiClientInterface
    {
    }

    public function getAccount(): AccountInterface
    {
    }

    public function getData(): DataInterface
    {
    }
}
