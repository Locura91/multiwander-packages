<?php
/**
 * Keeping the pages in step with Travel Compositor.
 *
 * Packages change on the Travel Compositor side — prices move, hotels get
 * swapped, descriptions are rewritten — and nobody edits the WordPress page
 * when that happens. Without a scheduled refresh the site slowly drifts out of
 * date and starts advertising prices that no longer exist.
 *
 * Work is done in small batches. A site with thirty country pages, each with
 * several packages, each needing six API calls in two languages, is far more
 * than one PHP request should attempt: it would hit the execution time limit
 * and leave the run half finished. So each pass takes a few country pages,
 * remembers where it stopped, and continues on the next.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Cron {

	const HOOK        = 'mwp_scheduled_sync';
	const OPT_ENABLED = 'mwp_cron_enabled';
	const OPT_CURSOR  = 'mwp_cron_cursor';
	const OPT_LAST    = 'mwp_cron_last_run';
	const OPT_REPORT  = 'mwp_cron_last_report';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( 'admin_post_mwp_sync_all', array( __CLASS__, 'handle_sync_all' ) );
	}

	/**
	 * Keep the scheduled event in line with the setting.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		$enabled  = self::enabled();
		$next     = wp_next_scheduled( self::HOOK );

		if ( $enabled && ! $next ) {
			// Start a few minutes out so activating the plugin doesn't fire a
			// full sync on the very next page load.
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::interval(), self::HOOK );
			return;
		}

		if ( ! $enabled && $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		// Default on: a travel site showing last month's prices is worse than
		// a little background API traffic.
		return (bool) get_option( self::OPT_ENABLED, 1 );
	}

	/**
	 * @return string A WP-Cron schedule name.
	 */
	public static function interval() {
		return (string) apply_filters( 'mwp_cron_interval', 'daily' );
	}

	/**
	 * How many country pages one pass handles.
	 *
	 * @return int
	 */
	public static function batch_size() {
		return max( 1, (int) apply_filters( 'mwp_cron_batch', 5 ) );
	}

	/**
	 * Every country page that has package IDs on it.
	 *
	 * @return int[] Post IDs.
	 */
	public static function country_pages() {
		$found = get_posts( array(
			'post_type'        => 'page',
			'post_status'      => array( 'publish', 'draft', 'private' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'lang'             => '',   // all languages; the sync itself resolves to Polish
			'meta_query'       => array(
				array(
					'key'     => MWP_META_IDS,
					'compare' => 'EXISTS',
				),
			),
		) );

		// A package page also carries IDs meta in some edge cases; never treat
		// one as a country page or the sync would recurse into itself.
		return array_values( array_filter( $found, function ( $id ) {
			return ! get_post_meta( $id, MWP_META_ID, true )
				&& MWP_Sync::get_package_ids( $id );
		} ) );
	}

	/**
	 * One scheduled pass.
	 *
	 * @return array{done:int,packages:int,errors:array}
	 */
	public static function run() {
		if ( ! MWP_Client::is_configured() ) {
			return array(
				'done'     => 0,
				'packages' => 0,
				'errors'   => array( __( 'Credentials are not configured.', 'multiwander-packages' ) ),
			);
		}

		$pages = self::country_pages();
		if ( ! $pages ) {
			update_option( self::OPT_LAST, current_time( 'mysql' ), false );
			return array(
				'done'     => 0,
				'packages' => 0,
				'errors'   => array(),
			);
		}

		$cursor = (int) get_option( self::OPT_CURSOR, 0 );
		if ( $cursor >= count( $pages ) ) {
			$cursor = 0;
		}

		$slice = array_slice( $pages, $cursor, self::batch_size() );

		// Fresh data is the whole point of the run, so drop the response cache
		// first — otherwise an hour-old cached price would just be rewritten.
		MWP_Client::flush_cache();

		$sync     = new MWP_Sync();
		$packages = 0;
		$errors   = array();

		foreach ( $slice as $country_id ) {
			$result    = $sync->sync_country_page( $country_id );
			$packages += count( $result['synced'] );

			foreach ( $result['errors'] as $package_id => $message ) {
				$errors[] = sprintf( '%s (%s): %s', get_the_title( $country_id ), $package_id, $message );
			}
		}

		$next = $cursor + count( $slice );
		update_option( self::OPT_CURSOR, $next >= count( $pages ) ? 0 : $next, false );
		update_option( self::OPT_LAST, current_time( 'mysql' ), false );
		update_option( self::OPT_REPORT, array(
			'pages'    => count( $slice ),
			'packages' => $packages,
			'errors'   => array_slice( $errors, 0, 10 ),
			'total'    => count( $pages ),
		), false );

		if ( $errors ) {
			mwp_log( 'Scheduled sync finished with errors', $errors );
		}

		return array(
			'done'     => count( $slice ),
			'packages' => $packages,
			'errors'   => $errors,
		);
	}

	/**
	 * "Sync everything now" from the settings screen.
	 *
	 * @return void
	 */
	public static function handle_sync_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'multiwander-packages' ) );
		}
		check_admin_referer( 'mwp_sync_all' );

		// Start from the beginning rather than wherever the cron happens to be.
		update_option( self::OPT_CURSOR, 0, false );
		self::run();

		wp_safe_redirect( admin_url( 'options-general.php?page=mwp-settings&mwp_synced=1' ) );
		exit;
	}

	/**
	 * Remove the scheduled event (called on deactivation).
	 *
	 * @return void
	 */
	public static function clear() {
		$next = wp_next_scheduled( self::HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}
	}
}
