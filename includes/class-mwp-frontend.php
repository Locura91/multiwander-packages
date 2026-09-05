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
		$has_offers = has_shortcode( $content, 'multiwander_offers' );

		if ( ! $is_package && ! $has_offers ) {
			return;
		}

		wp_enqueue_style(
			'mwp-front',
			MWP_URL . 'assets/front.css',
			array(),
			MWPP_asset_version( 'assets/front.css' )
		);

		// The slider script is only worth its bytes where a slider exists.
		if ( $has_offers && false !== strpos( $content, 'slider' ) ) {
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
		$atts = shortcode_atts(
			array(
				'layout'  => 'row',
				'ids'     => '',
				'columns' => 3,
				'limit'   => 0,
				'heading' => '',
			),
			$atts,
			'multiwander_offers'
		);

		$layout = in_array( $atts['layout'], array( 'row', 'slider', 'single' ), true )
			? $atts['layout']
			: 'row';

		$lang  = mwp_current_lang();
		$pages = self::resolve_pages( $atts['ids'], $lang );

		if ( 'single' === $layout ) {
			$pages = array_slice( $pages, 0, 1 );
		} elseif ( $atts['limit'] > 0 ) {
			$pages = array_slice( $pages, 0, (int) $atts['limit'] );
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

		$stops = wp_list_pluck( $data['destinations'], 'name' );
		$route = $stops ? implode( ' · ', array_slice( $stops, 0, 5 ) ) : '';
		if ( count( $stops ) > 5 ) {
			$route .= ' …';
		}

		$chips = array();
		if ( $data['duration']['days'] ) {
			$chips[] = sprintf( $en ? '%d days' : '%d dni', $data['duration']['days'] );
		}
		if ( count( $data['destinations'] ) ) {
			$chips[] = sprintf(
				$en ? '%d stops' : '%d miejsc',
				count( $data['destinations'] )
			);
		}
		if ( $data['flights'] ) {
			$chips[] = $en ? 'Flights included' : 'Z przelotem';
		}
		?>
		<article class="mwp-card">
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
				<h3 class="mwp-card-title">
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
				</h3>

				<?php if ( $route ) : ?>
					<p class="mwp-route-line"><?php echo esc_html( $route ); ?></p>
				<?php endif; ?>

				<?php if ( $chips ) : ?>
					<ul class="mwp-chips">
						<?php foreach ( $chips as $chip ) : ?>
							<li class="mwp-chip"><?php echo esc_html( $chip ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="mwp-card-foot">
					<?php if ( $price['value'] ) : ?>
						<p class="mwp-price">
							<span class="mwp-price-label"><?php echo esc_html( $en ? 'from' : 'już od' ); ?></span>
							<span class="mwp-price-amount"><?php echo esc_html( $price['value'] ); ?> <?php echo esc_html( $price['symbol'] ); ?></span>
							<span class="mwp-price-per"><?php echo esc_html( $en ? 'per person' : 'od osoby' ); ?></span>
						</p>
					<?php else : ?>
						<span></span>
					<?php endif; ?>

					<span class="mwp-cta"><?php echo esc_html( $en ? 'View trip' : 'Zobacz podróż' ); ?></span>
				</div>
			</div>
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
			return $content;
		}

		$data = MWP_Sync::get_data( $post_id );
		if ( ! $data ) {
			return $content;
		}

		ob_start();
		self::render_package( $post_id, $data, mwp_current_lang() );
		return $content . ob_get_clean();
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

		$price  = mwp_format_price( $d['price'], $lang );
		$hero   = self::hero_url( $post_id, $d );
		$title  = get_the_title( $post_id );
		$parent = wp_get_post_parent_id( $post_id );
		?>
		<div class="mwp mwp-package">

			<header class="mwp-hero">
				<?php if ( $hero ) : ?>
					<img src="<?php echo esc_url( $hero ); ?>"
						alt="<?php echo esc_attr( $title ); ?>"
						fetchpriority="high" decoding="async">
				<?php endif; ?>
				<div class="mwp-hero-overlay">
					<?php if ( $d['ribbon'] ) : ?>
						<span class="mwp-eyebrow"><?php echo esc_html( $d['ribbon'] ); ?></span>
					<?php endif; ?>
					<h1 class="mwp-title"><?php echo esc_html( $title ); ?></h1>
				</div>
			</header>

			<div class="mwp-bar">
				<?php if ( $price['value'] ) : ?>
					<p class="mwp-bar-price">
						<span class="mwp-price-label"><?php echo esc_html( $t( 'już od', 'from' ) ); ?></span>
						<span class="mwp-bar-amount"><?php echo esc_html( $price['value'] ); ?> <?php echo esc_html( $price['symbol'] ); ?></span>
						<span class="mwp-price-per"><?php echo esc_html( $t( 'od osoby', 'per person' ) ); ?></span>
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
					<?php if ( $d['hotels'] ) : ?>
						<li class="mwp-fact"><b><?php echo esc_html( count( $d['hotels'] ) ); ?></b><span><?php echo esc_html( $t( 'hotele', 'hotels' ) ); ?></span></li>
					<?php endif; ?>
				</ul>

				<?php if ( $d['booking_url'] ) : ?>
					<a class="mwp-cta" href="<?php echo esc_url( $d['booking_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $t( 'Sprawdź termin i cenę', 'Check dates & price' ) ); ?>
					</a>
				<?php endif; ?>

				<p class="mwp-note">
					<?php
					echo esc_html(
						$d['departures']['is_empty']
							? $t( 'Wylot w dowolnym terminie — trasę, hotele i lotnisko dopasujemy do Ciebie.', 'Departs any day — route, hotels and departure airport all adjustable.' )
							: sprintf(
								$t( 'Najbliższy wylot: %s. Trasę i hotele dopasujemy do Ciebie.', 'Next departure: %s. Route and hotels fully adjustable.' ),
								date_i18n( get_option( 'date_format' ), strtotime( $d['departures']['first'] ) )
							)
					);
					?>
				</p>
			</div>

			<?php if ( $d['description'] ) : ?>
				<section class="mwp-section">
					<h2><?php echo esc_html( sprintf( $t( 'O podróży: %s', 'About this trip: %s' ), $title ) ); ?></h2>
					<div class="mwp-prose"><?php echo wp_kses_post( $d['description'] ); ?></div>
				</section>
			<?php endif; ?>

			<?php if ( $d['destinations'] ) : ?>
				<section class="mwp-section">
					<h2><?php echo esc_html( $t( 'Trasa', 'The route' ) ); ?></h2>
					<p class="mwp-section-intro">
						<?php echo esc_html( $t( 'Każdy przystanek możesz wydłużyć, skrócić lub zamienić.', 'Every stop can be extended, shortened or swapped.' ) ); ?>
					</p>
					<ol class="mwp-route">
						<?php foreach ( $d['destinations'] as $stop ) : ?>
							<li class="mwp-stop">
								<b><?php echo esc_html( $stop['name'] ); ?></b>
								<span>
									<?php
									$bits = array_filter( array(
										$stop['country'],
										$stop['to_day'] ? sprintf( $t( 'dzień %1$d–%2$d', 'day %1$d–%2$d' ), $stop['from_day'], $stop['to_day'] ) : '',
									) );
									echo esc_html( implode( ' · ', $bits ) );
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endif; ?>

			<?php foreach ( $d['tours'] as $tour ) : ?>
				<?php if ( ! $tour['description'] ) { continue; } ?>
				<section class="mwp-section">
					<h2><?php echo esc_html( $tour['name'] ); ?></h2>
					<div class="mwp-prose"><?php echo wp_kses_post( $tour['description'] ); ?></div>

					<?php if ( $tour['included'] || $tour['not_included'] ) : ?>
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
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<?php if ( $d['hotels'] ) : ?>
				<section class="mwp-section">
					<h2><?php echo esc_html( $t( 'Hotele w tej podróży', 'Where you stay' ) ); ?></h2>
					<p class="mwp-section-intro">
						<?php echo esc_html( $t( 'Przykładowy dobór hoteli — każdy możesz wymienić na inny.', 'A suggested selection — any hotel can be swapped.' ) ); ?>
					</p>
					<ul class="mwp-hotels">
						<?php foreach ( $d['hotels'] as $hotel ) : ?>
							<li class="mwp-hotel">
								<?php if ( ! empty( $hotel['images'][0] ) ) : ?>
									<img src="<?php echo esc_url( $hotel['images'][0] ); ?>"
										alt="<?php echo esc_attr( sprintf( $t( 'Hotel %1$s, %2$s', '%1$s hotel in %2$s' ), $hotel['name'], $hotel['destination'] ) ); ?>"
										loading="lazy" decoding="async">
								<?php endif; ?>
								<div class="mwp-hotel-body">
									<h3>
										<?php echo esc_html( $hotel['name'] ); ?>
										<?php if ( $hotel['stars'] ) : ?>
											<span class="mwp-stars" aria-label="<?php echo esc_attr( sprintf( $t( '%d gwiazdek', '%d stars' ), $hotel['stars'] ) ); ?>"><?php echo esc_html( str_repeat( '★', $hotel['stars'] ) ); ?></span>
										<?php endif; ?>
									</h3>
									<?php
									$meta = array_filter( array(
										$hotel['destination'],
										$hotel['nights'] ? sprintf( $t( '%d nocy', '%d nights' ), $hotel['nights'] ) : '',
										$hotel['meal_plan'],
										$hotel['room_type'],
									) );
									?>
									<?php if ( $meta ) : ?>
										<p class="mwp-hotel-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
									<?php endif; ?>
									<?php if ( $hotel['score'] ) : ?>
										<p class="mwp-score">
											<b><?php echo esc_html( number_format_i18n( $hotel['score']['score'], 1 ) ); ?></b>/10
											<?php echo esc_html( sprintf( $t( '%1$s · %2$s opinii', '%1$s · %2$s reviews' ), $hotel['score']['source'], number_format_i18n( $hotel['score']['reviews'] ) ) ); ?>
										</p>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $d['flights'] ) : ?>
				<section class="mwp-section">
					<h2><?php echo esc_html( $t( 'Przeloty', 'Flights' ) ); ?></h2>
					<p class="mwp-section-intro">
						<?php echo esc_html( $t( 'Lotnisko wylotu jest dowolne — Kraków, Warszawa, Wrocław, Berlin i inne.', 'Depart from any airport — Kraków, Warsaw, Wrocław, Berlin and more.' ) ); ?>
					</p>
					<ul class="mwp-flights">
						<?php foreach ( $d['flights'] as $f ) : ?>
							<li class="mwp-flight">
								<b><?php echo esc_html( $f['from'] . ' → ' . $f['to'] ); ?></b>
								<?php echo esc_html( $f['airline'] ); ?>
								<?php if ( $f['duration'] ) : ?>
									<span><?php echo esc_html( $f['duration'] ); ?></span>
								<?php endif; ?>
								<?php if ( $f['baggage'] ) : ?>
									<span><?php echo esc_html( $f['baggage'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( $d['activities'] ) : ?>
				<section class="mwp-section">
					<h2><?php echo esc_html( $t( 'W programie', 'What you will do' ) ); ?></h2>
					<ul class="mwp-hotels">
						<?php foreach ( $d['activities'] as $a ) : ?>
							<li class="mwp-hotel">
								<?php if ( ! empty( $a['images'][0] ) ) : ?>
									<img src="<?php echo esc_url( $a['images'][0] ); ?>" alt="<?php echo esc_attr( $a['name'] ); ?>" loading="lazy" decoding="async">
								<?php endif; ?>
								<div class="mwp-hotel-body">
									<h3><?php echo esc_html( $a['name'] ); ?></h3>
									<?php if ( $a['duration'] ) : ?>
										<p class="mwp-hotel-meta"><?php echo esc_html( $a['duration'] ); ?></p>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( count( $d['gallery'] ) > 3 ) : ?>
				<section class="mwp-section">
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
				<?php if ( $d['booking_url'] ) : ?>
					<a class="mwp-cta" href="<?php echo esc_url( $d['booking_url'] ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( $t( 'Sprawdź termin i cenę', 'Check dates & price' ) ); ?>
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

		return isset( $data['hero_image'] ) ? $data['hero_image'] : '';
	}
}
