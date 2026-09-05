<?php
/**
 * Turns raw Travel Compositor responses into one flat, predictable array.
 *
 * Every field path here was read off real responses for packages 62837980
 * (Thailand) and 59875907 (Vietnam/Cambodia) — not guessed from documentation.
 * Where a name still felt uncertain, mwp_pick() scans candidates and records
 * which key matched so a wrong guess shows up in the debug screen.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Parser {

	/**
	 * Build the normalised payload for one package in one language.
	 *
	 * @param array $info     Response from /package/{ms}/info/{id}.
	 * @param array $detail   Response from /package/{ms}/{id}.
	 * @param array $calendar Response from /package/calendar/{ms}/{id}.
	 * @return array
	 */
	public static function normalise( $info, $detail, $calendar ) {
		$info     = is_array( $info ) ? $info : array();
		$detail   = is_array( $detail ) ? $detail : array();
		$calendar = is_array( $calendar ) ? $calendar : array();

		$title = trim( (string) mwp_pick( $info, array( 'title', 'largeTitle' ), '' ) );

		$data = array(
			'id'            => (string) mwp_pick( $info, array( 'id', 'packageId', 'holidayPackageId' ), '' ),
			'title'         => $title,
			'large_title'   => trim( (string) mwp_pick( $info, array( 'largeTitle', 'title' ), $title ) ),
			'description'   => (string) mwp_pick( $info, array( 'description' ), '' ),
			'ribbon'        => trim( (string) mwp_pick( $info, array( 'ribbonText' ), '' ) ),
			'themes'        => self::strings( mwp_pick( $info, array( 'themes' ), array() ) ),
			'active'        => (bool) mwp_pick( $info, array( 'active' ), true ),
			'booking_url'   => (string) mwp_pick( $info, array( 'ideaUrl' ), '' ),
			'hero_image'    => (string) mwp_pick( $info, array( 'imageUrl' ), '' ),
			'price'         => mwp_money( mwp_pick( $info, array( 'pricePerPerson' ), null ) ),
			'total_price'   => mwp_money( mwp_pick( $info, array( 'totalPrice' ), null ) ),
			'counters'      => self::counters( $info ),
			'destinations'  => self::destinations( $info, $detail ),
			'departures'    => self::departures( $calendar, $info ),
			'flights'       => self::flights( $detail ),
			'hotels'        => self::hotels( $detail ),
			'activities'    => self::activities( $detail ),
			'tours'         => self::tours( $detail ),
			'gallery'       => array(),
			'raw_keys'      => array(
				'info'   => array_keys( $info ),
				'detail' => array_keys( $detail ),
			),
		);

		$data['gallery']  = self::gallery( $data, $detail );
		$data['duration'] = self::duration( $data );

		return $data;
	}

	/**
	 * Headline counts used for the quick-facts strip.
	 *
	 * @param array $info Info response.
	 * @return array
	 */
	protected static function counters( $info ) {
		$c = mwp_pick( $info, array( 'counters' ), array() );
		$c = is_array( $c ) ? $c : array();

		$keys = array( 'adults', 'children', 'destinations', 'hotelNights', 'hotels', 'tickets', 'transfers', 'transports', 'closedTours', 'cruises', 'cars' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $c[ $key ] ) ? (int) $c[ $key ] : 0;
		}
		return $out;
	}

	/**
	 * Total trip length in days.
	 *
	 * Nights come from counters.hotelNights; days is nights + 1, which matches
	 * how the destination day ranges run (Bangkok day 1-4, … Phuket day 6-13).
	 *
	 * @param array $data Partial payload.
	 * @return array{nights:int,days:int}
	 */
	protected static function duration( $data ) {
		$nights = (int) $data['counters']['hotelNights'];

		// Prefer the highest toDay across destinations when present — it is
		// the trip's real end day and survives packages with no hotels.
		$max_day = 0;
		foreach ( $data['destinations'] as $dest ) {
			$max_day = max( $max_day, (int) $dest['to_day'] );
		}

		$days = $max_day > 0 ? $max_day : ( $nights > 0 ? $nights + 1 : 0 );

		return array(
			'nights' => $nights,
			'days'   => $days,
		);
	}

	/**
	 * Destinations, preferring the detail response (it carries day ranges,
	 * country, geolocation and images; the info response has only code+name).
	 *
	 * @param array $info   Info response.
	 * @param array $detail Detail response.
	 * @return array
	 */
	protected static function destinations( $info, $detail ) {
		$source = mwp_pick( $detail, array( 'destinations' ), array() );
		if ( ! is_array( $source ) || ! $source ) {
			$source = mwp_pick( $info, array( 'destinations' ), array() );
		}
		if ( ! is_array( $source ) ) {
			return array();
		}

		$out = array();
		foreach ( $source as $d ) {
			if ( ! is_array( $d ) ) {
				continue;
			}
			$out[] = array(
				'code'        => (string) mwp_pick( $d, array( 'code' ), '' ),
				'name'        => mwp_text( mwp_pick( $d, array( 'name' ), '' ) ),
				'country'     => mwp_text( mwp_pick( $d, array( 'country' ), '' ) ),
				'description' => (string) mwp_pick( $d, array( 'description' ), '' ),
				'from_day'    => (int) mwp_pick( $d, array( 'fromDay' ), 0 ),
				'to_day'      => (int) mwp_pick( $d, array( 'toDay' ), 0 ),
				'airport'     => (string) mwp_pick( $d, array( 'recommendedAirportName' ), '' ),
				'images'      => self::strings( mwp_pick( $d, array( 'imageUrls', 'images' ), array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Departure dates from the calendar.
	 *
	 * Two things confirmed against real data: prices in this response are all
	 * 0.0 (the calendar tells you WHEN, not how much), and an empty list is
	 * the normal case for a dynamic package that can depart any day.
	 *
	 * @param array $calendar Calendar response.
	 * @param array $info     Info response (for the fallback date).
	 * @return array
	 */
	protected static function departures( $calendar, $info ) {
		$dates = mwp_pick( $calendar, array( 'holidayPackagesDates', 'dates' ), array() );
		$out   = array();

		if ( is_array( $dates ) ) {
			foreach ( $dates as $row ) {
				if ( is_array( $row ) && ! empty( $row['date'] ) ) {
					$out[] = (string) $row['date'];
				} elseif ( is_string( $row ) ) {
					$out[] = $row;
				}
			}
		}

		sort( $out );

		// Only keep dates in the future — a package synced months ago
		// otherwise advertises departures that have already gone.
		$today = current_time( 'Y-m-d' );
		$out   = array_values( array_filter( $out, function ( $d ) use ( $today ) {
			return $d >= $today;
		} ) );

		return array(
			'dates'    => $out,
			'first'    => $out ? $out[0] : (string) mwp_pick( $info, array( 'departureDate' ), '' ),
			'is_empty' => empty( $out ),
		);
	}

	/**
	 * Flights, from detail.transports where transportType is FLIGHT.
	 *
	 * @param array $detail Detail response.
	 * @return array
	 */
	protected static function flights( $detail ) {
		$transports = mwp_pick( $detail, array( 'transports' ), array() );
		if ( ! is_array( $transports ) ) {
			return array();
		}

		$out = array();
		foreach ( $transports as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$type = strtoupper( (string) mwp_pick( $t, array( 'transportType' ), '' ) );
			if ( $type && 'FLIGHT' !== $type ) {
				continue;
			}
			$out[] = array(
				'airline'        => mwp_text( mwp_pick( $t, array( 'company' ), '' ) ),
				'airline_code'   => (string) mwp_pick( $t, array( 'marketingAirlineCode' ), '' ),
				'from'           => (string) mwp_pick( $t, array( 'originCode' ), '' ),
				'to'             => (string) mwp_pick( $t, array( 'targetCode' ), '' ),
				'departure_date' => (string) mwp_pick( $t, array( 'departureDate' ), '' ),
				'departure_time' => substr( (string) mwp_pick( $t, array( 'departureTime' ), '' ), 0, 5 ),
				'arrival_date'   => (string) mwp_pick( $t, array( 'arrivalDate' ), '' ),
				'arrival_time'   => substr( (string) mwp_pick( $t, array( 'arrivalTime' ), '' ), 0, 5 ),
				'duration'       => mwp_text( mwp_pick( $t, array( 'duration' ), '' ) ),
				'baggage'        => mwp_text( mwp_pick( $t, array( 'baggageInfo' ), '' ) ),
				'fare'           => mwp_text( mwp_pick( $t, array( 'fare' ), '' ) ),
				'segments'       => (int) mwp_pick( $t, array( 'numberOfSegments' ), 1 ),
				'day'            => (int) mwp_pick( $t, array( 'day' ), 0 ),
			);
		}
		return $out;
	}

	/**
	 * Hotels, with star rating taken from hotelData.category ("S4" => 4).
	 *
	 * hotelData.ratings is a LIST of per-source scores on DIFFERENT scales
	 * (Booking.com and Expedia are /10, Tripadvisor is /5). We deliberately
	 * display the star category instead and expose only the Booking.com score
	 * separately, never an average across sources.
	 *
	 * @param array $detail Detail response.
	 * @return array
	 */
	protected static function hotels( $detail ) {
		$hotels = mwp_pick( $detail, array( 'hotels' ), array() );
		if ( ! is_array( $hotels ) ) {
			return array();
		}

		$out = array();
		foreach ( $hotels as $h ) {
			if ( ! is_array( $h ) ) {
				continue;
			}
			$hd = is_array( mwp_pick( $h, array( 'hotelData' ), array() ) ) ? $h['hotelData'] : array();

			$images = array();
			foreach ( (array) mwp_pick( $hd, array( 'images' ), array() ) as $img ) {
				if ( is_array( $img ) && ! empty( $img['url'] ) ) {
					$images[] = (string) $img['url'];
				} elseif ( is_string( $img ) ) {
					$images[] = $img;
				}
			}

			$out[] = array(
				'name'        => mwp_text( mwp_pick( $hd, array( 'name' ), '' ) ),
				'stars'       => mwp_stars( (string) mwp_pick( $hd, array( 'category' ), '' ) ),
				'category'    => (string) mwp_pick( $hd, array( 'category' ), '' ),
				'address'     => mwp_text( mwp_pick( $hd, array( 'address' ), '' ) ),
				'destination' => mwp_text( mwp_pick( $hd, array( 'destination' ), '' ) ),
				'description' => mwp_text( mwp_pick( $hd, array( 'description' ), '' ) ),
				'nights'      => (int) mwp_pick( $h, array( 'nights' ), 0 ),
				'day'         => (int) mwp_pick( $h, array( 'day' ), 0 ),
				'meal_plan'   => mwp_text( mwp_pick( $h, array( 'mealPlan' ), '' ) ),
				'room_type'   => mwp_text( mwp_pick( $h, array( 'roomTypes', 'roomType' ), '' ) ),
				'score'       => self::booking_score( mwp_pick( $hd, array( 'ratings' ), array() ) ),
				'images'      => array_slice( $images, 0, 8 ),
			);
		}
		return $out;
	}

	/**
	 * Pull the Booking.com score out of the per-source ratings list.
	 *
	 * Chosen because it consistently carries by far the most reviews and its
	 * scale is already /10. Scores of 0 mean "no reviews" and are discarded.
	 *
	 * @param mixed $ratings Raw ratings value.
	 * @return array{score:float,reviews:int,source:string}|null
	 */
	protected static function booking_score( $ratings ) {
		if ( ! is_array( $ratings ) ) {
			return null;
		}
		foreach ( $ratings as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			if ( 'booking.com' !== strtolower( (string) mwp_pick( $r, array( 'source' ), '' ) ) ) {
				continue;
			}
			$score = (float) mwp_pick( $r, array( 'score' ), 0 );
			if ( $score <= 0 ) {
				return null;
			}
			return array(
				'score'   => $score,
				'reviews' => (int) mwp_pick( $r, array( 'numReviews' ), 0 ),
				'source'  => 'Booking.com',
			);
		}
		return null;
	}

	/**
	 * Excursions and experiences, from detail.tickets.
	 *
	 * @param array $detail Detail response.
	 * @return array
	 */
	protected static function activities( $detail ) {
		$tickets = mwp_pick( $detail, array( 'tickets' ), array() );
		if ( ! is_array( $tickets ) ) {
			return array();
		}

		$out = array();
		foreach ( $tickets as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$out[] = array(
				'name'          => mwp_text( mwp_pick( $t, array( 'name' ), '' ) ),
				'day'           => (int) mwp_pick( $t, array( 'day' ), 0 ),
				'duration'      => mwp_text( mwp_pick( $t, array( 'duration' ), '' ) ),
				'description'   => (string) mwp_pick( $t, array( 'description' ), '' ),
				'meeting_point' => mwp_text( mwp_pick( $t, array( 'meetingPoint' ), '' ) ),
				'images'        => self::strings( mwp_pick( $t, array( 'imageUrls' ), array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Multi-day guided tours, from detail.closedTours. These carry the
	 * included / not-included service lists as ready-made HTML <ul>s.
	 *
	 * @param array $detail Detail response.
	 * @return array
	 */
	protected static function tours( $detail ) {
		$tours = mwp_pick( $detail, array( 'closedTours' ), array() );
		if ( ! is_array( $tours ) ) {
			return array();
		}

		$out = array();
		foreach ( $tours as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$out[] = array(
				'name'         => mwp_text( mwp_pick( $t, array( 'name' ), '' ) ),
				'day_from'     => (int) mwp_pick( $t, array( 'dayFrom' ), 0 ),
				'day_to'       => (int) mwp_pick( $t, array( 'dayTo' ), 0 ),
				'description'  => (string) mwp_pick( $t, array( 'description' ), '' ),
				'included'     => (string) mwp_pick( $t, array( 'includedServices' ), '' ),
				'not_included' => (string) mwp_pick( $t, array( 'nonIncludedServices' ), '' ),
				'images'       => self::strings( mwp_pick( $t, array( 'imageUrls' ), array() ) ),
			);
		}
		return $out;
	}

	/**
	 * Collect a deduplicated gallery from every image-bearing part.
	 *
	 * Destination images come first — they are the scenic ones and come from
	 * TC's own storage. Hotel and activity images follow.
	 *
	 * @param array $data   Partial payload.
	 * @param array $detail Detail response.
	 * @return array
	 */
	protected static function gallery( $data, $detail ) {
		$urls = array();

		foreach ( $data['destinations'] as $d ) {
			foreach ( $d['images'] as $u ) {
				$urls[] = $u;
			}
		}
		foreach ( $data['tours'] as $t ) {
			foreach ( $t['images'] as $u ) {
				$urls[] = $u;
			}
		}
		foreach ( $data['activities'] as $a ) {
			foreach ( $a['images'] as $u ) {
				$urls[] = $u;
			}
		}
		foreach ( $data['hotels'] as $h ) {
			foreach ( array_slice( $h['images'], 0, 2 ) as $u ) {
				$urls[] = $u;
			}
		}

		$urls = array_values( array_unique( array_filter( $urls, function ( $u ) {
			return is_string( $u ) && preg_match( '#^https?://#i', $u );
		} ) ) );

		return array_slice( $urls, 0, (int) apply_filters( 'mwp_gallery_limit', 24 ) );
	}

	/**
	 * Coerce a value into a clean list of non-empty strings.
	 *
	 * @param mixed $value Raw.
	 * @return string[]
	 */
	protected static function strings( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$out[] = trim( $v );
			}
		}
		return $out;
	}
}
