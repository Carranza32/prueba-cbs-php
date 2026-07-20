<?php

namespace CBSNorthStar\Tests\Helpers;

use Brain\Monkey\Functions;
use CBSNorthStar\Helpers\TimeSlotReservationSession;
use CBSNorthStar\Tests\TestCase;

/**
 * Unit tests for TimeSlotReservationSession (OE-26588).
 *
 * Guards the fix for a guest reservation being silently orphaned:
 * reserveTimeSlot() / releaseTimeslotReservation() are REST routes, which
 * never trigger WooCommerce's automatic session-cookie hook
 * (woocommerce_set_cart_cookies, bound to front-end template rendering).
 * Without forcing the cookie in set(), a guest's session write is invisible
 * on the next request, so times_slots_order_id never reaches the order and
 * cbsConfirmTimeSlot() silently no-ops.
 *
 * WordPress/WooCommerce is faked with Brain\Monkey: WC() returns a stub
 * object carrying a fake session, so the logic runs without a WP/WC runtime.
 *
 * @covers \CBSNorthStar\Helpers\TimeSlotReservationSession
 */
final class TimeSlotReservationSessionTest extends TestCase
{
    private \stdClass $wc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wc = new \stdClass();
        $this->wc->session = null;

        Functions\when('function_exists')->alias(
            fn ($name) => 'WC' === $name ? true : \function_exists($name)
        );
        Functions\when('WC')->alias(fn () => $this->wc);
    }

    public function test_set_forces_the_session_cookie_so_a_guest_reservation_survives(): void
    {
        $session = new FakeWcSession();
        $this->wc->session = $session;

        TimeSlotReservationSession::set(['times_slots_order_id' => '1932']);

        $this->assertSame(
            ['times_slots_order_id' => '1932'],
            $session->data[TimeSlotReservationSession::SESSION_KEY] ?? null
        );
        $this->assertTrue(
            $session->cookieForced,
            'set_customer_session_cookie(true) must be called so the reservation survives into the checkout request.'
        );
    }

    public function test_set_does_not_error_when_the_session_backend_has_no_cookie_method(): void
    {
        $session = new FakeWcSessionWithoutCookieMethod();
        $this->wc->session = $session;

        TimeSlotReservationSession::set(['times_slots_order_id' => '1932']);

        $this->assertSame(
            ['times_slots_order_id' => '1932'],
            $session->data[TimeSlotReservationSession::SESSION_KEY] ?? null
        );
    }

    public function test_get_returns_null_when_there_is_no_active_session(): void
    {
        $this->wc->session = null;

        $this->assertNull(TimeSlotReservationSession::get());
    }

    public function test_get_time_slots_order_id_reads_the_stored_value(): void
    {
        $session = new FakeWcSession();
        $session->data[TimeSlotReservationSession::SESSION_KEY] = ['times_slots_order_id' => '1932'];
        $this->wc->session = $session;

        $this->assertSame('1932', TimeSlotReservationSession::getTimeSlotsOrderId());
    }

    public function test_clear_nulls_out_the_stored_reservation(): void
    {
        $session = new FakeWcSession();
        $session->data[TimeSlotReservationSession::SESSION_KEY] = ['times_slots_order_id' => '1932'];
        $this->wc->session = $session;

        TimeSlotReservationSession::clear();

        $this->assertNull($session->data[TimeSlotReservationSession::SESSION_KEY]);
    }
}

/** Minimal stand-in for WC_Session_Handler. */
class FakeWcSession
{
    public array $data = [];
    public bool $cookieForced = false;

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function set_customer_session_cookie(bool $set): void
    {
        $this->cookieForced = $set;
    }
}

/** Session backend without set_customer_session_cookie(), exercising the method_exists() guard. */
class FakeWcSessionWithoutCookieMethod
{
    public array $data = [];

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }
}
