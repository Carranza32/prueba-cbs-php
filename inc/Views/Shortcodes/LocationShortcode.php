<?php
namespace CBSNorthStar\Views\Shortcodes;

defined( 'ABSPATH' ) || exit;

final class LocationShortcode {

	private const HANDLE = 'cbs-locations';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	public function register_assets(): void {
		$script_path = plugin_dir_path( __FILE__ ) . '../../../js/locations.js';
		$script_url  = plugin_dir_url( __FILE__ ) . '../../../js/locations.js';

		wp_register_script(
			self::HANDLE,
			$script_url,
			[],
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0',
			true
		);
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'style'       => '',
				'map_control' => 'show',
			],
			(array) $atts,
			'locations'
		);

		wp_enqueue_script( self::HANDLE );

		wp_add_inline_script(
			self::HANDLE,
			'window.CBSLocationsConfig = ' . wp_json_encode(
				[
					'restUrl'        => esc_url_raw( rest_url( 'northstaronlineordering/v1/search' ) ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'googleMapsKey'  => (string) get_option( 'cbs_google_api', '' ),
					'showMapControl' => 'show' === $atts['map_control'],
					'tsNavEnabled'   => function_exists( 'carbon_get_theme_option' ) && (bool) carbon_get_theme_option( 'olo_enable_time_slots' ),
					'strings'        => [
						'searchPlaceholder' => __( 'Search by restaurant, address, city, state, or zip', 'cbs-online-ordering' ),
						'searchButton'      => __( 'Search', 'cbs-online-ordering' ),
						'selectRadius'      => __( 'Select a radius', 'cbs-online-ordering' ),
						'noResultsTitle'    => __( 'We are sorry.', 'cbs-online-ordering' ),
						'noResultsMessage'  => __( 'There are no restaurants found.', 'cbs-online-ordering' ),
						'orderOnline'       => __( 'Order Online', 'cbs-online-ordering' ),
						'comingSoon'        => __( 'Coming soon', 'cbs-online-ordering' ),
						'open'              => __( 'Open', 'cbs-online-ordering' ),
						'closed'            => __( 'Closed', 'cbs-online-ordering' ),
						'closedMessage'     => __( 'This location is currently closed. Please place your order during business hours.', 'cbs-online-ordering' ),
						'showMap'           => __( 'Show Map', 'cbs-online-ordering' ),
						'hideMap'           => __( 'Hide Map', 'cbs-online-ordering' ),
						'milesAway'         => __( 'miles away', 'cbs-online-ordering' ),
					],
				]
			),
			'before'
		);

		ob_start();
		?>
		<div class="cbs-locations" data-cbs-locations data-testid="locations-wrapper">
            <div id="locations-content" class="for_scrollbar" data-testid="locations-content-section">
                <div class="modal-body content search-content" data-testid="locations-search-section">
                    <div class="mb-4 text-center text-one" data-testid="locations-heading-wrapper">
                        <h1 class="search-title" data-testid="locations-heading-title"> Locations</h1>
                    </div>
                    <form class="cbs-locations__form" data-cbs-form data-testid="locations-search-form">
                        <div class="se-from leftinput" data-testid="locations-keyword-wrapper">
                            <input
                                type="text"
                                name="keyword"
                                class="form-control cbs_form-control_custom north_keyword"
                                placeholder="<?php echo esc_attr__( 'Search by restaurant, address, city, state, or zip', 'cbs-online-ordering' ); ?>"
                                aria-label="<?php echo esc_attr__( 'Search by restaurant, address, city, state, or zip code', 'cbs-online-ordering' ); ?>"
                                data-testid="locations-keyword-input"
                            >
                            <button
                                type="button"
                                class="north_clear_keyword"
                                aria-label="<?php echo esc_attr__( 'Clear search', 'cbs-online-ordering' ); ?>"
                                data-cbs-clear
                                data-testid="locations-keyword-clear-btn"
                            >
                                <i class="fa fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="searchbtn searchbtn2" data-testid="locations-search-btn-wrapper">
                            <button type="submit" class="btn north_btn" data-testid="locations-search-submit-btn">
                                <?php echo esc_html__( 'Search', 'cbs-online-ordering' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

			<div class="scroll-container" data-testid="locations-results-container">
                <div id="map-toggle-section" class="map-toggle" data-testid="locations-map-toggle-section">
				    <button type="button" id="map-toggle" class="button-map-toggle" data-cbs-map-toggle data-testid="locations-map-toggle-btn">
					    <?php echo esc_html__( 'Show Map', 'cbs-online-ordering' ); ?>
				    </button>
			    </div>

			    <div id="map-container" class="section_map_custom" data-cbs-map-container data-testid="locations-map-container">
				    <div id="map" class="show-map" tabindex="-1" aria-hidden="true" data-testid="locations-map-canvas"></div>
			    </div>
                <div class="location-list" data-cbs-results data-testid="locations-results-list">

                </div>

            </div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}