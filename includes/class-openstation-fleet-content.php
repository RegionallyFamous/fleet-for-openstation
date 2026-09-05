<?php
/**
 * Bounded Core content editing. No custom remote endpoints or block parser.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/** Content validation shared by the native editor and action boundary. */
final class OpenStation_Fleet_Content {
	/** Fields whose changes invalidate a pending editor or publishing review. */
	const FIELDS = 'id,title,content,excerpt,slug,status,date_gmt,author,featured_media,categories,tags,comment_status,ping_status';

	/** Optional Core publishing fields. */
	const PUBLISHING = array( 'author', 'featured_media', 'categories', 'tags', 'comment_status', 'ping_status' );

	/**
	 * Extract only editable fields, preserving WordPress block markup verbatim.
	 *
	 * @param array $values Untrusted form values.
	 * @return array|WP_Error
	 */
	public static function body( $values ) {
		$body = array();
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'status', 'date_gmt' ) as $key ) {
			if ( ! isset( $values[ $key ] ) || ! is_string( $values[ $key ] ) || strlen( $values[ $key ] ) > 200000 ) {
				return new WP_Error( 'fleet_content_invalid', __( 'Complete the editor fields. Each text field must be smaller than 200 KB.', 'fleet-for-openstation' ) );
			}
			$body[ $key ] = $values[ $key ];
		}
		// The destination WordPress account, not the hub, sanitizes content and blocks.
		if ( ! in_array( $body['status'], array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
			return new WP_Error( 'fleet_content_status', __( 'Choose a valid publishing status.', 'fleet-for-openstation' ) );
		}
		if ( '' === trim( $body['title'] ) ) {
			return new WP_Error( 'fleet_content_title', __( 'Give this post or page a title.', 'fleet-for-openstation' ) );
		}
		if ( '' === $body['date_gmt'] ) {
			unset( $body['date_gmt'] );
		} else {
			if ( ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $body['date_gmt'] ) ) {
				return new WP_Error( 'fleet_content_date', __( 'Use a UTC date such as 2026-12-31T14:30:00.', 'fleet-for-openstation' ) );
			}
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $body['date_gmt'], new DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d\TH:i:s' ) !== $body['date_gmt'] ) {
				return new WP_Error( 'fleet_content_date', __( 'Use a UTC date such as 2026-12-31T14:30:00.', 'fleet-for-openstation' ) );
			}
		}
		if ( 'future' === $body['status'] && ( empty( $body['date_gmt'] ) || strtotime( $body['date_gmt'] . 'Z' ) <= time() + 60 ) ) {
			return new WP_Error( 'fleet_content_schedule', __( 'Scheduled content needs a UTC date more than one minute in the future.', 'fleet-for-openstation' ) );
		}
		foreach ( self::PUBLISHING as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			if ( ! is_string( $value ) || strlen( $value ) > 2000 ) {
				return new WP_Error( 'fleet_publishing_invalid', __( 'Choose valid publishing options.', 'fleet-for-openstation' ) );
			}
			if ( in_array( $key, array( 'categories', 'tags' ), true ) ) {
				if ( '' !== $value && ! preg_match( '/\A[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,99}\z/', $value ) ) {
					return new WP_Error( 'fleet_publishing_terms', __( 'Choose up to 100 valid terms.', 'fleet-for-openstation' ) );
				}
				$body[ $key ] = '' === $value ? array() : array_values( array_unique( array_map( 'intval', explode( ',', $value ) ) ) );
			} elseif ( in_array( $key, array( 'author', 'featured_media' ), true ) ) {
				if ( '' === $value || ( 'author' === $key && '0' === $value ) ) {
					continue;
				}
				if ( ! preg_match( '/\A[0-9]{1,10}\z/', $value ) || ( 'author' === $key && (int) $value < 1 ) ) {
					return new WP_Error( 'fleet_publishing_id', __( 'Choose a valid author or image.', 'fleet-for-openstation' ) );
				}
				$body[ $key ] = (int) $value;
			} else {
				if ( '' === $value ) {
					continue;
				}
				if ( ! in_array( $value, array( 'open', 'closed' ), true ) ) {
					return new WP_Error( 'fleet_publishing_discussion', __( 'Choose open or closed discussion.', 'fleet-for-openstation' ) );
				}
				$body[ $key ] = $value;
			}
		}
		return $body;
	}

	/**
	 * Fingerprint fields the editor may overwrite, including same-second edits.
	 *
	 * @param array $item Core edit-context response.
	 * @return string
	 */
	public static function fingerprint( $item ) {
		return hash( 'sha256', (string) wp_json_encode( self::editable( $item ) ) );
	}

	/**
	 * Convert a Core response into the small editor model.
	 *
	 * @param array $item Core edit-context response.
	 * @return array
	 */
	public static function editable( $item ) {
		$result = array(
			'title'    => isset( $item['title']['raw'] ) ? $item['title']['raw'] : '',
			'content'  => isset( $item['content']['raw'] ) ? $item['content']['raw'] : '',
			'excerpt'  => isset( $item['excerpt']['raw'] ) ? $item['excerpt']['raw'] : '',
			'slug'     => isset( $item['slug'] ) ? $item['slug'] : '',
			'status'   => isset( $item['status'] ) ? $item['status'] : 'draft',
			'date_gmt' => isset( $item['date_gmt'] ) ? $item['date_gmt'] : '',
		);
		foreach ( self::PUBLISHING as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$value = $item[ $key ];
				if ( in_array( $key, array( 'categories', 'tags' ), true ) ) {
					$value = array_map( 'intval', (array) $value );
					sort( $value, SORT_NUMERIC );
					$value = implode( ',', $value );
				}
				$result[ $key ] = (string) $value;
			}
		}
		return $result;
	}

	/**
	 * Validate route selection independently of client-provided state.
	 *
	 * @param string $type Collection name.
	 * @return bool
	 */
	public static function valid_type( $type ) {
		return is_string( $type ) && 1 === preg_match( '/\A[a-z0-9_-]{1,64}\z/', $type );
	}

	/**
	 * Map advertised, editable content types to safe relative REST routes.
	 * Internal design types and attachments have their own Fleet surfaces.
	 *
	 * @param array $types Core types response.
	 * @param array $capabilities Current remote user capabilities.
	 * @return array
	 */
	public static function types( $types, $capabilities ) {
		$result = array();
		foreach ( $types as $slug => $type ) {
			if ( ! self::valid_type( $slug ) || ! is_array( $type ) || 'attachment' === $slug || 0 === strpos( $slug, 'wp_' ) || empty( $type['viewable'] ) || empty( $type['supports']['title'] ) || empty( $type['supports']['editor'] ) ) {
				continue;
			}
			$route = ( isset( $type['rest_namespace'] ) ? $type['rest_namespace'] : '' ) . '/' . ( isset( $type['rest_base'] ) ? $type['rest_base'] : '' );
			$cap   = isset( $type['capabilities']['edit_posts'] ) ? $type['capabilities']['edit_posts'] : '';
			if ( empty( $capabilities[ $cap ] ) || ! preg_match( '#\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)+\z#', $route ) ) {
				continue;
			}
			// Reserve built-in aliases without hiding custom types named posts/pages.
			$key            = in_array( $slug, array( 'posts', 'pages' ), true ) ? 'wp_type_' . $slug : $slug;
			$key            = 'post' === $slug ? 'posts' : ( 'page' === $slug ? 'pages' : $key );
			$result[ $key ] = array(
				'route'      => $route,
				'name'       => sanitize_text_field( isset( $type['name'] ) ? $type['name'] : $slug ),
				'supports'   => array_intersect_key( $type['supports'], array_flip( array( 'title', 'editor', 'excerpt', 'revisions', 'author', 'thumbnail', 'comments' ) ) ),
				'taxonomies' => array_values( array_intersect( (array) ( $type['taxonomies'] ?? array() ), array( 'category', 'post_tag' ) ) ),
			);
		}
		return $result;
	}

	/**
	 * Sign the exact reviewed operation, scoped to this user and connection.
	 * No content is persisted in a token or transient.
	 *
	 * @param array $site Connection record.
	 * @param array $values Editor values.
	 * @param int   $expires Expiry timestamp.
	 * @return string
	 */
	public static function review_token( $site, $values, $expires ) {
		$payload = array( 'fleet-content-review', get_current_blog_id(), get_current_user_id(), $site['site_url'], $site['connection_generation'], (int) $expires );
		foreach ( array_merge( array( 'content_type', 'content_id', 'fingerprint', 'request_id', 'title', 'content', 'excerpt', 'slug', 'status', 'date_gmt' ), self::PUBLISHING ) as $key ) {
			$payload[] = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		}
		return hash_hmac( 'sha256', (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	/**
	 * Verify a fresh review, including any fields changed after preview.
	 *
	 * @param array $site Connection record.
	 * @param array $values Editor values.
	 * @return bool
	 */
	public static function reviewed( $site, $values ) {
		$expires = isset( $values['review_expires'] ) ? (int) $values['review_expires'] : 0;
		return $expires >= time() && $expires <= time() + 600 && isset( $values['review_token'] ) && is_string( $values['review_token'] ) && hash_equals( self::review_token( $site, $values, $expires ), $values['review_token'] );
	}
}
