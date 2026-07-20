<?php

namespace CBSNorthStar\Tests\Unit\Helpers;

use CBSNorthStar\Helpers\OrderMeta;
use Mockery;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the OrderMeta HPOS helper class (OE-26645).
 */
class OrderMetaTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_empty_when_key_is_not_in_allowed_schema() {
        $order_mock = Mockery::mock( '\WC_Order' );
        $order_mock->shouldNotReceive( 'get_meta' );

        $result = OrderMeta::get( $order_mock, 'unauthorized_arbitrary_key' );
        $this->assertSame( '', $result );
    }

    public function test_get_retrieves_meta_from_wc_order_instance() {
        $order_mock = Mockery::mock( '\WC_Order' );
        $order_mock->shouldReceive( 'get_meta' )
                   ->once()
                   ->with( 'cbs_orderid', true )
                   ->andReturn( 'CHK-889900' );

        $result = OrderMeta::get( $order_mock, 'cbs_orderid' );
        $this->assertSame( 'CHK-889900', $result );
    }

    public function test_get_resolves_numeric_id_via_wc_get_order() {
        $order_mock = Mockery::mock( '\WC_Order' );
        $order_mock->shouldReceive( 'get_meta' )
                   ->once()
                   ->with( 'cbs_siteid', true )
                   ->andReturn( 'SITE-42' );

        Functions\expect( 'wc_get_order' )
            ->once()
            ->with( 1050 )
            ->andReturn( $order_mock );

        $result = OrderMeta::get( 1050, 'cbs_siteid' );
        $this->assertSame( 'SITE-42', $result );
    }

    public function test_set_returns_false_and_skips_update_for_invalid_keys() {
        $order_mock = Mockery::mock( '\WC_Order' );
        $order_mock->shouldNotReceive( 'update_meta_data' );
        $order_mock->shouldNotReceive( 'save' );

        $result = OrderMeta::set( $order_mock, 'invalid_key_attempt', '123' );
        $this->assertFalse( $result );
    }

    public function test_set_updates_meta_data_and_saves_by_default() {
        $order_mock = Mockery::mock( '\WC_Order' );
        $order_mock->shouldReceive( 'update_meta_data' )
                   ->once()
                   ->with( 'cbs_checknumber', '5544' );
        $order_mock->shouldReceive( 'save' )
                   ->once();

        $result = OrderMeta::set( $order_mock, 'cbs_checknumber', '5544' );
        $this->assertTrue( $result );
    }

    public function test_set_skips_save_when_save_flag_is_false() {
        $order_mock = Mockery::mock( '\WC_Order' );
        $order_mock->shouldReceive( 'update_meta_data' )
                   ->once()
                   ->with( 'cbs_orderFinalized', 'CHK-889900' );
        $order_mock->shouldNotReceive( 'save' );

        $result = OrderMeta::set( $order_mock, 'cbs_orderFinalized', 'CHK-889900', false );
        $this->assertTrue( $result );
    }
}