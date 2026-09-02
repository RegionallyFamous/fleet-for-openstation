<?php
/**
 * Incremental, bounded search indexing for connected WordPress sites.
 *
 * @package FleetForOpenStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advances a local search index one Core REST collection page at a time.
 *
 * The class is deliberately transport-agnostic. Callers may pass a callback
 * that reads WordPress Core REST endpoints, or named arrays in tests. The
 * returned state is JSON-safe and can be stored alongside a connected site.
 */
final class OpenStation_Fleet_Search_Index {
	const STATE_VERSION = 1;

	/**
	 * Collections visited in round-robin order.
	 *
	 * @var array<string>
	 */
	private $collections;

	/**
	 * Maximum records retained across the whole index.
	 *
	 * @var int
	 */
	private $max_items;

	/**
	 * Maximum records retained for any one collection.
	 *
	 * @var int
	 */
	private $max_per_collection;

	/**
	 * Maximum raw records requested in one remote page.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Seconds between full collection reconciliations.
	 *
	 * @var int
	 */
	private $reconciliation_interval;

	/**
	 * Configure index bounds and scheduling.
	 *
	 * Supported options are `collections`, `max_items`,
	 * `max_per_collection`, `per_page`, and `reconciliation_interval`.
	 *
	 * @param array $options Optional configuration.
	 */
	public function __construct( $options = array() ) {
		$known       = array( 'posts', 'pages', 'users', 'comments', 'media' );
		$collections = isset( $options['collections'] ) && is_array( $options['collections'] )
			? $options['collections']
			: $known;
		$collections = array_values( array_unique( array_intersect( $known, $collections ) ) );

		$this->collections             = ! empty( $collections ) ? $collections : $known;
		$this->max_items               = $this->bounded_int( isset( $options['max_items'] ) ? $options['max_items'] : 750, 25, 5000 );
		$this->max_per_collection      = $this->bounded_int( isset( $options['max_per_collection'] ) ? $options['max_per_collection'] : 250, 10, 2000 );
		$this->per_page                = $this->bounded_int( isset( $options['per_page'] ) ? $options['per_page'] : 50, 1, 100 );
		$this->reconciliation_interval = $this->bounded_int( isset( $options['reconciliation_interval'] ) ? $options['reconciliation_interval'] : 86400, 300, 30 * 86400 );
	}

	/**
	 * Return a new JSON-safe index state.
	 *
	 * Each collection begins with a full reconciliation so older records are
	 * discovered page by page before normal watermark-based refreshes begin.
	 *
	 * @param int|null $now Unix timestamp. Defaults to the current time.
	 * @return array Index state.
	 */
	public function initial_state( $now = null ) {
		$now         = $this->timestamp( $now );
		$collections = array();
		foreach ( $this->collections as $collection ) {
			$collections[ $collection ] = $this->initial_collection_state();
		}

		return array(
			'version'         => self::STATE_VERSION,
			'items'           => array(),
			'collections'     => $collections,
			'next_collection' => 0,
			'updated_at'      => $now,
		);
	}

	/**
	 * Sanitize a previously stored state and add any missing defaults.
	 *
	 * @param array    $state Stored state.
	 * @param int|null $now   Unix timestamp. Defaults to the current time.
	 * @return array Normalized state.
	 */
	public function normalize_state( $state, $now = null ) {
		$now = $this->timestamp( $now );
		if ( ! is_array( $state ) || self::STATE_VERSION !== (int) ( isset( $state['version'] ) ? $state['version'] : 0 ) ) {
			return $this->initial_state( $now );
		}

		$normalized = $this->initial_state( $now );
		$items      = isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : array();
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) || empty( $item['_source'] ) || ! in_array( $item['_source'], $this->collections, true ) ) {
				continue;
			}
			$clean = $this->normalize_record( $item['_source'], $item, $now );
			if ( null === $clean ) {
				continue;
			}
			$clean['_generation'] = max( 0, (int) ( isset( $item['_generation'] ) ? $item['_generation'] : 0 ) );
			$clean['_seen_at']    = max( 0, (int) ( isset( $item['_seen_at'] ) ? $item['_seen_at'] : $now ) );
			$normalized['items'][ $this->record_key( $clean['_source'], $clean['_id'] ) ] = $clean;
		}

		$stored_collections = isset( $state['collections'] ) && is_array( $state['collections'] ) ? $state['collections'] : array();
		foreach ( $this->collections as $collection ) {
			$stored                                   = isset( $stored_collections[ $collection ] ) && is_array( $stored_collections[ $collection ] )
				? $stored_collections[ $collection ]
				: array();
			$normalized['collections'][ $collection ] = $this->normalize_collection_state( $stored );
		}

		$normalized['next_collection'] = (int) ( isset( $state['next_collection'] ) ? $state['next_collection'] : 0 ) % count( $this->collections );
		$normalized['updated_at']      = max( 0, (int) ( isset( $state['updated_at'] ) ? $state['updated_at'] : $now ) );

		return $this->prune( $normalized );
	}

	/**
	 * Describe the next bounded page to fetch.
	 *
	 * The callback should translate `collection`, `page`, `per_page`, and the
	 * optional ISO-8601 `after` watermark into the corresponding Core REST
	 * query. Reconciliation requests intentionally omit `after`.
	 *
	 * @param array    $state Stored index state.
	 * @param int|null $now   Unix timestamp. Defaults to the current time.
	 * @return array Request descriptor.
	 */
	public function next_request( $state, $now = null ) {
		$now        = $this->timestamp( $now );
		$state      = $this->normalize_state( $state, $now );
		$position   = $state['next_collection'] % count( $this->collections );
		$collection = $this->collections[ $position ];
		$progress   = $state['collections'][ $collection ];
		$mode       = $progress['mode'];
		$page       = $progress['cursor'];
		$generation = $progress['generation'];

		if ( 'incremental' === $mode && 1 === $page && $now >= $progress['reconcile_at'] ) {
			$mode       = 'reconcile';
			$generation = $progress['generation'] + 1;
		}

		return array(
			'collection' => $collection,
			'page'       => $page,
			'per_page'   => $this->per_page,
			'mode'       => $mode,
			'after'      => 'incremental' === $mode && $progress['watermark'] > 0 ? gmdate( 'c', $progress['watermark'] ) : '',
			'generation' => $generation,
		);
	}

	/**
	 * Merge one fetched page into the index.
	 *
	 * A response may be a plain list of items, or an envelope containing
	 * `items` and optional `has_more`, `total_pages`, `deleted`, or `error`
	 * values. A complete reconciliation removes records no longer returned by
	 * WordPress. Failed pages retain their cursor for a later retry.
	 *
	 * @param array       $state    Stored index state.
	 * @param array       $request  Descriptor returned by next_request().
	 * @param array|mixed $response Plain item list or response envelope.
	 * @param int|null    $now      Unix timestamp. Defaults to the current time.
	 * @return array Updated index state.
	 */
	public function apply_page( $state, $request, $response, $now = null ) {
		$now   = $this->timestamp( $now );
		$state = $this->normalize_state( $state, $now );
		if ( ! is_array( $request ) || empty( $request['collection'] ) || ! in_array( $request['collection'], $this->collections, true ) ) {
			return $state;
		}

		$collection = $request['collection'];
		$progress   = $state['collections'][ $collection ];
		$error      = $this->response_error( $response );
		if ( '' !== $error ) {
			$progress['last_error']              = $this->clean_text( $error, 300 );
			$progress['last_attempt_at']         = $now;
			$state['collections'][ $collection ] = $progress;
			return $this->advance_collection_pointer( $state, $now );
		}

		$envelope   = $this->response_envelope( $response, isset( $request['page'] ) ? $request['page'] : 1 );
		$items      = $envelope['items'];
		$mode       = isset( $request['mode'] ) && 'incremental' === $request['mode'] ? 'incremental' : 'reconcile';
		$page       = max( 1, (int) ( isset( $request['page'] ) ? $request['page'] : 1 ) );
		$generation = max( 1, (int) ( isset( $request['generation'] ) ? $request['generation'] : $progress['generation'] ) );
		$cycle_max  = 0;

		foreach ( $items as $item ) {
			$record = $this->normalize_record( $collection, $item, $now );
			if ( null === $record ) {
				continue;
			}
			$key = $this->record_key( $collection, $record['_id'] );
			if ( 'incremental' === $mode && isset( $state['items'][ $key ]['_generation'] ) ) {
				$record['_generation'] = $state['items'][ $key ]['_generation'];
			} else {
				$record['_generation'] = $generation;
			}
			$record['_seen_at']     = $now;
			$state['items'][ $key ] = $record;
			$cycle_max              = max( $cycle_max, $record['_modified'] );
		}

		foreach ( $envelope['deleted'] as $deleted_id ) {
			unset( $state['items'][ $this->record_key( $collection, $deleted_id ) ] );
		}

		$progress['mode']            = $mode;
		$progress['generation']      = $generation;
		$progress['cycle_watermark'] = max( $progress['cycle_watermark'], $cycle_max, $envelope['watermark'] );
		$progress['last_attempt_at'] = $now;
		$progress['last_error']      = '';
		if ( 'incremental' === $mode && empty( $request['after'] ) ) {
			// Collections without a usable watermark refresh their newest page
			// between complete reconciliations instead of rescanning every page.
			$envelope['has_more'] = false;
		}
		if ( 'reconcile' === $mode && $page >= (int) ceil( $this->max_per_collection / $this->per_page ) ) {
			// Fleet retains only a recent bounded window, so do not spend future
			// hourly passes downloading older pages that pruning would discard.
			$envelope['has_more'] = false;
		}

		if ( $envelope['has_more'] ) {
			$progress['cursor'] = $page + 1;
		} else {
			if ( 'reconcile' === $mode ) {
				foreach ( $state['items'] as $key => $record ) {
					if ( $collection === $record['_source'] && $generation !== (int) $record['_generation'] ) {
						unset( $state['items'][ $key ] );
					}
				}
				$progress['reconcile_at'] = $now + $this->reconciliation_interval;
			}
			$progress['mode']              = 'incremental';
			$progress['cursor']            = 1;
			$progress['watermark']         = max( $progress['watermark'], $progress['cycle_watermark'] );
			$progress['cycle_watermark']   = 0;
			$progress['last_completed_at'] = $now;
		}

		$state['collections'][ $collection ] = $progress;
		$state                               = $this->advance_collection_pointer( $state, $now );
		return $this->prune( $state );
	}

	/**
	 * Fetch and merge a small number of pages.
	 *
	 * `$source` may be a callable receiving a request descriptor, or an array
	 * keyed by collection containing raw Core REST records. Array sources are
	 * paginated automatically and are primarily useful for deterministic tests.
	 *
	 * @param array          $state  Stored index state.
	 * @param callable|array $source Page fetcher or named record arrays.
	 * @param int|null       $now    Unix timestamp. Defaults to the current time.
	 * @param int            $budget Maximum pages to fetch, capped at 20.
	 * @return array Updated index state.
	 */
	public function advance( $state, $source, $now = null, $budget = 1 ) {
		$now    = $this->timestamp( $now );
		$state  = $this->normalize_state( $state, $now );
		$budget = $this->bounded_int( $budget, 1, 20 );

		for ( $step = 0; $step < $budget; ++$step ) {
			$request = $this->next_request( $state, $now );
			try {
				if ( is_callable( $source ) ) {
					$response = call_user_func( $source, $request );
				} elseif ( is_array( $source ) ) {
					$all      = isset( $source[ $request['collection'] ] ) && is_array( $source[ $request['collection'] ] ) ? array_values( $source[ $request['collection'] ] ) : array();
					$offset   = ( $request['page'] - 1 ) * $request['per_page'];
					$response = array(
						'items'       => array_slice( $all, $offset, $request['per_page'] ),
						'total_pages' => max( 1, (int) ceil( count( $all ) / $request['per_page'] ) ),
					);
				} else {
					$response = array( 'error' => 'Search index source is not callable or an array.' );
				}
			} catch ( Throwable $exception ) {
				$response = array( 'error' => $exception->getMessage() );
			}
			$state = $this->apply_page( $state, $request, $response, $now );
		}

		return $state;
	}

	/**
	 * Export normalized records for Fleet's existing search view.
	 *
	 * @param array    $state Stored index state.
	 * @param int|null $limit Optional result limit.
	 * @return array Public search records without index bookkeeping fields.
	 */
	public function records( $state, $limit = null ) {
		$state = $this->normalize_state( $state );
		$items = array_values( $state['items'] );
		usort( $items, array( $this, 'compare_records' ) );
		if ( null !== $limit ) {
			$items = array_slice( $items, 0, max( 0, (int) $limit ) );
		}
		return array_map( array( $this, 'public_record' ), $items );
	}

	/**
	 * Search the local index without making remote requests.
	 *
	 * @param array  $state Stored index state.
	 * @param string $query Case-insensitive title and metadata query.
	 * @param int    $limit Maximum records returned.
	 * @return array Matching public records.
	 */
	public function search( $state, $query, $limit = 24 ) {
		$query = $this->lower( $this->clean_text( $query, 200 ) );
		if ( '' === $query ) {
			return array();
		}

		$matches = array();
		foreach ( $this->records( $state ) as $record ) {
			if ( false !== strpos( $this->lower( $record['title'] . ' ' . $record['meta'] ), $query ) ) {
				$matches[] = $record;
				if ( count( $matches ) >= max( 1, (int) $limit ) ) {
					break;
				}
			}
		}
		return $matches;
	}

	/**
	 * Normalize one Core REST record into Fleet's search schema.
	 *
	 * @param string   $collection Core collection name.
	 * @param array    $item       Core REST record.
	 * @param int|null $now        Unix timestamp. Defaults to the current time.
	 * @return array|null Normalized record, or null when no stable record can be made.
	 */
	public function normalize_record( $collection, $item, $now = null ) {
		if ( ! in_array( $collection, $this->collections, true ) || ! is_array( $item ) ) {
			return null;
		}
		$now = $this->timestamp( $now );
		$id  = isset( $item['_id'] ) ? $item['_id'] : ( isset( $item['id'] ) ? $item['id'] : '' );
		if ( '' === (string) $id ) {
			$encoded = wp_json_encode( $item );
			if ( false === $encoded ) {
				return null;
			}
			$id = sha1( $collection . ':' . $encoded );
		}
		$id = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $id );
		if ( '' === $id ) {
			return null;
		}
		if ( isset( $item['_source'], $item['_id'], $item['title'], $item['meta'], $item['section'], $item['icon'] ) ) {
			return array(
				'title'       => $this->clean_text( $item['title'], 240 ),
				'meta'        => $this->clean_text( $item['meta'], 500 ),
				'section'     => $this->clean_key( $item['section'] ),
				'icon'        => $this->clean_key( $item['icon'] ),
				'_source'     => $collection,
				'_id'         => $id,
				'_modified'   => $this->item_timestamp( $item ),
				'_generation' => max( 0, (int) ( isset( $item['_generation'] ) ? $item['_generation'] : 0 ) ),
				'_seen_at'    => max( 0, (int) ( isset( $item['_seen_at'] ) ? $item['_seen_at'] : $now ) ),
			);
		}

		$title    = '';
		$meta     = '';
		$section  = 'content';
		$icon     = 'dashicons-admin-post';
		$modified = $this->item_timestamp( $item );

		switch ( $collection ) {
			case 'pages':
				$title = $this->rendered_text( isset( $item['title'] ) ? $item['title'] : '' );
				$meta  = 'Page · ' . ucfirst( $this->clean_key( isset( $item['status'] ) ? $item['status'] : 'page' ) );
				$icon  = 'dashicons-admin-page';
				break;
			case 'users':
				$title   = $this->clean_text( isset( $item['name'] ) ? $item['name'] : 'User', 240 );
				$roles   = isset( $item['roles'] ) && is_array( $item['roles'] ) ? array_map( array( $this, 'clean_key' ), $item['roles'] ) : array();
				$meta    = ! empty( $roles ) ? implode( ', ', array_filter( $roles ) ) : 'WordPress user';
				$section = 'users';
				$icon    = 'dashicons-admin-users';
				break;
			case 'comments':
				$title   = $this->clean_text( isset( $item['author_name'] ) ? $item['author_name'] : 'Comment', 240 );
				$meta    = $this->rendered_text( isset( $item['content'] ) ? $item['content'] : 'Comment', 500 );
				$section = 'comments';
				$icon    = 'dashicons-admin-comments';
				break;
			case 'media':
				$title   = $this->rendered_text( isset( $item['title'] ) ? $item['title'] : '(Untitled media)' );
				$meta    = $this->clean_text( isset( $item['mime_type'] ) ? $item['mime_type'] : 'Media', 500 );
				$section = 'media';
				$icon    = 'dashicons-format-image';
				break;
			case 'posts':
			default:
				$title = $this->rendered_text( isset( $item['title'] ) ? $item['title'] : '' );
				$meta  = 'Post · ' . ucfirst( $this->clean_key( isset( $item['status'] ) ? $item['status'] : 'post' ) );
				break;
		}

		return array(
			'title'       => '' !== $title ? $title : '(Untitled)',
			'meta'        => '' !== $meta ? $meta : ucfirst( $collection ),
			'section'     => $section,
			'icon'        => $icon,
			'_source'     => $collection,
			'_id'         => $id,
			'_modified'   => $modified,
			'_generation' => max( 0, (int) ( isset( $item['_generation'] ) ? $item['_generation'] : 0 ) ),
			'_seen_at'    => max( 0, (int) ( isset( $item['_seen_at'] ) ? $item['_seen_at'] : $now ) ),
		);
	}

	/**
	 * Return default progress for one collection.
	 *
	 * @return array Collection progress.
	 */
	private function initial_collection_state() {
		return array(
			'mode'              => 'reconcile',
			'cursor'            => 1,
			'watermark'         => 0,
			'cycle_watermark'   => 0,
			'generation'        => 1,
			'reconcile_at'      => 0,
			'last_attempt_at'   => 0,
			'last_completed_at' => 0,
			'last_error'        => '',
		);
	}

	/**
	 * Normalize stored progress for one collection.
	 *
	 * @param array $stored Stored progress.
	 * @return array Normalized progress.
	 */
	private function normalize_collection_state( $stored ) {
		$state                      = array_merge( $this->initial_collection_state(), is_array( $stored ) ? $stored : array() );
		$state['mode']              = 'incremental' === $state['mode'] ? 'incremental' : 'reconcile';
		$state['cursor']            = max( 1, (int) $state['cursor'] );
		$state['watermark']         = max( 0, (int) $state['watermark'] );
		$state['cycle_watermark']   = max( 0, (int) $state['cycle_watermark'] );
		$state['generation']        = max( 1, (int) $state['generation'] );
		$state['reconcile_at']      = max( 0, (int) $state['reconcile_at'] );
		$state['last_attempt_at']   = max( 0, (int) $state['last_attempt_at'] );
		$state['last_completed_at'] = max( 0, (int) $state['last_completed_at'] );
		$state['last_error']        = $this->clean_text( $state['last_error'], 300 );
		return $state;
	}

	/**
	 * Convert a response into a consistent envelope.
	 *
	 * @param mixed $response Page response.
	 * @param int   $page     Requested page number.
	 * @return array Normalized response envelope.
	 */
	private function response_envelope( $response, $page ) {
		if ( is_array( $response ) && ! $this->is_list( $response ) && array_key_exists( 'items', $response ) ) {
			$items = is_array( $response['items'] ) ? array_values( $response['items'] ) : array();
		} else {
			$items    = is_array( $response ) ? array_values( $response ) : array();
			$response = array();
		}

		if ( isset( $response['has_more'] ) ) {
			$has_more = (bool) $response['has_more'];
		} elseif ( isset( $response['total_pages'] ) ) {
			$has_more = (int) $page < (int) $response['total_pages'];
		} else {
			$has_more = count( $items ) >= $this->per_page;
		}

		return array(
			'items'     => $items,
			'has_more'  => $has_more,
			'deleted'   => isset( $response['deleted'] ) && is_array( $response['deleted'] ) ? $response['deleted'] : array(),
			'watermark' => $this->value_timestamp( isset( $response['watermark'] ) ? $response['watermark'] : 0 ),
		);
	}

	/**
	 * Extract an error without requiring WordPress to be loaded.
	 *
	 * @param mixed $response Page response.
	 * @return string Error message, or an empty string.
	 */
	private function response_error( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'get_error_message' ) ) {
			return (string) $response->get_error_message();
		}
		if ( is_array( $response ) && ! empty( $response['error'] ) ) {
			return is_scalar( $response['error'] ) ? (string) $response['error'] : 'Search index request failed.';
		}
		if ( ! is_array( $response ) ) {
			return 'Search index request returned an invalid response.';
		}
		return '';
	}

	/**
	 * Move fairly to the next collection.
	 *
	 * @param array $state Index state.
	 * @param int   $now   Unix timestamp.
	 * @return array Updated state.
	 */
	private function advance_collection_pointer( $state, $now ) {
		$state['next_collection'] = ( $state['next_collection'] + 1 ) % count( $this->collections );
		$state['updated_at']      = $now;
		return $state;
	}

	/**
	 * Enforce per-collection and total storage bounds.
	 *
	 * @param array $state Index state.
	 * @return array Bounded state.
	 */
	private function prune( $state ) {
		foreach ( $this->collections as $collection ) {
			$keys = array();
			foreach ( $state['items'] as $key => $record ) {
				if ( $collection === $record['_source'] ) {
					$keys[] = $key;
				}
			}
			usort(
				$keys,
				function ( $left, $right ) use ( $state ) {
					return $this->compare_records( $state['items'][ $left ], $state['items'][ $right ] );
				}
			);
			foreach ( array_slice( $keys, $this->max_per_collection ) as $key ) {
				unset( $state['items'][ $key ] );
			}
		}

		if ( count( $state['items'] ) > $this->max_items ) {
			$keys = array_keys( $state['items'] );
			usort(
				$keys,
				function ( $left, $right ) use ( $state ) {
					return $this->compare_records( $state['items'][ $left ], $state['items'][ $right ] );
				}
			);
			foreach ( array_slice( $keys, $this->max_items ) as $key ) {
				unset( $state['items'][ $key ] );
			}
		}

		return $state;
	}

	/**
	 * Sort freshest records first with stable tie-breaking.
	 *
	 * @param array $left  Left record.
	 * @param array $right Right record.
	 * @return int Comparison result.
	 */
	private function compare_records( $left, $right ) {
		$left_modified  = (int) $left['_modified'];
		$right_modified = (int) $right['_modified'];
		if ( $left_modified !== $right_modified ) {
			return $left_modified > $right_modified ? -1 : 1;
		}
		if ( (int) $left['_seen_at'] !== (int) $right['_seen_at'] ) {
			return (int) $left['_seen_at'] > (int) $right['_seen_at'] ? -1 : 1;
		}
		return strcmp( $this->record_key( $left['_source'], $left['_id'] ), $this->record_key( $right['_source'], $right['_id'] ) );
	}

	/**
	 * Remove private bookkeeping fields.
	 *
	 * @param array $record Internal record.
	 * @return array Public Fleet search record.
	 */
	private function public_record( $record ) {
		return array_intersect_key( $record, array_flip( array( 'title', 'meta', 'section', 'icon' ) ) );
	}

	/**
	 * Build a stable deduplication key.
	 *
	 * @param string $collection Collection name.
	 * @param string $id         Core record id.
	 * @return string Record key.
	 */
	private function record_key( $collection, $id ) {
		return $collection . ':' . (string) $id;
	}

	/**
	 * Extract the best available modification timestamp from a Core record.
	 *
	 * @param array $item Core REST record.
	 * @return int Unix timestamp, or zero.
	 */
	private function item_timestamp( $item ) {
		foreach ( array( '_modified', 'modified_gmt', 'modified', 'date_gmt', 'date' ) as $key ) {
			if ( ! empty( $item[ $key ] ) ) {
				$value = $item[ $key ];
				if ( false !== strpos( $key, '_gmt' ) && is_string( $value ) && ! preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
					$value .= 'Z';
				}
				$timestamp = $this->value_timestamp( $value );
				if ( $timestamp > 0 ) {
					return $timestamp;
				}
			}
		}
		return 0;
	}

	/**
	 * Convert an integer or date string to a Unix timestamp.
	 *
	 * @param mixed $value Timestamp or date string.
	 * @return int Unix timestamp, or zero.
	 */
	private function value_timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}
		$timestamp = is_string( $value ) ? strtotime( $value ) : false;
		return false === $timestamp ? 0 : max( 0, $timestamp );
	}

	/**
	 * Extract rendered text from Core REST fields.
	 *
	 * @param mixed $value Raw field value.
	 * @param int   $limit Maximum characters.
	 * @return string Clean display text.
	 */
	private function rendered_text( $value, $limit = 240 ) {
		if ( is_array( $value ) ) {
			$value = isset( $value['rendered'] ) ? $value['rendered'] : '';
		}
		return $this->clean_text( $value, $limit );
	}

	/**
	 * Sanitize display text with WordPress Core.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum characters.
	 * @return string Clean display text.
	 */
	private function clean_text( $value, $limit = 240 ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_strip_all_tags( $value, true );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( is_string( $value ) ? $value : '' );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit );
		}
		return substr( $value, 0, $limit );
	}

	/**
	 * Sanitize a Core key such as a role or status.
	 *
	 * @param mixed $value Raw key.
	 * @return string Clean key.
	 */
	private function clean_key( $value ) {
		return sanitize_key( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Lowercase Unicode text when mbstring is available.
	 *
	 * @param string $value Text to lowercase.
	 * @return string Lowercase text.
	 */
	private function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Clamp an integer option.
	 *
	 * @param mixed $value   Raw integer.
	 * @param int   $minimum Minimum value.
	 * @param int   $maximum Maximum value.
	 * @return int Bounded integer.
	 */
	private function bounded_int( $value, $minimum, $maximum ) {
		return max( $minimum, min( $maximum, (int) $value ) );
	}

	/**
	 * Normalize a caller-supplied timestamp.
	 *
	 * @param int|null $now Optional Unix timestamp.
	 * @return int Unix timestamp.
	 */
	private function timestamp( $now ) {
		return null === $now ? time() : max( 0, (int) $now );
	}

	/**
	 * Detect sequential arrays on PHP 7.4.
	 *
	 * @param mixed $value Candidate array.
	 * @return bool Whether the value is a list.
	 */
	private function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$position = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $position++ ) {
				return false;
			}
		}
		return true;
	}
}
