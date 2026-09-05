<?php
/**
 * Small shared helpers.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unwrap a Travel Compositor money object.
 *
 * TC never returns a bare number for money. Every price is
 * {"amount": 2477.75, "currency": "EUR"}. Handing that dict to a generic
 * number parser silently produces garbage, so always come through here.
 *
 * @param mixed $value Raw value from the API.
 * @return array{amount: float|null, currency: string}
 */
function mwp_money( $value ) {
	$out = array(
		'amount'   => null,
		'currency' => 'EUR',
	);

	if ( is_array( $value ) ) {
		if ( isset( $value['amount'] ) && is_numeric( $value['amount'] ) ) {
			$out['amount'] = (float) $value['amount'];
		}
		if ( ! empty( $value['currency'] ) && is_string( $value['currency'] ) ) {
			$out['currency'] = strtoupper( $value['currency'] );
		}
		return $out;
	}

	// A bare number would be unexpected, but don't throw away a usable value.
	if ( is_numeric( $value ) ) {
		$out['amount'] = (float) $value;
	}

	return $out;
}

/**
 * Read a key from an array case-insensitively, trying several likely names.
 *
 * TC's field naming is inconsistent enough that hard-coding one guess is
 * risky. This scans the response's own keys and — importantly — records
 * which key actually matched, so a wrong guess surfaces in the debug view
 * instead of silently reading a nonsense field forever.
 *
 * @param array  $haystack Source array.
 * @param array  $names    Candidate key names, best first.
 * @param mixed  $default  Returned when nothing matches.
 * @param string $matched  Receives the key that matched.
 * @return mixed
 */
function mwp_pick( $haystack, array $names, $default = null, &$matched = '' ) {
	$matched = '';
	if ( ! is_array( $haystack ) ) {
		return $default;
	}

	$lower = array();
	foreach ( $haystack as $key => $value ) {
		$lower[ strtolower( (string) $key ) ] = $key;
	}

	foreach ( $names as $name ) {
		$needle = strtolower( $name );
		if ( isset( $lower[ $needle ] ) ) {
			$real = $lower[ $needle ];
			if ( null !== $haystack[ $real ] && '' !== $haystack[ $real ] ) {
				$matched = $real;
				return $haystack[ $real ];
			}
		}
	}

	return $default;
}

/**
 * Turn a Travel Compositor title into a URL slug.
 *
 * TC titles arrive with trailing whitespace ("… Angkor Wat    ") and Polish
 * diacritics. Left alone that produces slugs with stray trailing dashes,
 * which is exactly what the old offer IDs suffered from.
 *
 * @param string $title Raw title.
 * @return string
 */
function mwp_slug( $title ) {
	$title = trim( (string) $title );
	$title = str_replace( array( '&', '+' ), ' ', $title );
	$title = remove_accents( $title );          // Kambodża -> Kambodza
	$slug  = sanitize_title( $title );
	$slug  = trim( $slug, '-' );

	if ( '' === $slug ) {
		$slug = 'pakiet-' . wp_generate_password( 6, false, false );
	}

	// Keep permalinks readable; TC titles can run very long.
	if ( strlen( $slug ) > 90 ) {
		$slug = substr( $slug, 0, 90 );
		$slug = preg_replace( '/-[^-]*$/', '', $slug ); // don't cut mid-word
		$slug = trim( $slug, '-' );
	}

	return $slug;
}

/**
 * Extract a numeric package ID from whatever the editor pasted.
 *
 * Accepts a bare ID, or a full momira.travel / Travel Compositor URL such as
 * https://momira.travel/en/idea/59875907/vietnam-cambodia-from-hanoi-to-angkor-wat-
 *
 * @param string $input Raw input.
 * @return string Numeric id, or '' when nothing usable was found.
 */
function mwp_extract_id( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return '';
	}

	if ( preg_match( '/^\d{4,}$/', $input ) ) {
		return $input;
	}

	// .../idea/59875907/slug  or  ?id=59875907
	if ( preg_match( '#/idea/(\d{4,})#i', $input, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '#[?&]id=(\d{4,})#i', $input, $m ) ) {
		return $m[1];
	}

	// Last resort: the longest run of digits in the string.
	if ( preg_match_all( '/\d{4,}/', $input, $m ) ) {
		usort( $m[0], function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );
		return $m[0][0];
	}

	return '';
}

/**
 * Coerce a value that TC returns inconsistently as either a string or a list.
 *
 * Confirmed real variance: `roomTypes` and `mealPlan` come back as a plain
 * string on some packages ("SUPERIOR Flexi") and as an array on others.
 * Casting an array to string raises a PHP warning and yields "Array", so
 * always come through here.
 *
 * @param mixed  $value Raw value.
 * @param string $glue  Separator used when joining a list.
 * @return string
 */
function mwp_text( $value, $glue = ', ' ) {
	if ( is_string( $value ) ) {
		return trim( $value );
	}
	if ( is_numeric( $value ) ) {
		return (string) $value;
	}
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) || is_numeric( $item ) ) {
				$parts[] = trim( (string) $item );
			} elseif ( is_array( $item ) ) {
				// e.g. [{"name": "SUPERIOR Flexi"}]
				$inner = mwp_pick( $item, array( 'name', 'description', 'text', 'value' ), '' );
				if ( is_string( $inner ) && '' !== $inner ) {
					$parts[] = trim( $inner );
				}
			}
		}
		return implode( $glue, array_filter( array_unique( $parts ) ) );
	}
	return '';
}

/**
 * Convert a TC hotel category code to a star count.
 *
 * Real values seen: "S4" (superior 4), "4", "4EST", "H4". Anything we can't
 * read returns 0 and the template simply shows no stars.
 *
 * @param string $category Raw category.
 * @return int 0-5
 */
function mwp_stars( $category ) {
	if ( ! is_string( $category ) || '' === $category ) {
		return 0;
	}
	if ( preg_match( '/(\d)/', $category, $m ) ) {
		$n = (int) $m[1];
		return ( $n >= 1 && $n <= 5 ) ? $n : 0;
	}
	return 0;
}

/**
 * Format a price for display, converting EUR to PLN where appropriate.
 *
 * Travel Compositor returns EUR for this microsite, so Polish pages need a
 * conversion. The child theme already owns that logic in
 * inc/exchange-rate.php; reuse it rather than adding a second rate source.
 *
 * @param array  $money   Result of mwp_money().
 * @param string $lang    Language slug ('pl' or 'en').
 * @return array{value: string, symbol: string, converted: bool}
 */
function mwp_format_price( $money, $lang = 'pl' ) {
	$amount = isset( $money['amount'] ) ? $money['amount'] : null;
	if ( null === $amount ) {
		return array(
			'value'     => '',
			'symbol'    => '',
			'converted' => false,
		);
	}

	$currency = isset( $money['currency'] ) ? $money['currency'] : 'EUR';

	// Polish pages show PLN. If TC ever starts returning PLN natively this
	// branch is skipped and no conversion happens.
	if ( 'pl' === $lang && 'EUR' === $currency && function_exists( 'get_eur_to_pln_exchange_rate' ) ) {
		$rate = (float) get_eur_to_pln_exchange_rate();
		if ( $rate > 0 ) {
			return array(
				'value'     => number_format( round( $amount * $rate ), 0, ',', ' ' ),
				'symbol'    => 'zł',
				'converted' => true,
			);
		}
	}

	$symbols = array(
		'EUR' => '€',
		'PLN' => 'zł',
		'USD' => '$',
		'GBP' => '£',
	);

	return array(
		'value'     => number_format( round( $amount ), 0, ',', ' ' ),
		'symbol'    => isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency,
		'converted' => false,
	);
}

/**
 * Current Polylang language slug, with a sane default.
 *
 * @return string
 */
function mwp_current_lang() {
	if ( function_exists( 'pll_current_language' ) ) {
		$lang = pll_current_language( 'slug' );
		if ( $lang ) {
			return $lang;
		}
	}
	return 'pl';
}

/**
 * Languages this plugin syncs. Polish is the source, English the translation.
 * The site's Austrian/German language is deliberately out of scope: Travel
 * Compositor only carries PL and EN copy for these packages.
 *
 * @return array<string,string> site language slug => TC lang parameter
 */
function mwp_languages() {
	return apply_filters( 'mwp_languages', array(
		'pl' => 'PL',
		'en' => 'EN',
	) );
}

/**
 * Encryption key derived from this site's own salts.
 *
 * Those salts live in wp-config.php, never in the database, so a stolen
 * database export on its own cannot decrypt anything stored with this.
 *
 * @return string
 */
function mwp_crypt_key() {
	$material = '';
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $constant ) {
		if ( defined( $constant ) ) {
			$material .= constant( $constant );
		}
	}
	if ( '' === $material ) {
		// A WordPress install without salts is unusual; fall back to something
		// site-specific rather than a fixed string.
		$material = get_site_url() . ABSPATH;
	}
	return hash( 'sha256', 'mwp|' . $material, true );
}

/**
 * Encrypt a secret for storage in the options table.
 *
 * @param string $plaintext Value to protect.
 * @return string Base64 payload, or '' for empty input.
 */
function mwp_encrypt( $plaintext ) {
	$plaintext = (string) $plaintext;
	if ( '' === $plaintext ) {
		return '';
	}
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		// Better to store readable than to lose the value silently; the
		// settings screen warns when this happens.
		return 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	$iv     = openssl_random_pseudo_bytes( 16 );
	$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', mwp_crypt_key(), OPENSSL_RAW_DATA, $iv );
	if ( false === $cipher ) {
		return '';
	}

	return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/**
 * Decrypt a value written by mwp_encrypt().
 *
 * @param string $stored Stored payload.
 * @return string
 */
function mwp_decrypt( $stored ) {
	$stored = (string) $stored;
	if ( '' === $stored ) {
		return '';
	}

	if ( 0 === strpos( $stored, 'plain:' ) ) {
		return (string) base64_decode( substr( $stored, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}

	$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( false === $raw || strlen( $raw ) <= 16 ) {
		return '';
	}

	$plain = openssl_decrypt(
		substr( $raw, 16 ),
		'aes-256-cbc',
		mwp_crypt_key(),
		OPENSSL_RAW_DATA,
		substr( $raw, 0, 16 )
	);

	return false === $plain ? '' : $plain;
}

/**
 * Write a line to the plugin log (only when WP_DEBUG is on).
 *
 * @param string $message Message.
 * @param mixed  $context Optional context.
 * @return void
 */
function mwp_log( $message, $context = null ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}
	$line = '[MultiWander Packages] ' . $message;
	if ( null !== $context ) {
		$line .= ' ' . wp_json_encode( $context );
	}
	error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}
