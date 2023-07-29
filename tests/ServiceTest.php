<?php

namespace Tests;

use Neucore\Plugin\Data\PluginConfiguration;
use Neucore\Plugin\Discord\Service;
use Neucore\Plugin\ServiceInterface;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    public function testConstruct()
    {
        $implementation = new Service(
            new TestLogger(),
            new PluginConfiguration(0, '', true, [], ''),
            new TestFactory()
        );
        $this->assertInstanceOf(ServiceInterface::class, $implementation);
    }
}
