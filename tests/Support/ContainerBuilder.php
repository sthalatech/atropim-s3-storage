<?php

declare(strict_types=1);

namespace S3Storage\Tests\Support;

use Atro\Core\Container;
use Atro\Core\Container\ServiceManagerConfig;
use Laminas\ServiceManager\ServiceManager;

/**
 * Atro\Core\Container is `final` and cannot be mocked by PHPUnit — this builds a
 * REAL Container wired to a real (but test-populated) Laminas ServiceManager, so
 * production code that type-hints the concrete Container class can be exercised
 * against ordinary PHPUnit mock services.
 */
final class ContainerBuilder
{
    public static function build(array $services): Container
    {
        $container = new Container(new ServiceManagerConfig());
        $sm = new ServiceManager(['services' => array_merge(['container' => $container], $services)]);
        $container->setSm($sm);

        return $container;
    }
}
