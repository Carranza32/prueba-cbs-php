<?php

namespace CBSNorthStar\Tests\Unit\Woapi;

use CBSNorthStar\Woapi\OrderProcess;
use Mockery;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OrderProcess transient retry logic (OE-26645).
 */
class OrderSubmitRetryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_transient_error_correctly_classifies_responses() {
        // 1. WP_Error siempre es transitorio (caída de red, timeout cURL)
        $wp_error = Mockery::mock('\WP_Error');
        $this->assertTrue(OrderProcess::isTransientError($wp_error));

        // 2. Códigos HTTP temporales (503, 504, 429, 408) deben reintentarse
        $this->assertTrue(OrderProcess::isTransientError((object)['StatusCode' => 503]));
        $this->assertTrue(OrderProcess::isTransientError((object)['StatusCode' => 504]));
        $this->assertTrue(OrderProcess::isTransientError((object)['StatusCode' => 429]));
        $this->assertTrue(OrderProcess::isTransientError((object)['StatusCode' => 408]));

        // 3. Errores de negocio (400 Bad Request, 422, o respuestas no-objeto) NO deben reintentarse
        $this->assertFalse(OrderProcess::isTransientError((object)['StatusCode' => 400]));
        $this->assertFalse(OrderProcess::isTransientError((object)['StatusCode' => 422]));
        $this->assertFalse(OrderProcess::isTransientError('string_response'));
    }
}