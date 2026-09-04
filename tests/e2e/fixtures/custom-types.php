<?php
/** Disposable fixture, not Fleet runtime. Enable only through the opt-in E2E test. */
add_action( 'init', static function () {
	if ( ! get_option( 'fleet_e2e_custom_types' ) ) {
		return;
	}
	register_post_type( 'fleet_event', array(
		'label' => 'Studio events', 'public' => true, 'show_in_rest' => true,
		'rest_namespace' => 'studio/v1', 'rest_base' => 'events',
		'supports' => array( 'title', 'editor', 'excerpt', 'revisions' ),
	) );
	register_post_type( 'fleet_private', array(
		'label' => 'Restricted events', 'public' => true, 'show_in_rest' => true,
		'capability_type' => array( 'fleet_private', 'fleet_privates' ), 'map_meta_cap' => true,
		'supports' => array( 'title', 'editor' ),
	) );
} );
