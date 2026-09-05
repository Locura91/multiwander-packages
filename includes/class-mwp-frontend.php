<?php
/**
 * Front end: the offer row on country pages, and the package page itself.
 *
 * Nothing here calls the API. Everything is read from post meta written at
 * sync time, so page loads stay fast and a slow API can never slow the site
 * down — which is what the old scraping shortcode did on every uncached view.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Frontend {

	public static function init() {
		add_shortcode( 'multiwander_offers', array( __CLASS__, 'offers_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'package_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$is_package = (bool) get_post_meta( $post->ID, MWP_META_ID, true );
		$has_row    = has_shortcode( (string) $post->post_content, 'multiwander_offers' );

		if ( ! $is_package && ! $has_row ) {
			return;
		}

		wp_enqueue_style(
			'mwp-front',
			MWP_URL . 'assets/front.css',
			array(),
			MWPP_asset_version( 'assets/front.css' )
		);
	}

	// -----------------------------------------------------------------
	// Offer row
	// -----------------------------------------------------------------

	/**
	 * [multiwander_offers] — the preview cards on a country page.
	 *
	 * Cards are built from the package pages themselves, so removing an ID
	 * from the meta box drops the card while leaving the page published and
	 * indexed. No dead URLs.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function offers_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'ids'     => '',   // optional explicit list, overrides the meta box
				'columns' => 3,
				'limit'   => 0,
			),
			$atts,
			'multiwander_offers'
		);

		$lang = mwp_current_lang();
		$post = get_post();

		if ( $atts['ids'] ) {
			$pages = array();
			foreach ( preg_split( '/[\s,]+/', $atts['ids'] ) as $raw ) {
				$id = mwp_extract_id( $raw );
				if ( '' === $id ) {
					continue;
				}
				$found = MWP_Sync::find_package_page( $id, $lang );
				if ( $found && 'publish' === get_post_status( $found ) ) {
					$pages[] = get_post( $found );
				}
			}
		} else {
			if ( ! $post ) {
				return '';
			}
			// On an English country page, the IDs live on its Polish original.
			$source_id = $post->ID;
			if ( 'pl' !== $lang && function_exists( 'pll_get_post' ) ) {
				$pl = pll_get_post( $post->ID, 'pl' );
				if ( $pl ) {
					$source_id = $pl;
				}
			}
			$pages = MWP_Sync::get_package_pages( $source_id, $lang );
		}

		if ( $atts['limit'] > 0 ) {
			$pages = array_slice( $pages, 0, (int) $atts['limit'] );
		}

		if ( ! $pages ) {
			// Say nothing on the front end rather than showing a broken row.
			return current_user_can( 'edit_posts' )
				? '<p class="mwp-empty"><em>' . esc_html__( 'No travel packages yet — add package IDs in the page sidebar and update the page.', 'multiwander-packages' ) . '</em></p>'
				: '';
		}

		ob_start();
		echo '<div class="mwp-offers mwp-cols-' . esc_attr( (int) $atts['columns'] ) . '">';
		foreach ( $pages as $page ) {
			self::render_card( $page, $lang );
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * One offer card.
	 *
	 * @param WP_Post $page Package page.
	 * @param string  $lang Language slug.
	 * @return void
	 */
	protected static function render_card( $page, $lang ) {
		$data = MWP_Sync::get_data( $page->ID );
		if ( ! $data ) {
			return;
		}

		$price  = mwp_format_price( $data['price'], $lang );
		$image  = self::hero_url( $page->ID, $data );
		$ribbon = $data['ribbon'];
		$per    = ( 'en' === $lang ) ? __( 'per person', 'multiwander-packages' ) : 'od osoby';
		$cta    = ( 'en' === $lang ) ? __( 'View trip', 'multiwander-packages' ) : 'Zobacz podróż';
		?>
		<article class="mwp-card">
			<a class="mwp-card-link" href="<?php echo esc_url( get_permalink( $page ) ); ?>">
				<div class="mwp-card-media">
					<?php if ( $image ) : ?>
						<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( get_the_title( $page ) ); ?>" loading="lazy" decoding="async">
					<?php endif; ?>
					<?php if ( $ribbon ) : ?>
						<span class="mwp-ribbon"><?php echo esc_html( $ribbon ); ?></span>
					<?php endif; ?>
				</div>

				<div class="mwp-card-body">
					<h3 class="mwp-card-title"><?php echo esc_html( get_the_title( $page ) ); ?></h3>

					<?php if ( $data['duration']['days'] ) : ?>
						<p class="mwp-card-meta">
							<?php
							echo esc_html(
								'en' === $lang
									? sprintf( '%d days · %d destinations', $data['duration']['days'], max( 1, count( $data['destinations'] ) ) )
									: sprintf( '%d dni · %d miejsc', $data['duration']['days'], max( 1, count( $data['destinations'] ) ) )
							);
							?>
						</p>
					<?php endif; ?>

					<?php if ( $price['value'] ) : ?>
						<p class="mwp-card-price">
							<span class="mwp-price-amount"><?php echo esc_html( $price['value'] ); ?> <?php echo esc_html( $price['symbol'] ); ?></span>
							<span class="mwp-price-per"><?php echo esc_html( $per ); ?></span>
						</p>
					<?php endif; ?>

					<span class="mwp-card-cta"><?php echo esc_html( $cta ); ?></span>
				</div>
			</a>
		</article>
		<?php
	}

	// -----------------------------------------------------------------
	// Package page
	// -----------------------------------------------------------------

	/**
	 * Render the package page body.
	 *
	 * Appended to the_content rather than shipped as a page template, so the
	 * page keeps whatever theme template the country pages use and an editor
	 * can still add their own copy above it.
	 *
	 * @param string $content Existing content.
	 * @return string
	 */
	public static function package_content( $content ) {
		if ( ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! get_post_meta( $post_id, MWP_META_ID, true ) ) {
			return $content;
		}

		$data = MWP_Sync::get_data( $post_id );
		if ( ! $data ) {
			return $content;
		}

		$lang = mwp_current_lang();

		ob_start();
		self::render_package( $post_id, $data, $lang );
		return $content . ob_get_clean();
	}

	/**
	 * The full package layout.
	 *
	 * @param int    $post_id Post.
	 * @param array  $d       Payload.
	 * @param string $lang    Language slug.
	 * @return void
	 */
	protected static function render_package( $post_id, array $d, $lang ) {
		$en    = ( 'en' === $lang );
		$price = mwp_format_price( $d['price'], $lang );
		$hero  = self::hero_url( $post_id, $d );

		$t = function ( $pl, $english ) use ( $en ) {
			return $en ? $english : $pl;
		};
		?>
		<div class="mwp-package">

			<?php if ( $hero ) : ?>
				<div class="mwp-hero">
					<img src="<?php echo esc_url( $hero ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" loading="eager" decoding="async">
				</div>
			<?php endif; ?>

			<div class="mwp-summary">
				<?php if ( $price['value'] ) : ?>
					<div class="mwp-summary-price">
						<span class="mwp-from"><?php echo esc_html( $t( 'od', 'from' ) ); ?></span>
						<span class="mwp-amount"><?php echo esc_html( $price['value'] ); ?> <?php echo esc_html( $price['symbol'] ); ?></span>
						<span class="mwp-per"><?php echo esc_html( $t( 'od osoby', 'per person' ) ); ?></span>
					</div>
				<?php endif; ?>

				<ul class="mwp-facts">
					<?php if ( $d['duration']['days'] ) : ?>
						<li><strong><?php echo esc_html( $d['duration']['days'] ); ?></strong> <?php echo esc_html( $t( 'dni', 'days' ) ); ?></li>
					<?php endif; ?>
					<?php if ( $d['duration']['nights'] ) : ?>
						<li><strong><?php echo esc_html( $d['duration']['nights'] ); ?></strong> <?php echo esc_html( $t( 'nocy', 'nights' ) ); ?></li>
					<?php endif; ?>
					<?php if ( $d['destinations'] ) : ?>
						<li><strong><?php echo esc_html( count( $d['destinations'] ) ); ?></strong> <?php echo esc_html( $t( 'miejsc', 'destinations' ) ); ?></li>
					<?php endif; ?>
					<?php if ( $d['flights'] ) : ?>
						<li><strong><?php echo esc_html( count( $d['flights'] ) ); ?></strong> <?php echo esc_html( $t( 'przelotów', 'flights' ) ); ?></li>
					<?php endif; ?>
				</ul>

				<?php if ( ! $d['departures']['is_empty'] && $d['departures']['first'] ) : ?>
					<p class="mwp-departure">
						<?php
						echo esc_html( sprintf(
							$t( 'np. z wylotem %s', 'e.g. departing %s' ),
							date_i18n( get_option( 'date_format' ), strtotime( $d['departures']['first'] ) )
						) );
						?>
					</p>
				<?php else : ?>
					<p class="mwp-departure"><?php echo esc_html( $t( 'Wylot możliwy w dowolnym terminie', 'Departs any day you choose' ) ); ?></p>
				<?php endif; ?>

				<?php if ( $d['booking_url'] ) : ?>
					<a class="mwp-book" href="<?php echo esc_url( $d['booking_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $t( 'Sprawdź i zarezerwuj', 'Check availability & book' ) ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( $d['description'] ) : ?>
				<div class="mwp-description"><?php echo wp_kses_post( $d['description'] ); ?></div>
			<?php endif; ?>

			<?php if ( $d['destinations'] ) : ?>
				<section class="mwp-section mwp-route">
					<h2><?php echo esc_html( $t( 'Trasa podróży', 'Your route' ) ); ?></h2>
					<ol class="mwp-route-list">
						<?php foreach ( $d['destinations'] as $dest ) : ?>
							<li>
								<span class="mwp-route-name"><?php echo esc_html( $dest['name'] ); ?></span>
								<?php if ( $dest['country'] ) : ?>
									<span class="mwp-route-country"><?php echo esc_html( $dest['country'] ); ?></span>
								<?php endif; ?>
								<?php if ( $dest['to_day'] ) : ?>
									<span class="mwp-route-days">
										<?php echo esc_html( sprintf( $t( 'dzień %1$d–%2$d', 'day %1$d–%2$d' ), $dest['from_day'], $dest['to_day'] ) ); ?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

			<?php foreach ( $d['tours'] as $tour ) : ?>
				<?php if ( ! $tour['description'] ) { continue; } ?>
				<section class="mwp-section mwp-tour">
					<h2><?php echo esc_html( $tour['name'] ); ?></h2>
					<div class="mwp-tour-body"><?php echo wp_kses_post( $tour['description'] ); ?></div>

					<?php if ( $tour['included'] || $tour['not_included'] ) : ?>
						<div class="mwp-inclusions">
							<?php if ( $tour['included'] ) : ?>
								<div class="mwp-included">
									<h3><?php echo esc_html( $t( 'W cenie', 'Included' ) ); ?></h3>
									<?php echo wp_kses_post( $tour['included'] ); ?>
								</div>
							<?php endif; ?>
							<?php if ( $tour['not_included'] ) : ?>
								<div class="mwp-not-included">
									<h3><?php echo esc_html( $t( 'Nie zawiera', 'Not included' ) ); ?></h3>
									<?php echo wp_kses_post( $tour['not_included'] ); ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<?php if ( $d['hotels'] ) : ?>
				<section class="mwp-section mwp-hotels">
					<h2><?php echo esc_html( $t( 'Hotele', 'Hotels' ) ); ?></h2>
					<ul class="mwp-hotel-list">
						<?php foreach ( $d['hotels'] as $hotel ) : ?>
							<li class="mwp-hotel">
								<?php if ( ! empty( $hotel['images'][0] ) ) : ?>
									<img class="mwp-hotel-img" src="<?php echo esc_url( $hotel['images'][0] ); ?>" alt="<?php echo esc_attr( $hotel['name'] ); ?>" loading="lazy" decoding="async">
								<?php endif; ?>
								<div class="mwp-hotel-body">
									<h3>
										<?php echo esc_html( $hotel['name'] ); ?>
										<?php if ( $hotel['stars'] ) : ?>
											<span class="mwp-stars" aria-label="<?php echo esc_attr( sprintf( $t( '%d gwiazdek', '%d stars' ), $hotel['stars'] ) ); ?>">
												<?php echo esc_html( str_repeat( '★', $hotel['stars'] ) ); ?>
											</span>
										<?php endif; ?>
									</h3>
									<?php if ( $hotel['destination'] || $hotel['nights'] ) : ?>
										<p class="mwp-hotel-meta">
											<?php
											$bits = array_filter( array(
												$hotel['destination'],
												$hotel['nights'] ? sprintf( $t( '%d nocy', '%d nights' ), $hotel['nights'] ) : '',
												$hotel['meal_plan'],
											) );
											echo esc_html( implode( ' · ', $bits ) );
											?>
										</p>
									<?php endif; ?>
									<?php if ( $hotel['score'] ) : ?>
										<p class="mwp-hotel-score">
											<strong><?php echo esc_html( number_format( $hotel['score']['score'], 1 ) ); ?></strong>/10
											<span class="mwp-hotel-source">
												<?php echo esc_html( sprintf( $t( '%1$s, %2$d opinii', '%1$s, %2$d reviews' ), $hotel['score']['source'], $hotel['score']['reviews'] ) ); ?>
											</span>
										</p>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $d['flights'] ) : ?>
				<section class="mwp-section mwp-flights">
					<h2><?php echo esc_html( $t( 'Przeloty', 'Flights' ) ); ?></h2>
					<ul class="mwp-flight-list">
						<?php foreach ( $d['flights'] as $f ) : ?>
							<li class="mwp-flight">
								<span class="mwp-flight-route"><?php echo esc_html( $f['from'] . ' → ' . $f['to'] ); ?></span>
								<span class="mwp-flight-airline"><?php echo esc_html( $f['airline'] ); ?></span>
								<?php if ( $f['duration'] ) : ?>
									<span class="mwp-flight-duration"><?php echo esc_html( $f['duration'] ); ?></span>
								<?php endif; ?>
								<?php if ( $f['baggage'] ) : ?>
									<span class="mwp-flight-bag"><?php echo esc_html( $f['baggage'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="mwp-flight-note"><?php echo esc_html( $t( 'Lotnisko wylotu i terminy możesz dowolnie zmienić.', 'Departure airport and dates are fully flexible.' ) ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( $d['activities'] ) : ?>
				<section class="mwp-section mwp-activities">
					<h2><?php echo esc_html( $t( 'W programie', 'Experiences included' ) ); ?></h2>
					<ul class="mwp-activity-list">
						<?php foreach ( $d['activities'] as $a ) : ?>
							<li class="mwp-activity">
								<?php if ( ! empty( $a['images'][0] ) ) : ?>
									<img src="<?php echo esc_url( $a['images'][0] ); ?>" alt="<?php echo esc_attr( $a['name'] ); ?>" loading="lazy" decoding="async">
								<?php endif; ?>
								<div>
									<h3><?php echo esc_html( $a['name'] ); ?></h3>
									<?php if ( $a['duration'] ) : ?>
										<p class="mwp-activity-meta"><?php echo esc_html( $a['duration'] ); ?></p>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( count( $d['gallery'] ) > 1 ) : ?>
				<section class="mwp-section mwp-gallery">
					<h2><?php echo esc_html( $t( 'Galeria', 'Gallery' ) ); ?></h2>
					<div class="mwp-gallery-grid">
						<?php foreach ( array_slice( $d['gallery'], 0, 12 ) as $url ) : ?>
							<img src="<?php echo esc_url( $url ); ?>" alt="" loading="lazy" decoding="async">
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $d['booking_url'] ) : ?>
				<div class="mwp-cta-final">
					<p><?php echo esc_html( $t( 'Ta podróż jest w pełni elastyczna — zmień hotele, terminy i lotnisko wylotu.', 'This trip is fully customisable — change hotels, dates and departure airport.' ) ); ?></p>
					<a class="mwp-book" href="<?php echo esc_url( $d['booking_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $t( 'Sprawdź i zarezerwuj', 'Check availability & book' ) ); ?>
					</a>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Hero image URL: manual override, then the imported featured image, then
	 * the Travel Compositor URL as a last resort.
	 *
	 * @param int   $post_id Post.
	 * @param array $data    Payload.
	 * @return string
	 */
	protected static function hero_url( $post_id, array $data ) {
		$override = get_post_meta( $post_id, MWP_META_IMAGE, true );
		if ( $override ) {
			return $override;
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$url = get_the_post_thumbnail_url( $post_id, 'large' );
			if ( $url ) {
				return $url;
			}
		}

		return $data['hero_image'];
	}
}
