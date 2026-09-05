<?php
/**
 * Admin: the ID box on country pages, the settings/status screen, and a raw
 * JSON viewer for when a field needs checking against the real response.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Admin {

	const NOTICE_TRANSIENT = 'mwp_sync_notice_';

	public static function init() {
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_page' ), 20, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_mwp_resync', array( __CLASS__, 'handle_resync' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
	}

	/**
	 * Load the admin stylesheet only where it is used.
	 *
	 * @param string $hook Current screen.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'settings_page_mwp-settings' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'mwp-admin', MWP_URL . 'assets/admin.css', array(), MWPP_asset_version( 'assets/admin.css' ) );
	}

	/**
	 * Mark generated pages in the Pages list so they are easy to spot.
	 *
	 * @param array   $states Existing states.
	 * @param WP_Post $post   Post.
	 * @return array
	 */
	public static function post_state( $states, $post ) {
		if ( get_post_meta( $post->ID, MWP_META_ID, true ) ) {
			$states['mwp'] = __( 'Travel package', 'multiwander-packages' );
		}
		return $states;
	}

	// -----------------------------------------------------------------
	// Meta boxes
	// -----------------------------------------------------------------

	public static function add_meta_boxes( $post ) {
		add_meta_box(
			'mwp-country-packages',
			__( 'Travel packages on this page', 'multiwander-packages' ),
			array( __CLASS__, 'render_country_box' ),
			'page',
			'normal',
			'high'
		);

		if ( get_post_meta( $post->ID, MWP_META_ID, true ) ) {
			add_meta_box(
				'mwp-package-info',
				__( 'Travel Compositor package', 'multiwander-packages' ),
				array( __CLASS__, 'render_package_box' ),
				'page',
				'side',
				'high'
			);
		}
	}

	/**
	 * The box where the editor pastes package IDs.
	 *
	 * @param WP_Post $post Country page.
	 * @return void
	 */
	public static function render_country_box( $post ) {
		wp_nonce_field( 'mwp_save_' . $post->ID, 'mwp_nonce' );

		$source = MWP_Sync::source_country_id( $post->ID );
		$raw    = get_post_meta( $source, MWP_META_IDS, true );
		$ids    = MWP_Sync::get_package_ids( $source );

		if ( $source !== $post->ID ) {
			echo '<div class="notice notice-info inline"><p>' .
				sprintf(
					/* translators: %s: link to the Polish page */
					esc_html__( 'Packages for this page are managed on its Polish version: %s', 'multiwander-packages' ),
					'<a href="' . esc_url( (string) get_edit_post_link( $source ) ) . '">' . esc_html( get_the_title( $source ) ) . '</a>'
				) .
				'</p></div>';
		}

		if ( ! MWP_Client::is_configured() ) {
			echo '<div class="notice notice-error inline"><p><strong>' .
				esc_html__( 'Travel Compositor credentials are missing.', 'multiwander-packages' ) .
				'</strong> <a href="' . esc_url( admin_url( 'options-general.php?page=mwp-settings' ) ) . '">' .
				esc_html__( 'Add them in Settings → MultiWander Packages', 'multiwander-packages' ) .
				'</a>.</p></div>';
		}
		?>
		<p class="mwp-help">
			<?php esc_html_e( 'One package per line. Paste either the ID or the whole momira.travel address — the ID is picked out automatically.', 'multiwander-packages' ); ?>
		</p>

		<textarea name="mwp_package_ids" rows="6" class="large-text code" placeholder="59875907&#10;https://momira.travel/en/idea/62837980/thailand-culinary-journey"><?php echo esc_textarea( $raw ); ?></textarea>

		<p class="mwp-help">
			<?php
			printf(
				/* translators: %s: shortcode */
				esc_html__( 'Put %s in the page content where the offer row should appear.', 'multiwander-packages' ),
				'<code>[multiwander_offers]</code>'
			);
			?>
		</p>

		<?php if ( $ids ) : ?>
			<table class="widefat striped mwp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'multiwander-packages' ); ?></th>
						<th><?php esc_html_e( 'Page', 'multiwander-packages' ); ?></th>
						<th><?php esc_html_e( 'Last synced', 'multiwander-packages' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $ids as $id ) : ?>
					<?php
					$pl     = MWP_Sync::find_package_page( $id, 'pl' );
					$synced = $pl ? get_post_meta( $pl, MWP_META_SYNCED, true ) : '';
					?>
					<tr>
						<td><code><?php echo esc_html( $id ); ?></code></td>
						<td>
							<?php if ( $pl ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $pl ) ); ?>"><?php echo esc_html( get_the_title( $pl ) ); ?></a><br>
								<a href="<?php echo esc_url( get_permalink( $pl ) ); ?>" target="_blank" rel="noopener" class="mwp-permalink"><?php echo esc_html( wp_make_link_relative( get_permalink( $pl ) ) ); ?></a>
							<?php else : ?>
								<em><?php esc_html_e( 'Not created yet — update the page to sync.', 'multiwander-packages' ); ?></em>
							<?php endif; ?>
						</td>
						<td><?php echo $synced ? esc_html( $synced ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<a class="button" href="<?php echo esc_url( self::resync_url( $source ) ); ?>">
					<?php esc_html_e( 'Re-sync now', 'multiwander-packages' ); ?>
				</a>
				<span class="mwp-help"><?php esc_html_e( 'Refreshes prices and content without editing the page.', 'multiwander-packages' ); ?></span>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sidebar box on a generated package page.
	 *
	 * @param WP_Post $post Package page.
	 * @return void
	 */
	public static function render_package_box( $post ) {
		$id     = get_post_meta( $post->ID, MWP_META_ID, true );
		$data   = MWP_Sync::get_data( $post->ID );
		$locked = get_post_meta( $post->ID, MWP_META_LOCKED, true );

		wp_nonce_field( 'mwp_save_' . $post->ID, 'mwp_nonce' );

		echo '<p><strong>' . esc_html__( 'Package ID', 'multiwander-packages' ) . ':</strong> <code>' . esc_html( $id ) . '</code></p>';

		if ( $data ) {
			$price = mwp_format_price( $data['price'], 'pl' );
			echo '<p><strong>' . esc_html__( 'Price from', 'multiwander-packages' ) . ':</strong> ' .
				esc_html( $price['value'] . ' ' . $price['symbol'] );
			if ( ! empty( $price['converted'] ) ) {
				echo ' <span class="mwp-help">' . esc_html__( '(converted from EUR)', 'multiwander-packages' ) . '</span>';
			}
			echo '</p>';

			echo '<p><strong>' . esc_html__( 'Trip', 'multiwander-packages' ) . ':</strong> ' .
				esc_html( sprintf( '%d dni / %d nocy', $data['duration']['days'], $data['duration']['nights'] ) ) . '</p>';

			if ( ! empty( $data['booking_url'] ) ) {
				echo '<p><a href="' . esc_url( $data['booking_url'] ) . '" target="_blank" rel="noopener">' .
					esc_html__( 'Open in Travel Compositor', 'multiwander-packages' ) . '</a></p>';
			}
		}

		echo '<p><label><input type="checkbox" name="mwp_slug_locked" value="1" ' . checked( $locked, '1', false ) . '> ' .
			esc_html__( 'Keep this URL — do not regenerate the slug from the title', 'multiwander-packages' ) .
			'</label></p>';

		$override = get_post_meta( $post->ID, MWP_META_IMAGE, true );
		echo '<p><label for="mwp_image_override"><strong>' . esc_html__( 'Hero image override', 'multiwander-packages' ) . '</strong></label>';
		echo '<input type="url" id="mwp_image_override" name="mwp_image_override" class="widefat" value="' . esc_attr( $override ) . '" placeholder="https://multiwander.com/wp-content/uploads/...">';
		echo '<span class="mwp-help">' . esc_html__( 'Leave empty to use the image from Travel Compositor.', 'multiwander-packages' ) . '</span></p>';

		echo '<p><a class="button" href="' . esc_url( self::debug_url( $id ) ) . '">' .
			esc_html__( 'View raw API response', 'multiwander-packages' ) . '</a></p>';
	}

	// -----------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------

	/**
	 * Persist the meta box fields, then sync.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public static function save_page( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['mwp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mwp_nonce'] ) ), 'mwp_save_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Fields on a generated package page.
		if ( get_post_meta( $post_id, MWP_META_ID, true ) ) {
			update_post_meta( $post_id, MWP_META_LOCKED, isset( $_POST['mwp_slug_locked'] ) ? '1' : '' );

			$override = isset( $_POST['mwp_image_override'] ) ? esc_url_raw( wp_unslash( $_POST['mwp_image_override'] ) ) : '';
			update_post_meta( $post_id, MWP_META_IMAGE, $override );
		}

		// Fields on a country page.
		if ( ! isset( $_POST['mwp_package_ids'] ) ) {
			return;
		}

		// Always store and sync against the Polish original: Polish is the
		// source language and the Polish country page is the parent the
		// package pages hang off. Editing the English page must not build the
		// tree under the English parent.
		$raw    = sanitize_textarea_field( wp_unslash( $_POST['mwp_package_ids'] ) );
		$source = MWP_Sync::source_country_id( $post_id );
		update_post_meta( $source, MWP_META_IDS, $raw );

		if ( '' === trim( $raw ) || ! MWP_Client::is_configured() ) {
			return;
		}

		// Avoid recursion: sync_package writes pages, which fires save_post.
		remove_action( 'save_post_page', array( __CLASS__, 'save_page' ), 20 );
		$sync   = new MWP_Sync();
		$result = $sync->sync_country_page( $source );
		add_action( 'save_post_page', array( __CLASS__, 'save_page' ), 20, 3 );

		self::store_notice( $source, $result );
	}

	// -----------------------------------------------------------------
	// Manual re-sync
	// -----------------------------------------------------------------

	public static function resync_url( $country_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=mwp_resync&post=' . (int) $country_id ),
			'mwp_resync_' . (int) $country_id
		);
	}

	public static function debug_url( $package_id ) {
		return admin_url( 'options-general.php?page=mwp-settings&mwp_debug=' . rawurlencode( $package_id ) );
	}

	public static function handle_resync() {
		$country_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( ! $country_id || ! current_user_can( 'edit_post', $country_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'multiwander-packages' ) );
		}
		check_admin_referer( 'mwp_resync_' . $country_id );

		MWP_Client::flush_cache();

		$sync   = new MWP_Sync();
		$result = $sync->sync_country_page( $country_id );
		self::store_notice( $country_id, $result );

		wp_safe_redirect( get_edit_post_link( $country_id, 'raw' ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Notices
	// -----------------------------------------------------------------

	protected static function store_notice( $post_id, array $result ) {
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), array(
			'post'   => $post_id,
			'result' => $result,
		), 60 );
	}

	public static function notices() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		$r = $notice['result'];

		if ( ! empty( $r['synced'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				esc_html(
					/* translators: %d: number of packages */
					_n( '%d travel package synced.', '%d travel packages synced.', count( $r['synced'] ), 'multiwander-packages' )
				),
				count( $r['synced'] )
			);
			echo '</p><ul style="margin-left:1.5em;list-style:disc">';
			foreach ( $r['synced'] as $id => $one ) {
				echo '<li><a href="' . esc_url( $one['url'] ) . '" target="_blank" rel="noopener">' .
					esc_html( $one['title'] ) . '</a> <code>' . esc_html( $id ) . '</code></li>';
			}
			echo '</ul></div>';
		}

		foreach ( (array) $r['warnings'] as $id => $messages ) {
			foreach ( (array) $messages as $message ) {
				echo '<div class="notice notice-warning is-dismissible"><p><code>' .
					esc_html( $id ) . '</code> — ' . esc_html( $message ) . '</p></div>';
			}
		}

		foreach ( (array) $r['errors'] as $id => $message ) {
			echo '<div class="notice notice-error is-dismissible"><p><code>' .
				esc_html( $id ) . '</code> — ' . esc_html( $message ) . '</p></div>';
		}
	}

	// -----------------------------------------------------------------
	// Settings / status screen
	// -----------------------------------------------------------------

	public static function menu() {
		add_options_page(
			__( 'MultiWander Packages', 'multiwander-packages' ),
			__( 'MultiWander Packages', 'multiwander-packages' ),
			'manage_options',
			'mwp-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['mwp_flush'] ) && check_admin_referer( 'mwp_flush' ) ) {
			$n = MWP_Client::flush_cache();
			delete_transient( MWP_Client::TOKEN_TRANSIENT );
			echo '<div class="notice notice-success"><p>' .
				sprintf( esc_html__( 'Cleared %d cached responses.', 'multiwander-packages' ), (int) $n ) .
				'</p></div>';
		}

		if ( isset( $_POST['mwp_save_template'] ) && check_admin_referer( 'mwp_template' ) ) {
			update_option( 'mwp_page_template', sanitize_text_field( wp_unslash( $_POST['mwp_page_template'] ?? '' ) ) );
			echo '<div class="notice notice-success"><p>' .
				esc_html__( 'Saved. Re-sync a country page to apply the template to its package pages.', 'multiwander-packages' ) .
				'</p></div>';
		}

		if ( isset( $_POST['mwp_save_credentials'] ) && check_admin_referer( 'mwp_credentials' ) ) {
			MWP_Client::save_settings( array(
				'user'      => isset( $_POST['mwp_user'] ) ? wp_unslash( $_POST['mwp_user'] ) : '',
				'pass'      => isset( $_POST['mwp_pass'] ) ? wp_unslash( $_POST['mwp_pass'] ) : '',
				'microsite' => isset( $_POST['mwp_microsite'] ) ? wp_unslash( $_POST['mwp_microsite'] ) : '',
				'base'      => isset( $_POST['mwp_base'] ) ? wp_unslash( $_POST['mwp_base'] ) : '',
			) );
			echo '<div class="notice notice-success"><p>' .
				esc_html__( 'Credentials saved.', 'multiwander-packages' ) . '</p></div>';
		}

		$c            = MWP_Client::config();
		$from_config  = ( 'wp-config.php' === $c['source'] );
		$has_password = MWP_Client::has_stored_password();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MultiWander Packages', 'multiwander-packages' ); ?></h1>

			<h2><?php esc_html_e( 'Connection', 'multiwander-packages' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Status', 'multiwander-packages' ); ?></th>
					<td><?php self::render_connection_status(); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Credentials from', 'multiwander-packages' ); ?></th>
					<td>
						<?php if ( $from_config ) : ?>
							<code>wp-config.php</code>
							<span class="mwp-help"><?php esc_html_e( 'The safest option — the fields below are ignored while these constants are set.', 'multiwander-packages' ); ?></span>
						<?php else : ?>
							<?php esc_html_e( 'the fields below', 'multiwander-packages' ); ?>
							<span class="mwp-help"><?php esc_html_e( 'Stored in the database, encrypted with this site\'s salts. Moving them into wp-config.php later is more secure and takes priority automatically.', 'multiwander-packages' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post">
				<?php wp_nonce_field( 'mwp_credentials' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="mwp_user"><?php esc_html_e( 'Username', 'multiwander-packages' ); ?></label></th>
						<td>
							<input type="text" id="mwp_user" name="mwp_user" class="regular-text"
								value="<?php echo esc_attr( $from_config ? '' : get_option( MWP_Client::OPT_USER, '' ) ); ?>"
								autocomplete="off" <?php disabled( $from_config ); ?>>
							<span class="mwp-help"><?php esc_html_e( 'This username contains spaces. That is correct.', 'multiwander-packages' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="mwp_pass"><?php esc_html_e( 'Password', 'multiwander-packages' ); ?></label></th>
						<td>
							<input type="password" id="mwp_pass" name="mwp_pass" class="regular-text"
								value="" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $has_password ? __( 'stored — leave empty to keep it', 'multiwander-packages' ) : '' ); ?>"
								<?php disabled( $from_config ); ?>>
							<span class="mwp-help"><?php esc_html_e( 'Never shown again once saved. Leave empty when changing the other fields.', 'multiwander-packages' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><label for="mwp_microsite"><?php esc_html_e( 'Microsite', 'multiwander-packages' ); ?></label></th>
						<td><input type="text" id="mwp_microsite" name="mwp_microsite" class="regular-text"
							value="<?php echo esc_attr( $c['microsite'] ); ?>" <?php disabled( $from_config ); ?>></td>
					</tr>
					<tr>
						<th><label for="mwp_base"><?php esc_html_e( 'Base URL', 'multiwander-packages' ); ?></label></th>
						<td><input type="url" id="mwp_base" name="mwp_base" class="large-text"
							value="<?php echo esc_attr( $c['base'] ); ?>" <?php disabled( $from_config ); ?>></td>
					</tr>
				</table>
				<?php if ( ! $from_config ) : ?>
					<p><button class="button button-primary" name="mwp_save_credentials" value="1"><?php esc_html_e( 'Save credentials', 'multiwander-packages' ); ?></button></p>
				<?php endif; ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Package pages', 'multiwander-packages' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'mwp_template' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="mwp_page_template"><?php esc_html_e( 'Page template', 'multiwander-packages' ); ?></label></th>
						<td>
							<?php $current = (string) get_option( 'mwp_page_template', '' ); ?>
							<select id="mwp_page_template" name="mwp_page_template">
								<option value=""><?php esc_html_e( '— leave each page as it is —', 'multiwander-packages' ); ?></option>
								<option value="default" <?php selected( $current, 'default' ); ?>><?php esc_html_e( 'Default template', 'multiwander-packages' ); ?></option>
								<?php foreach ( wp_get_theme()->get_page_templates( null, 'page' ) as $file => $name ) : ?>
									<option value="<?php echo esc_attr( $file ); ?>" <?php selected( $current, $file ); ?>>
										<?php echo esc_html( $name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="mwp-help">
								<?php esc_html_e( 'Package pages print their own H1 in the hero image. Choose a template that does NOT also display the page title — otherwise every package page has two H1 headings, which weakens it in search. On this theme that is "Page Builder (Transparent Header, Without Title)".', 'multiwander-packages' ); ?>
							</span>
						</td>
					</tr>
				</table>
				<p><button class="button button-primary" name="mwp_save_template" value="1"><?php esc_html_e( 'Save', 'multiwander-packages' ); ?></button></p>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'mwp_flush' ); ?>
				<p><button class="button" name="mwp_flush" value="1"><?php esc_html_e( 'Clear cached API responses', 'multiwander-packages' ); ?></button></p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Inspect a package', 'multiwander-packages' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Shows the raw Travel Compositor response next to what the plugin made of it. Use this when a field on a page looks wrong.', 'multiwander-packages' ); ?>
			</p>
			<form method="get">
				<input type="hidden" name="page" value="mwp-settings">
				<input type="text" name="mwp_debug" class="regular-text" placeholder="59875907"
					value="<?php echo isset( $_GET['mwp_debug'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['mwp_debug'] ) ) ) : ''; ?>">
				<button class="button"><?php esc_html_e( 'Fetch', 'multiwander-packages' ); ?></button>
			</form>

			<?php self::render_debug(); ?>
		</div>
		<?php
	}

	protected static function render_connection_status() {
		if ( ! MWP_Client::is_configured() ) {
			echo '<span class="mwp-bad">&#10007;</span> ' . esc_html__( 'Not configured', 'multiwander-packages' );
			return;
		}

		$client = new MWP_Client();
		$token  = $client->authenticate();

		if ( is_wp_error( $token ) ) {
			echo '<span class="mwp-bad">&#10007;</span> ' . esc_html( $token->get_error_message() );
			return;
		}

		echo '<span class="mwp-ok">&#10003;</span> ' . esc_html__( 'Connected', 'multiwander-packages' );
	}

	protected static function render_debug() {
		if ( empty( $_GET['mwp_debug'] ) ) {
			return;
		}

		$id = mwp_extract_id( sanitize_text_field( wp_unslash( $_GET['mwp_debug'] ) ) );
		if ( '' === $id ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That is not a package ID.', 'multiwander-packages' ) . '</p></div>';
			return;
		}

		$client   = new MWP_Client();
		$info     = $client->get_info( $id, 'PL' );
		$detail   = $client->get_detail( $id, 'PL' );
		$calendar = $client->get_calendar( $id, 'PL' );

		foreach ( array( 'info' => $info, 'detail' => $detail, 'calendar' => $calendar ) as $label => $response ) {
			if ( is_wp_error( $response ) ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html( $label ) . '</strong>: ' .
					esc_html( $response->get_error_message() ) . '</p></div>';
			}
		}

		if ( is_wp_error( $info ) ) {
			return;
		}

		$parsed = MWP_Parser::normalise(
			$info,
			is_wp_error( $detail ) ? array() : $detail,
			is_wp_error( $calendar ) ? array() : $calendar
		);

		echo '<h3>' . esc_html__( 'What the plugin read', 'multiwander-packages' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		$summary = array(
			__( 'Title (PL)', 'multiwander-packages' )   => $parsed['title'],
			__( 'Slug', 'multiwander-packages' )         => mwp_slug( $parsed['title'] ),
			__( 'Price', 'multiwander-packages' )        => $parsed['price']['amount'] . ' ' . $parsed['price']['currency'],
			__( 'Duration', 'multiwander-packages' )     => $parsed['duration']['days'] . ' / ' . $parsed['duration']['nights'],
			__( 'Destinations', 'multiwander-packages' ) => implode( ' → ', wp_list_pluck( $parsed['destinations'], 'name' ) ),
			__( 'Flights', 'multiwander-packages' )      => count( $parsed['flights'] ),
			__( 'Hotels', 'multiwander-packages' )       => count( $parsed['hotels'] ),
			__( 'Activities', 'multiwander-packages' )   => count( $parsed['activities'] ),
			__( 'Gallery', 'multiwander-packages' )      => count( $parsed['gallery'] ),
			__( 'Departures', 'multiwander-packages' )   => $parsed['departures']['is_empty']
				? __( 'none listed (departs any day)', 'multiwander-packages' )
				: count( $parsed['departures']['dates'] ) . ' — ' . $parsed['departures']['first'],
		);
		foreach ( $summary as $label => $value ) {
			echo '<tr><th style="width:180px">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Raw response', 'multiwander-packages' ) . '</h3>';
		echo '<textarea class="widefat code" rows="20" readonly>' .
			esc_textarea( wp_json_encode(
				array(
					'info'     => $info,
					'detail'   => is_wp_error( $detail ) ? null : $detail,
					'calendar' => is_wp_error( $calendar ) ? null : $calendar,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			) ) .
			'</textarea>';
	}
}

/**
 * Cache-busting version for a bundled asset.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function MWPP_asset_version( $relative ) { // phpcs:ignore WordPress.NamingConventions
	$path = MWP_DIR . $relative;
	return file_exists( $path ) ? (string) filemtime( $path ) : MWP_VERSION;
}
