<?php
/**
 * Creates and updates the package pages.
 *
 * One Travel Compositor package becomes one Polish page and one English page,
 * both children of the country page, linked to each other through Polylang.
 *
 * They are ordinary WordPress Pages on purpose. A custom post type cannot have
 * a Page as its parent, so producing /azja-wakacje/tajlandia/{slug} with a CPT
 * would mean hand-written rewrite rules plus permalink filters, kept working
 * against the cache plugin, Yoast's sitemaps and breadcrumbs. Core page
 * hierarchy already does exactly this, correctly.
 *
 * @package multiwander-packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MWP_Sync {

	/** @var MWP_Client */
	protected $client;

	public function __construct( MWP_Client $client = null ) {
		$this->client = $client ? $client : new MWP_Client();
	}

	/**
	 * Sync every package ID listed on a country page.
	 *
	 * @param int $country_id Country page ID.
	 * @return array{synced:array,errors:array,warnings:array}
	 */
	public function sync_country_page( $country_id ) {
		$result = array(
			'synced'   => array(),
			'errors'   => array(),
			'warnings' => array(),
		);

		$ids = self::get_package_ids( $country_id );
		if ( ! $ids ) {
			return $result;
		}

		foreach ( $ids as $package_id ) {
			$one = $this->sync_package( $package_id, $country_id );

			if ( is_wp_error( $one ) ) {
				$result['errors'][ $package_id ] = $one->get_error_message();
				continue;
			}

			$result['synced'][ $package_id ] = $one;
			if ( ! empty( $one['warnings'] ) ) {
				$result['warnings'][ $package_id ] = $one['warnings'];
			}
		}

		return $result;
	}

	/**
	 * Sync one package into PL + EN child pages.
	 *
	 * @param string $package_id TC package id.
	 * @param int    $country_id Parent country page ID (the Polish one).
	 * @return array|WP_Error
	 */
	public function sync_package( $package_id, $country_id ) {
		$package_id = mwp_extract_id( $package_id );
		if ( '' === $package_id ) {
			return new WP_Error( 'mwp_bad_id', __( 'That does not look like a package ID.', 'multiwander-packages' ) );
		}

		$payloads = array();
		$warnings = array();

		foreach ( mwp_languages() as $site_lang => $tc_lang ) {
			$info = $this->client->get_info( $package_id, $tc_lang );
			if ( is_wp_error( $info ) ) {
				// Polish is the source language: without it there is no page.
				if ( 'pl' === $site_lang ) {
					return $info;
				}
				$warnings[] = sprintf(
					/* translators: 1: language, 2: error */
					__( 'Could not load the %1$s version: %2$s', 'multiwander-packages' ),
					strtoupper( $site_lang ),
					$info->get_error_message()
				);
				continue;
			}

			$detail   = $this->client->get_detail( $package_id, $tc_lang );
			$calendar = $this->client->get_calendar( $package_id, $tc_lang );

			// A missing detail or calendar is survivable — the page still has
			// its title, description, price and hero image.
			$payloads[ $site_lang ] = MWP_Parser::normalise(
				$info,
				is_wp_error( $detail ) ? array() : $detail,
				is_wp_error( $calendar ) ? array() : $calendar
			);

			if ( is_wp_error( $detail ) ) {
				$warnings[] = sprintf(
					/* translators: %s: error message */
					__( 'Itinerary detail unavailable: %s', 'multiwander-packages' ),
					$detail->get_error_message()
				);
			}
		}

		if ( empty( $payloads['pl'] ) ) {
			return new WP_Error( 'mwp_no_pl', __( 'No Polish data came back for this package.', 'multiwander-packages' ) );
		}

		// Catch the untranslated case before it reaches a live Polish page.
		if ( ! empty( $payloads['en'] ) ) {
			$pl_title = $payloads['pl']['title'];
			$en_title = $payloads['en']['title'];
			if ( '' !== $pl_title && $pl_title === $en_title ) {
				$warnings[] = sprintf(
					/* translators: %s: package title */
					__( 'This package has no Polish translation in Travel Compositor — the Polish page will show the English title "%s". Translate it in Travel Compositor, then re-sync.', 'multiwander-packages' ),
					$pl_title
				);
			}
		}

		if ( empty( $payloads['pl']['active'] ) ) {
			$warnings[] = __( 'Travel Compositor marks this package as inactive.', 'multiwander-packages' );
		}

		// ---- Create or update the pages ----
		$posts   = array();
		$post_id = $this->upsert_page( $package_id, $payloads['pl'], $country_id, 'pl' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$posts['pl'] = $post_id;

		if ( ! empty( $payloads['en'] ) ) {
			$en_parent = $this->translated_parent( $country_id, 'en' );
			$en_id     = $this->upsert_page( $package_id, $payloads['en'], $en_parent, 'en' );
			if ( ! is_wp_error( $en_id ) ) {
				$posts['en'] = $en_id;
			} else {
				$warnings[] = $en_id->get_error_message();
			}
		}

		$this->link_translations( $posts );

		return array(
			'posts'    => $posts,
			'title'    => $payloads['pl']['title'],
			'url'      => get_permalink( $posts['pl'] ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Create the page if it doesn't exist yet, otherwise update it in place.
	 *
	 * @param string $package_id TC id.
	 * @param array  $data       Normalised payload.
	 * @param int    $parent_id  Parent page.
	 * @param string $lang       Site language slug.
	 * @return int|WP_Error Post ID.
	 */
	protected function upsert_page( $package_id, array $data, $parent_id, $lang ) {
		$existing = self::find_package_page( $package_id, $lang );

		$title = $data['title'];
		if ( '' === $title ) {
			$title = sprintf( 'Pakiet %s', $package_id );
		}

		$postarr = array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => '', // Rendered from meta; see MWP_Frontend.
			'post_parent'  => (int) $parent_id,
			'post_status'  => 'publish',
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;

			// Never fight an editor who has renamed the URL by hand.
			if ( ! get_post_meta( $existing, MWP_META_LOCKED, true ) ) {
				$postarr['post_name'] = mwp_slug( $title );
			}

			// Keep a manually unpublished page unpublished.
			$current = get_post_status( $existing );
			if ( in_array( $current, array( 'draft', 'private', 'pending' ), true ) ) {
				unset( $postarr['post_status'] );
			}

			$post_id = wp_update_post( $postarr, true );
		} else {
			$postarr['post_name'] = mwp_slug( $title );

			// Tell Polylang which language this insert belongs to. The filter
			// is removed immediately afterwards — leaving it attached would
			// force the language of every later insert in the same request.
			$set_lang = function () use ( $lang ) {
				return $lang;
			};
			add_filter( 'pll_inserted_post_language', $set_lang );
			$post_id = wp_insert_post( $postarr, true );
			remove_filter( 'pll_inserted_post_language', $set_lang );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $lang );
		}

		update_post_meta( $post_id, MWP_META_ID, $package_id );
		update_post_meta( $post_id, MWP_META_DATA, wp_slash( wp_json_encode( $data ) ) );
		update_post_meta( $post_id, MWP_META_SYNCED, current_time( 'mysql' ) );

		$this->maybe_set_featured_image( $post_id, $data );

		return $post_id;
	}

	/**
	 * Give the page a featured image so archives, Yoast and social cards work.
	 *
	 * The hero is sideloaded into the media library rather than hotlinked:
	 * Travel Compositor URLs change, and a local copy gets WordPress's own
	 * resizing and lazy-loading. A manual override always wins.
	 *
	 * @param int   $post_id Post.
	 * @param array $data    Payload.
	 * @return void
	 */
	protected function maybe_set_featured_image( $post_id, array $data ) {
		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		$override = get_post_meta( $post_id, MWP_META_IMAGE, true );
		$url      = $override ? $override : $data['hero_image'];
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			return;
		}

		if ( ! apply_filters( 'mwp_import_images', true, $post_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $post_id, $data['title'], 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			mwp_log( 'Hero image import failed', array(
				'post'  => $post_id,
				'url'   => $url,
				'error' => $attachment_id->get_error_message(),
			) );
			return;
		}

		set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * Tell Polylang that these posts are translations of each other.
	 *
	 * Without this the language switcher breaks on exactly the pages you most
	 * want indexed.
	 *
	 * @param array $posts language slug => post ID.
	 * @return void
	 */
	protected function link_translations( array $posts ) {
		if ( count( $posts ) < 2 || ! function_exists( 'pll_save_post_translations' ) ) {
			return;
		}
		pll_save_post_translations( array_map( 'intval', $posts ) );
	}

	/**
	 * The country page's counterpart in another language.
	 *
	 * Falls back to the Polish parent so an English page is never orphaned at
	 * the site root.
	 *
	 * @param int    $country_id Polish country page.
	 * @param string $lang       Target language.
	 * @return int
	 */
	protected function translated_parent( $country_id, $lang ) {
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $country_id, $lang );
			if ( $translated ) {
				return (int) $translated;
			}
		}
		return (int) $country_id;
	}

	// -----------------------------------------------------------------
	// Lookups
	// -----------------------------------------------------------------

	/**
	 * Find the page representing a package in a given language.
	 *
	 * @param string $package_id TC id.
	 * @param string $lang       Language slug.
	 * @return int 0 when not found.
	 */
	public static function find_package_page( $package_id, $lang = 'pl' ) {
		$args = array(
			'post_type'        => 'page',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array(
				array(
					'key'   => MWP_META_ID,
					'value' => (string) $package_id,
				),
			),
		);

		if ( function_exists( 'pll_current_language' ) ) {
			$args['lang'] = $lang;
		}

		$found = get_posts( $args );

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Package IDs entered on a country page.
	 *
	 * @param int $country_id Page ID.
	 * @return string[]
	 */
	public static function get_package_ids( $country_id ) {
		$raw = get_post_meta( $country_id, MWP_META_IDS, true );
		if ( ! $raw ) {
			return array();
		}

		$lines = preg_split( '/[\r\n,]+/', (string) $raw );
		$ids   = array();

		foreach ( $lines as $line ) {
			$id = mwp_extract_id( $line );
			if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Package pages belonging to a country page, in the order their IDs were
	 * entered — so the editor controls the order of the offer row.
	 *
	 * @param int    $country_id Country page.
	 * @param string $lang       Language slug.
	 * @return WP_Post[]
	 */
	public static function get_package_pages( $country_id, $lang = 'pl' ) {
		$posts = array();
		foreach ( self::get_package_ids( $country_id ) as $id ) {
			$post_id = self::find_package_page( $id, $lang );
			if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
				$posts[] = get_post( $post_id );
			}
		}
		return $posts;
	}

	/**
	 * Read the stored payload off a package page.
	 *
	 * @param int $post_id Post.
	 * @return array|null
	 */
	public static function get_data( $post_id ) {
		$raw = get_post_meta( $post_id, MWP_META_DATA, true );
		if ( ! $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}
}
