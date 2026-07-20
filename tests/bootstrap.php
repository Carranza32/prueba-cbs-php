<?php
/**
 * PHPUnit bootstrap for northstaronlineordering plugin tests.
 *
 * FIRST-TIME SETUP
 * ----------------
 * Run this once to install the WordPress test library and a temporary test DB:
 *
 *   bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
 *
 * Arguments: <db-name> <db-user> <db-pass> <db-host> <wp-version>
 * The script downloads WP core + the wp-tests stubs into /tmp/wordpress-tests-lib.
 *
 * After that, `composer test` will work.
 */

// ── Composer autoloader ───────────────────────────────────────────────────────
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
    echo "Error: vendor/autoload.php not found.\nRun: composer install\n";
    exit( 1 );
}
require_once $autoloader;

// ── Patchwork (Brain Monkey) ──────────────────────────────────────────────────
// Patchwork has no Composer autoload entry and can only redefine functions in
// files included AFTER it. It must load before the WP test library defines
// get_option()/add_action()/etc., or every Brain\Monkey Functions\when() stub
// throws Patchwork\Exceptions\DefinedTooEarly.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

// ── WordPress test library ────────────────────────────────────────────────────
$wpTestsDir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $wpTestsDir . '/includes/functions.php' ) ) {
    echo "Error: WordPress test library not found at {$wpTestsDir}.\n";
    echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
    exit( 1 );
}

// Point WP_TESTS_DIR at the installed library.
define( 'WP_TESTS_DIR', $wpTestsDir );

// functions.php must be loaded first — it defines tests_add_filter().
require_once $wpTestsDir . '/includes/functions.php';

// ── Load the plugin before WP sets up ────────────────────────────────────────
tests_add_filter( 'muplugins_loaded', function () {
    // Define the constant the plugin uses for its own file path.
    if ( ! defined( 'CBS_PLUGIN_FILE' ) ) {
        define( 'CBS_PLUGIN_FILE', dirname( __DIR__ ) . '/northstaronlineordering.php' );
    }

    // Load only the plugin's autoloaded classes — skip the main plugin file
    // to avoid registering hooks and enqueueing assets during tests.
    // Individual tests can load specific files if they need hook behaviour.
} );

require_once $wpTestsDir . '/includes/bootstrap.php';
