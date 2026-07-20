<?php

namespace CBSNorthStar\Admin;

class NoDaypartNotice {

    private static ?NoDaypartNotice $instance = null;

    private function __construct() {}

    public static function create(): NoDaypartNotice {
        if ( self::$instance === null ) {
            self::$instance = new NoDaypartNotice();
        }
        return self::$instance;
    }

    public function renderAdminNotice(): void {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT s.siteid, s.site_name
             FROM cbs_site_details s
             INNER JOIN cbs_configure_details c ON s.config_id = c.id
             LEFT JOIN cbs_daypartmenus d ON d.siteid = s.siteid
             WHERE s.menu_type = 'Default'
               AND d.siteid IS NULL
               AND c.id = (SELECT id FROM cbs_configure_details ORDER BY id DESC LIMIT 1)"
        );

        if ( empty( $results ) ) {
            return;
        }

        foreach ( $results as $row ) {
            $label = $row->site_name ? esc_html( $row->site_name ) : esc_html( $row->siteid );
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . $label . '</strong> ';
            echo esc_html__( 'does not have a daypart attached. Configure dayparts in ECM and run Save Products to resolve.', 'northstaronlineordering' );
            echo '</p></div>';
        }
    }
}
