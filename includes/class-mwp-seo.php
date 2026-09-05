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
