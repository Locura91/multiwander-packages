<?php
/**
 * Update the plugin straight from its public GitHub repository.
 *
 * WordPress only knows how to check wordpress.org for updates. This hooks into
 * the same update machinery and points it at GitHub instead, so a new version
 * appears on the Plugins screen with a normal "update now" link rather than
 * needing a zip to be downloaded and uploaded by hand.
 *
 * It reads the Version header off the plugin file on the default branch and
 * compares it with the installed one, so publishing a new version is just a
 * commit and push — no GitHub release needed.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Updater {

	const REPO      = 'Locura91/multiwander-packages';
	const BRANCH    = 'main';
	const TRANSIENT = 'mwp_remote_version';

	/** @var string Plugin basename, e.g. multiwander-packages/multiwander-packages.php */
	protected static $basename = '';

	public static function init() {
		self::$basename = plugin_basename( MWP_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_mwp_check_update', array( __CLASS__, 'force_check' ) );
	}

	/**
	 * The version currently published on the default branch.
	 *
	 * Cached for six hours: the Plugins screen can fire the update check
	 * several times per page load, and GitHub rate-limits unauthenticated
	 * requests to 60 an hour per IP.
	 *
	 * @param bool $force Ignore the cache.
	 * @return string Empty string when GitHub could not be reached.
	 */
	public static function remote_version( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( false !== $cached ) {
				return (string) $cached;
			}
		}

		$url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/multiwander-packages.php',
			self::REPO,
			self::BRANCH
		);

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				// GitHub rejects requests without a User-Agent.
				'User-Agent' => 'MultiWander-Packages/' . MWP_VERSION,
				'Accept'     => 'text/plain',
			),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a GitHub outage doesn't add 15
			// seconds to every admin page load.
			set_transient( self::TRANSIENT, '', 15 * MINUTE_IN_SECONDS );
			return '';
		}

		$body    = wp_remote_retrieve_body( $response );
		$version = '';

		if ( preg_match( '/^[\s\*]*Version:\s*(.+)$/mi', $body, $m ) ) {
			$version = trim( $m[1] );
		}

		set_transient( self::TRANSIENT, $version, 6 * HOUR_IN_SECONDS );

		return $version;
	}

	/**
	 * Tell WordPress an update is available.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = self::remote_version();
		if ( '' === $remote || version_compare( $remote, MWP_VERSION, '<=' ) ) {
			return $transient;
		}

		$update = (object) array(
			'slug'        => dirname( self::$basename ),
			'plugin'      => self::$basename,
			'new_version' => $remote,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => sprintf(
				'https://github.com/%s/archive/refs/heads/%s.zip',
				self::REPO,
				self::BRANCH
			),
			'tested'      => get_bloginfo( 'version' ),
			'icons'       => array(),
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ self::$basename ] = $update;

		return $transient;
	}

	/**
	 * Fill in the "View details" panel.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || dirname( self::$basename ) !== $args->slug ) {
			return $result;
		}

		$remote = self::remote_version();

		return (object) array(
			'name'          => 'MultiWander Packages',
			'slug'          => dirname( self::$basename ),
			'version'       => $remote ? $remote : MWP_VERSION,
			'author'        => '<a href="https://multiwander.com">Momira Travel</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => sprintf(
				'https://github.com/%s/archive/refs/heads/%s.zip',
				self::REPO,
				self::BRANCH
			),
			'sections'      => array(
				'description' => __( 'Pulls Travel Compositor Holiday Packages into MultiWander: enter a package ID on a country page and the package pages, offer cards and structured data are generated for Polish and English.', 'multiwander-packages' ),
			),
		);
	}

	/**
	 * Rename the unpacked folder.
	 *
	 * GitHub's branch zips unpack to "multiwander-packages-main", which
	 * WordPress would install as a second, separate plugin. Rename it back to
	 * the real folder so the update replaces the existing install.
	 *
	 * @param string      $source        Unpacked folder.
	 * @param string      $remote_source Parent temp folder.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $args          Hook args.
	 * @return string|WP_Error
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( empty( $args['plugin'] ) || self::$basename !== $args['plugin'] ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . dirname( self::$basename );

		if ( trailingslashit( $source ) === trailingslashit( $wanted ) ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( $source, $wanted, true ) ) {
			return new WP_Error(
				'mwp_rename_failed',
				__( 'Could not rename the downloaded folder.', 'multiwander-packages' )
			);
		}

		return trailingslashit( $wanted );
	}

	/**
	 * Add a "check for updates" link under the plugin row.
	 *
	 * @param array  $links Existing links.
	 * @param string $file  Plugin file.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( self::$basename !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( wp_nonce_url(
			admin_url( 'admin-post.php?action=mwp_check_update' ),
			'mwp_check_update'
		) ) . '">' . esc_html__( 'Check for updates', 'multiwander-packages' ) . '</a>';

		return $links;
	}

	/**
	 * Drop the caches and re-check immediately.
	 *
	 * @return void
	 */
	public static function force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'multiwander-packages' ) );
		}
		check_admin_referer( 'mwp_check_update' );

		delete_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		self::remote_version( true );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
