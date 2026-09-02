<?php
/**
 * Fleet hub — one native OpenStation app for every connected site.
 *
 * @package FleetForOpenStation
 */

use OpenStation\App;
use OpenStation\App\Os;
use OpenStation\App\State;

defined( 'ABSPATH' ) || exit;

$openstation_fleet_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><path d="M12 18h16v12H12zM36 10h16v12H36zM36 42h16v12H36z" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/><path d="M28 24h8M44 22v20" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>';

$openstation_fleet_app = App::define( 'fleet-for-openstation' )
	->title( __( 'Fleet', 'fleet-for-openstation' ) )
	->icon( $openstation_fleet_icon )
	->size( 980, 700 )
	->min_size( 680, 500 )
	->placement( 'dock' )
	->dock_order( 58 )
	->capabilities( OpenStation_Fleet::CAPABILITY )
	->style( OPENSTATION_FLEET_DIR . 'assets/fleet-app.css' )
	->state(
		array(
			'query'  => '',
			'notice' => '',
		)
	)
	->mount(
		static function ( State $state, Os $os ) {
			$data = OpenStation_Fleet::app_hub_data( 'sites' );
			$os->badge( $data['counts']['inbox'] );
		}
	)
	->title_bar_button(
		'refresh',
		array(
			'label'  => __( 'Refresh', 'fleet-for-openstation' ),
			'icon'   => 'reload',
			'action' => 'refresh',
		)
	)
	->action(
		'refresh',
		static function ( State $state ) {
			$state->set( 'notice', '' );
		}
	)
	->view(
		static function ( State $state ) {
			OpenStation_Fleet_App::render_sites( $state );
		}
	)
	->tab(
		'inbox',
		array(
			'label' => __( 'Inbox', 'fleet-for-openstation' ),
			'view'  => static function ( State $state ) {
				OpenStation_Fleet_App::render_inbox( $state );
			},
		)
	)
	->tab(
		'search',
		array(
			'label' => __( 'Search', 'fleet-for-openstation' ),
			'view'  => static function ( State $state ) {
				OpenStation_Fleet_App::render_search( $state );
			},
		)
	)
	->tab(
		'workspaces',
		array(
			'label' => __( 'Workspaces', 'fleet-for-openstation' ),
			'view'  => static function ( State $state ) {
				OpenStation_Fleet_App::render_workspaces( $state );
			},
		)
	)
	->tab(
		'activity',
		array(
			'label' => __( 'Activity', 'fleet-for-openstation' ),
			'view'  => static function ( State $state ) {
				OpenStation_Fleet_App::render_activity( $state );
			},
		)
	);

foreach ( array( 'connect', 'search', 'open-site', 'open-workspace', 'refresh-site', 'favorite', 'install', 'disconnect' ) as $openstation_fleet_action ) {
	$openstation_fleet_app->action(
		$openstation_fleet_action,
		static function ( State $state, Os $os, array $args ) use ( $openstation_fleet_action ) {
			OpenStation_Fleet_App::hub_action( $openstation_fleet_action, $state, $os, $args );
		}
	);
}

return $openstation_fleet_app;
