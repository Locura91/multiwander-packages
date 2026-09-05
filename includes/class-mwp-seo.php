<?php
/**
 * SEO for package pages.
 *
 * Yoast handles titles, canonicals and sitemaps perfectly well on its own.
 * What it cannot do is describe a travel package to a search engine — that
 * needs schema.org markup built from the actual package data, which is what
 * this class emits.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_SEO {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'json_ld' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'faq_json_ld' ), 20 );
	}

	/**
	 * Turn a hand-written FAQ block into FAQPage structured data.
	 *
	 * The theme's country pages already mark FAQs up as
	 *
	 *     <div class="faq-item">
	 *       <h3 class="faq-question">…</h3>
	 *       <p class="faq-answer">…</p>
	 *     </div>
	 *
	 * so the same HTML pasted onto a package page is picked up automatically —
	 * no shortcode, no second place to maintain the questions.
	 *
	 * Worth knowing: Google now shows FAQ rich results only for a narrow set of
	 * authoritative sites, so treat this as machine-readability for AI answer
	 * engines and future crawlers rather than a guaranteed search feature. It
	 * costs nothing and the questions are already written.
	 *
	 * Emitted in the footer because it reads the rendered page content.
	 *
	 * @return void
	 */
	public static function faq_json_ld() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post || false === strpos( (string) $post->post_content, 'faq-question' ) ) {
			return;
		}

		$pairs = self::extract_faqs( $post->post_content );
		if ( count( $pairs ) < 2 ) {
			// One lone question is not an FAQ page; don't claim it is.
			return;
		}

		$entities = array();
		foreach ( $pairs as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['a'],
				),
			);
		}

		echo "\n" . '<script type="application/ld+json">' .
			wp_json_encode(
				array(
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'@id'        => get_permalink( $post ) . '#faq',
					'mainEntity' => $entities,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) .
			"</script>\n";
	}

	/**
	 * Pull question/answer pairs out of the theme's FAQ markup.
	 *
	 * @param string $html Page content.
	 * @return array<int,array{q:string,a:string}>
	 */
	protected static function extract_faqs( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return array();
		}

		$html = do_shortcode( $html );

		$doc = new DOMDocument();
		// Suppress the warnings malformed page HTML would otherwise raise.
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="UTF-8"><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $doc );

		$questions = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' faq-question ')]" );
		$answers   = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' faq-answer ')]" );

		if ( ! $questions || ! $answers ) {
			return array();
		}

		$out = array();
		$n   = min( $questions->length, $answers->length );

		for ( $i = 0; $i < $n; $i++ ) {
			$q = trim( preg_replace( '/\s+/u', ' ', $questions->item( $i )->textContent ) );
			$a = trim( preg_replace( '/\s+/u', ' ', $answers->item( $i )->textContent ) );

			if ( '' !== $q && '' !== $a ) {
				$out[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}

		return $out;
	}

	/**
	 * Emit TouristTrip structured data on package pages.
	 *
	 * TouristTrip is the closest schema.org type to a multi-stop holiday
	 * package: it carries the price offer, the trip length and the places
	 * visited, which is what Google needs to show anything richer than a
	 * plain blue link.
	 *
	 * @return void
	 */
	public static function json_ld() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id || ! get_post_meta( $post_id, MWP_META_ID, true ) ) {
			return;
		}

		$d = MWP_Sync::get_data( $post_id );
		if ( ! $d ) {
			return;
		}

		$lang = mwp_current_lang();

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'TouristTrip',
			'@id'         => get_permalink( $post_id ) . '#trip',
			'name'        => get_the_title( $post_id ),
			'url'         => get_permalink( $post_id ),
			'description' => self::plain_description( $d, 300 ),
			'provider'    => array(
				'@type' => 'TravelAgency',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);

		$hero = MWP_Frontend::hero_url( $post_id, $d );
		if ( $hero ) {
			$schema['image'] = $hero;
		}

		// ISO 8601 duration — "P13D" for a thirteen-day trip.
		if ( $d['duration']['days'] > 0 ) {
			$schema['itinerary']['@type'] = 'ItemList';
			$schema['subjectOf']          = array(
				'@type' => 'CreativeWork',
				'about' => sprintf( '%d days', $d['duration']['days'] ),
			);
			$schema['duration'] = 'P' . (int) $d['duration']['days'] . 'D';
		}

		// The places visited, in order.
		if ( $d['destinations'] ) {
			$elements = array();
			$position = 1;
			foreach ( $d['destinations'] as $stop ) {
				if ( '' === $stop['name'] ) {
					continue;
				}
				$place = array(
					'@type' => 'City',
					'name'  => $stop['name'],
				);
				if ( $stop['country'] ) {
					$place['containedInPlace'] = array(
						'@type' => 'Country',
						'name'  => $stop['country'],
					);
				}
				$elements[] = array(
					'@type'    => 'ListItem',
					'position' => $position++,
					'item'     => $place,
				);
			}
			if ( $elements ) {
				$schema['itinerary'] = array(
					'@type'           => 'ItemList',
					'numberOfItems'   => count( $elements ),
					'itemListElement' => $elements,
				);
			}
		}

		// Price. Displayed in PLN on Polish pages, so advertise the same
		// currency the visitor actually sees — a mismatch between the markup
		// and the page is exactly what triggers a structured-data penalty.
		$price = mwp_format_price( $d['price'], $lang );
		if ( null !== $d['price']['amount'] ) {
			$amount   = $d['price']['amount'];
			$currency = $d['price']['currency'];

			if ( ! empty( $price['converted'] ) && function_exists( 'get_eur_to_pln_exchange_rate' ) ) {
				$rate = (float) get_eur_to_pln_exchange_rate();
				if ( $rate > 0 ) {
					$amount   = round( $amount * $rate );
					$currency = 'PLN';
				}
			}

			$offer = array(
				'@type'         => 'Offer',
				'price'         => (string) round( $amount, 2 ),
				'priceCurrency' => $currency,
				'availability'  => $d['active']
					? 'https://schema.org/InStock'
					: 'https://schema.org/OutOfStock',
				'url'           => get_permalink( $post_id ),
			);

			// Prices are converted at render time and TC pricing moves, so
			// don't claim a validity longer than the next sync realistically is.
			$offer['priceValidUntil'] = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );

			$schema['offers'] = $offer;
		}

		echo "\n<!-- MultiWander Packages -->\n";
		echo '<script type="application/ld+json">' .
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
			"</script>\n";
	}

	/**
	 * Package description as plain text, trimmed to a length.
	 *
	 * @param array $data  Payload.
	 * @param int   $chars Maximum characters.
	 * @return string
	 */
	public static function plain_description( array $data, $chars = 155 ) {
		$text = isset( $data['description'] ) ? $data['description'] : '';
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $chars : strlen( $text ) <= $chars ) {
			return $text;
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $chars ) : substr( $text, 0, $chars );

		// Trim back to the last word so the description doesn't end mid-word.
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > $chars * 0.6 ) {
			$cut = substr( $cut, 0, $space );
		}

		return rtrim( $cut, " ,.;:–-" ) . '…';
	}

	/**
	 * Write an SEO title for a freshly synced package.
	 *
	 * Defensive, not decorative: the live site was serving "MultiWander" as
	 * the <title> of every package page — the site name alone, with no page
	 * name — because Yoast's title template for Pages was not producing one.
	 * Google uses <title> as the clickable headline, so every package page
	 * would have competed in search results as the same identical blue link.
	 *
	 * Writing an explicit per-page title makes the plugin independent of that
	 * template. A title an editor has written by hand is never overwritten.
	 *
	 * @param int    $post_id Post.
	 * @param array  $data    Payload.
	 * @param string $lang    Language slug.
	 * @return void
	 */
	public static function maybe_set_title( $post_id, array $data, $lang = 'pl' ) {
		$existing = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		if ( $existing ) {
			return;
		}

		$en    = ( 'en' === $lang );
		$title = trim( $data['title'] );
		if ( '' === $title ) {
			return;
		}

		// A short qualifier helps the result stand out without pushing the
		// title past the ~60 characters Google will actually show.
		$qualifier = '';
		if ( $data['duration']['days'] ) {
			$qualifier = sprintf( $en ? '%d days' : '%d dni', $data['duration']['days'] );
		}

		$brand = get_bloginfo( 'name' );
		$parts = array_filter( array( $title, $qualifier ) );

		$seo_title = implode( ' | ', $parts );

		// Only append the brand when there is room for it.
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $seo_title ) : strlen( $seo_title );
		if ( $brand && $length + 3 + strlen( $brand ) <= 65 ) {
			$seo_title .= ' | ' . $brand;
		}

		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
	}

	/**
	 * Build a meta description for a freshly synced package.
	 *
	 * Written once, on creation, and never over an existing value — an editor
	 * who has hand-written a description keeps it.
	 *
	 * @param int   $post_id Post.
	 * @param array $data    Payload.
	 * @param string $lang   Language slug.
	 * @return void
	 */
	public static function maybe_set_meta_description( $post_id, array $data, $lang = 'pl' ) {
		$existing = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( $existing ) {
			return;
		}

		$en    = ( 'en' === $lang );
		$stops = wp_list_pluck( $data['destinations'], 'name' );
		$price = mwp_format_price( $data['price'], $lang );

		$parts = array();

		if ( $data['duration']['days'] && $stops ) {
			$parts[] = sprintf(
				$en ? '%1$d-day trip through %2$s.' : '%1$d-dniowa podróż przez %2$s.',
				$data['duration']['days'],
				implode( ', ', array_slice( $stops, 0, 3 ) )
			);
		}

		if ( $price['value'] ) {
			$parts[] = sprintf(
				$en ? 'From %1$s %2$s per person, flights included.' : 'Już od %1$s %2$s za osobę, z przelotem.',
				$price['value'],
				$price['symbol']
			);
		}

		$parts[] = $en
			? 'Fully customisable — change dates, hotels and departure airport.'
			: 'W pełni elastyczna — zmień terminy, hotele i lotnisko wylotu.';

		$description = trim( implode( ' ', $parts ) );

		// Fall back to the package's own copy if we somehow built nothing.
		if ( '' === $description ) {
			$description = self::plain_description( $data, 155 );
		}

		if ( '' !== $description ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		}
	}
}
