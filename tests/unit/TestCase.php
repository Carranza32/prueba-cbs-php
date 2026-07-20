<?php

namespace CBSNorthStar\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;

/**
 * Base test case for the pure-unit suite.
 *
 * Boots Brain\Monkey (to fake WordPress/WooCommerce functions) and wires the
 * Mockery <-> PHPUnit integration so mock expectations are verified on tear down.
 * Also exposes small reflection helpers so tests can exercise protected/private
 * methods and seed protected properties without modifying production source.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build an instance without running its constructor (skips API/DB side effects).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function newWithoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * Invoke a protected/private method on an object.
     *
     * @param object $object
     * @param string $method
     * @param array  $args  Positional arguments; passed by reference where the
     *                      target method declares reference parameters.
     * @return mixed
     */
    protected function callMethod(object $object, string $method, array $args = [])
    {
        $ref = (new ReflectionClass($object))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $args);
    }

    /**
     * Set a protected/private property on an object.
     */
    protected function setProperty(object $object, string $property, $value): void
    {
        $ref = new ReflectionClass($object);
        // Walk up the hierarchy so inherited properties are reachable.
        while ($ref && ! $ref->hasProperty($property)) {
            $ref = $ref->getParentClass();
        }
        if (! $ref) {
            throw new \InvalidArgumentException(sprintf('Property "%s" not found on %s or its ancestors.', $property, get_class($object)));
        }
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
