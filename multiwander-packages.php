<?php
/**
 * Plugin Name:       MultiWander Packages
 * Plugin URI:        https://multiwander.com/
 * Description:       Pulls Travel Compositor Holiday Packages into MultiWander. Enter a package ID on a country page; the plugin creates the package page underneath it (PL + EN, linked via Polylang) and renders the offer preview row.
 * Version:           1.8.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Momira Travel
 * Text Domain:       multiwander-packages
 * Update URI:        https://github.com/Locura91/multiwander-packages
 *
 * ---------------------------------------------------------------------
 * CREDENTIALS
 * Add these to wp-config.php, ABOVE the "That's all, stop editing" line.
 * They are deliberately not stored in the database.
 *
 *   define( 'MW_TC_USERNAME',  'your-api-username' );
 *   define( 'MW_TC_PASSWORD',  'your-api-password' );
 *   define( 'MW_TC_MICROSITE', 'momiratravel' );
 *   define( 'MW_TC_BASE_URL',  'https://online.travelcompositor.com/resources' );
 *
 * ---------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MWP_VERSION', '1.8.0' );
define( 'MWP_FILE', __FILE__ );
define( 'MWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWP_URL', plugin_dir_url( __FILE__ ) );

/** Post meta keys. Kept in one place so nothing drifts. */
define( 'MWP_META_IDS', '_mwp_package_ids' );      // on the country page: the IDs entered
define( 'MWP_META_HEADING', '_mwp_offers_heading' ); // on the country page: section title
define( 'MWP_META_SUB', '_mwp_offers_sub' );        // on the country page: section subtitle
define( 'MWP_META_LAYOUT', '_mwp_offers_layout' );  // on the country page: row|slider|single
define( 'MWP_META_ID', '_mwp_package_id' );        // on a package page: its TC id
define( 'MWP_META_DATA', '_mwp_package_data' );    // on a package page: normalised payload
define( 'MWP_META_SYNCED', '_mwp_synced_at' );     // on a package page: last sync timestamp
define( 'MWP_META_IMAGE', '_mwp_image_override' ); // on a package page: manual hero image URL
define( 'MWP_META_LOCKED', '_mwp_slug_locked' );   // on a package page: don't regenerate slug

require_once MWP_DIR . 'includes/helpers.php';
require_once MWP_DIR . 'includes/class-mwp-client.php';
require_once MWP_DIR . 'includes/class-mwp-parser.php';
require_once MWP_DIR . 'includes/class-mwp-timeline.php';
require_once MWP_DIR . 'includes/class-mwp-sync.php';
require_once MWP_DIR . 'includes/class-mwp-admin.php';
require_once MWP_DIR . 'includes/class-mwp-frontend.php';
require_once MWP_DIR . 'includes/class-mwp-seo.php';
require_once MWP_DIR . 'includes/class-mwp-updater.php';

add_action( 'plugins_loaded', function () {
	MWP_Admin::init();
	MWP_Frontend::init();
	MWP_SEO::init();

	if ( is_admin() ) {
		MWP_Updater::init();
	}
} );

register_activation_hook( __FILE__, function () {
	// Nothing to create — the plugin stores everything in post meta.
	// Flush so child page permalinks resolve immediately.
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
