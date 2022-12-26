<?php

namespace Tests;

use Neucore\Plugin\Discord\Service;
use Neucore\Plugin\ServiceConfiguration;
use Neucore\Plugin\ServiceInterface;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    public function testConstruct()
    {
        $implementation = new Service(
            new TestLogger(),
            new ServiceConfiguration(0, [], '')
        );
        $this->assertInstanceOf(ServiceInterface::class, $implementation);
    }
}
