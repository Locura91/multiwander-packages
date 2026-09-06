<?php
/**
 * Builds the chronological trip plan for a package page.
 *
 * Travel Compositor returns each element type in its own bucket — hotels,
 * transports, tickets, closedTours, destinations — each carrying a day number.
 * Its own brochure then presents them as one date-ordered stream of cards, and
 * that is what a traveller actually reads. This class flattens the buckets
 * back into that stream so the WordPress page and the booking engine tell the
 * story in the same order.
 *
 * Built at render time from the stored payload rather than at sync time, so
 * pages synced with an earlier version pick it up without a re-sync.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Timeline {

	/**
	 * Sort weight within a single day: you fly, you arrive somewhere, you
	 * check in, then you do things.
	 */
	const ORDER = array(
		'flight'   => 0,
		'place'    => 1,
		'hotel'    => 2,
		'tour'     => 3,
		'activity' => 4,
	);

	/**
	 * Build the ordered list of trip elements.
	 *
	 * @param array  $d    Normalised package payload.
	 * @param string $lang Site language slug.
	 * @return array<int,array>
	 */
	public static function build( array $d, $lang = 'pl' ) {
		$en = ( 'en' === $lang );
		$t  = function ( $pl, $english ) use ( $en ) {
			return $en ? $english : $pl;
		};

		$items = array();

		// --- Flights -------------------------------------------------
		foreach ( $d['flights'] as $f ) {
			$route = trim( $f['from'] . ' → ' . $f['to'] );

			$meta = array();
			if ( $f['departure_time'] ) {
				$meta[] = $f['departure_time'] . ( $f['arrival_time'] ? ' – ' . $f['arrival_time'] : '' );
			}
			if ( $f['duration'] ) {
				$meta[] = $f['duration'];
			}
			if ( $f['segments'] > 1 ) {
				$meta[] = sprintf(
					$t( '%d przesiadka', '%d stop' ),
					$f['segments'] - 1
				);
			}
			if ( $f['baggage'] ) {
				$meta[] = $f['baggage'];
			}
			if ( $f['fare'] ) {
				$meta[] = $f['fare'];
			}

			$items[] = array(
				'day'    => max( 1, (int) $f['day'] ),
				'kind'   => 'flight',
				'title'  => $f['airline'] ? sprintf( $t( 'Przelot %s', 'Flight %s' ), $route ) : $route,
				'badge'  => $t( 'Przelot', 'Flight' ),
				'lead'   => $f['airline'],
				'meta'   => $meta,
				'images' => array(),
			);
		}

		// --- Destinations --------------------------------------------
		foreach ( $d['destinations'] as $dest ) {
			$nights = max( 0, (int) $dest['to_day'] - (int) $dest['from_day'] );

			$meta = array();
			if ( $dest['country'] ) {
				$meta[] = $dest['country'];
			}
			if ( $nights > 0 ) {
				$meta[] = sprintf(
					$t( '%d nocy', '%d nights' ),
					$nights
				);
			}

			$items[] = array(
				'day'    => max( 1, (int) $dest['from_day'] ),
				'kind'   => 'place',
				'title'  => $dest['name'],
				'badge'  => $t( 'Pobyt od', 'Stay from' ),
				'lead'   => '',
				'meta'   => $meta,
				'body'   => $dest['description'],
				'images' => array_slice( $dest['images'], 0, 3 ),
			);
		}

		// --- Hotels ---------------------------------------------------
		foreach ( $d['hotels'] as $h ) {
			$meta = array_filter( array(
				$h['destination'],
				$h['room_type'],
				$h['meal_plan'],
			) );

			$items[] = array(
				'day'    => max( 1, (int) $h['day'] ),
				'kind'   => 'hotel',
				'title'  => $h['name'],
				'badge'  => $h['nights']
					? sprintf( $t( '%d nocy', '%d nights' ), $h['nights'] )
					: $t( 'Zakwaterowanie', 'Accommodation' ),
				'lead'   => '',
				'meta'   => $meta,
				'stars'  => $h['stars'],
				'score'  => $h['score'],
				'images' => array_slice( $h['images'], 0, 3 ),
			);
		}

		// --- Guided tours ---------------------------------------------
		foreach ( $d['tours'] as $tour ) {
			$meta = array();
			if ( $tour['day_to'] && $tour['day_to'] > $tour['day_from'] ) {
				$meta[] = sprintf(
					$t( 'dni %1$d–%2$d', 'days %1$d–%2$d' ),
					$tour['day_from'],
					$tour['day_to']
				);
			}

			$items[] = array(
				'day'    => max( 1, (int) $tour['day_from'] ),
				'kind'   => 'tour',
				'title'  => $tour['name'],
				'badge'  => $t( 'Wycieczka', 'Guided tour' ),
				'lead'   => '',
				'meta'   => $meta,
				'body'   => $tour['description'],
				'images' => array_slice( $tour['images'], 0, 3 ),
			);
		}

		// --- Experiences ----------------------------------------------
		foreach ( $d['activities'] as $a ) {
			$items[] = array(
				'day'    => max( 1, (int) $a['day'] ),
				'kind'   => 'activity',
				'title'  => $a['name'],
				'badge'  => $t( 'Atrakcja', 'Experience' ),
				'lead'   => '',
				'meta'   => array_filter( array( $a['duration'], $a['meeting_point'] ) ),
				'body'   => $a['description'],
				'images' => array_slice( $a['images'], 0, 3 ),
			);
		}

		// Stable ordering: by day, then by what happens first within it, then
		// by the order the API listed them so equal items never jump around
		// between page loads.
		foreach ( $items as $i => $item ) {
			$items[ $i ]['_seq'] = $i;
			$items[ $i ] += array(
				'body'  => '',
				'stars' => 0,
				'score' => null,
				'lead'  => '',
			);
		}

		usort( $items, function ( $a, $b ) {
			if ( $a['day'] !== $b['day'] ) {
				return $a['day'] <=> $b['day'];
			}
			$ra = isset( self::ORDER[ $a['kind'] ] ) ? self::ORDER[ $a['kind'] ] : 9;
			$rb = isset( self::ORDER[ $b['kind'] ] ) ? self::ORDER[ $b['kind'] ] : 9;
			if ( $ra !== $rb ) {
				return $ra <=> $rb;
			}
			return $a['_seq'] <=> $b['_seq'];
		} );

		// Turn day numbers into real dates when a departure is known — the
		// booking engine shows dates, so match it rather than showing "day 5"
		// next to its "05 paź".
		$start = self::start_timestamp( $d );

		foreach ( $items as $i => $item ) {
			$items[ $i ]['date'] = $start
				? $start + ( $item['day'] - 1 ) * DAY_IN_SECONDS
				: 0;
		}

		return $items;
	}

	/**
	 * The trip's first day as a timestamp, when one can be known.
	 *
	 * @param array $d Payload.
	 * @return int 0 when the package has no fixed departure.
	 */
	protected static function start_timestamp( array $d ) {
		$first = isset( $d['departures']['first'] ) ? $d['departures']['first'] : '';
		if ( ! $first ) {
			return 0;
		}
		$ts = strtotime( $first );
		return $ts ? $ts : 0;
	}
}
