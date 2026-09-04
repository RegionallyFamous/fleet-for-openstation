<?php
/**
 * Reusable managed-site window. Each open instance receives a site_id.
 *
 * @package FleetForOpenStation
 */

use OpenStation\App;
use OpenStation\App\Os;
use OpenStation\App\State;

defined( 'ABSPATH' ) || exit;

$openstation_fleet_site_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect x="10" y="12" width="44" height="40" rx="5" fill="none" stroke="currentColor" stroke-width="4"/><path d="M10 24h44M20 18h.01M28 18h.01" stroke="currentColor" stroke-width="4" stroke-linecap="round"/><circle cx="43" cy="40" r="6" fill="currentColor"/></svg>';

$openstation_fleet_site_view = static function ( $section ) {
	return static function ( State $state ) use ( $section ) {
		OpenStation_Fleet_App::render_site( $section, $state );
	};
};

$openstation_fleet_site_app = App::define( 'fleet-site' )
	->title( __( 'Overview', 'fleet-for-openstation' ) )
	->icon( $openstation_fleet_site_icon )
	->size( 1000, 720 )
	->min_size( 700, 520 )
	->placement( 'none' )
	->placeable( false )
	->capabilities( OpenStation_Fleet::CAPABILITY )
	->style( OPENSTATION_FLEET_DIR . 'assets/fleet-app.css' )
	->state(
		array(
			'site_id'     => '',
			'notice'      => '',
			'response'    => '',
			'collections' => array(),
			'views_open'  => false,
			'editor'      => array(),
			'review'      => array(),
			'history'     => array(),
			'revision'    => array(),
			'connection'  => array(),
			'saved'       => '',
		)
	)
	->mount( array( 'OpenStation_Fleet_App', 'mount_site' ) )
	->title_bar_button(
		'hub',
		array(
			'label'     => __( 'Fleet', 'fleet-for-openstation' ),
			'icon'      => 'dashicons-networking',
			'action'    => 'open-hub',
			'placement' => 'left',
		)
	)
	->title_bar_button(
		'refresh',
		array(
			'label'  => __( 'Refresh', 'fleet-for-openstation' ),
			'icon'   => 'reload',
			'action' => 'refresh',
		)
	)
	->window_action(
		'visit',
		array(
			'label'  => __( 'Visit site', 'fleet-for-openstation' ),
			'icon'   => 'dashicons-external',
			'action' => 'open-site-url',
		)
	)
	->window_action(
		'reconnect',
		array(
			'label'  => __( 'Repair connection', 'fleet-for-openstation' ),
			'icon'   => 'dashicons-admin-links',
			'action' => 'reconnect',
		)
	)
	->window_action(
		'install',
		array(
			'label'  => __( 'Install or activate OpenStation', 'fleet-for-openstation' ),
			'icon'   => 'dashicons-download',
			'action' => 'install-openstation',
		)
	)
	->window_action(
		'disconnect',
		array(
			'label'   => __( 'Disconnect site', 'fleet-for-openstation' ),
			'icon'    => 'dashicons-dismiss',
			'action'  => 'disconnect',
			'confirm' => array(
				'title'   => __( 'Disconnect this site?', 'fleet-for-openstation' ),
				'message' => __( 'Fleet will revoke its Application Password on the managed site and remove the connection from this hub.', 'fleet-for-openstation' ),
				'label'   => __( 'Disconnect', 'fleet-for-openstation' ),
				'danger'  => true,
			),
		)
	)
	->view( $openstation_fleet_site_view( 'overview' ) )
	->tab(
		'content',
		array(
			'label' => __( 'Content', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'content' ),
		)
	)
	->tab(
		'media',
		array(
			'label' => __( 'Media', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'media' ),
		)
	)
	->tab(
		'comments',
		array(
			'label' => __( 'Comments', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'comments' ),
		)
	)
	->tab(
		'design',
		array(
			'label' => __( 'Design', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'design' ),
		)
	)
	->tab(
		'plugins',
		array(
			'label' => __( 'Plugins', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'plugins' ),
		)
	)
	->tab(
		'users',
		array(
			'label' => __( 'Users', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'users' ),
		)
	)
	->tab(
		'settings',
		array(
			'label' => __( 'Settings', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'settings' ),
		)
	)
	->tab(
		'agency',
		array(
			'label' => __( 'Agency', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'agency' ),
		)
	)
	->tab(
		'api',
		array(
			'label' => __( 'Explorer', 'fleet-for-openstation' ),
			'view'  => $openstation_fleet_site_view( 'api' ),
		)
	);

foreach ( array( 'save-view', 'apply-view', 'delete-view', 'review-content', 'confirm-content', 'cancel-review', 'revision-history', 'preview-revision', 'use-revision', 'close-history', 'reconnect', 'authorize', 'cancel-connect', 'browse', 'edit-content', 'new-content', 'close-editor', 'trash-content', 'open-hub', 'open-site-url', 'refresh', 'finish-setup', 'install-openstation', 'save-content', 'save-comment', 'save-media', 'save-settings', 'save-agency', 'change-plugin', 'install-plugin', 'create-user', 'save-user', 'api-request', 'disconnect' ) as $openstation_fleet_site_action ) {
	$openstation_fleet_site_app->action(
		$openstation_fleet_site_action,
		static function ( State $state, Os $os, array $args ) use ( $openstation_fleet_site_action ) {
			OpenStation_Fleet_App::site_action( $openstation_fleet_site_action, $state, $os, $args );
		}
	);
}

return $openstation_fleet_site_app;
