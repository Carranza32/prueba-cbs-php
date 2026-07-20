<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Helpers\TimeSlotReservationSession;
use CBSNorthStar\Helpers\TimeSlotValueParser;
use CBSNorthStar\Services\TimeSlotService;

class TimeSlotsLoader {

	private static $instance = null;

	public static function create(): ?TimeSlotsLoader {
		if ( self::$instance === null ) {
			self::$instance = new TimeSlotsLoader();
		}
		return self::$instance;
	}

	public function registerScripts(): void {
		add_action( 'wp', [ $this, 'boot' ] );
        add_action('wp', [$this, 'bootNavPopup']);
		add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save' ] );
	}

	public function boot(): void {
		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return;
		}

		if ( ! (bool) carbon_get_theme_option( 'olo_enable_time_slots' ) ) {
			return;
		}

		// Nav popup already captured the slot — show read-only summary instead of the picker.
		if ( ! empty( $_COOKIE['oloNavTimeslot'] ) ) {
			add_action( $this->getHookFromSettings(), [ $this, 'renderSlotSummary' ], 20 );
			return;
		}

		add_action( $this->getHookFromSettings(), [ $this, 'renderField' ], 20 );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

    
    /**
     * Boot the navigation time-slot popup on locations / categories / menu-items pages.
     */
    public function bootNavPopup(): void
    {
        if (!function_exists('carbon_get_theme_option')) {
            return;
        }

        $enabled = (bool) carbon_get_theme_option('olo_enable_time_slots');

        if (!$enabled) {
            return;
        }

        if ($this->isNavPopupPage()) {
            add_action('wp_enqueue_scripts', [$this, 'enqueueNavPopupAssets']);
            add_action('wp_footer', [$this, 'renderNavPopupHtml'], 5);
        }
    }

    /**
     * Check if the current page should show the time-slot navigation popup.
     */
    private function isNavPopupPage(): bool
    {
        // Exclude thank-you page (order-received endpoint on checkout page)
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return false;
        }

        return is_page('locations') || is_page('categories')
            || is_page('menu-items') || is_page('cart')
            || is_page('checkout') || is_product();
    }

    public function enqueueNavPopupAssets(): void
    {
        $pluginUrl = plugin_dir_url(CBS_PLUGIN_FILE);
        $version   = class_exists('\CBSNorthStar\Helpers\BuildNumberHelper')
            ? \CBSNorthStar\Helpers\BuildNumberHelper::getBuildNumber()
            : '1.0.0';

        wp_enqueue_style(
            'olo-timeslot-popup',
            $pluginUrl . 'css/timeslot-popup.css',
            [],
            $version
        );

        wp_enqueue_script(
            'olo-timeslot-popup',
            $pluginUrl . 'js/timeslot-popup.js',
            [],
            $version,
            true
        );

        $maxDaysAhead = (int) carbon_get_theme_option('olo_time_slot_max_days_ahead');

        $menuItemsPage = get_page_by_path('menu-items');

        wp_localize_script('olo-timeslot-popup', 'oloTimeslotPopup', [
            'restUrl'       => esc_url_raw(rest_url('northstaronlineordering/v1/timeslots')),
            'restBase'      => esc_url_raw(rest_url('northstaronlineordering/v1')),
            'maxDaysAhead'  => $maxDaysAhead,
            'nonce'         => wp_create_nonce('wp_rest'),
            'isProductPage' => is_product(),
            'menuItemsUrl'  => $menuItemsPage ? get_permalink($menuItemsPage) : '/menu-items/',
            // OE-26492 daypart watcher: site to poll + how often the client re-checks
            // whether the live daypart has rolled past the menu the cart was built on.
            'siteId'        => isset($_COOKIE['siteid']) ? sanitize_text_field(wp_unslash($_COOKIE['siteid'])) : '',
            'daypartPollMs' => (int) apply_filters('olo_daypart_poll_ms', 60000),
        ]);
    }

    /**
     * Render the popup HTML shell in the footer.
     *
     * On menu pages (categories / menu-items) the data-ts-autoopen attribute
     * tells JS to open the popup automatically when no slot cookie exists.
     */
    public function renderNavPopupHtml(): void
    {
        $autoOpen = (is_page('categories') || is_page('menu-items')) ? ' data-ts-autoopen="1"' : '';
        ?>
        <dialog id="olo-ts-dialog" aria-label="<?php esc_attr_e('Select a Time Slot', 'olo'); ?>"<?php echo $autoOpen; ?>>
            <button type="button" id="olo-ts-close" class="olo-ts-close" aria-label="<?php esc_attr_e('Close', 'olo'); ?>">&#x2715;</button>
            <h3><?php esc_html_e('Select a Time Slot', 'olo'); ?></h3>
            <p id="olo-ts-required-msg" class="olo-ts-required-msg"><?php esc_html_e('Please select a time slot to continue.', 'olo'); ?></p>
            <div id="olo-ts-error" class="olo-ts-error"></div>
            <div class="olo-ts-date-row">
                <label for="olo-ts-date"><?php esc_html_e('Choose a date', 'olo'); ?></label>
                <input type="date" id="olo-ts-date" />
            </div>
            <div id="olo-ts-loading" class="olo-ts-loading"><?php esc_html_e('Loading time slots…', 'olo'); ?></div>
            <div class="olo-ts-select-row">
                <label for="olo-ts-select"><?php esc_html_e('Choose a time slot', 'olo'); ?></label>
                <select id="olo-ts-select"></select>
            </div>
            <div id="olo-ts-warning" class="olo-ts-warning" hidden>
                <p><?php esc_html_e('The selected time slot uses a different menu. Your current cart will be cleared.', 'olo'); ?></p>
                <div class="olo-ts-warning-actions">
                    <button type="button" id="olo-ts-warn-back" class="olo-ts-warn-back"><?php esc_html_e('Go Back', 'olo'); ?></button>
                    <button type="button" id="olo-ts-warn-continue" class="olo-ts-warn-continue"><?php esc_html_e('Continue', 'olo'); ?></button>
                </div>
            </div>
            <?php // OE-26492: daypart rolled over mid-order — the expired slot auto-switches to ASAP; warn before removing items absent from the new menu. ?>
            <div id="olo-ts-daypart-warning" class="olo-ts-warning olo-ts-daypart-warning" hidden>
                <p class="olo-ts-daypart-asap-msg"><?php esc_html_e('Your time has switched to ASAP since the original time selected has passed.', 'olo'); ?></p>
                <div id="olo-ts-daypart-removed" hidden>
                    <p><?php esc_html_e('Some items in your cart are not available on the new menu and will be removed.', 'olo'); ?></p>
                </div>
                <div class="olo-ts-warning-actions">
                    <button type="button" id="olo-ts-daypart-continue" class="olo-ts-warn-continue"><?php esc_html_e('Continue', 'olo'); ?></button>
                </div>
            </div>
            <button type="button" id="olo-ts-confirm" class="olo-ts-confirm" disabled><?php esc_html_e('Confirm', 'olo'); ?></button>
        </dialog>
        <?php
    }

	public function renderField(): void {
		$siteId = isset( $_COOKIE['siteid'] ) ? sanitize_text_field( $_COOKIE['siteid'] ) : '';
		// OE-26541: resolve the area from the current site via the guarded resolver
		// (honors areaIdSite, re-derives from cbs_site_details on mismatch) instead of
		// trusting a raw areaId cookie that may belong to a previously visited site.
		$areaId = $siteId ? (string) getAreaId( $siteId ) : '';

		if ( ! $siteId || ! $areaId ) {
			echo '<p class="olo-timeslot-missing">' . esc_html__( 'Time slots unavailable (missing site/area).', 'olo' ) . '</p>';
			return;
		}

		$maxDaysAhead = (int) carbon_get_theme_option( 'olo_time_slot_max_days_ahead' );
		// OE-26541: anchor "today" and the cutoff to the site's own clock (SiteClock), not WP's
		// timezone, so the picker matches the endpoint and slots near "now" are not mis-trimmed
		// when the two timezones differ. Falls back to WP current_time() when the site has none.
		$siteNow      = \CBSNorthStar\Helpers\SiteClock::nowForSite( $siteId );
		$minDate      = $siteNow->format( 'Y-m-d' );
		$maxDate      = wp_date( 'Y-m-d', strtotime( "+{$maxDaysAhead} days" ) );

		// Restore previously selected slot from the WC session (survives page reloads).
		$sessionReservation = TimeSlotReservationSession::get();
		$sessionDate        = $sessionReservation['business_date'] ?? '';
		$sessionSlot        = $sessionReservation['raw_value']     ?? '';

		// Priority: posted value (checkout validation re-render) → session → today.
		$checkout     = WC()->checkout();
		$selectedDate = $checkout ? $checkout->get_value( 'olo_slot_date' ) : '';
		if ( ! $selectedDate && $sessionDate ) {
			$selectedDate = $sessionDate;
		}
		if ( ! $selectedDate ) {
			$selectedDate = $minDate;
		}

		$slotDate = ( $selectedDate === $minDate )
			? $selectedDate . ' ' . $siteNow->format( 'H:i:s' )
			: $selectedDate . ' 00:00:00';

		$options   = TimeSlotService::create()->oloGetAvailableTimeslotOptions( $siteId, $areaId, $slotDate );
		$dateClass = $maxDaysAhead !== 0 ? 'form-row-wide' : 'form-row-wide olo-hide-field';

		// Inline config for the JS layer.
		// selectedSlot / selectedDate are passed so JS can restore the prior
		// selection after loadSlots() without triggering a new reservation.
		echo '<script>window.oloTimeSlotsConfig = ' . wp_json_encode( [
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'restUrl'      => esc_url_raw( rest_url( 'northstaronlineordering/v1' ) ),
			'selectedSlot' => $sessionSlot,
			'selectedDate' => $sessionDate,
		] ) . ';</script>';

		echo '<div class="olo-timeslot-field"><h3>' . esc_html__( 'Time Slot', 'olo' ) . '</h3>';

		woocommerce_form_field( 'olo_slot_date', [
			'type'              => 'date',
			'class'             => [ $dateClass ],
			'label'             => __( 'Choose a date', 'olo' ),
			'required'          => true,
			'custom_attributes' => [
				'min'          => $minDate,
				'max'          => $maxDate,
				'data-site-id' => $siteId,
				'data-area-id' => $areaId,
			],
		], $selectedDate );

		woocommerce_form_field( 'olo_slot_date_display', [
			'type'              => 'text',
			'class'             => [ 'form-row-wide' ],
			'label'             => __( 'Selected date', 'olo' ),
			'required'          => false,
			'custom_attributes' => [ 'readonly' => 'readonly' ],
		], date_i18n( get_option( 'date_format' ), strtotime( $slotDate ) ) );

		woocommerce_form_field( 'olo_time_slot', [
			'type'     => 'select',
			'class'    => [ 'form-row-wide' ],
			'label'    => __( 'Choose a time slot', 'olo' ),
			'required' => true,
			'options'  => $options,
		], WC()->checkout() ? WC()->checkout()->get_value( 'olo_time_slot' ) : '' );

		echo '</div>';
	}

	/**
	 * Render a read-only time slot summary on checkout (nav popup flow).
	 * Mirrors the thank-you page display format.
	 * Uses <tr> when inside the order review table, <div> otherwise.
	 */
	public function renderSlotSummary(): void {
		$slotTime     = isset( $_COOKIE['oloNavTimeslotTime'] )
			? sanitize_text_field( $_COOKIE['oloNavTimeslotTime'] ) : '';
		$slotDate     = isset( $_COOKIE['oloNavTimeslotDate'] )
			? sanitize_text_field( $_COOKIE['oloNavTimeslotDate'] ) : '';

		if ( ! $slotTime && ! $slotDate ) {
			return;
		}

		// Format time to 12-hour (e.g., "4:00 PM") using the literal wall-clock CBS returned.
		$displayTime = '';
		if ( $slotTime ) {
			$displayTime = TimeSlotValueParser::formatDisplayTime( $slotTime );
		}

		// Format date for display (e.g., "25 March, 2026")
		$displayDate = '';
		if ( $slotDate ) {
			$dt = \DateTime::createFromFormat( 'Y-m-d', $slotDate );
			$displayDate = $dt ? $dt->format( 'j F, Y' ) : esc_html( $slotDate );
		}

		$inTable = ( (string) carbon_get_theme_option( 'olo_time_slot_position' ) === '4' );

		if ( $inTable ) {
			echo '<tr class="olo-ts-checkout-summary">';
			echo '<td colspan="2">';
		} else {
			echo '<div class="olo-ts-checkout-summary">';
		}

		if ( $displayDate ) {
			echo '<p><strong>' . esc_html__( 'Delivery Date:', 'olo' ) . '</strong> '
				. esc_html( $displayDate ) . '</p>';
		}
		if ( $displayTime ) {
			echo '<p><strong>' . esc_html__( 'Time Slot:', 'olo' ) . '</strong> '
				. esc_html( $displayTime ) . '</p>';
		}

		if ( $inTable ) {
			echo '</td></tr>';
		} else {
			echo '</div>';
		}
	}

	// -------------------------------------------------------------------------
	// Validation (woocommerce_checkout_process)
	// -------------------------------------------------------------------------

	public function validate(): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

        if(! empty($_COOKIE['oloNavTimeslot']) ) {
            return;
        }

		if ( empty( $_POST['olo_time_slot'] ) ) {
			wc_add_notice( __( 'Please select a time slot.', 'olo' ), 'error' );
			return;
		}

		$raw    = sanitize_text_field( $_POST['olo_time_slot'] );
		$parsed = TimeSlotValueParser::parse( $raw );

		if ( ! $parsed ) {
			wc_add_notice( __( 'Invalid time slot selection.', 'olo' ), 'error' );
			return;
		}

		$date = isset( $_POST['olo_slot_date'] ) ? sanitize_text_field( $_POST['olo_slot_date'] ) : '';

		// Confirm the reservation stored in session matches what was submitted.
		if ( ! TimeSlotReservationSession::matchesSelection( $parsed['time_slot_id'], $date ) ) {
			wc_add_notice( __( 'Please reselect and reserve your pickup time.', 'olo' ), 'error' );
		}
	}

	// -------------------------------------------------------------------------
	// Save (woocommerce_checkout_create_order)
	// -------------------------------------------------------------------------

	public function save( $order ): void {
		if ( ! empty( $_COOKIE['oloNavTimeslot'] ) && empty( $_POST['olo_time_slot'] ) ) {
			$navSlot  = sanitize_text_field( $_COOKIE['oloNavTimeslot'] );
			$parts    = explode( '|', $navSlot, 2 );
			$timeSlotId   = $parts[0] ?? '';
			$slotTime     = isset( $_COOKIE['oloNavTimeslotTime'] ) ? sanitize_text_field( $_COOKIE['oloNavTimeslotTime'] ) : ( $parts[1] ?? '' );
			$businessDate = isset( $_COOKIE['oloNavTimeslotDate'] ) ? sanitize_text_field( $_COOKIE['oloNavTimeslotDate'] ) : '';

			$timeSlotsOrderId = TimeSlotReservationSession::getTimeSlotsOrderId();

			if ( $timeSlotId ) {
				$order->update_meta_data( '_olo_time_slot_id', $timeSlotId );
			}
			if ( $slotTime ) {
				$order->update_meta_data( '_olo_time_slot_time', $slotTime );
			}
			if ( $businessDate ) {
				$order->update_meta_data( '_olo_time_slot_business_date', $businessDate );
			}
			if ( $timeSlotsOrderId ) {
				$order->update_meta_data( '_olo_time_slot_order_id', $timeSlotsOrderId );
			}
			$order->update_meta_data( '_olo_time_slot', $navSlot );
			TimeSlotReservationSession::clear();
			return;
		}

		if ( empty( $_POST['olo_time_slot'] ) ) {
			return;
		}

		$raw         = sanitize_text_field( $_POST['olo_time_slot'] );
		$parsed      = TimeSlotValueParser::parse( $raw );
		$reservation = TimeSlotReservationSession::get();

		// Prefer session-confirmed values; fall back to the posted value.
		$timeSlotId   = $reservation['time_slot_id']  ?? ( $parsed['time_slot_id'] ?? '' );
		$slotTime     = $reservation['slot_time']      ?? ( $parsed['slot_time']    ?? '' );
		$businessDate = $reservation['business_date']  ?? ( isset( $_POST['olo_slot_date'] ) ? sanitize_text_field( $_POST['olo_slot_date'] ) : '' );

		if ( $timeSlotId ) {
			$order->update_meta_data( '_olo_time_slot_id', $timeSlotId );
		}
		if ( $slotTime ) {
			$order->update_meta_data( '_olo_time_slot_time', $slotTime );
		}
		if ( $businessDate ) {
			$order->update_meta_data( '_olo_time_slot_business_date', $businessDate );
		}
		if ( ! empty( $reservation['reserved_at'] ) ) {
			$order->update_meta_data( '_olo_time_slot_reserved_at', (int) $reservation['reserved_at'] );
		}
		if ( ! empty( $reservation['times_slots_order_id'] ) ) {
			$order->update_meta_data( '_olo_time_slot_order_id', (string) $reservation['times_slots_order_id'] );
		}

		$order->update_meta_data( '_olo_time_slot', $raw );

		TimeSlotReservationSession::clear();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function isEnabled(): bool {
		return function_exists( 'carbon_get_theme_option' )
			&& (bool) carbon_get_theme_option( 'olo_enable_time_slots' );
	}

	private function getHookFromSettings(): string {
		$position = (string) carbon_get_theme_option( 'olo_time_slot_position' );

		switch ( $position ) {
			case '0':
				return 'woocommerce_after_checkout_shipping_form';
			case '1':
				return 'woocommerce_before_order_notes';
			case '2':
				return 'woocommerce_after_order_notes';
			case '3':
				return 'woocommerce_checkout_before_customer_details';
			case '4':
				return 'woocommerce_review_order_after_order_total';
			default:
				return 'woocommerce_after_checkout_shipping_form';
		}
	}
}
