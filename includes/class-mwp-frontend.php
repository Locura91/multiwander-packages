<?php
/**
 * Front end: the teaser cards and the package page.
 *
 * Nothing here calls the API. Everything comes from post meta written at sync
 * time, so page loads stay fast and a slow Travel Compositor can never slow
 * the site down — which is exactly what the old scraping shortcode did on
 * every uncached view.
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

	/**
	 * Load assets only on pages that actually use them.
	 *
	 * @return void
	 */
	public static function assets() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$is_package = (bool) get_post_meta( $post->ID, MWP_META_ID, true );
		$content    = (string) $post->post_content;
		$source     = MWP_Sync::source_country_id( $post->ID );
		$has_ids    = (bool) MWP_Sync::get_package_ids( $source );

		// Offers may be placed by the shortcode or appended automatically, so
		// the presence of IDs counts too — otherwise auto-placed cards would
		// render unstyled.
		$has_offers = has_shortcode( $content, 'multiwander_offers' ) || $has_ids;

		if ( ! $is_package && ! $has_offers ) {
			return;
		}

		$layout = (string) get_post_meta( $source, MWP_META_LAYOUT, true );

		wp_enqueue_style(
			'mwp-front',
			MWP_URL . 'assets/front.css',
			array(),
			MWPP_asset_version( 'assets/front.css' )
		);

		// The slider script is only worth its bytes where a slider exists.
		// With the automatic layout the shape is only known at render time, so
		// load the tiny slider script whenever a slider is possible.
		$maybe_slider = ( '' === $layout || 'auto' === $layout || 'slider' === $layout )
			|| false !== strpos( $content, 'layout="slider"' );

		if ( $has_offers && $maybe_slider ) {
			wp_enqueue_script(
				'mwp-slider',
				MWP_URL . 'assets/slider.js',
				array(),
				MWPP_asset_version( 'assets/slider.js' ),
				true
			);
		}
	}

	// -----------------------------------------------------------------
	// Teasers
	// -----------------------------------------------------------------

	/**
	 * [multiwander_offers] — teaser cards.
	 *
	 * Attributes:
	 *   layout   row (default) | slider | single
	 *   ids      explicit package IDs, overriding the page's own list
	 *   columns  2-4, row layout only
	 *   limit    maximum cards
	 *   heading  optional H2 above the cards
	 *
	 * Cards are built from the package pages themselves, so removing an ID
	 * from the meta box drops the card while leaving the page published and
	 * indexed. No dead URLs.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function offers_shortcode( $atts ) {
		// Settings live on the page, in the same box as the IDs. Shortcode
		// attributes are only an override for the odd case where one page
		// needs something different.
		$page   = get_post();
		$source = $page ? MWP_Sync::source_country_id( $page->ID ) : 0;

		$defaults = array(
			'layout'  => $source ? ( get_post_meta( $source, MWP_META_LAYOUT, true ) ?: 'auto' ) : 'auto',
			'ids'     => '',
			'columns' => 3,
			'limit'   => 0,
			'heading' => $source ? (string) get_post_meta( $source, MWP_META_HEADING, true ) : '',
			'sub'     => $source ? (string) get_post_meta( $source, MWP_META_SUB, true ) : '',
		);

		$atts = shortcode_atts( $defaults, $atts, 'multiwander_offers' );

		$layout = in_array( $atts['layout'], array( 'auto', 'row', 'slider', 'single', 'duo' ), true )
			? $atts['layout']
			: 'auto';

		$lang  = mwp_current_lang();
		$pages = self::resolve_pages( $atts['ids'], $lang );

		if ( 'single' === $layout ) {
			$pages = array_slice( $pages, 0, 1 );
		} elseif ( $atts['limit'] > 0 ) {
			$pages = array_slice( $pages, 0, (int) $atts['limit'] );
		}

		// Let the number of offers choose the shape. One card centred and not
		// stretched across the page; two side by side; three as a full desktop
		// row; four or more as a scrollable track, because a fourth card would
		// otherwise squeeze the others below a readable width.
		if ( 'auto' === $layout ) {
			$count = count( $pages );
			if ( $count <= 1 ) {
				$layout = 'single';
			} elseif ( 2 === $count ) {
				$layout = 'duo';
			} elseif ( 3 === $count ) {
				$layout = 'row';
			} else {
				$layout = 'slider';
			}
		}

		if ( ! $pages ) {
			// Say nothing to visitors rather than showing a broken row.
			return current_user_can( 'edit_posts' )
				? '<p class="mwp mwp-empty">' . esc_html__( 'No travel packages yet — add package IDs in the box below the editor and update the page.', 'multiwander-packages' ) . '</p>'
				: '';
		}

		$columns = max( 2, min( 4, (int) $atts['columns'] ) );

		$classes = array( 'mwp', 'mwp-offers', 'mwp-' . $layout );
		if ( 'row' === $layout ) {
			$classes[] = 'mwp-cols-' . $columns;
		}

		ob_start();
		?>
		<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<?php if ( $atts['heading'] ) : ?>
				<h2 class="mwp-offers-heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
			<?php endif; ?>

			<?php if ( $atts['sub'] ) : ?>
				<p class="mwp-offers-sub"><?php echo esc_html( $atts['sub'] ); ?></p>
			<?php endif; ?>

			<?php if ( 'slider' === $layout ) : ?>
				<button type="button" class="mwp-slider-nav mwp-prev" aria-label="<?php esc_attr_e( 'Previous', 'multiwander-packages' ); ?>">&#8249;</button>
				<button type="button" class="mwp-slider-nav mwp-next" aria-label="<?php esc_attr_e( 'Next', 'multiwander-packages' ); ?>">&#8250;</button>
			<?php endif; ?>

			<div class="mwp-grid">
				<?php foreach ( $pages as $page ) : ?>
					<?php self::render_card( $page, $lang ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( 'slider' === $layout ) : ?>
				<div class="mwp-dots" role="tablist" aria-label="<?php esc_attr_e( 'Choose slide', 'multiwander-packages' ); ?>"></div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Work out which package pages a shortcode should show.
	 *
	 * @param string $ids  Explicit ids attribute, possibly empty.
	 * @param string $lang Language slug.
	 * @return WP_Post[]
	 */
	protected static function resolve_pages( $ids, $lang ) {
		if ( $ids ) {
			$pages = array();
			foreach ( preg_split( '/[\s,]+/', $ids ) as $raw ) {
				$id = mwp_extract_id( $raw );
				if ( '' === $id ) {
					continue;
				}
				$found = MWP_Sync::find_package_page( $id, $lang );
				if ( $found && 'publish' === get_post_status( $found ) ) {
					$pages[] = get_post( $found );
				}
			}
			return $pages;
		}

		$post = get_post();
		if ( ! $post ) {
			return array();
		}

		// On an English page the IDs live on its Polish original.
		$source_id = $post->ID;
		if ( 'pl' !== $lang && function_exists( 'pll_get_post' ) ) {
			$pl = pll_get_post( $post->ID, 'pl' );
			if ( $pl ) {
				$source_id = $pl;
			}
		}

		return MWP_Sync::get_package_pages( $source_id, $lang );
	}

	/**
	 * One teaser card.
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

		$en    = ( 'en' === $lang );
		$price = mwp_format_price( $data['price'], $lang );
		$image = self::hero_url( $page->ID, $data );
		$url   = get_permalink( $page );
		$title = get_the_title( $page );

		// Small line above the title: the package's own first theme, falling
		// back to the ribbon so the slot is never empty.
		$kicker = ! empty( $data['themes'][0] ) ? $data['themes'][0] : $data['ribbon'];

		// One descriptive line: where you go, then what is in it.
		$stops = wp_list_pluck( $data['destinations'], 'name' );
		$route = $stops ? implode( ' · ', array_slice( $stops, 0, 4 ) ) : '';
		if ( count( $stops ) > 4 ) {
			$route .= ' …';
		}

		$bits = array();
		if ( $data['duration']['days'] ) {
			$bits[] = sprintf( $en ? '%d days' : '%d dni', $data['duration']['days'] );
		}

		$stars = 0;
		foreach ( $data['hotels'] as $hotel ) {
			$stars = max( $stars, (int) $hotel['stars'] );
		}
		if ( $stars ) {
			$bits[] = $stars . '★ ' . ( $en ? 'hotels' : 'hotele' );
		}
		if ( $data['flights'] ) {
			$bits[] = $en ? 'flights included' : 'z przelotem';
		}

		$summary = trim( $route . ( $bits ? ' — ' . implode( ', ', $bits ) : '' ) );
		?>
		<article class="mwp-card">
			<a class="mwp-card-link" href="<?php echo esc_url( $url ); ?>">

				<div class="mwp-card-media">
					<?php if ( $image ) : ?>
						<img src="<?php echo esc_url( $image ); ?>"
							alt="<?php echo esc_attr( sprintf( $en ? 'Trip: %s' : 'Podróż: %s', $title ) ); ?>"
							loading="lazy" decoding="async">
					<?php endif; ?>
					<?php if ( $data['ribbon'] ) : ?>
						<span class="mwp-ribbon"><?php echo esc_html( $data['ribbon'] ); ?></span>
					<?php endif; ?>
				</div>

				<div class="mwp-card-body">
					<?php if ( $kicker ) : ?>
						<p class="mwp-kicker"><?php echo esc_html( $kicker ); ?></p>
					<?php endif; ?>

					<h3 class="mwp-card-title"><?php echo esc_html( $title ); ?></h3>

					<?php if ( $summary ) : ?>
						<p class="mwp-card-summary"><?php echo esc_html( $summary ); ?></p>
					<?php endif; ?>

					<div class="mwp-card-foot">
						<?php if ( $price['value'] ) : ?>
							<p class="mwp-price">
								<span class="mwp-price-from"><?php echo esc_html( $en ? 'from' : 'od' ); ?></span>
								<span class="mwp-price-amount"><?php echo esc_html( $price['value'] ); ?> <?php echo esc_html( $price['symbol'] ); ?></span>
								<span class="mwp-price-per"><?php echo esc_html( $en ? 'p. p.' : 'os.' ); ?></span>
							</p>
						<?php else : ?>
							<span></span>
						<?php endif; ?>

						<span class="cta-button mwp-pill"><?php echo esc_html( $en ? 'View' : 'Zobacz' ); ?></span>
					</div>
				</div>
			</a>
		</article>
		<?php
	}

	// -----------------------------------------------------------------
	// Package page
	// -----------------------------------------------------------------

	/**
	 * Append the package layout to the page content.
	 *
	 * Done through the_content rather than a bundled page template so the page
	 * keeps whatever theme template the rest of the site uses, and an editor
	 * can still write their own copy above it.
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
			// Not a package page — but it may be a country page whose offers
			// should be placed automatically. The shortcode is only needed
			// when the section has to sit at a particular spot in the content.
			return self::maybe_append_offers( $content, $post_id );
		}

		$data = MWP_Sync::get_data( $post_id );
		if ( ! $data ) {
			return $content;
		}

		ob_start();
		self::render_package( $post_id, $data, mwp_current_lang() );
		$package = ob_get_clean();

		// The package layout comes FIRST and whatever the editor writes in the
		// page follows it. That way anything added by hand — an FAQ block, a
		// note, an advisor card — lands underneath the trip details, which is
		// where extra content belongs on a product page.
		$extra = trim( $content );

		if ( '' !== $extra ) {
			$extra = '<div class="mwp mwp-extra">' . $content . '</div>';
		}

		return $package . $extra;
	}

	/**
	 * Place a country page's offers automatically.
	 *
	 * If the editor has entered package IDs but not placed the shortcode
	 * anywhere, the section is appended to the content so the offers appear
	 * without them having to touch a block. Adding the shortcode gives them
	 * control over exactly where it sits instead.
	 *
	 * @param string $content Page content.
	 * @param int    $post_id Page.
	 * @return string
	 */
	protected static function maybe_append_offers( $content, $post_id ) {
		if ( has_shortcode( $content, 'multiwander_offers' ) ) {
			return $content;
		}

		$source = MWP_Sync::source_country_id( $post_id );
		if ( ! MWP_Sync::get_package_ids( $source ) ) {
			return $content;
		}

		if ( ! apply_filters( 'mwp_auto_place_offers', true, $post_id ) ) {
			return $content;
		}

		return $content . self::offers_shortcode( array() );
	}

	/**
	 * The package layout.
	 *
	 * Heading order is deliberate: one H1 in the hero, H2 per section. If the
	 * active page template also prints the title there would be two H1s, which
	 * is why the sync applies the "no title" template chosen in settings.
	 *
	 * @param int    $post_id Post.
	 * @param array  $d       Payload.
	 * @param string $lang    Language slug.
	 * @return void
	 */
	protected static function render_package( $post_id, array $d, $lang ) {
		$en = ( 'en' === $lang );
		$t  = function ( $pl, $english ) use ( $en ) {
			return $en ? $english : $pl;
		};

		$price   = mwp_format_price( $d['price'], $lang );
		$hero    = self::hero_url( $post_id, $d );
		$title   = get_the_title( $post_id );
		$parent  = wp_get_post_parent_id( $post_id );
		$booking = mwp_booking_url( $d, $lang );
		$items   = MWP_Timeline::build( $d, $lang );
		$adults  = max( 1, (int) $d['counters']['adults'] );
		?>
		<div class="mwp mwp-package">

			<?php
			$shots = array();
			if ( $hero ) {
				$shots[] = $hero;
			}
			foreach ( $d['gallery'] as $g ) {
				if ( count( $shots ) >= 3 ) {
					break;
				}
				if ( ! in_array( $g, $shots, true ) ) {
					$shots[] = $g;
				}
			}
			?>

			<?php if ( $shots ) : ?>
				<div class="mwp-collage mwp-collage-<?php echo esc_attr( count( $shots ) ); ?>">
					<?php foreach ( $shots as $i => $shot ) : ?>
						<figure class="mwp-shot">
							<img src="<?php echo esc_url( $shot ); ?>"
								alt="<?php echo esc_attr( $title ); ?>"
								<?php echo 0 === $i ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
								decoding="async">
						</figure>
					<?php endforeach; ?>

					<?php if ( count( $d['gallery'] ) > 3 ) : ?>
						<a class="mwp-gallery-btn" href="#mwp-gallery">
							<?php echo esc_html( $t( 'Galeria zdjęć', 'Photo gallery' ) ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<header class="mwp-head">
				<?php if ( $d['ribbon'] ) : ?>
					<span class="mwp-eyebrow"><?php echo esc_html( $d['ribbon'] ); ?></span>
				<?php endif; ?>

				<h1 class="mwp-title"><?php echo esc_html( $title ); ?></h1>

				<?php
				$summary = array();
				if ( $d['duration']['days'] ) {
					$summary[] = sprintf( $t( '%d dni', '%d days' ), $d['duration']['days'] );
				}
				if ( $d['destinations'] ) {
					$first = $d['destinations'][0]['name'];
					$last  = $d['destinations'][ count( $d['destinations'] ) - 1 ]['name'];
					$summary[] = $first === $last
						? $first
						: sprintf( $t( 'od %1$s do %2$s', 'from %1$s to %2$s' ), $first, $last );
				}
				?>
				<?php if ( $summary ) : ?>
					<p class="mwp-subline"><?php echo esc_html( implode( ', ', $summary ) ); ?></p>
				<?php endif; ?>
			</header>

			<div class="mwp-top">

				<aside class="mwp-aside">
					<div class="mwp-pricecard">
						<?php if ( $booking ) : ?>
							<div class="mwp-actions">
								<a class="cta-button mwp-btn mwp-btn-primary" href="<?php echo esc_url( $booking ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $t( 'Zarezerwuj teraz', 'Book now' ) ); ?>
								</a>
								<a class="mwp-btn" href="<?php echo esc_url( $booking ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $t( 'Dostosuj podróż', 'Configure this trip' ) ); ?>
								</a>
								<?php $enquiry = mwp_enquiry_url( $lang ); ?>
								<?php if ( $enquiry ) : ?>
									<a class="mwp-btn" href="<?php echo esc_url( $enquiry ); ?>">
										<?php echo esc_html( $t( 'Zapytaj o ofertę', 'Request advice' ) ); ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( $price['value'] ) : ?>
							<p class="mwp-bar-amount">
								<span class="mwp-from-word"><?php echo esc_html( $t( 'od', 'from' ) ); ?></span>
								<?php echo esc_html( $price['value'] ); ?> <?php echo esc_html( $price['symbol'] ); ?>
							</p>
							<p class="mwp-price-basis">
								<?php
								echo esc_html( sprintf(
									$t( 'za osobę, przy %d osobach dorosłych', 'per person, based on %d adults' ),
									$adults
								) );
								?>
							</p>
						<?php endif; ?>

						<ul class="mwp-facts">
							<?php if ( $d['duration']['days'] ) : ?>
								<li class="mwp-fact"><b><?php echo esc_html( $d['duration']['days'] ); ?></b><span><?php echo esc_html( $t( 'dni', 'days' ) ); ?></span></li>
							<?php endif; ?>
							<?php if ( $d['duration']['nights'] ) : ?>
								<li class="mwp-fact"><b><?php echo esc_html( $d['duration']['nights'] ); ?></b><span><?php echo esc_html( $t( 'nocy', 'nights' ) ); ?></span></li>
							<?php endif; ?>
							<?php if ( $d['destinations'] ) : ?>
								<li class="mwp-fact"><b><?php echo esc_html( count( $d['destinations'] ) ); ?></b><span><?php echo esc_html( $t( 'miejsc', 'stops' ) ); ?></span></li>
							<?php endif; ?>
							<?php if ( $d['flights'] ) : ?>
								<li class="mwp-fact"><b><?php echo esc_html( count( $d['flights'] ) ); ?></b><span><?php echo esc_html( $t( 'przeloty', 'flights' ) ); ?></span></li>
							<?php endif; ?>
						</ul>

						<p class="mwp-note">
							<?php
							echo esc_html(
								$d['departures']['is_empty']
									? $t( 'Wylot w dowolnym terminie.', 'Departs any day you choose.' )
									: sprintf(
										$t( 'Najbliższy wylot: %s', 'Next departure: %s' ),
										date_i18n( get_option( 'date_format' ), strtotime( $d['departures']['first'] ) )
									)
							);
							?>
						</p>
					</div>
				</aside>

				<div class="mwp-main">

					<?php if ( $d['destinations'] ) : ?>
						<p class="mwp-dests">
							<b><?php echo esc_html( $t( 'Destynacje:', 'Destinations:' ) ); ?></b>
							<?php
							$labels = array();
							foreach ( $d['destinations'] as $dest ) {
								$labels[] = $dest['country']
									? $dest['name'] . ', ' . $dest['country']
									: $dest['name'];
							}
							echo esc_html( implode( ' · ', $labels ) );
							?>
						</p>
					<?php endif; ?>

					<?php if ( $d['themes'] ) : ?>
						<ul class="mwp-themes">
							<?php foreach ( $d['themes'] as $theme ) : ?>
								<li><?php echo esc_html( $theme ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $d['description'] ) : ?>
						<section class="mwp-section">
							<h2><?php echo esc_html( $t( 'Opis podróży', 'About this trip' ) ); ?></h2>
							<div class="mwp-prose"><?php echo wp_kses_post( $d['description'] ); ?></div>
						</section>
					<?php endif; ?>

					<?php if ( $items ) : ?>
						<section class="mwp-section mwp-plan">
							<h2><?php echo esc_html( $t( 'Szczegółowy plan podróży', 'Your detailed trip plan' ) ); ?></h2>
							<p class="mwp-section-intro">
								<?php echo esc_html( $t( 'Przykładowy przebieg — każdy przelot, hotel i dzień możesz zmienić.', 'A suggested sequence — every flight, hotel and day can be changed.' ) ); ?>
							</p>

							<?php
							$counts = array_filter( array(
								$t( 'Destynacje', 'Destinations' )       => count( $d['destinations'] ),
								$t( 'Transport', 'Transport' )           => $d['counters']['transports'],
								$t( 'Zakwaterowanie', 'Accommodation' )  => count( $d['hotels'] ),
								$t( 'Transfery', 'Transfers' )           => $d['counters']['transfers'],
							) );
							?>
							<?php if ( $counts ) : ?>
								<ul class="mwp-includes">
									<?php foreach ( $counts as $label => $n ) : ?>
										<li><b><?php echo esc_html( $n ); ?></b><span><?php echo esc_html( $label ); ?></span></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>

							<ol class="mwp-plan-list">
								<?php foreach ( $items as $item ) : ?>
									<li class="mwp-item mwp-item-<?php echo esc_attr( $item['kind'] ); ?>">

										<div class="mwp-item-head">
											<span class="mwp-chip">
												<?php if ( $item['date'] ) : ?>
													<b><?php echo esc_html( date_i18n( 'd', $item['date'] ) ); ?></b>
													<span><?php echo esc_html( date_i18n( 'M', $item['date'] ) ); ?></span>
												<?php else : ?>
													<b><?php echo esc_html( $item['day'] ); ?></b>
													<span><?php echo esc_html( $t( 'dzień', 'day' ) ); ?></span>
												<?php endif; ?>
											</span>

											<h3 class="mwp-item-title"><?php echo esc_html( $item['title'] ); ?></h3>

											<span class="mwp-item-badge"><?php echo esc_html( $item['badge'] ); ?></span>
										</div>

										<div class="mwp-item-body">
											<?php if ( ! empty( $item['images'] ) ) : ?>
												<div class="mwp-item-gallery">
													<?php foreach ( $item['images'] as $img ) : ?>
														<img src="<?php echo esc_url( $img ); ?>"
															alt="<?php echo esc_attr( $item['title'] ); ?>"
															loading="lazy" decoding="async">
													<?php endforeach; ?>
												</div>
											<?php endif; ?>

											<div class="mwp-item-detail">
												<?php if ( $item['lead'] ) : ?>
													<p class="mwp-item-lead"><?php echo esc_html( $item['lead'] ); ?></p>
												<?php endif; ?>

												<?php if ( ! empty( $item['stars'] ) || ! empty( $item['score'] ) ) : ?>
													<p class="mwp-item-rating">
														<?php if ( ! empty( $item['stars'] ) ) : ?>
															<span class="mwp-stars"><?php echo esc_html( str_repeat( '★', (int) $item['stars'] ) ); ?></span>
														<?php endif; ?>
														<?php if ( ! empty( $item['score'] ) ) : ?>
															<span class="mwp-badge-score"><?php echo esc_html( number_format_i18n( $item['score']['score'], 1 ) ); ?></span>
															<span class="mwp-score-note">
																<?php echo esc_html( sprintf( $t( '%1$s · %2$s opinii', '%1$s · %2$s reviews' ), $item['score']['source'], number_format_i18n( $item['score']['reviews'] ) ) ); ?>
															</span>
														<?php endif; ?>
													</p>
												<?php endif; ?>

												<?php if ( ! empty( $item['meta'] ) ) : ?>
													<ul class="mwp-tags">
														<?php foreach ( $item['meta'] as $tag ) : ?>
															<li><?php echo esc_html( $tag ); ?></li>
														<?php endforeach; ?>
													</ul>
												<?php endif; ?>

												<?php if ( ! empty( $item['body'] ) ) : ?>
													<div class="mwp-item-text mwp-prose"><?php echo wp_kses_post( $item['body'] ); ?></div>
												<?php endif; ?>
											</div>
										</div>
									</li>
								<?php endforeach; ?>
							</ol>
						</section>
					<?php endif; ?>

					<?php foreach ( $d['tours'] as $tour ) : ?>
						<?php if ( ! $tour['included'] && ! $tour['not_included'] ) { continue; } ?>
						<section class="mwp-section">
							<h2><?php echo esc_html( $t( 'Co zawiera cena', 'What is included' ) ); ?></h2>
							<div class="mwp-inclusions">
								<?php if ( $tour['included'] ) : ?>
									<div class="mwp-inc">
										<h3><?php echo esc_html( $t( 'W cenie', 'Included' ) ); ?></h3>
										<?php echo wp_kses_post( $tour['included'] ); ?>
									</div>
								<?php endif; ?>
								<?php if ( $tour['not_included'] ) : ?>
									<div class="mwp-exc">
										<h3><?php echo esc_html( $t( 'Nie zawiera', 'Not included' ) ); ?></h3>
										<?php echo wp_kses_post( $tour['not_included'] ); ?>
									</div>
								<?php endif; ?>
							</div>
						</section>
					<?php endforeach; ?>

					<?php if ( count( $d['gallery'] ) > 3 ) : ?>
						<section class="mwp-section" id="mwp-gallery">
							<h2><?php echo esc_html( sprintf( $t( '%s w obrazach', '%s in pictures' ), $title ) ); ?></h2>
							<div class="mwp-gallery">
								<?php foreach ( array_slice( $d['gallery'], 0, 12 ) as $i => $url ) : ?>
									<img src="<?php echo esc_url( $url ); ?>"
										alt="<?php echo esc_attr( sprintf( '%s — %d', $title, $i + 1 ) ); ?>"
										loading="lazy" decoding="async">
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<section class="mwp-final">
						<h2><?php echo esc_html( $t( 'Ta podróż, po Twojemu', 'Make this trip yours' ) ); ?></h2>
						<p>
							<?php echo esc_html( $t( 'Zmień terminy, hotele, lotnisko wylotu i długość pobytu w każdym miejscu. Sprawdź aktualną cenę i dostępność w kilka sekund.', 'Change the dates, the hotels, your departure airport and how long you stay in each place. Check live pricing and availability in seconds.' ) ); ?>
						</p>
						<?php if ( $booking ) : ?>
							<a class="cta-button mwp-btn mwp-btn-primary mwp-btn-wide" href="<?php echo esc_url( $booking ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $t( 'Zarezerwuj teraz', 'Book now' ) ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $parent ) : ?>
							<br>
							<a class="mwp-back" href="<?php echo esc_url( get_permalink( $parent ) ); ?>">
								<?php echo esc_html( sprintf( $t( '← Więcej podróży: %s', '← More trips: %s' ), get_the_title( $parent ) ) ); ?>
							</a>
						<?php endif; ?>
					</section>

				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Hero image: manual override, then the imported featured image, then the
	 * Travel Compositor URL as a last resort.
	 *
	 * @param int   $post_id Post.
	 * @param array $data    Payload.
	 * @return string
	 */
	public static function hero_url( $post_id, array $data ) {
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

		if ( ! empty( $data['hero_image'] ) ) {
			return $data['hero_image'];
		}

		// Last resort: the first gallery image, so a package without its own
		// hero still leads with a picture rather than a blank block.
		return ! empty( $data['gallery'][0] ) ? $data['gallery'][0] : '';
	}
}
