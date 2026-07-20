<?php

namespace CBSNorthStar\Services;


class ComponentVersionChecker
{
    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    private static ?self $instance = null;

    private function __construct() {}

    public static function create(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    /**
     * Check installed component versions against compatible-with.json.
     *
     * Returns an empty array when:
     *   - CBS_SKIP_VERSION_CHECK is defined and truthy
     *   - compatible-with.json is missing or unreadable
     *   - All components match their expected versions
     *
     * Returns one entry per mismatch:
     *   [ 'name' => string, 'installed' => string|null, 'expected' => string ]
     *
     * @return array<int, array{name: string, installed: string|null, expected: string}>
     */
    public function check(): array
    {
        if ( defined( 'CBS_SKIP_VERSION_CHECK' ) && CBS_SKIP_VERSION_CHECK ) {
            return [];
        }

        $manifest = $this->readManifest();
        if ( empty( $manifest ) ) {
            return [];
        }

        $mismatches = [];

        $siteMode = get_option( 'siteMode', 'olo' );

        foreach ( $manifest as $name => $entry ) {
            $path     = $entry['path']     ?? null;
            $expected = $entry['expected'] ?? null;
            $mode     = $entry['mode']     ?? null;

            if ( $path === null || $expected === null ) {
                continue;
            }

            // Skip components that are not applicable to this site's mode.
            if ( $mode !== null && $mode !== $siteMode ) {
                continue;
            }

            $buildFile = WP_CONTENT_DIR . '/' . ltrim( $path, '/' ) . '/build.txt';
            $installed = null;

            if ( file_exists( $buildFile ) ) {
                $raw = file_get_contents( $buildFile );
                if ( $raw !== false ) {
                    $installed = trim( $raw );
                    if ( $installed === '' ) {
                        $installed = null;
                    }
                }
            }

            if ( $installed !== $expected ) {
                $mismatches[] = [
                    'name'      => (string) $name,
                    'installed' => $installed,
                    'expected'  => (string) $expected,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Render a WP admin notice when component version mismatches are detected.
     *
     * Hooked to `admin_notices`. Gated to:
     *   1. CBS_SKIP_VERSION_CHECK not set
     *   2. branch.txt == "master" OR olo_force_version_check is enabled
     *   3. Current user has manage_options
     *   4. At least one mismatch exists
     */
    public function renderAdminNotice(): void
    {
        if ( defined( 'CBS_SKIP_VERSION_CHECK' ) && CBS_SKIP_VERSION_CHECK ) {
            return;
        }

        if ( ! $this->shouldRun() ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $mismatches = $this->check();
        if ( empty( $mismatches ) ) {
            return;
        }

        echo '<div class="notice notice-error">';
        echo '<p><strong>NorthStar: Component version mismatch detected.</strong></p>';
        echo '<table style="border-collapse:collapse;min-width:400px">';
        echo '<tr><th style="text-align:left;padding:2px 12px 2px 0">Component</th>';
        echo '<th style="text-align:left;padding:2px 12px 2px 0">Installed</th>';
        echo '<th style="text-align:left;padding:2px 0">Expected</th></tr>';

        foreach ( $mismatches as $mismatch ) {
            $installed = $mismatch['installed'] !== null
                ? esc_html( $mismatch['installed'] )
                : '<em>unknown</em>';

            printf(
                '<tr><td style="padding:2px 12px 2px 0">%s</td><td style="padding:2px 12px 2px 0">%s</td><td style="padding:2px 0">%s</td></tr>',
                esc_html( $mismatch['name'] ),
                $installed,
                esc_html( $mismatch['expected'] )
            );
        }

        echo '</table>';
        echo '<p>Install the correct versions to ensure compatibility.</p>';
        echo '</div>';
    }


    /**
     * Determine whether the version check should run.
     *
     * Returns true when:
     *   - olo_force_version_check is enabled (bypasses branch gate), OR
     *   - plugin_root/branch.txt contains "master"
     */
    private function shouldRun(): bool
    {
        if ( function_exists( 'carbon_get_theme_option' ) &&
             (bool) carbon_get_theme_option( 'olo_force_version_check' ) ) {
            return true;
        }

        $branchFile = plugin_dir_path( CBS_PLUGIN_FILE ) . 'branch.txt';

        if ( ! file_exists( $branchFile ) ) {
            return false;
        }

        $branch = trim( (string) file_get_contents( $branchFile ) );

        return $branch === 'master';
    }

    /**
     * Read and decode compatible-with.json from the plugin root.
     *
     * @return array<string, array{path: string, expected: string}>
     */
    private function readManifest(): array
    {
        $file = plugin_dir_path( CBS_PLUGIN_FILE ) . 'compatible-with.json';

        if ( ! file_exists( $file ) ) {
            return [];
        }

        $json = file_get_contents( $file );
        if ( $json === false ) {
            return [];
        }

        $decoded = json_decode( $json, true );

        return is_array( $decoded ) ? $decoded : [];
    }
}
