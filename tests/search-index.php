<?php
/**
 * Standalone tests for the incremental Fleet search index.
 *
 * Run with: php tests/search-index.php
 *
 * @package FleetForOpenStation
 */

define( 'ABSPATH', __DIR__ . '/' );

/**
 * Minimal standalone replacement for Core's tag stripping helper.
 *
 * @param string $text Text to clean.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This standalone WordPress test double must call native PHP.
	return strip_tags( (string) $text );
}

/**
 * Minimal standalone replacement for Core's key sanitizer.
 *
 * @param string $key Key to clean.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) );
}

/**
 * Minimal standalone replacement for Core's JSON helper.
 *
 * @param mixed $value Value to encode.
 * @return string|false
 */
function wp_json_encode( $value ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This standalone WordPress test double must call native PHP.
	return json_encode( $value );
}

require dirname( __DIR__ ) . '/includes/class-openstation-fleet-search-index.php';

/**
 * Fail with a useful message instead of relying on disabled PHP assertions.
 *
 * @param bool   $condition Test result.
 * @param string $message   Failure message.
 * @return void
 */
function fleet_search_expect( $condition, $message ) {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI test output.
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/**
 * Find one record by title.
 *
 * @param array  $records Public index records.
 * @param string $title   Expected title.
 * @return bool Whether the title exists.
 */
function fleet_search_has_title( $records, $title ) {
	foreach ( $records as $record ) {
		if ( isset( $record['title'] ) && $title === $record['title'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Make Core-like posts with deterministic modification dates.
 *
 * @param int    $count  Number of posts.
 * @param string $prefix Title prefix.
 * @return array Core-like post records.
 */
function fleet_search_posts( $count, $prefix = 'Article' ) {
	$posts = array();
	for ( $id = 1; $id <= $count; ++$id ) {
		$posts[] = array(
			'id'           => $id,
			'title'        => array( 'rendered' => $prefix . ' ' . $id ),
			'status'       => 'publish',
			'modified_gmt' => gmdate( 'Y-m-d\TH:i:s', 1700000000 + $id ),
		);
	}
	return $posts;
}

// Full pagination discovers records beyond the first page, then switches to
// a watermark-based incremental request.
$index   = new OpenStation_Fleet_Search_Index(
	array(
		'collections'             => array( 'posts' ),
		'per_page'                => 2,
		'max_items'               => 100,
		'max_per_collection'      => 100,
		'reconciliation_interval' => 300,
	)
);
$state   = $index->initial_state( 1000 );
$state   = $index->advance( $state, array( 'posts' => fleet_search_posts( 5 ) ), 1000, 3 );
$records = $index->records( $state );
fleet_search_expect( 5 === count( $records ), 'all three pages should be indexed' );
fleet_search_expect( fleet_search_has_title( $records, 'Article 5' ), 'an item beyond page one should be searchable' );

$request = $index->next_request( $state, 1001 );
fleet_search_expect( 'incremental' === $request['mode'], 'a completed reconciliation should enter incremental mode' );
fleet_search_expect( '' !== $request['after'] && false !== strtotime( $request['after'] ), 'incremental requests should carry an ISO-8601 watermark' );

// Core pagination headers prevent an exactly full final page from advancing
// to a nonexistent page and leaving reconciliation stuck there forever.
$exact_index = new OpenStation_Fleet_Search_Index(
	array(
		'collections' => array( 'posts' ),
		'per_page'    => 2,
	)
);
$exact_state = $exact_index->advance(
	$exact_index->initial_state( 1000 ),
	static function () {
		return array(
			'items'       => fleet_search_posts( 2 ),
			'total_pages' => 1,
		);
	},
	1000
);
fleet_search_expect( 1 === $exact_state['collections']['posts']['cursor'], 'an exactly full final page should complete reconciliation' );
fleet_search_expect( 'incremental' === $exact_state['collections']['posts']['mode'], 'an exactly full final page should enter incremental mode' );

// Updating an existing Core id replaces it rather than creating a duplicate.
$captured = array();
$state    = $index->advance(
	$state,
	function ( $next ) use ( &$captured ) {
		$captured = $next;
		return array(
			'items'       => array(
				array(
					'id'           => 3,
					'title'        => array( 'rendered' => 'Article 3 revised' ),
					'status'       => 'draft',
					'modified_gmt' => '2026-09-01T12:00:00',
				),
			),
			'total_pages' => 1,
		);
	},
	1001
);
$records  = $index->records( $state );
fleet_search_expect( 5 === count( $records ), 'incremental updates should deduplicate by collection and id' );
fleet_search_expect( fleet_search_has_title( $records, 'Article 3 revised' ), 'the newer normalized record should replace the older one' );
fleet_search_expect( 'incremental' === $captured['mode'], 'the callback should receive the request descriptor' );
fleet_search_expect( 1 === count( $index->search( $state, 'revised', 24 ) ), 'local search should find updated title text' );

// Explicit deletion hints remove a record immediately.
$request = $index->next_request( $state, 1002 );
$state   = $index->apply_page(
	$state,
	$request,
	array(
		'items'       => array(),
		'deleted'     => array( 2 ),
		'total_pages' => 1,
	),
	1002
);
fleet_search_expect( 4 === count( $index->records( $state ) ), 'explicit deleted ids should be removed' );

// Periodic reconciliation catches deletions even when the transport has no
// deletion feed. The former id 4 is absent from the fresh complete scan.
$remaining = array( fleet_search_posts( 5 )[0], fleet_search_posts( 5 )[2], fleet_search_posts( 5 )[4] );
$state     = $index->advance( $state, array( 'posts' => $remaining ), 1400, 2 );
$records   = $index->records( $state );
fleet_search_expect( 3 === count( $records ), 'complete reconciliation should sweep stale records' );
fleet_search_expect( ! fleet_search_has_title( $records, 'Article 4' ), 'a deleted remote record should not survive reconciliation' );

// Collections without modification watermarks refresh only page one between
// reconciliations, then resume complete pagination when reconciliation is due.
$user_index  = new OpenStation_Fleet_Search_Index(
	array(
		'collections'             => array( 'users' ),
		'per_page'                => 2,
		'reconciliation_interval' => 300,
	)
);
$user_source = array(
	'users' => array(
		array(
			'id'   => 1,
			'name' => 'Editor One',
		),
		array(
			'id'   => 2,
			'name' => 'Editor Two',
		),
		array(
			'id'   => 3,
			'name' => 'Editor Three',
		),
	),
);
$user_state  = $user_index->advance( $user_index->initial_state( 1500 ), $user_source, 1500, 2 );
$user_next   = $user_index->next_request( $user_state, 1501 );
fleet_search_expect( 'incremental' === $user_next['mode'] && '' === $user_next['after'], 'users should expose that no watermark is available' );
$user_state = $user_index->apply_page(
	$user_state,
	$user_next,
	array(
		'items'       => array_slice( $user_source['users'], 0, 2 ),
		'total_pages' => 2,
	),
	1501
);
fleet_search_expect( 1 === $user_state['collections']['users']['cursor'], 'non-watermarked incremental refreshes should stop after page one' );
fleet_search_expect( 'reconcile' === $user_index->next_request( $user_state, 1900 )['mode'], 'a due reconciliation should resume complete collection scanning' );

// Transport failures retain the page cursor and are cleared by a later
// successful fetch.
$error_index = new OpenStation_Fleet_Search_Index( array( 'collections' => array( 'comments' ) ) );
$error_state = $error_index->advance(
	$error_index->initial_state( 2000 ),
	function () {
		throw new RuntimeException( 'Temporary REST failure' );
	},
	2000
);
fleet_search_expect( 1 === $error_state['collections']['comments']['cursor'], 'a failed page should be retried' );
fleet_search_expect( 'Temporary REST failure' === $error_state['collections']['comments']['last_error'], 'transport errors should be retained for diagnostics' );
$error_state = $error_index->advance( $error_state, array( 'comments' => array() ), 2001 );
fleet_search_expect( '' === $error_state['collections']['comments']['last_error'], 'a successful retry should clear the error' );

// Collection rotation is fair: one page of each collection is processed
// before any collection receives its second page.
$all_index = new OpenStation_Fleet_Search_Index( array( 'per_page' => 1 ) );
$all_state = $all_index->advance(
	$all_index->initial_state( 3000 ),
	array(
		'posts'    => array( fleet_search_posts( 1 )[0] ),
		'pages'    => array(
			array(
				'id'     => 1,
				'title'  => array( 'rendered' => 'About' ),
				'status' => 'publish',
			),
		),
		'users'    => array(
			array(
				'id'    => 1,
				'name'  => 'Sam Editor',
				'roles' => array( 'editor' ),
			),
		),
		'comments' => array(
			array(
				'id'          => 1,
				'author_name' => 'Avery',
				'content'     => array( 'rendered' => 'Helpful guide' ),
			),
		),
		'media'    => array(
			array(
				'id'        => 1,
				'title'     => array( 'rendered' => 'Studio map' ),
				'mime_type' => 'image/jpeg',
			),
		),
	),
	3000,
	5
);
fleet_search_expect( 5 === count( $all_index->records( $all_state ) ), 'all supported Core collections should normalize' );
fleet_search_expect( 0 === $all_state['next_collection'], 'collection rotation should wrap after a complete round' );

// Reconciliation work is bounded to the number of pages Fleet can retain.
// The production transport requests newest records first, represented here by
// reversing the deterministic source records.
$bounded_index = new OpenStation_Fleet_Search_Index(
	array(
		'collections'        => array( 'posts' ),
		'per_page'           => 10,
		'max_items'          => 25,
		'max_per_collection' => 10,
	)
);
$bounded_state = $bounded_index->advance( $bounded_index->initial_state( 4000 ), array( 'posts' => array_reverse( fleet_search_posts( 30 ) ) ), 4000, 1 );
$bounded_items = $bounded_index->records( $bounded_state );
fleet_search_expect( 10 === count( $bounded_items ), 'the per-collection storage cap should be enforced' );
fleet_search_expect( fleet_search_has_title( $bounded_items, 'Article 30' ), 'the newest record should remain in the bounded index' );
fleet_search_expect( ! fleet_search_has_title( $bounded_items, 'Article 1' ), 'older records should yield to fresher records at the bound' );
fleet_search_expect( 'incremental' === $bounded_state['collections']['posts']['mode'], 'bounded reconciliation should complete without scanning discarded pages' );
fleet_search_expect( 1 === $bounded_state['collections']['posts']['cursor'], 'bounded reconciliation should reset to the incremental cursor' );

// Normalizing stored state preserves public record text and strips internal
// bookkeeping from exported records.
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone JSON round-trip test.
$round_trip = $bounded_index->normalize_state( json_decode( json_encode( $bounded_state ), true ), 4001 );
$public     = $bounded_index->records( $round_trip, 1 )[0];
fleet_search_expect( array( 'title', 'meta', 'section', 'icon' ) === array_keys( $public ), 'exports should expose only the established Fleet search schema' );
fleet_search_expect( 'Post · Publish' === $public['meta'], 'stored record metadata should survive normalization' );

echo "Fleet incremental search index tests passed.\n";
