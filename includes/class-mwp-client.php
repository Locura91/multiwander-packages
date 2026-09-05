<?php
/**
 * Travel Compositor API client.
 *
 * Ported from the proven Python client in momira-tc-tool
 * (travelcompositor_api.py), which has been running against this account for
 * months. The behaviours below are not guesses:
 *
 *  - The auth token comes back as a RESPONSE HEADER (auth-token), not in the
 *    JSON body. A body fallback exists but has never actually been needed.
 *  - Subsequent requests send it as an `auth-token` REQUEST header. This is
 *    not `Authorization: Bearer ...` — TC uses its own header name.
 *  - Tokens expire. On a 401, re-authenticate with force and retry once
 *    rather than treating it as fatal.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Client {

	/** @var string|null */
	protected $token = null;

	/** @var array Last request/response pair, for the debug screen. */
	protected $last_debug = array();

	const TOKEN_TRANSIENT = 'mwp_tc_auth_token';
	const CACHE_PREFIX    = 'mwp_tc_';

	/**
	 * Credentials, read from wp-config constants only.
	 *
	 * @return array{user:string,pass:string,microsite:string,base:string}
	 */
	public static function config() {
		return array(
			'user'      => defined( 'MW_TC_USERNAME' ) ? MW_TC_USERNAME : '',
			'pass'      => defined( 'MW_TC_PASSWORD' ) ? MW_TC_PASSWORD : '',
			'microsite' => defined( 'MW_TC_MICROSITE' ) ? MW_TC_MICROSITE : 'momiratravel',
			'base'      => rtrim( defined( 'MW_TC_BASE_URL' ) ? MW_TC_BASE_URL : 'https://online.travelcompositor.com/resources', '/' ),
		);
	}

	/**
	 * Whether the plugin has everything it needs to talk to the API.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$c = self::config();
		return '' !== $c['user'] && '' !== $c['pass'];
	}

	/**
	 * Authenticate and return a token.
	 *
	 * @param bool $force Ignore any cached token.
	 * @return string|WP_Error
	 */
	public function authenticate( $force = false ) {
		if ( ! $force ) {
			if ( $this->token ) {
				return $this->token;
			}
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( $cached ) {
				$this->token = $cached;
				return $this->token;
			}
		}

		$c = self::config();
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'mwp_no_credentials',
				__( 'Travel Compositor credentials are missing. Add MW_TC_USERNAME and MW_TC_PASSWORD to wp-config.php.', 'multiwander-packages' )
			);
		}

		$response = wp_remote_post(
			$c['base'] . '/authentication/authenticate',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'username'    => $c['user'],
						'password'    => $c['pass'],
						'micrositeId' => $c['microsite'],
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mwp_auth_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Travel Compositor rejected the login (HTTP %d). Check MW_TC_USERNAME and MW_TC_PASSWORD.', 'multiwander-packages' ),
					$code
				),
				array( 'body' => substr( wp_remote_retrieve_body( $response ), 0, 500 ) )
			);
		}

		// The token lives in a response header.
		$token = wp_remote_retrieve_header( $response, 'auth-token' );

		// Body fallback — documented for completeness; not observed in practice.
		if ( ! $token ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( is_array( $data ) ) {
				foreach ( array( 'token', 'authToken', 'auth-token' ) as $key ) {
					if ( ! empty( $data[ $key ] ) ) {
						$token = $data[ $key ];
						break;
					}
				}
			} elseif ( $body ) {
				$token = trim( $body, "\" \n\r\t" );
			}
		}

		if ( ! $token ) {
			return new WP_Error(
				'mwp_no_token',
				__( 'Travel Compositor accepted the login but returned no auth token.', 'multiwander-packages' )
			);
		}

		$this->token = $token;
		// Short TTL — a 401 re-auths anyway, so this is just to avoid
		// re-logging in on every single request.
		set_transient( self::TOKEN_TRANSIENT, $token, 20 * MINUTE_IN_SECONDS );

		return $token;
	}

	/**
	 * GET a path, with one automatic re-auth-and-retry on 401.
	 *
	 * @param string $path   Path below the base URL, e.g. "/package/momiratravel".
	 * @param array  $params Query parameters.
	 * @return array|WP_Error Decoded JSON.
	 */
	public function get( $path, array $params = array() ) {
		$token = $this->authenticate();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->raw_get( $path, $params, $token );

		if ( is_wp_error( $result ) && 'mwp_unauthorized' === $result->get_error_code() ) {
			mwp_log( 'Token rejected, re-authenticating', array( 'path' => $path ) );
			delete_transient( self::TOKEN_TRANSIENT );
			$token = $this->authenticate( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = $this->raw_get( $path, $params, $token );
		}

		return $result;
	}

	/**
	 * Single GET attempt.
	 *
	 * @param string $path   Path.
	 * @param array  $params Query args.
	 * @param string $token  Auth token.
	 * @return array|WP_Error
	 */
	protected function raw_get( $path, array $params, $token ) {
		$c   = self::config();
		$url = $c['base'] . $path;
		if ( $params ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'auth-token' => $token,
					'Accept'     => 'application/json',
				),
			)
		);

		$this->last_debug = array(
			'url'    => $url,
			'method' => 'GET',
		);

		if ( is_wp_error( $response ) ) {
			$this->last_debug['error'] = $response->get_error_message();
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$this->last_debug['status'] = $code;

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'mwp_unauthorized', __( 'Travel Compositor rejected the auth token.', 'multiwander-packages' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mwp_http_error',
				sprintf(
					/* translators: 1: HTTP status, 2: URL */
					__( 'Travel Compositor returned HTTP %1$d for %2$s', 'multiwander-packages' ),
					$code,
					$path
				),
				array( 'body' => substr( $body, 0, 500 ) )
			);
		}

		$data = json_decode( $body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'mwp_bad_json',
				__( 'Travel Compositor returned something that is not valid JSON.', 'multiwander-packages' ),
				array( 'body' => substr( $body, 0, 500 ) )
			);
		}

		$this->last_debug['bytes'] = strlen( $body );

		return $data;
	}

	// -----------------------------------------------------------------
	// Endpoints
	// -----------------------------------------------------------------

	/**
	 * GET /package/{micrositeId}/info/{id} — flat title/description summary.
	 *
	 * @param string $id   Package id.
	 * @param string $lang TC language code ("PL", "EN").
	 * @return array|WP_Error
	 */
	public function get_info( $id, $lang = 'PL' ) {
		$c = self::config();
		return $this->cached( "info_{$id}_{$lang}", function () use ( $c, $id, $lang ) {
			return $this->get( "/package/{$c['microsite']}/info/{$id}", array( 'lang' => $lang ) );
		} );
	}

	/**
	 * GET /package/{micrositeId}/{id} — the full day-to-day detail.
	 *
	 * @param string $id   Package id.
	 * @param string $lang TC language code.
	 * @return array|WP_Error
	 */
	public function get_detail( $id, $lang = 'PL' ) {
		$c = self::config();
		return $this->cached( "detail_{$id}_{$lang}", function () use ( $c, $id, $lang ) {
			return $this->get( "/package/{$c['microsite']}/{$id}", array( 'lang' => $lang ) );
		} );
	}

	/**
	 * GET /package/calendar/{micrositeId}/{id} — departure dates.
	 *
	 * An empty calendar is the NORMAL case for a dynamic package that can
	 * depart any day. It is not an error and must not be treated as one.
	 *
	 * @param string $id   Package id.
	 * @param string $lang TC language code.
	 * @return array|WP_Error
	 */
	public function get_calendar( $id, $lang = 'PL' ) {
		$c = self::config();
		return $this->cached( "calendar_{$id}_{$lang}", function () use ( $c, $id, $lang ) {
			return $this->get( "/package/calendar/{$c['microsite']}/{$id}", array( 'lang' => $lang ) );
		} );
	}

	/**
	 * GET /package/{micrositeId} — the package list.
	 *
	 * @param string $lang    TC language code.
	 * @param array  $filters Extra query params.
	 * @return array|WP_Error
	 */
	public function get_packages( $lang = 'PL', array $filters = array() ) {
		$c = self::config();
		return $this->get( "/package/{$c['microsite']}", array_merge( array( 'lang' => $lang ), $filters ) );
	}

	// -----------------------------------------------------------------
	// Caching
	// -----------------------------------------------------------------

	/**
	 * Wrap a call in a transient.
	 *
	 * Front-end rendering never calls the API — package pages read from post
	 * meta — so this cache only smooths repeated admin syncs.
	 *
	 * @param string   $key      Cache key suffix.
	 * @param callable $callback Producer.
	 * @return array|WP_Error
	 */
	protected function cached( $key, callable $callback ) {
		$ttl = (int) apply_filters( 'mwp_cache_ttl', HOUR_IN_SECONDS );
		if ( $ttl <= 0 ) {
			return $callback();
		}

		$transient = self::CACHE_PREFIX . md5( $key );
		$hit       = get_transient( $transient );
		if ( false !== $hit ) {
			return $hit;
		}

		$value = $callback();
		if ( ! is_wp_error( $value ) ) {
			set_transient( $transient, $value, $ttl );
		}

		return $value;
	}

	/**
	 * Drop every cached API response (not the auth token).
	 *
	 * @return int Number of rows removed.
	 */
	public static function flush_cache() {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
		$n    = 0;
		foreach ( $rows as $name ) {
			$key = str_replace( '_transient_', '', $name );
			if ( delete_transient( $key ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Details of the most recent request, for the debug screen.
	 *
	 * @return array
	 */
	public function last_debug() {
		return $this->last_debug;
	}
}
