<?php
/**
 * Fleet's App Framework integration and WordPress-to-WordPress orchestration.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-openstation-fleet-crypto.php';
require_once __DIR__ . '/class-openstation-fleet-repository.php';
require_once __DIR__ . '/class-openstation-fleet-rest-client.php';
require_once __DIR__ . '/class-openstation-fleet-search-index.php';
require_once __DIR__ . '/class-openstation-fleet-sync-policy.php';
require_once __DIR__ . '/class-openstation-fleet-content.php';
require_once __DIR__ . '/class-openstation-fleet-recovery.php';
require_once __DIR__ . '/class-openstation-fleet-access.php';

/**
 * Implements the experimental Fleet feature with WordPress Core primitives.
 */
final class OpenStation_Fleet {
	const CAPABILITY             = 'manage_options';
	const PLUGIN_SLUG            = 'desktop-mode';
	const PLUGIN_REST_ID         = 'desktop-mode/desktop-mode';
	const CRON_HOOK              = 'openstation_fleet_scheduled_check';
	const CRON_SCHEDULE          = 'openstation_fleet_five_minutes';
	const PREVIOUS_CRON_SCHEDULE = 'openstation_fleet_15_minutes';
	const CRON_CURSOR            = 'openstation_fleet_scheduled_check_cursor';
	const DISCOVERY_LIMIT        = 4_194_304;

	/** App entry gate: owners or explicitly delegated, authenticated hub accounts.
	 *
	 * @return bool
	 */
	public static function can_use() {
		if ( current_user_can( self::CAPABILITY ) ) {
			return true; }
		foreach ( OpenStation_Fleet_Access::ids() as $id ) {
			if ( OpenStation_Fleet_Access::resolve( $id ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Register Fleet's native apps, authorization callback, and scheduled checks.
	 */
	public static function boot() {
		add_filter( 'openstation_apps_directories', array( __CLASS__, 'register_app_directory' ) );
		add_action( 'init', array( __CLASS__, 'register_app_assets' ), 5 );
		add_filter( 'openstation_app_window_args', array( __CLASS__, 'add_app_assets' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_dependency_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_dependency_notice' ) );
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Lightweight status synchronization is deliberately time-budgeted at five minutes.
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_privacy_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_checks' ) );
		add_action( 'admin_post_openstation_fleet_authorized', array( __CLASS__, 'handle_authorized' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'recovery_heartbeat' ), 10, 2 );
		add_action( 'delete_user', array( 'OpenStation_Fleet_Recovery', 'erase' ) );
		add_action( 'delete_user', array( 'OpenStation_Fleet_Access', 'delete_user' ) );

		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			$event = wp_get_scheduled_event( self::CRON_HOOK );
			if ( $event && self::PREVIOUS_CRON_SCHEDULE === $event->schedule ) {
				wp_unschedule_event( $event->timestamp, self::CRON_HOOK, isset( $event->args ) ? $event->args : array() );
				$next = false;
			}
		}
		if ( ! $next ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Whether this OpenStation build provides the App Framework Fleet requires.
	 *
	 * @return bool
	 */
	public static function has_app_framework() {
		if ( ! class_exists( '\\OpenStation\\App' ) || ! class_exists( '\\OpenStation\\App\\Os' ) || ! class_exists( '\\OpenStation\\App\\State' ) ) {
			return false;
		}
		foreach ( array( 'define', 'title', 'icon', 'size', 'min_size', 'placement', 'placeable', 'can', 'style', 'state', 'mount', 'title_bar_button', 'window_action', 'view', 'tab', 'action', 'dock_order', 'watch' ) as $method ) {
			if ( ! method_exists( '\\OpenStation\\App', $method ) ) {
				return false;
			}
		}
		foreach ( array( 'get', 'set' ) as $method ) {
			if ( ! method_exists( '\\OpenStation\\App\\State', $method ) ) {
				return false;
			}
		}
		foreach ( array( 'param', 'title', 'badge', 'toast', 'open', 'open_url', 'close', 'page', 'announce' ) as $method ) {
			if ( ! method_exists( '\\OpenStation\\App\\Os', $method ) ) {
				return false;
			}
		}
		if ( ! class_exists( '\\OpenStation\\App\\Effects' ) || ! property_exists( '\\OpenStation\\App\\Os', 'effects' ) || ! in_array( 'add', get_class_methods( '\\OpenStation\\App\\Effects' ), true ) ) {
			return false;
		}
		return function_exists( 'openstation_apps_registry' )
			&& function_exists( 'openstation_apps_runtime' )
			&& function_exists( '\\OpenStation\\App\\Html\\esc' )
			&& function_exists( '\\OpenStation\\App\\Html\\json' );
	}

	/**
	 * Explain the hard App Framework dependency without adding a second UI.
	 */
	public static function render_dependency_notice() {
		if ( self::has_app_framework() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Fleet for OpenStation needs a newer OpenStation build.', 'fleet-for-openstation' ); ?></strong></p>
			<p><?php esc_html_e( 'Update OpenStation to a version that includes the experimental App Framework. Fleet runs only as a native OpenStation app.', 'fleet-for-openstation' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Let OpenStation discover Fleet's native App Framework definitions.
	 *
	 * @param string[] $directories App definition directories.
	 * @return string[]
	 */
	public static function register_app_directory( $directories ) {
		if ( ! self::has_app_framework() ) {
			return $directories;
		}
		$directories[] = OPENSTATION_FLEET_DIR . 'apps';
		return array_values( array_unique( $directories ) );
	}

	/**
	 * Register the tiny effect bridge shared by Fleet's native windows.
	 */
	public static function register_app_assets() {
		if ( ! self::has_app_framework() ) {
			return;
		}
		wp_register_script(
			'fleet-for-openstation-app',
			OPENSTATION_FLEET_URL . 'assets/fleet-app.js',
			array( 'openstation-app-runtime', 'heartbeat' ),
			OPENSTATION_FLEET_VERSION,
			true
		);
	}

	/**
	 * Attach Fleet's effect bridge only to its two framework apps.
	 *
	 * @param array  $args Window registration arguments.
	 * @param string $id   App id.
	 * @param mixed  $app  App Framework definition (unused).
	 * @return array
	 */
	public static function add_app_assets( $args, $id, $app = null ) {
		unset( $app );
		if ( in_array( $id, array( 'fleet-for-openstation', 'fleet-site' ), true ) ) {
			$args['scripts']   = isset( $args['scripts'] ) && is_array( $args['scripts'] ) ? $args['scripts'] : array();
			$args['scripts'][] = 'fleet-for-openstation-app';
			$args['scripts']   = array_values( array_unique( $args['scripts'] ) );
		}
		return $args;
	}

	/**
	 * Add Fleet's lightweight background-check interval.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Fleet)', 'fleet-for-openstation' ),
		);
		return $schedules;
	}

	/**
	 * Remove Fleet's recurring job when the plugin is deactivated.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::CRON_CURSOR );
	}

	/**
	 * Register Fleet's data with WordPress's personal-data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_privacy_exporter( $exporters ) {
		$exporters['fleet-for-openstation'] = array(
			'exporter_friendly_name' => __( 'Fleet for OpenStation', 'fleet-for-openstation' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register a safe eraser that never strands remote credentials silently.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_privacy_eraser( $erasers ) {
		$erasers['fleet-for-openstation'] = array(
			'eraser_friendly_name' => __( 'Fleet for OpenStation', 'fleet-for-openstation' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Suggest an accurate Fleet paragraph for the site's privacy policy.
	 */
	public static function add_privacy_policy_content() {
		wp_add_privacy_policy_content(
			__( 'Fleet for OpenStation', 'fleet-for-openstation' ),
			wp_kses_post( __( 'Fleet stores connection URLs, remote usernames, encrypted Application Passwords, client labels, private notes, recent activity, and explicit team grants on this hub. Optional encrypted draft recovery copies expire after seven days; up to ten copies of 200 KB per user are retained. Recovery does not publish content. Shared workflows authenticate remotely as the connection owner’s approved WordPress account, not as a separate remote teammate. Fleet includes no telemetry or third-party tracking. Disconnect sites before removing Fleet to revoke remote credentials. Verified WordPress personal-data exports and erasure include Fleet’s user data and recovery copies.', 'fleet-for-openstation' ) )
		);
	}

	/**
	 * Export one user's non-secret Fleet metadata.
	 *
	 * @param string $email_address Requested email address.
	 * @return array WordPress privacy exporter response.
	 */
	public static function export_personal_data( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$data  = array_merge( OpenStation_Fleet_Recovery::export( $user->ID ), OpenStation_Fleet_Access::export( $user->ID ) );
		$sites = OpenStation_Fleet_Repository::all( $user->ID, array( __CLASS__, 'normalize_site_record' ) );
		foreach ( $sites as $site_id => $site ) {
			$site   = self::normalize_site_record( is_array( $site ) ? $site : array() );
			$agency = $site['agency'];
			$data[] = array(
				'group_id'    => 'fleet-for-openstation-sites',
				'group_label' => __( 'Fleet connected sites', 'fleet-for-openstation' ),
				'item_id'     => 'fleet-site-' . sanitize_key( $site_id ),
				'data'        => array(
					array(
						'name'  => __( 'Site URL', 'fleet-for-openstation' ),
						'value' => esc_url_raw( isset( $site['site_url'] ) ? $site['site_url'] : '' ),
					),
					array(
						'name'  => __( 'Remote username', 'fleet-for-openstation' ),
						'value' => sanitize_user( isset( $site['user_login'] ) ? $site['user_login'] : '', true ),
					),
					array(
						'name'  => __( 'Client', 'fleet-for-openstation' ),
						'value' => sanitize_text_field( $agency['client_name'] ),
					),
					array(
						'name'  => __( 'Tags', 'fleet-for-openstation' ),
						'value' => implode( ', ', array_map( 'sanitize_text_field', $agency['tags'] ) ),
					),
					array(
						'name'  => __( 'Private notes', 'fleet-for-openstation' ),
						'value' => sanitize_textarea_field( $agency['notes'] ),
					),
					array(
						'name'  => __( 'Saved work views', 'fleet-for-openstation' ),
						'value' => wp_json_encode( $site['views'] ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase local activity and metadata after credentials are safely revoked.
	 *
	 * @param string $email_address Requested email address.
	 * @return array WordPress privacy eraser response.
	 */
	public static function erase_personal_data( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$sites = OpenStation_Fleet_Repository::all( $user->ID, array( __CLASS__, 'normalize_site_record' ) );
		if ( is_array( $sites ) && ! empty( $sites ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'Disconnect every Fleet site first so WordPress can revoke each remote credential safely.', 'fleet-for-openstation' ) ),
				'done'           => true,
			);
		}
		delete_user_meta( $user->ID, OpenStation_Fleet_Repository::activity_meta_key() );
		OpenStation_Fleet_Recovery::erase( $user->ID );
		$grants_removed = OpenStation_Fleet_Access::erase( $user->ID );
		delete_user_meta( $user->ID, OpenStation_Fleet_Repository::app_id_meta_key() );
		if ( ! OpenStation_Fleet_Repository::delete_all( $user->ID ) || ! $grants_removed ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'Fleet data changed while WordPress was erasing it. Try the erasure again.', 'fleet-for-openstation' ) ),
				'done'           => true,
			);
		}
		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Build a bounded local search index from existing Core collections.
	 *
	 * Fleet searches this cached index instead of opening a synchronous HTTP
	 * request to every connected site while the user waits.
	 *
	 * @param array      $site     Connected site record.
	 * @param array      $search   Separately persisted search payload.
	 * @param int        $budget   Maximum Core collection pages to fetch.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array Incremental index state envelope.
	 */
	private static function fetch_search_index( $site, $search, $budget = 1, $deadline = null ) {
		$collections = array();
		foreach ( array( 'posts', 'pages', 'users', 'comments', 'media' ) as $collection ) {
			if ( self::supports( $site, $collection ) ) {
				$collections[] = $collection;
			}
		}
		if ( empty( $collections ) ) {
			return array( 'state' => array() );
		}

		$index = new OpenStation_Fleet_Search_Index(
			array(
				'collections' => $collections,
				'per_page'    => 50,
				'max_items'   => 750,
			)
		);
		$state = isset( $search['search_index_state'] ) && is_array( $search['search_index_state'] ) ? $search['search_index_state'] : $index->initial_state();
		$state = $index->advance(
			$state,
			static function ( $request ) use ( $site, $deadline ) {
				$collection = sanitize_key( isset( $request['collection'] ) ? $request['collection'] : '' );
				$fields     = array(
					'posts'    => 'id,title,status,modified_gmt,date_gmt,type',
					'pages'    => 'id,title,status,modified_gmt,date_gmt,type',
					'users'    => 'id,name,roles',
					'comments' => 'id,author_name,content,status,date_gmt',
					'media'    => 'id,title,mime_type,modified_gmt,date_gmt',
				);
				if ( ! isset( $fields[ $collection ] ) ) {
					return array( 'error' => __( 'Unsupported search collection.', 'fleet-for-openstation' ) );
				}
				$query = array(
					'context'  => 'edit',
					'page'     => max( 1, (int) $request['page'] ),
					'per_page' => max( 1, min( 100, (int) $request['per_page'] ) ),
					'_fields'  => $fields[ $collection ],
				);
				if ( in_array( $collection, array( 'posts', 'pages' ), true ) ) {
					$query['status']  = 'any';
					$query['orderby'] = 'modified';
					$query['order']   = 'desc';
				} elseif ( 'comments' === $collection ) {
					$query['status'] = 'all';
				}
				if ( ! empty( $request['after'] ) && in_array( $collection, array( 'posts', 'pages', 'media' ), true ) ) {
					$query['modified_after'] = sanitize_text_field( $request['after'] );
				} elseif ( ! empty( $request['after'] ) && 'comments' === $collection ) {
					$query['after'] = sanitize_text_field( $request['after'] );
				}
				$timeout = self::request_timeout( $deadline );
				if ( false === $timeout ) {
					return array( 'error' => __( 'The Fleet synchronization time budget was reached.', 'fleet-for-openstation' ) );
				}
				$copy   = $site;
				$result = OpenStation_Fleet_REST_Client::request_envelope( $copy, 'GET', 'wp/v2/' . $collection . '?' . http_build_query( $query, '', '&' ), null, $timeout );
				return is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result;
			},
			time(),
			max( 1, min( 5, (int) $budget ) )
		);
		return array( 'state' => $state );
	}

	/**
	 * Store and verify the approved Application Password, then make the site
	 * OpenStation-ready before returning to the hub.
	 */
	public static function handle_authorized() {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- State selects the nonce action and is validated before the nonce check.
		$state = sanitize_text_field( self::request_string( $_GET, 'state' ) );
		if ( ! wp_is_uuid( $state ) ) {
			self::fail_authorization( 'authorization_failed' );
		}
		check_admin_referer( 'openstation_fleet_authorized_' . $state );

		$key     = self::pending_key( get_current_user_id(), $state );
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) ) {
			self::fail_authorization( 'authorization_expired' );
		}
		if ( isset( $_GET['rejected'] ) || 'false' === self::request_string( $_GET, 'success' ) || '' !== self::request_string( $_GET, 'error' ) ) {
			self::fail_authorization( 'authorization_rejected' );
		}
		$returned_url = self::normalize_site_url( self::request_string( $_GET, 'site_url' ) );
		$username     = sanitize_user( self::request_string( $_GET, 'user_login' ), true );
		$password     = trim( self::request_string( $_GET, 'password' ) );
		if (
			is_wp_error( $returned_url ) ||
			'' === $username ||
			'' === $password ||
			self::url_origin( $returned_url ) !== self::url_origin( $pending['site_url'] )
		) {
			self::fail_authorization( 'authorization_failed' );
		}

		$sealed   = OpenStation_Fleet_Crypto::seal( $password );
		$password = null;
		if ( is_wp_error( $sealed ) ) {
			self::fail_authorization( 'encryption_failed' );
		}

		$site = array(
			'name'            => $pending['name'],
			'site_url'        => $returned_url,
			'rest_url'        => $pending['rest_url'],
			'user_login'      => $username,
			'secret'          => $sealed,
			'credential_uuid' => '',
			'openstation'     => array( 'status' => 'unknown' ),
			'setup_status'    => 'pending',
			'last_checked'    => 0,
			'error'           => '',
		);

		$credential = self::remote_request( $site, 'GET', 'wp/v2/users/me/application-passwords/introspect' );
		if ( is_wp_error( $credential ) || empty( $credential['uuid'] ) ) {
			self::fail_authorization( 'credential_failed' );
		}
		$site['credential_uuid']       = sanitize_text_field( $credential['uuid'] );
		$site['connection_generation'] = $site['credential_uuid'];
		$account                       = self::remote_request( $site, 'GET', 'wp/v2/users/me?context=edit&_fields=id,name,roles,capabilities' );
		if ( is_wp_error( $account ) || empty( $account['capabilities']['manage_options'] ) ) {
			self::fail_authorization( self::revoke_new_credential( $site ) ? 'administrator_required' : 'administrator_revoke_failed' );
		}
		$site_id   = self::site_id( $returned_url );
		$previous  = self::get_site( $site_id );
		$reconnect = ! empty( $pending['replace_generation'] );
		if ( false !== $previous && ! $reconnect ) {
			if ( ! self::revoke_new_credential( $site ) ) {
				self::fail_authorization( 'duplicate_revoke_failed' );
			}
			self::return_to_openstation();
		}
		// Save the verified connection before potentially slow plugin installation/indexing.
		$saved = $reconnect
			? OpenStation_Fleet_Repository::reauthorize( get_current_user_id(), $site_id, $pending['replace_generation'], $site, array( __CLASS__, 'normalize_site_record' ) )
			: self::save_site( $site_id, $site, array(), true );
		if ( ! $saved ) {
			self::fail_authorization( self::revoke_new_credential( $site ) ? 'storage_failed' : 'storage_revoke_failed' );
		}
		self::invalidate_read_cache( $site_id );
		self::record_activity( $site_id, $site, 'connected', __( 'Site connected with WordPress Core.', 'fleet-for-openstation' ) );
		if ( $reconnect && is_array( $previous ) && ! self::revoke_new_credential( $previous ) ) {
			$site['error'] = __( 'The new connection is saved. Fleet could not confirm revocation of the old credential. Check Users → Profile → Application Passwords on the managed site and remove only the older Fleet credential.', 'fleet-for-openstation' );
			self::save_site( $site_id, $site, array( 'error' ) );
			self::record_activity( $site_id, $site, 'reconnected', $site['error'], 'warning' );
		}
		self::return_to_openstation( $site_id );
	}

	/**
	 * Revoke the just-issued remote credential and confirm the REST result.
	 *
	 * @param array $site Connected site record.
	 * @return bool Whether WordPress confirmed revocation.
	 */
	private static function revoke_new_credential( $site ) {
		if ( empty( $site['credential_uuid'] ) ) {
			return false;
		}
		$result = self::remote_request( $site, 'DELETE', 'wp/v2/users/me/application-passwords/' . rawurlencode( $site['credential_uuid'] ) );
		return ! is_wp_error( $result );
	}

	/**
	 * Install or activate OpenStation without redirecting.
	 *
	 * @param array $site Connected site record, updated in place.
	 * @return string|WP_Error Either active or installed on success.
	 */
	private static function install_openstation( &$site ) {
		$site['last_checked'] = time();
		$plugins              = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' );
		if ( is_wp_error( $plugins ) ) {
			$site['error'] = $plugins->get_error_message();
			return $plugins;
		}
		$status = self::inspect_plugins( $plugins );
		if ( 'active' === $status['status'] ) {
			$site['openstation'] = $status;
			$site['error']       = '';
			return 'active';
		}
		$result = 'missing' === $status['status']
			? self::remote_request(
				$site,
				'POST',
				'wp/v2/plugins',
				array(
					'slug'   => self::PLUGIN_SLUG,
					'status' => 'active',
				)
			)
			: self::remote_request( $site, 'POST', 'wp/v2/plugins/' . self::PLUGIN_REST_ID, array( 'status' => 'active' ) );
		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			return $result;
		}
		$site['openstation'] = self::inspect_plugins( array( $result ) );
		$site['error']       = '';
		return 'installed';
	}
	/**
	 * Roles Fleet exposes in the focused user interface.
	 *
	 * @return array
	 */
	private static function editable_roles() {
		return array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	}
	/**
	 * Keep API Explorer requests inside the discovered REST base path.
	 *
	 * @param string $route User-entered REST route.
	 * @return bool
	 */
	private static function is_safe_api_route( $route ) {
		$route = trim( (string) $route );
		if (
			'' === $route
			|| false !== strpos( $route, '://' )
			|| 0 === strpos( $route, '//' )
			|| false !== strpos( $route, '\\' )
			|| false !== strpos( $route, '#' )
			|| preg_match( '/[\x00-\x1F\x7F]/', $route )
		) {
			return false;
		}
		$query = array();
		if ( false !== strpos( $route, '?' ) ) {
			wp_parse_str( (string) wp_parse_url( $route, PHP_URL_QUERY ), $query );
			foreach ( array_keys( $query ) as $query_key ) {
				if ( '_method' === strtolower( (string) $query_key ) ) {
					return false;
				}
			}
		}

		$path = rawurldecode( explode( '?', ltrim( $route, '/' ), 2 )[0] );
		if ( false !== strpos( $path, '\\' ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return '' !== $path;
	}
	/**
	 * Return the safe, presentation-ready hub model used by the App Framework.
	 * Secrets and credential identifiers deliberately never cross this boundary.
	 *
	 * @param string $view  Hub view.
	 * @param string $query Optional cross-site search query.
	 * @return array
	 */
	public static function app_hub_data( $view = 'sites', $query = '' ) {
		$view     = in_array( $view, array( 'sites', 'inbox', 'search', 'workspaces', 'activity' ), true ) ? $view : 'sites';
		$query    = sanitize_text_field( (string) $query );
		$site_ids = self::get_site_ids();
		$model    = array(
			'sites'      => array(),
			'activity'   => 'activity' === $view ? self::get_activity() : array(),
			'workspaces' => array(),
			'search'     => array(),
			'counts'     => array(
				'sites'     => count( $site_ids ),
				'healthy'   => 0,
				'attention' => 0,
				'inbox'     => 0,
			),
		);

		foreach ( $site_ids as $id ) {
			$site = self::get_site( $id );
			if ( ! is_array( $site ) || ! OpenStation_Fleet_Access::allowed( $site, 'overview' ) ) {
				continue;
			}
			$attention        = self::attention_reasons( $site );
			$inbox            = self::inbox_item_count( $site );
			$status           = isset( $site['openstation']['status'] ) ? sanitize_key( $site['openstation']['status'] ) : 'unknown';
			$agency           = isset( $site['agency'] ) && is_array( $site['agency'] ) ? $site['agency'] : array();
			$client           = ! empty( $agency['client_name'] ) ? sanitize_text_field( $agency['client_name'] ) : __( 'Unassigned sites', 'fleet-for-openstation' );
			$item             = array(
				'id'                => sanitize_key( $id ),
				'name'              => sanitize_text_field( isset( $site['name'] ) ? $site['name'] : $site['site_url'] ),
				'url'               => esc_url_raw( $site['site_url'] ),
				'host'              => sanitize_text_field( (string) wp_parse_url( $site['site_url'], PHP_URL_HOST ) ),
				'user'              => sanitize_user( isset( $site['user_login'] ) ? $site['user_login'] : '', true ),
				'wordpress_version' => sanitize_text_field( isset( $site['wordpress_version'] ) ? $site['wordpress_version'] : '' ),
				'openstation'       => $status,
				'setup_status'      => sanitize_key( isset( $site['setup_status'] ) ? $site['setup_status'] : 'ready' ),
				'last_checked'      => absint( isset( $site['last_checked'] ) ? $site['last_checked'] : 0 ),
				'error'             => sanitize_text_field( isset( $site['error'] ) ? $site['error'] : '' ),
				'attention'         => $attention,
				'inbox'             => isset( $site['inbox'] ) ? self::normalize_inbox_summary( $site['inbox'] ) : self::empty_inbox_summary(),
				'inbox_count'       => $inbox,
				'agency'            => array(
					'client_name' => ! empty( $agency['client_name'] ) ? sanitize_text_field( $agency['client_name'] ) : '',
					'tags'        => ! empty( $agency['tags'] ) && is_array( $agency['tags'] ) ? array_values( array_map( 'sanitize_text_field', $agency['tags'] ) ) : array(),
					'plan_status' => sanitize_key( isset( $agency['plan_status'] ) ? $agency['plan_status'] : 'none' ),
					'favorite'    => ! empty( $agency['favorite'] ),
				),
			);
			$model['sites'][] = $item;
			if ( empty( $item['error'] ) && 'active' === $status ) {
				++$model['counts']['healthy'];
			}
			if ( ! empty( $attention ) ) {
				++$model['counts']['attention'];
			}
			$model['counts']['inbox'] += $inbox;
			if ( ! isset( $model['workspaces'][ $client ] ) ) {
				$model['workspaces'][ $client ] = array();
			}
			$model['workspaces'][ $client ][] = $item;

			if ( 'search' === $view && strlen( $query ) >= 2 ) {
				$search = self::get_site_search( $id, $site );
				if ( ! empty( $search['search_index_state'] ) ) {
					$index   = new OpenStation_Fleet_Search_Index();
					$results = $index->search( $search['search_index_state'], $query, 24 );
				} else {
					$needle  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
					$results = array_filter(
						$search['search_index'],
						static function ( $search_item ) use ( $needle ) {
							$text = isset( $search_item['title'], $search_item['meta'] ) ? $search_item['title'] . ' ' . $search_item['meta'] : '';
							$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
							return false !== strpos( $text, $needle );
						}
					);
				}
				$model['search'][] = array(
					'site_id'   => sanitize_key( $id ),
					'site_name' => sanitize_text_field( $site['name'] ),
					'items'     => array_slice( array_values( $results ), 0, 24 ),
					'error'     => '',
				);
			}
		}

		usort(
			$model['sites'],
			static function ( $a, $b ) {
				if ( $a['agency']['favorite'] !== $b['agency']['favorite'] ) {
					return $a['agency']['favorite'] ? -1 : 1;
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		uksort( $model['workspaces'], 'strnatcasecmp' );
		$model['counts']['sites'] = count( $model['sites'] );

		return $model;
	}

	/**
	 * Return one connected site's safe record for framework window setup.
	 *
	 * @param string $id Site id.
	 * @return array|WP_Error
	 */
	public static function app_site( $id ) {
		$id   = sanitize_key( $id );
		$site = self::get_site( $id );
		if ( '' === $id || ! is_array( $site ) || ! OpenStation_Fleet_Access::allowed( $site, 'overview' ) ) {
			return new WP_Error( 'openstation_fleet_site_missing', __( 'That connected site no longer exists.', 'fleet-for-openstation' ) );
		}
		return array(
			'id'                => $id,
			'access_role'       => $site['_fleet_role'] ?? 'owner',
			'name'              => sanitize_text_field( $site['name'] ),
			'url'               => esc_url_raw( $site['site_url'] ),
			'host'              => sanitize_text_field( (string) wp_parse_url( $site['site_url'], PHP_URL_HOST ) ),
			'user'              => sanitize_user( $site['user_login'], true ),
			'wordpress_version' => sanitize_text_field( isset( $site['wordpress_version'] ) ? $site['wordpress_version'] : '' ),
			'environment'       => isset( $site['environment'] ) && is_array( $site['environment'] ) ? $site['environment'] : array(),
			'openstation'       => isset( $site['openstation'] ) && is_array( $site['openstation'] ) ? $site['openstation'] : array( 'status' => 'unknown' ),
			'setup_status'      => sanitize_key( isset( $site['setup_status'] ) ? $site['setup_status'] : 'ready' ),
			'last_checked'      => absint( isset( $site['last_checked'] ) ? $site['last_checked'] : 0 ),
			'sync'              => self::sync_status( $site ),
			'error'             => sanitize_text_field( isset( $site['error'] ) ? $site['error'] : '' ),
			'attention'         => self::attention_reasons( $site ),
			'capabilities'      => isset( $site['capabilities'] ) && is_array( $site['capabilities'] ) ? $site['capabilities'] : array(),
			'agency'            => $site['agency'],
		);
	}

	/**
	 * A whitelist-only support report: no remote calls, names, URLs or credentials.
	 *
	 * @return array
	 */
	public static function diagnostics() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array();
		}
		$sites  = OpenStation_Fleet_Repository::all( get_current_user_id(), array( __CLASS__, 'normalize_site_record' ) );
		$report = array(
			'fleet_version'        => OPENSTATION_FLEET_VERSION,
			'wordpress_version'    => get_bloginfo( 'version' ),
			'php_version'          => PHP_VERSION,
			'app_framework_ready'  => self::has_app_framework(),
			'https'                => 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME ),
			'encryption_available' => function_exists( 'sodium_crypto_secretbox' ),
			'multisite'            => is_multisite(),
			'cron_disabled'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_check_utc'       => wp_next_scheduled( self::CRON_HOOK ) ? gmdate( 'c', wp_next_scheduled( self::CRON_HOOK ) ) : null,
			'connected_sites'      => count( $sites ),
			'sites_needing_setup'  => 0,
			'sites_with_errors'    => 0,
			'sites_backing_off'    => 0,
		);
		foreach ( $sites as $site ) {
			$report['sites_needing_setup'] += 'ready' !== $site['setup_status'] ? 1 : 0;
			$report['sites_with_errors']   += ! empty( $site['error'] ) ? 1 : 0;
			$report['sites_backing_off']   += ! empty( $site['next_retry'] ) && $site['next_retry'] > time() ? 1 : 0;
		}
		return $report;
	}

	/**
	 * Explain cache freshness, retry delay and cron liveness without remote calls.
	 *
	 * @param array $site Connection record.
	 * @return array
	 */
	public static function sync_status( $site ) {
		$now  = time();
		$next = wp_next_scheduled( self::CRON_HOOK );
		$run  = get_option( 'openstation_fleet_last_sync_run', array() );
		return array(
			'status_checked'   => absint( $site['status_checked'] ?? 0 ),
			'metadata_checked' => absint( $site['metadata_checked'] ?? 0 ),
			'health_checked'   => absint( $site['health_checked'] ?? 0 ),
			'next_retry'       => absint( $site['next_retry'] ?? 0 ),
			'failures'         => absint( $site['sync_failures'] ?? 0 ),
			'queued'           => OpenStation_Fleet_Sync_Policy::due( $site, 'status', $now ),
			'cron_stalled'     => ! $next || $next < $now - 600,
			'last_run'         => absint( is_array( $run ) ? ( $run['finished'] ?? 0 ) : 0 ),
		);
	}

	/**
	 * Load the remote data required by one native managed-site tab.
	 *
	 * @param string $id      Site id.
	 * @param string $section App tab slug.
	 * @param array  $options Collection filters and selection.
	 * @return array|WP_Error
	 */
	public static function app_site_data( $id, $section, $options = array() ) {
		if ( ! self::can_use() ) {
			return new WP_Error( 'openstation_fleet_forbidden', __( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}
		$id      = sanitize_key( $id );
		$section = sanitize_key( $section );
		$site    = self::get_site( $id );
		if ( ! OpenStation_Fleet_Access::allowed( $site, $section ) ) {
			return new WP_Error( 'fleet_role_forbidden', __( 'This section is not available to your Fleet role.', 'fleet-for-openstation' ) );
		}
		$site['_fleet_operation'] = $section;
		if ( '' === $id || ! is_array( $site ) ) {
			return new WP_Error( 'openstation_fleet_site_missing', __( 'That connected site no longer exists.', 'fleet-for-openstation' ) );
		}
		if ( in_array( $section, array( 'content', 'media', 'comments', 'users' ), true ) ) {
			return self::collection_data( $site, $section, $options );
		}
		if ( 'agency' === $section ) {
			return array(
				'agency'    => $site['agency'],
				'attention' => self::attention_reasons( $site ),
			);
		}
		$cached = self::read_cache_get( $id, $section );
		if ( false !== $cached ) {
			return $cached;
		}

		switch ( $section ) {
			case 'content-types':
				$types  = self::remote_request( $site, 'GET', 'wp/v2/types?context=edit' );
				$user   = self::remote_request( $site, 'GET', 'wp/v2/users/me?context=edit&_fields=capabilities' );
				$result = is_wp_error( $types ) ? $types : ( is_wp_error( $user ) ? $user : OpenStation_Fleet_Content::types( $types, isset( $user['capabilities'] ) ? $user['capabilities'] : array() ) );
				break;
			case 'plugins':
				$result = array( 'plugins' => self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' ) );
				break;
			case 'settings':
				$result = array( 'settings' => self::remote_request( $site, 'GET', 'wp/v2/settings?context=edit' ) );
				break;
			case 'design':
				$design = self::remote_get_map(
					$site,
					array(
						'themes'         => 'wp/v2/themes?context=edit&status=active&_fields=stylesheet,name,status,version',
						'templates'      => 'wp/v2/templates?context=edit&per_page=30&_fields=id,slug,title,theme,modified,status',
						'template_parts' => 'wp/v2/template-parts?context=edit&per_page=30&_fields=id,slug,title,area,theme,modified,status',
						'navigation'     => 'wp/v2/navigation?context=edit&per_page=30&_fields=id,title,status,modified',
						'font_families'  => 'wp/v2/font-families?context=edit&per_page=30&_fields=id,name,slug,font_face',
						'patterns'       => 'wp/v2/blocks?context=edit&per_page=30&_fields=id,title,status,modified',
					)
				);
				$themes = isset( $design['themes'] ) && is_array( $design['themes'] ) ? $design['themes'] : array();
				if ( ! empty( $themes[0]['stylesheet'] ) ) {
					$design['global_styles'] = self::remote_request( $site, 'GET', 'wp/v2/global-styles/themes/' . rawurlencode( $themes[0]['stylesheet'] ) );
				}
				$result = $design;
				break;
			case 'api':
				$root       = self::remote_request( $site, 'GET', '' );
				$namespaces = isset( $site['capabilities']['namespaces'] ) && is_array( $site['capabilities']['namespaces'] ) ? $site['capabilities']['namespaces'] : array();
				$abilities  = in_array( 'wp-abilities/v1', $namespaces, true )
					? self::remote_request( $site, 'GET', 'wp-abilities/v1/abilities?per_page=100&_fields=name,label,description,category,input_schema,output_schema,meta' )
					: new WP_Error( 'openstation_fleet_abilities_unavailable', __( 'This site does not expose the WordPress Abilities API.', 'fleet-for-openstation' ) );
				$result     = array(
					'catalog'   => is_wp_error( $root ) ? $root : self::api_route_catalog( $root ),
					'abilities' => $abilities,
				);
				break;
			case 'overview':
			default:
				$result = self::remote_get_map(
					$site,
					array(
						'settings' => 'wp/v2/settings?context=edit&_fields=title,description,timezone,date_format,time_format,start_of_week',
						'posts'    => 'wp/v2/posts?context=edit&status=any&per_page=5&orderby=modified&order=desc&_fields=id,title,status,modified,type',
						'pages'    => 'wp/v2/pages?context=edit&status=any&per_page=5&orderby=modified&order=desc&_fields=id,title,status,modified,type',
					)
				);
				break;
		}
		return self::read_cache_set( $id, $section, $result );
	}

	/**
	 * Fetch just one page or one selected record, without caching raw drafts.
	 *
	 * @param array  $site Connection record.
	 * @param string $section Collection section.
	 * @param array  $options Pagination and selection.
	 * @return array|WP_Error
	 */
	private static function collection_data( $site, $section, $options ) {
		$type       = 'content' === $section ? ( isset( $options['type'] ) ? $options['type'] : 'posts' ) : $section;
		$descriptor = 'content' === $section ? self::content_type( $site, $type ) : array( 'route' => 'wp/v2/' . $section );
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		$route = $descriptor['route'];
		$id    = isset( $options['selected'] ) ? absint( $options['selected'] ) : 0;
		if ( 'content' === $section && $id ) {
			$item = self::remote_request( $site, 'GET', $route . '/' . $id . '?context=edit&_fields=' . OpenStation_Fleet_Content::FIELDS );
			return is_wp_error( $item ) ? $item : array(
				'item'       => $item,
				'type'       => $type,
				'descriptor' => $descriptor,
			);
		}
		$query = array(
			'context'  => 'edit',
			'per_page' => 12,
			'page'     => max( 1, isset( $options['page'] ) ? absint( $options['page'] ) : 1 ),
			'search'   => substr( sanitize_text_field( isset( $options['search'] ) && is_string( $options['search'] ) ? $options['search'] : '' ), 0, 160 ),
		);
		if ( 'content' === $section ) {
			$query['status']  = isset( $options['status'] ) && in_array( $options['status'], array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' ), true ) ? $options['status'] : 'any';
			$query['orderby'] = 'modified';
			$query['_fields'] = 'id,title,status,modified,type';
			if ( isset( $options['period'] ) && 'week' === $options['period'] ) {
				$settings = self::remote_request( $site, 'GET', 'wp/v2/settings?_fields=timezone,start_of_week' );
				if ( is_wp_error( $settings ) ) {
					return $settings;
				}
				$zone = self::content_timezone( $site, isset( $settings['timezone'] ) ? $settings['timezone'] : '' );
				if ( is_wp_error( $zone ) ) {
					return $zone;
				}
				$start            = new DateTimeImmutable( 'today', $zone );
				$days             = ( (int) $start->format( 'w' ) - (int) $settings['start_of_week'] + 7 ) % 7;
				$start            = $start->modify( '-' . $days . ' days' );
				$query['after']   = $start->modify( '-1 second' )->format( 'Y-m-d\TH:i:s' );
				$query['before']  = $start->modify( '+7 days' )->format( 'Y-m-d\TH:i:s' );
				$query['orderby'] = 'date';
				$query['order']   = 'asc';
			}
		} elseif ( 'comments' === $section ) {
			$query['status']  = isset( $options['status'] ) && in_array( $options['status'], array( 'hold', 'approve', 'spam', 'trash' ), true ) ? $options['status'] : 'all';
			$query['_fields'] = 'id,author_name,content,date,status,post';
		} elseif ( 'media' === $section ) {
			$query['_fields'] = 'id,title,alt_text,caption,media_type,mime_type,source_url,date';
		} else {
			$query['_fields'] = 'id,username,name,email,roles,avatar_urls';
		}
		$result = OpenStation_Fleet_REST_Client::request_envelope( $site, 'GET', add_query_arg( $query, $route ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			$type        => $result['items'],
			'type'       => $type,
			'descriptor' => $descriptor,
			'pagination' => \OpenStation\App\Os::page( $result['items'], isset( $result['total'] ) ? $result['total'] : count( $result['items'] ), $query['page'], 12 ),
		);
	}

	/**
	 * Resolve routes from Core discovery, never a route submitted by the browser.
	 *
	 * @param array  $site Connection.
	 * @param string $type Fleet content type key.
	 * @return array|WP_Error
	 */
	private static function content_type( $site, $type ) {
		if ( ! OpenStation_Fleet_Content::valid_type( $type ) ) {
			return new WP_Error( 'fleet_content_type', __( 'Choose a supported content type.', 'fleet-for-openstation' ) );
		}
		$types = self::app_site_data( $site['_fleet_alias'] ?? self::site_id( $site['site_url'] ), 'content-types' );
		if ( is_wp_error( $types ) || ! isset( $types[ $type ] ) ) {
			return is_wp_error( $types ) ? $types : new WP_Error( 'fleet_content_type', __( 'This content type is not exposed for editing by the connected account.', 'fleet-for-openstation' ) );
		}
		$descriptor = $types[ $type ];
		// Core collections are known; custom controllers must expose a compatible schema.
		if ( ! in_array( $type, array( 'posts', 'pages' ), true ) ) {
			$schema = self::remote_request( $site, 'OPTIONS', $descriptor['route'] );
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}
			foreach ( array( 'title', 'content', 'slug', 'status', 'date_gmt' ) as $field ) {
				if ( empty( $schema['schema']['properties'][ $field ] ) || ! empty( $schema['schema']['properties'][ $field ]['readonly'] ) ) {
					return new WP_Error( 'fleet_content_schema', __( 'This custom content controller does not support the standard WordPress editor fields.', 'fleet-for-openstation' ) );
				}
			}
		}
		return $descriptor;
	}

	/**
	 * Bounded, searchable Core publishing choices. No managed-site extension.
	 *
	 * @param string $id Connection id.
	 * @param array  $values Kind, search and page.
	 * @return array|WP_Error
	 */
	public static function publishing_options( $id, $values ) {
		$site = self::get_site( $id );
		if ( ! OpenStation_Fleet_Access::allowed( $site, 'content', true ) ) {
			return new WP_Error( 'fleet_options_forbidden', __( 'This connection is unavailable.', 'fleet-for-openstation' ) );
		}
		$site['_fleet_operation'] = 'content';
		$site['_fleet_write']     = true;
		$kind                     = is_string( $values['kind'] ?? null ) ? $values['kind'] : '';
		$routes                   = array(
			'author'         => 'users',
			'featured_media' => 'media',
			'categories'     => 'categories',
			'tags'           => 'tags',
		);
		if ( ! isset( $routes[ $kind ] ) ) {
			return new WP_Error( 'fleet_options_kind', __( 'Choose a publishing field.', 'fleet-for-openstation' ) );
		}
		$query = array(
			'context'  => 'edit',
			'per_page' => 25,
			'page'     => max( 1, min( 10000, (int) ( $values['page'] ?? 1 ) ) ),
			'_fields'  => 'id,name,title',
		);
		if ( 'featured_media' === $kind ) {
			$query['media_type'] = 'image';
		}
		if ( 'author' === $kind ) {
			$query['who'] = 'authors';
		}
		$query['search'] = sanitize_text_field( is_string( $values['search'] ?? null ) ? substr( $values['search'], 0, 200 ) : '' );
		$result          = OpenStation_Fleet_REST_Client::request_envelope( $site, 'GET', 'wp/v2/' . $routes[ $kind ] . '?' . http_build_query( $query, '', '&' ) );
		return is_wp_error( $result ) ? $result : array_merge(
			$result,
			array(
				'kind'   => $kind,
				'search' => $query['search'],
				'page'   => $query['page'],
			)
		);
	}

	/**
	 * Bind recovery to a user and the currently authorized connection.
	 *
	 * @param string $id Connection id.
	 * @return string
	 */
	public static function recovery_context( $id ) {
		$site = self::get_site( $id );
		return $site ? hash_hmac( 'sha256', get_current_user_id() . ':' . $site['site_url'] . ':' . $site['connection_generation'], wp_salt( 'auth' ) ) : '';
	}

	/**
	 * Use Core's nonce-protected Heartbeat; do not repaint an editor being typed in.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data Untrusted heartbeat payload.
	 * @return array
	 */
	public static function recovery_heartbeat( $response, $data ) {
		if ( ! self::can_use() || ! is_array( $data['fleet_recovery'] ?? null ) ) {
			return $response;
		}
		$response['fleet_recovery'] = array();
		foreach ( array_slice( $data['fleet_recovery'], 0, 5, true ) as $window => $values ) {
			if ( ! is_array( $values ) || ! is_string( $window ) || strlen( $window ) > 100 ) {
				continue;
			}
			$id   = is_string( $values['site_id'] ?? null ) ? sanitize_key( $values['site_id'] ) : '';
			$site = self::get_site( $id );
			if ( ! OpenStation_Fleet_Access::allowed( $site, 'recovery', true ) || ! is_string( $values['connection'] ?? null ) || ! hash_equals( self::recovery_context( $id ), $values['connection'] ) ) {
				$result = new WP_Error( 'fleet_recovery_connection', __( 'The connection changed. Recovery is paused; copy your source before reopening this editor.', 'fleet-for-openstation' ) );
			} else {
				$result = OpenStation_Fleet_Recovery::save( $site, $values );
			}
			$response['fleet_recovery'][ $window ] = is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result;
		}
		return $response;
	}

	/**
	 * Resolve local recovery only after connection ownership checks.
	 *
	 * @param string $id Connection id.
	 * @param string $action list, restore or delete.
	 * @param string $key Checkpoint key.
	 * @return array|WP_Error
	 */
	public static function recovery( $id, $action = 'list', $key = '' ) {
		$site = self::get_site( $id );
		if ( ! OpenStation_Fleet_Access::allowed( $site, 'recovery', true ) ) {
			return new WP_Error( 'fleet_recovery_forbidden', __( 'This connection is unavailable.', 'fleet-for-openstation' ) );
		}
		if ( 'list' === $action ) {
			return OpenStation_Fleet_Recovery::listing( $site );
		}
		$data = OpenStation_Fleet_Recovery::read( $site, $key );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 'delete' === $action ) {
			return OpenStation_Fleet_Recovery::remove( $key ) ? array() : new WP_Error( 'fleet_recovery_busy', __( 'The checkpoint could not be removed. Try again.', 'fleet-for-openstation' ) );
		}
		$editor = $data['editor'];
		$type   = self::content_type( $site, $editor['content_type'] );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$editor['descriptor'] = $type;
		// Keep the original fingerprint and request id. A conflict or uncertain
		// creation must remain blocked after recovery, not silently become a retry.
		return $editor;
	}

	/**
	 * Review and execute a small comment batch with durable individual outcomes.
	 *
	 * @param string $id Connection id.
	 * @param array  $values Selection or signed review.
	 * @param bool   $confirm Whether to execute the reviewed batch.
	 * @return array|WP_Error
	 */
	public static function comment_batch( $id, $values, $confirm = false ) {
		$site = self::get_site( $id );
		if ( ! OpenStation_Fleet_Access::allowed( $site, 'comment-batch', true ) ) {
			return new WP_Error( 'fleet_batch_forbidden', __( 'This connection is unavailable.', 'fleet-for-openstation' ) );
		}
		$site['_fleet_operation'] = 'comment-batch';
		$site['_fleet_write']     = true;
		$status                   = $values['status'] ?? '';
		if ( ! is_string( $status ) || ! in_array( $status, array( 'approved', 'hold', 'spam', 'trash' ), true ) ) {
			return new WP_Error( 'fleet_batch_status', __( 'Choose a moderation status.', 'fleet-for-openstation' ) );
		}
		if ( ! $confirm ) {
			$ids = $values['ids'] ?? array();
			if ( ! is_array( $ids ) || ! $ids || count( $ids ) > 12 || array_filter(
				$ids,
				static function ( $value ) {
					return ! is_scalar( $value ) || ! preg_match( '/\A[1-9][0-9]{0,9}\z/', (string) $value ); }
			) ) {
				return new WP_Error( 'fleet_batch_ids', __( 'Select between one and twelve comments.', 'fleet-for-openstation' ) );
			}
			$ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );
			$items = self::remote_request(
				$site,
				'GET',
				'wp/v2/comments?' . http_build_query(
					array(
						'context'  => 'edit',
						'status'   => 'all',
						'include'  => $ids,
						'per_page' => 12,
						'_fields'  => 'id,status,author_name,content',
					),
					'',
					'&'
				)
			);
			if ( is_wp_error( $items ) || count( $items ) !== count( $ids ) ) {
				return new WP_Error( 'fleet_batch_missing', __( 'Some selected comments are no longer available. Refresh the queue.', 'fleet-for-openstation' ) );
			}
			$review = array(
				'status'     => $status,
				'expires'    => time() + 600,
				'request_id' => wp_generate_uuid4(),
				'items'      => array(),
			);
			foreach ( $items as $item ) {
				$review['items'][] = array(
					'id'      => (int) $item['id'],
					'status'  => $item['status'],
					'author'  => sanitize_text_field( $item['author_name'] ),
					'excerpt' => wp_trim_words( wp_strip_all_tags( $item['content']['rendered'] ?? '' ), 20 ),
				);
			}
			$review['token'] = hash_hmac( 'sha256', self::recovery_context( $id ) . wp_json_encode( $review ), wp_salt( 'auth' ) );
			return $review;
		}
		$token = $values['token'] ?? '';
		unset( $values['token'] );
		if ( ! is_string( $token ) || ! is_numeric( $values['expires'] ?? null ) || $values['expires'] < time() || $values['expires'] > time() + 600 || ! hash_equals( hash_hmac( 'sha256', self::recovery_context( $id ) . wp_json_encode( $values ), wp_salt( 'auth' ) ), $token ) ) {
			return new WP_Error( 'fleet_batch_review', __( 'Review this selection again before moderating.', 'fleet-for-openstation' ) );
		}
		$key  = 'fleet_batch_' . hash( 'sha256', self::recovery_context( $id ) . $values['request_id'] );
		$lock = OpenStation_Fleet_Repository::acquire_lock( $key, get_current_user_id() );
		if ( false === $lock ) {
			return new WP_Error( 'fleet_batch_busy', __( 'This batch is already running. Wait for its results.', 'fleet-for-openstation' ) );
		}
		try {
			$results  = get_transient( $key );
			$results  = is_array( $results ) ? $results : array();
			$deadline = microtime( true ) + 20;
			foreach ( $values['items'] as $item ) {
				$comment_id = $item['id'];
				if ( isset( $results[ $comment_id ] ) ) {
					continue; }
				if ( microtime( true ) >= $deadline - 1 ) {
					$results[ $comment_id ] = __( 'Not attempted: batch time limit. Select again to review.', 'fleet-for-openstation' );
					continue;
				}
				$current = self::remote_request( $site, 'GET', 'wp/v2/comments/' . $comment_id . '?context=edit&_fields=id,status', null, $deadline );
				if ( is_wp_error( $current ) || ( $current['status'] ?? '' ) !== $item['status'] ) {
					$results[ $comment_id ] = __( 'Not changed: unavailable or changed since review.', 'fleet-for-openstation' );
					continue;
				}
				$results[ $comment_id ] = __( 'Result unknown: check WordPress before trying again.', 'fleet-for-openstation' );
				if ( ! set_transient( $key, $results, DAY_IN_SECONDS ) ) {
					return new WP_Error( 'fleet_batch_storage', __( 'Batch stopped because its safety journal could not be saved. Refresh the queue before continuing.', 'fleet-for-openstation' ) );
				}
				$result = self::remote_request( $site, 'POST', 'wp/v2/comments/' . $comment_id, array( 'status' => $status ), $deadline );
				if ( ! is_wp_error( $result ) && ( $result['status'] ?? '' ) === $status ) {
					$results[ $comment_id ] = __( 'Updated.', 'fleet-for-openstation' );
				}
				set_transient( $key, $results, DAY_IN_SECONDS );
			}
			set_transient( $key, $results, DAY_IN_SECONDS );
			self::invalidate_read_cache( $id );
			self::record_activity( $id, $site, 'comment-batch', __( 'Reviewed comment batch processed; inspect individual outcomes.', 'fleet-for-openstation' ), 'info' );
			return array( 'results' => $results );
		} finally {
			OpenStation_Fleet_Repository::release_lock( $lock );
		}
	}

	/**
	 * Resolve both named zones and Core's fixed-offset setting without guessing.
	 *
	 * @param array  $site Connection.
	 * @param string $name REST settings timezone.
	 * @return DateTimeZone|WP_Error
	 */
	private static function content_timezone( $site, $name ) {
		if ( '' === $name ) {
			$root = self::remote_request( $site, 'GET', '?_fields=gmt_offset,timezone_string' );
			if ( is_wp_error( $root ) || ! isset( $root['gmt_offset'] ) || ! is_numeric( $root['gmt_offset'] ) || abs( (float) $root['gmt_offset'] ) > 24 ) {
				return new WP_Error( 'fleet_timezone', __( 'The site timezone could not be verified. Check the connection and its time settings.', 'fleet-for-openstation' ) );
			}
			$minutes = (int) round( (float) $root['gmt_offset'] * 60 );
			$name    = sprintf( '%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv( abs( $minutes ), 60 ), abs( $minutes ) % 60 );
		}
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $error ) {
			return new WP_Error( 'fleet_timezone', __( 'WordPress returned an unsupported timezone.', 'fleet-for-openstation' ) );
		}
	}

	/**
	 * Read a bounded revision history or one selected revision. Never writes.
	 *
	 * @param string $id Site id.
	 * @param array  $editor Editor identity.
	 * @param int    $revision Optional revision id.
	 * @param int    $page Page number.
	 * @return array|WP_Error
	 */
	public static function content_revisions( $id, $editor, $revision = 0, $page = 1 ) {
		$site = self::get_site( $id );
		if ( ! OpenStation_Fleet_Access::allowed( $site, 'content' ) ) {
			return new WP_Error( 'fleet_content_site', __( 'That site is unavailable.', 'fleet-for-openstation' ) );
		}
		$type   = self::content_type( $site, isset( $editor['content_type'] ) ? $editor['content_type'] : '' );
		$parent = absint( isset( $editor['content_id'] ) ? $editor['content_id'] : 0 );
		if ( is_wp_error( $type ) || ! $parent || empty( $type['supports']['revisions'] ) ) {
			return new WP_Error( 'fleet_revisions_unavailable', __( 'Revision history is not available for this item.', 'fleet-for-openstation' ) );
		}
		$route = $type['route'] . '/' . $parent . '/revisions';
		if ( $revision ) {
			$item = self::remote_request( $site, 'GET', $route . '/' . absint( $revision ) . '?context=edit&_fields=id,parent,title,content,excerpt,date_gmt' );
			return is_wp_error( $item ) ? $item : ( isset( $item['parent'] ) && (int) $item['parent'] === $parent ? $item : new WP_Error( 'fleet_revision_parent', __( 'That revision belongs to another item.', 'fleet-for-openstation' ) ) );
		}
		return OpenStation_Fleet_REST_Client::request_envelope(
			$site,
			'GET',
			add_query_arg(
				array(
					'context'  => 'edit',
					'per_page' => 12,
					'page'     => max( 1, (int) $page ),
					'_fields'  => 'id,parent,date_gmt',
				),
				$route
			)
		);
	}

	/**
	 * Prepare a review of exactly the submitted values without remote mutation.
	 *
	 * @param string $id Site id.
	 * @param array  $values Editor values.
	 * @return array|WP_Error
	 */
	public static function content_review( $id, $values ) {
		$site = self::get_site( $id );
		$body = OpenStation_Fleet_Content::body( $values );
		if ( ! OpenStation_Fleet_Access::allowed( $site, 'content', true ) || is_wp_error( $body ) ) {
			return is_wp_error( $body ) ? $body : new WP_Error( 'fleet_review_site', __( 'That site is unavailable.', 'fleet-for-openstation' ) );
		}
		$site['_fleet_operation'] = 'content';
		$site['_fleet_write']     = true;
		$type                     = self::content_type( $site, isset( $values['content_type'] ) ? $values['content_type'] : '' );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$parent  = absint( isset( $values['content_id'] ) ? $values['content_id'] : 0 );
		$current = $parent ? self::remote_request( $site, 'GET', $type['route'] . '/' . $parent . '?context=edit&_fields=' . OpenStation_Fleet_Content::FIELDS ) : array();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( $parent && ( empty( $values['fingerprint'] ) || ! hash_equals( OpenStation_Fleet_Content::fingerprint( $current ), (string) $values['fingerprint'] ) ) ) {
			return new WP_Error( 'fleet_content_conflict', __( 'WordPress changed since you opened this item. Copy your edits before loading the latest version.', 'fleet-for-openstation' ) );
		}
		$settings = self::remote_request( $site, 'GET', 'wp/v2/settings?_fields=timezone' );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$zone = self::content_timezone( $site, isset( $settings['timezone'] ) ? $settings['timezone'] : '' );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$when = __( 'On confirmation', 'fleet-for-openstation' );
		if ( ! empty( $body['date_gmt'] ) ) {
			try {
				$when = ( new DateTimeImmutable( $body['date_gmt'] . 'Z' ) )->setTimezone( $zone )->format( 'Y-m-d H:i T' );
			} catch ( Exception $error ) {
				$when = $body['date_gmt'] . ' UTC';
			}
		}
		$expires = time() + 600;
		return array(
			'before'  => OpenStation_Fleet_Content::editable( $current ),
			'when'    => $when,
			'token'   => OpenStation_Fleet_Content::review_token( $site, $values, $expires ),
			'expires' => $expires,
		);
	}

	/**
	 * User-owned view configuration lives with its site, not with remote content.
	 *
	 * @param string $id Site id.
	 * @param string $action Read, save or delete.
	 * @param array  $values View name/configuration.
	 * @return array|WP_Error
	 */
	public static function work_views( $id, $action = 'read', $values = array() ) {
		if ( ! current_user_can( self::CAPABILITY ) || 0 === strpos( $id, 'shared_' ) ) {
			return new WP_Error( 'fleet_views_forbidden', __( 'You cannot manage these views.', 'fleet-for-openstation' ) );
		}
		$lock = 'read' !== $action ? OpenStation_Fleet_Repository::acquire_lock( 'views_' . $id, get_current_user_id() ) : null;
		if ( false === $lock ) {
			return new WP_Error( 'fleet_views_busy', __( 'Another window is updating views. Try again.', 'fleet-for-openstation' ) );
		}
		try {
			$site = self::get_site( $id );
			if ( ! is_array( $site ) ) {
				return new WP_Error( 'fleet_views_missing', __( 'That site is unavailable.', 'fleet-for-openstation' ) );
			}
			if ( 'read' === $action ) {
				return $site['views'];
			}
			if ( 'delete' === $action ) {
				unset( $site['views'][ sanitize_key( isset( $values['view_id'] ) ? $values['view_id'] : '' ) ] );
			} elseif ( 'save' === $action ) {
				$name = substr( sanitize_text_field( isset( $values['view_name'] ) && is_string( $values['view_name'] ) ? $values['view_name'] : '' ), 0, 80 );
				if ( '' === $name ) {
					return new WP_Error( 'fleet_view_name', __( 'Give this view a name.', 'fleet-for-openstation' ) );
				}
				$key = substr( hash( 'sha256', $name ), 0, 16 );
				if ( count( $site['views'] ) >= 12 && ! isset( $site['views'][ $key ] ) ) {
					return new WP_Error( 'fleet_views_limit', __( 'You can save twelve views per site. Remove one before adding another.', 'fleet-for-openstation' ) );
				}
				$site['views'][ $key ] = array(
					'name'   => $name,
					'type'   => sanitize_key( isset( $values['type'] ) && is_string( $values['type'] ) ? $values['type'] : 'posts' ),
					'status' => isset( $values['status'] ) && in_array( $values['status'], array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' ), true ) ? $values['status'] : 'any',
					'period' => isset( $values['period'] ) && 'week' === $values['period'] ? 'week' : 'all',
					'search' => substr( sanitize_text_field( isset( $values['search'] ) && is_string( $values['search'] ) ? $values['search'] : '' ), 0, 160 ),
				);
			} else {
				return new WP_Error( 'fleet_views_action', __( 'Unknown view action.', 'fleet-for-openstation' ) );
			}
			return self::save_site( $id, $site, array( 'views' ) ) ? $site['views'] : new WP_Error( 'fleet_views_storage', __( 'Fleet could not save this view. Try again.', 'fleet-for-openstation' ) );
		} finally {
			if ( null !== $lock ) {
				OpenStation_Fleet_Repository::release_lock( $lock );
			}
		}
	}

	/**
	 * Prepare the Core Application Password approval URL for a native app.
	 *
	 * @param string $raw_url User-entered managed-site URL.
	 * @param bool   $reconnect Replace the existing credential after approval.
	 * @return array|WP_Error Authorization URL, or an existing site id.
	 */
	public static function app_connect( $raw_url, $reconnect = false ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error( 'openstation_fleet_forbidden', __( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}
		if ( 'https' !== wp_parse_url( admin_url(), PHP_URL_SCHEME ) ) {
			return new WP_Error( 'openstation_fleet_https_required', __( 'The Fleet hub must use HTTPS before it can connect sites.', 'fleet-for-openstation' ) );
		}
		$site_url = self::normalize_site_url( $raw_url );
		if ( is_wp_error( $site_url ) ) {
			return $site_url;
		}
		if ( self::is_hub_site( $site_url ) ) {
			return new WP_Error( 'openstation_fleet_self_site', __( 'The Fleet hub cannot connect to itself.', 'fleet-for-openstation' ) );
		}
		$discovery = self::discover_site( $site_url );
		if ( is_wp_error( $discovery ) ) {
			return $discovery;
		}
		if ( self::is_hub_site( $discovery['site_url'] ) ) {
			return new WP_Error( 'openstation_fleet_self_site', __( 'The Fleet hub cannot connect to itself.', 'fleet-for-openstation' ) );
		}
		$id       = self::site_id( $discovery['site_url'] );
		$previous = self::get_site( $id );
		if ( false !== $previous && ! $reconnect ) {
			return array(
				'site_id'           => $id,
				'authorization_url' => '',
			);
		}
		if ( $reconnect ) {
			if ( ! is_array( $previous ) ) {
				return new WP_Error( 'fleet_reconnect_missing', __( 'This connection was removed. Connect it again from Fleet.', 'fleet-for-openstation' ) );
			}
			$discovery['replace_generation'] = $previous['connection_generation'];
		}
		$state                     = wp_generate_uuid4();
		$callback                  = add_query_arg(
			array(
				'action'   => 'openstation_fleet_authorized',
				'state'    => $state,
				'_wpnonce' => wp_create_nonce( 'openstation_fleet_authorized_' . $state ),
			),
			admin_url( 'admin-post.php' )
		);
		$discovery['callback']     = $callback;
		$discovery['approval_url'] = self::authorization_url(
			$discovery['authorization_url'],
			array(
				// translators: %s: Fleet hub hostname.
				'app_name'    => sprintf( __( 'Fleet for OpenStation on %s', 'fleet-for-openstation' ), wp_parse_url( home_url(), PHP_URL_HOST ) ),
				'app_id'      => self::get_app_id(),
				'success_url' => $callback,
				'reject_url'  => add_query_arg( 'rejected', '1', $callback ),
			)
		);
		set_transient( self::pending_key( get_current_user_id(), $state ), $discovery, 10 * MINUTE_IN_SECONDS );
		return array(
			'site_id'           => '',
			'name'              => $discovery['name'],
			'url'               => $discovery['site_url'],
			'expires'           => time() + 10 * MINUTE_IN_SECONDS,
			'ticket'            => $state,
			'authorization_url' => $discovery['approval_url'],
		);
	}

	/**
	 * Resolve approval from the expiring server record, never a client-state URL.
	 *
	 * @param mixed $ticket Connection check identifier.
	 * @return string|WP_Error
	 */
	public static function app_authorize( $ticket ) {
		if ( ! current_user_can( self::CAPABILITY ) || ! is_string( $ticket ) || ! wp_is_uuid( $ticket ) ) {
			return new WP_Error( 'fleet_approval_missing', __( 'Start a new connection check before approving this site.', 'fleet-for-openstation' ) );
		}
		$pending = get_transient( self::pending_key( get_current_user_id(), $ticket ) );
		if ( ! is_array( $pending ) || empty( $pending['approval_url'] ) ) {
			return new WP_Error( 'fleet_approval_expired', __( 'The connection check expired. Enter the site address again to start a fresh check.', 'fleet-for-openstation' ) );
		}
		return $pending['approval_url'];
	}

	/**
	 * Run a validated framework action against local Fleet state or Core REST.
	 *
	 * @param string $id     Site id.
	 * @param string $action Action name.
	 * @param array  $values Untrusted action values.
	 * @return array|WP_Error
	 */
	public static function app_action( $id, $action, $values = array() ) {
		$values = is_array( $values ) ? $values : array();
		$lock   = null;
		if ( in_array( $action, array( 'content', 'trash-content' ), true ) && ! empty( $values['content_id'] ) ) {
			$site = self::get_site( sanitize_key( $id ) );
			if ( ! OpenStation_Fleet_Access::allowed( $site, $action, true ) ) {
				return new WP_Error( 'fleet_role_forbidden', __( 'Your Fleet role does not allow this action. Ask the connection owner.', 'fleet-for-openstation' ) );
			}
			// Serialize Fleet writers to this item, including delegated teammates.
			// A writer outside this hub still requires Core's remote conflict check.
			$key  = hash( 'sha256', $site['site_url'] . ':' . sanitize_key( $values['content_type'] ?? '' ) . ':' . absint( $values['content_id'] ) );
			$lock = OpenStation_Fleet_Repository::acquire_lock( 'content_write', 0, $key );
			if ( false === $lock ) {
				return new WP_Error( 'fleet_content_busy', __( 'Another Fleet window is saving this item. Keep your edits and try again after it finishes.', 'fleet-for-openstation' ) );
			}
		}
		try {
			return self::dispatch_action( $id, $action, $values );
		} finally {
			if ( null !== $lock ) {
				OpenStation_Fleet_Repository::release_lock( $lock );
			}
		}
	}

	/**
	 * Execute one authorized action, under the caller's item lock when necessary.
	 *
	 * @param string $id Connection id.
	 * @param string $action Action name.
	 * @param array  $values Untrusted values.
	 * @return array|WP_Error
	 */
	private static function dispatch_action( $id, $action, $values ) {
		if ( ! self::can_use() ) {
			return new WP_Error( 'openstation_fleet_forbidden', __( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}
		$id     = sanitize_key( $id );
		$action = sanitize_key( $action );
		$values = is_array( $values ) ? $values : array();
		$site   = self::get_site( $id );
		if ( ! OpenStation_Fleet_Access::allowed( $site, $action, true ) ) {
			return new WP_Error( 'fleet_role_forbidden', __( 'Your Fleet role does not allow this action. Ask the connection owner.', 'fleet-for-openstation' ) );
		}
		$site['_fleet_operation'] = $action;
		$site['_fleet_write']     = true;
		if ( '' === $id || ! is_array( $site ) ) {
			return new WP_Error( 'openstation_fleet_site_missing', __( 'That connected site no longer exists.', 'fleet-for-openstation' ) );
		}
		$result       = array( 'ok' => true );
		$message      = '';
		$status       = 'success';
		$local_fields = array();
		$search_state = null;

		switch ( $action ) {
			case 'refresh':
				$search_state = self::get_site_search( $id, $site );
				$site         = self::refresh_site( $site, true, null, $search_state );
				$result       = empty( $site['error'] ) ? array( 'ok' => true ) : new WP_Error( 'openstation_fleet_check_failed', $site['error'] );
				$message      = is_wp_error( $result ) ? __( 'Site check failed.', 'fleet-for-openstation' ) : __( 'Site status refreshed.', 'fleet-for-openstation' );
				$local_fields = self::remote_state_fields();
				break;
			case 'finish-setup':
			case 'install-openstation':
				$search_state = self::get_site_search( $id, $site );
				$result       = self::install_openstation( $site );
				if ( ! is_wp_error( $result ) ) {
					$site                 = self::refresh_site( $site, true, null, $search_state );
					$site['setup_status'] = empty( $site['error'] ) ? 'ready' : 'error';
					$result               = empty( $site['error'] ) ? array( 'ok' => true ) : new WP_Error( 'openstation_fleet_check_failed', $site['error'] );
				} else {
					$site['setup_status'] = 'error';
				}
				$message      = is_wp_error( $result ) ? __( 'OpenStation installation failed.', 'fleet-for-openstation' ) : __( 'OpenStation is installed, active, and ready.', 'fleet-for-openstation' );
				$local_fields = array_merge( self::remote_state_fields(), array( 'setup_status' ) );
				break;
			case 'favorite':
				$site['agency']['favorite'] = empty( $site['agency']['favorite'] );
				$local_fields[]             = 'agency';
				$message                    = $site['agency']['favorite'] ? __( 'Site added to favorites.', 'fleet-for-openstation' ) : __( 'Site removed from favorites.', 'fleet-for-openstation' );
				break;
			case 'settings':
				$body   = array(
					'title'         => sanitize_text_field( isset( $values['title'] ) ? $values['title'] : '' ),
					'description'   => sanitize_text_field( isset( $values['description'] ) ? $values['description'] : '' ),
					'timezone'      => sanitize_text_field( isset( $values['timezone'] ) ? $values['timezone'] : '' ),
					'date_format'   => sanitize_text_field( isset( $values['date_format'] ) ? $values['date_format'] : '' ),
					'time_format'   => sanitize_text_field( isset( $values['time_format'] ) ? $values['time_format'] : '' ),
					'start_of_week' => min( 6, absint( isset( $values['start_of_week'] ) ? $values['start_of_week'] : 0 ) ),
				);
				$result = self::remote_request( $site, 'POST', 'wp/v2/settings', $body );
				if ( ! is_wp_error( $result ) && ( ! isset( $result['timezone'] ) || $result['timezone'] !== $body['timezone'] ) ) {
					$result = new WP_Error( 'fleet_settings_unconfirmed', __( 'WordPress did not confirm the requested timezone. Reload settings before trying again.', 'fleet-for-openstation' ) );
				}
				if ( ! is_wp_error( $result ) && '' !== $body['title'] ) {
					$site['name']   = $body['title'];
					$local_fields[] = 'name';
				}
				$message = __( 'Remote site settings updated.', 'fleet-for-openstation' );
				break;
			case 'content':
			case 'trash-content':
				$type       = sanitize_key( isset( $values['content_type'] ) ? $values['content_type'] : '' );
				$content_id = absint( isset( $values['content_id'] ) ? $values['content_id'] : 0 );
				if ( ! OpenStation_Fleet_Content::valid_type( $type ) || ( 'trash-content' === $action && ! $content_id ) ) {
					return new WP_Error( 'openstation_fleet_invalid_content', __( 'Choose a valid post or page.', 'fleet-for-openstation' ) );
				}
				$body = 'trash-content' === $action ? null : OpenStation_Fleet_Content::body( $values );
				if ( is_wp_error( $body ) ) {
					return $body;
				}
				if ( ! $content_id && isset( $values['request_id'] ) && is_string( $values['request_id'] ) && wp_is_uuid( $values['request_id'] ) && false !== get_transient( 'fleet_create_' . get_current_user_id() . '_' . hash( 'sha256', $id . $values['request_id'] ) ) ) {
					return new WP_Error( 'fleet_create_uncertain', __( 'This creation was already attempted. Check the content list before creating another draft; WordPress may have saved it. Your text is still here to copy.', 'fleet-for-openstation' ) );
				}
				$descriptor = self::content_type( $site, $type );
				if ( is_wp_error( $descriptor ) ) {
					return $descriptor;
				}
				if ( is_array( $body ) && empty( $descriptor['supports']['excerpt'] ) ) {
					unset( $body['excerpt'] );
				}
				$route   = $descriptor['route'] . ( $content_id ? '/' . $content_id : '' );
				$current = array();
				if ( $content_id ) {
					$current = self::remote_request( $site, 'GET', $route . '?context=edit&_fields=' . OpenStation_Fleet_Content::FIELDS );
					if ( is_wp_error( $current ) ) {
						return $current;
					}
					if ( empty( $values['fingerprint'] ) || ! is_string( $values['fingerprint'] ) || ! hash_equals( OpenStation_Fleet_Content::fingerprint( $current ), $values['fingerprint'] ) ) {
						return new WP_Error( 'fleet_content_conflict', __( 'This content changed on WordPress since you opened it. Your edits are still here. Copy them before returning to the list and opening the latest version.', 'fleet-for-openstation' ) );
					}
				}
				if ( 'content' === $action && ( in_array( $body['status'], array( 'publish', 'future' ), true ) || ( isset( $current['status'] ) && in_array( $current['status'], array( 'publish', 'future' ), true ) ) ) && ! OpenStation_Fleet_Content::reviewed( $site, $values ) ) {
					return new WP_Error( 'fleet_review_required', __( 'Review the destination and changes before publishing or scheduling.', 'fleet-for-openstation' ) );
				}
				// Core has no atomic compare-and-swap: this detects changes before the write, not a remote lock.
				$result  = $content_id ? self::remote_request( $site, 'trash-content' === $action ? 'DELETE' : 'POST', $route, $body ) : self::create_content_once( $id, $site, $route, $body, isset( $values['request_id'] ) ? $values['request_id'] : '' );
				$message = 'trash-content' === $action ? __( 'Moved to Trash on WordPress.', 'fleet-for-openstation' ) : __( 'Saved on WordPress.', 'fleet-for-openstation' );
				if ( ! is_wp_error( $result ) && ! OpenStation_Fleet_Recovery::complete( $site, $values ) ) {
					$message .= ' ' . __( 'An older recovery checkpoint could not be cleared; remove it from Recovered drafts.', 'fleet-for-openstation' );
				}
				break;
			case 'comment':
				$comment_id     = absint( isset( $values['comment_id'] ) ? $values['comment_id'] : 0 );
				$comment_status = sanitize_key( isset( $values['status'] ) ? $values['status'] : '' );
				if ( $comment_id < 1 || ! in_array( $comment_status, array( 'approved', 'hold', 'spam', 'trash' ), true ) ) {
					return new WP_Error( 'openstation_fleet_invalid_comment', __( 'Choose a valid comment status.', 'fleet-for-openstation' ) );
				}
				$result = self::remote_request( $site, 'POST', 'wp/v2/comments/' . $comment_id, array( 'status' => $comment_status ) );
				// translators: %s: comment status.
				$message = sprintf( __( 'Comment moved to %s.', 'fleet-for-openstation' ), $comment_status );
				break;
			case 'reply-comment':
				$parent = absint( $values['comment_id'] ?? 0 );
				$text   = $values['content'] ?? null;
				if ( ! $parent || ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > 20000 ) {
					return new WP_Error( 'fleet_reply_invalid', __( 'Write a reply smaller than 20 KB.', 'fleet-for-openstation' ) );
				}
				$comment = self::remote_request( $site, 'GET', 'wp/v2/comments/' . $parent . '?context=edit&_fields=id,post,status' );
				if ( is_wp_error( $comment ) || empty( $comment['post'] ) || in_array( $comment['status'] ?? '', array( 'spam', 'trash' ), true ) ) {
					return new WP_Error( 'fleet_reply_parent', __( 'This comment is unavailable for replies. Refresh the queue.', 'fleet-for-openstation' ) );
				}
				$result  = self::create_content_once(
					$id,
					$site,
					'wp/v2/comments',
					array(
						'post'    => $comment['post'],
						'parent'  => $parent,
						'content' => $text,
					),
					$values['request_id'] ?? ''
				);
				$message = __( 'Reply saved on WordPress.', 'fleet-for-openstation' );
				break;
			case 'upload-media':
				$upload = OpenStation_Fleet_REST_Client::upload_body( $values );
				if ( is_wp_error( $upload ) ) {
					return $upload;
				}
				$result  = self::create_content_once( $id, $site, 'wp/v2/media', array(), $values['request_id'] ?? '', $upload );
				$message = __( 'File uploaded to this site’s media library.', 'fleet-for-openstation' );
				break;
			case 'media':
				$media_id = absint( isset( $values['media_id'] ) ? $values['media_id'] : 0 );
				if ( $media_id < 1 ) {
					return new WP_Error( 'openstation_fleet_invalid_media', __( 'Choose a valid media item.', 'fleet-for-openstation' ) );
				}
				$result  = self::remote_request(
					$site,
					'POST',
					'wp/v2/media/' . $media_id,
					array(
						'title'    => sanitize_text_field( isset( $values['title'] ) ? $values['title'] : '' ),
						'alt_text' => sanitize_text_field( isset( $values['alt_text'] ) ? $values['alt_text'] : '' ),
						'caption'  => sanitize_textarea_field( isset( $values['caption'] ) ? $values['caption'] : '' ),
					)
				);
				$message = __( 'Media details updated.', 'fleet-for-openstation' );
				break;
			case 'plugin':
				$plugin        = isset( $values['plugin'] ) ? (string) $values['plugin'] : '';
				$plugin_status = sanitize_key( isset( $values['status'] ) ? $values['status'] : '' );
				if ( ! preg_match( '#^[a-z0-9._-]+(?:/[a-z0-9._-]+)?$#i', $plugin ) || ! in_array( $plugin_status, array( 'active', 'inactive' ), true ) ) {
					return new WP_Error( 'openstation_fleet_invalid_plugin', __( 'Choose a valid plugin action.', 'fleet-for-openstation' ) );
				}
				$route  = implode( '/', array_map( 'rawurlencode', explode( '/', $plugin ) ) );
				$result = self::remote_request( $site, 'POST', 'wp/v2/plugins/' . $route, array( 'status' => $plugin_status ) );
				// translators: 1: plugin id, 2: plugin status.
				$message = sprintf( __( '%1$s set to %2$s.', 'fleet-for-openstation' ), $plugin, $plugin_status );
				break;
			case 'install-plugin':
				$slug          = sanitize_key( isset( $values['plugin_slug'] ) ? $values['plugin_slug'] : '' );
				$plugin_status = sanitize_key( isset( $values['status'] ) ? $values['status'] : 'active' );
				if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $slug ) || ! in_array( $plugin_status, array( 'active', 'inactive' ), true ) ) {
					return new WP_Error( 'openstation_fleet_invalid_plugin', __( 'Enter a valid WordPress.org plugin slug.', 'fleet-for-openstation' ) );
				}
				$result = self::remote_request(
					$site,
					'POST',
					'wp/v2/plugins',
					array(
						'slug'   => $slug,
						'status' => $plugin_status,
					)
				);
				// translators: %s: WordPress.org plugin slug.
				$message = sprintf( __( 'Plugin %s installed.', 'fleet-for-openstation' ), $slug );
				break;
			case 'create-user':
				$username = sanitize_user( isset( $values['username'] ) ? $values['username'] : '', true );
				$email    = sanitize_email( isset( $values['email'] ) ? $values['email'] : '' );
				$password = isset( $values['password'] ) ? (string) $values['password'] : '';
				$role     = sanitize_key( isset( $values['role'] ) ? $values['role'] : '' );
				if ( '' === $username || ! is_email( $email ) || strlen( $password ) < 12 || ! in_array( $role, self::editable_roles(), true ) ) {
					return new WP_Error( 'openstation_fleet_invalid_user', __( 'Enter a username, valid email, role, and password of at least 12 characters.', 'fleet-for-openstation' ) );
				}
				$result   = self::remote_request(
					$site,
					'POST',
					'wp/v2/users',
					array(
						'username' => $username,
						'email'    => $email,
						'password' => $password,
						'roles'    => array( $role ),
					)
				);
				$password = null;
				// translators: %s: new WordPress username.
				$message = sprintf( __( 'User %s created.', 'fleet-for-openstation' ), $username );
				break;
			case 'user':
				$user_id = absint( isset( $values['user_id'] ) ? $values['user_id'] : 0 );
				$name    = sanitize_text_field( isset( $values['name'] ) ? $values['name'] : '' );
				$email   = sanitize_email( isset( $values['email'] ) ? $values['email'] : '' );
				$role    = sanitize_key( isset( $values['role'] ) ? $values['role'] : '' );
				if ( $user_id < 1 || '' === $name || ! is_email( $email ) || ! in_array( $role, self::editable_roles(), true ) ) {
					return new WP_Error( 'openstation_fleet_invalid_user', __( 'Enter a display name, valid email, and role.', 'fleet-for-openstation' ) );
				}
				$result = self::remote_request(
					$site,
					'POST',
					'wp/v2/users/' . $user_id,
					array(
						'name'  => $name,
						'email' => $email,
						'roles' => array( $role ),
					)
				);
				// translators: %d: WordPress user id.
				$message = sprintf( __( 'User #%d updated.', 'fleet-for-openstation' ), $user_id );
				break;
			case 'agency':
				$plan           = sanitize_key( isset( $values['plan_status'] ) ? $values['plan_status'] : 'none' );
				$tags           = preg_split( '/[,\r\n]+/', isset( $values['tags'] ) ? (string) $values['tags'] : '' );
				$site['agency'] = array(
					'client_name' => sanitize_text_field( isset( $values['client_name'] ) ? $values['client_name'] : '' ),
					'tags'        => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', is_array( $tags ) ? $tags : array() ) ) ) ),
					'plan_status' => in_array( $plan, array( 'none', 'active', 'paused', 'ended' ), true ) ? $plan : 'none',
					'notes'       => sanitize_textarea_field( isset( $values['notes'] ) ? $values['notes'] : '' ),
					'favorite'    => ! empty( $values['favorite'] ),
				);
				$local_fields[] = 'agency';
				$message        = __( 'Agency profile updated.', 'fleet-for-openstation' );
				break;
			case 'api':
				$method = strtoupper( sanitize_key( isset( $values['api_method'] ) ? $values['api_method'] : 'GET' ) );
				$route  = trim( isset( $values['api_route'] ) ? (string) $values['api_route'] : '' );
				$raw    = trim( isset( $values['api_body'] ) ? (string) $values['api_body'] : '' );
				if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) || ! self::is_safe_api_route( $route ) ) {
					return new WP_Error( 'openstation_fleet_invalid_api', __( 'Choose a valid method and REST route.', 'fleet-for-openstation' ) );
				}
				$body = null;
				if ( '' !== $raw ) {
					$body = json_decode( $raw, true );
					if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
						return new WP_Error( 'openstation_fleet_invalid_json', __( 'The request body must be a JSON object or array.', 'fleet-for-openstation' ) );
					}
				}
				$result = self::remote_request( $site, $method, ltrim( $route, '/' ), $body );
				// translators: 1: HTTP method, 2: REST route.
				$message = sprintf( __( '%1$s /%2$s completed.', 'fleet-for-openstation' ), $method, ltrim( $route, '/' ) );
				break;
			case 'disconnect':
				$uuid = isset( $site['credential_uuid'] ) ? rawurlencode( $site['credential_uuid'] ) : '';
				if ( '' === $uuid ) {
					return new WP_Error( 'openstation_fleet_disconnect_failed', __( 'Fleet could not identify the remote authorization.', 'fleet-for-openstation' ) );
				}
				$revoked = self::remote_request( $site, 'DELETE', 'wp/v2/users/me/application-passwords/' . $uuid );
				if ( is_wp_error( $revoked ) ) {
					return $revoked;
				}
				if ( ! self::remove_site( $id, $site['connection_generation'] ) ) {
					return new WP_Error( 'openstation_fleet_storage_failed', __( 'The remote authorization was revoked, but Fleet could not remove its local connection. Refresh this window before continuing.', 'fleet-for-openstation' ) );
				}
				self::invalidate_read_cache( $id );
				self::record_activity( $id, $site, 'disconnected', __( 'Remote authorization revoked and site disconnected.', 'fleet-for-openstation' ) );
				return array(
					'ok'           => true,
					'disconnected' => true,
				);
			default:
				return new WP_Error( 'openstation_fleet_unknown_action', __( 'Fleet did not recognize that action.', 'fleet-for-openstation' ) );
		}

		if ( is_wp_error( $result ) ) {
			$status  = 'error';
			$message = $result->get_error_message();
		}
		if ( is_array( $search_state ) && ! self::save_site_search( $id, $site, $search_state ) ) {
			return new WP_Error( 'openstation_fleet_storage_failed', __( 'The site check completed, but Fleet could not save its search index. Refresh this window before continuing.', 'fleet-for-openstation' ) );
		}
		if ( ! empty( $local_fields ) && ! self::save_site( $id, $site, $local_fields ) ) {
			return new WP_Error( 'openstation_fleet_storage_failed', __( 'The remote action completed, but Fleet could not save its local state. Refresh this window before continuing.', 'fleet-for-openstation' ) );
		}
		self::invalidate_read_cache( $id );
		self::record_activity( $id, $site, $action, $message, $status );
		return is_wp_error( $result ) ? $result : array(
			'ok'      => true,
			'message' => $message,
			'data'    => $result,
		);
	}

	/**
	 * Do not replay an uncertain create. Core has no idempotency key support.
	 *
	 * @param string     $id Site id.
	 * @param array      $site Connection.
	 * @param string     $route Core collection route.
	 * @param array      $body Validated content.
	 * @param string     $request_id Per-editor operation id.
	 * @param array|null $upload Validated binary upload, if this creates media.
	 * @return array|WP_Error
	 */
	private static function create_content_once( $id, $site, $route, $body, $request_id, $upload = null ) {
		if ( ! is_string( $request_id ) || ! wp_is_uuid( $request_id ) ) {
			return new WP_Error( 'fleet_create_id', __( 'Reopen this workflow before creating an item.', 'fleet-for-openstation' ) );
		}
		$key  = 'fleet_create_' . get_current_user_id() . '_' . hash( 'sha256', $id . $request_id );
		$lock = OpenStation_Fleet_Repository::acquire_lock( $key, get_current_user_id() );
		if ( false === $lock ) {
			return new WP_Error( 'fleet_create_busy', __( 'This item is already being saved. Wait for the result before trying again.', 'fleet-for-openstation' ) );
		}
		try {
			if ( false !== get_transient( $key ) ) {
				return new WP_Error( 'fleet_create_uncertain', __( 'This creation was already attempted. Refresh the relevant list before creating another item; WordPress may have saved it.', 'fleet-for-openstation' ) );
			}
			if ( ! set_transient( $key, 'attempted', 14 * DAY_IN_SECONDS ) ) {
				return new WP_Error( 'fleet_create_storage', __( 'Fleet could not protect this item against duplicate creation. Nothing was sent. Try again.', 'fleet-for-openstation' ) );
			}
			$result = null === $upload ? self::remote_request( $site, 'POST', $route, $body ) : OpenStation_Fleet_REST_Client::upload( $site, $upload );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_data();
				if ( is_array( $error ) && isset( $error['status'] ) && in_array( $error['status'], array( 400, 401, 403, 404, 405, 413, 422 ), true ) ) {
					delete_transient( $key );
					return $result;
				}
				return new WP_Error( 'fleet_create_uncertain', __( 'Fleet could not confirm whether WordPress saved this item. Refresh the relevant list before trying again. Keep a copy of any unsaved text.', 'fleet-for-openstation' ) );
			}
			return $result;
		} finally {
			OpenStation_Fleet_Repository::release_lock( $lock );
		}
	}

	/**
	 * Normalize and validate a public HTTPS WordPress site URL.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error
	 */
	public static function normalize_site_url( $url ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if (
			false === $parts ||
			empty( $parts['scheme'] ) ||
			'https' !== strtolower( $parts['scheme'] ) ||
			empty( $parts['host'] ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] )
		) {
			return new WP_Error( 'openstation_fleet_invalid_url', __( 'Enter a public HTTPS site URL without query parameters or credentials.', 'fleet-for-openstation' ) );
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '';
		return untrailingslashit( 'https://' . strtolower( $parts['host'] ) . $port . $path );
	}

	/**
	 * Summarize OpenStation from a Core Plugins endpoint response.
	 *
	 * @param array $plugins Plugin records.
	 * @return array
	 */
	public static function inspect_plugins( $plugins ) {
		foreach ( (array) $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			$file       = isset( $plugin['plugin'] ) ? (string) $plugin['plugin'] : '';
			$textdomain = isset( $plugin['textdomain'] ) ? (string) $plugin['textdomain'] : '';
			if ( self::PLUGIN_REST_ID !== $file && self::PLUGIN_REST_ID . '.php' !== $file && 'desktop-mode' !== $textdomain ) {
				continue;
			}

			$remote_status = isset( $plugin['status'] ) ? (string) $plugin['status'] : 'inactive';
			return array(
				'status'  => in_array( $remote_status, array( 'active', 'network-active' ), true ) ? 'active' : 'inactive',
				'version' => isset( $plugin['version'] ) ? (string) $plugin['version'] : '',
			);
		}

		return array(
			'status'  => 'missing',
			'version' => '',
		);
	}

	/**
	 * Discover REST and Application Password endpoints from the Core REST index.
	 *
	 * @param string $site_url Normalized URL.
	 * @return array|WP_Error
	 */
	private static function discover_site( $site_url ) {
		$endpoints = array(
			// Core's query form works without rewrite rules, including nested installs.
			add_query_arg( 'rest_route', '/', trailingslashit( $site_url ) ),
			trailingslashit( $site_url ) . 'wp-json/',
		);

		foreach ( $endpoints as $rest_url ) {
			$response = wp_safe_remote_get(
				$rest_url,
				array(
					'timeout'             => 15,
					'redirection'         => 3,
					'limit_response_size' => self::DISCOVERY_LIMIT,
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$reported_url = isset( $data['url'] ) ? self::normalize_site_url( $data['url'] ) : $site_url;
			if ( is_wp_error( $reported_url ) ) {
				continue;
			}

			$name              = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : wp_parse_url( $reported_url, PHP_URL_HOST );
			$authorization_url = isset( $data['authentication']['application-passwords']['endpoints']['authorization'] )
				? $data['authentication']['application-passwords']['endpoints']['authorization']
				: '';
			if ( self::is_same_origin_https_url( $authorization_url, $reported_url ) ) {
				$canonical_rest_url = false !== strpos( $rest_url, 'rest_route=' )
					? add_query_arg( 'rest_route', '/', trailingslashit( $reported_url ) )
					: trailingslashit( $reported_url ) . 'wp-json/';
				return array(
					'name'              => $name,
					'site_url'          => $reported_url,
					'rest_url'          => $canonical_rest_url,
					'authorization_url' => esc_url_raw( $authorization_url ),
				);
			}
		}

		return new WP_Error( 'openstation_fleet_discovery_failed', __( 'Could not find WordPress’s secure Application Password approval on that site.', 'fleet-for-openstation' ) );
	}

	/**
	 * Refresh cached OpenStation status for one site.
	 *
	 * @param array      $site         Site record.
	 * @param bool       $force        Refresh every tier regardless of its cadence.
	 * @param float|null $deadline     Optional cron wall-clock deadline.
	 * @param array|null $search_state Separately persisted search payload, updated in place when metadata is due.
	 * @return array
	 */
	private static function refresh_site( $site, $force = true, $deadline = null, &$search_state = null ) {
		$site         = self::normalize_site_record( $site );
		$now          = time();
		$status_due   = OpenStation_Fleet_Sync_Policy::due( $site, 'status', $now, $force );
		$metadata_due = OpenStation_Fleet_Sync_Policy::due( $site, 'metadata', $now, $force );
		$health_due   = OpenStation_Fleet_Sync_Policy::due( $site, 'health', $now, $force );
		if ( ! $status_due && ! $metadata_due && ! $health_due ) {
			return $site;
		}

		$site['last_checked'] = $now;
		$root                 = self::remote_request( $site, 'GET', '', null, $deadline );
		if ( is_wp_error( $root ) ) {
			return OpenStation_Fleet_Sync_Policy::failed( $site, $root->get_error_message(), $now );
		}
		$site['capabilities'] = self::discover_capabilities( $root );

		if ( $status_due && self::sync_has_time( $deadline ) ) {
			$inbox = self::fetch_inbox_summary( $site, $deadline );
			if ( is_wp_error( $inbox ) ) {
				return OpenStation_Fleet_Sync_Policy::failed( $site, $inbox->get_error_message(), $now );
			}
			$site['inbox']          = $inbox;
			$site['status_checked'] = $now;
		}

		if ( $metadata_due && self::sync_has_time( $deadline ) ) {
			if ( ! is_array( $search_state ) ) {
				$search_state = array(
					'search_index_state' => array(),
					'search_index'       => array(),
				);
			}
			$site['environment']       = self::fetch_core_environment( $site, $deadline );
			$site['wordpress_version'] = ! empty( $site['environment']['wp_version'] )
				? $site['environment']['wp_version']
				: self::wordpress_version_from_root( $root );
			if ( ! self::sync_has_time( $deadline ) ) {
				return OpenStation_Fleet_Sync_Policy::succeeded( $site );
			}
			$plugins = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit', null, $deadline );
			if ( is_wp_error( $plugins ) ) {
				return OpenStation_Fleet_Sync_Policy::failed( $site, $plugins->get_error_message(), $now );
			}
			$site['openstation'] = self::inspect_plugins( $plugins );
			if ( self::sync_has_time( $deadline ) ) {
				$search_state['search_index_state'] = self::fetch_search_index( $site, $search_state, $force ? 2 : 1, $deadline )['state'];
				$search_state['search_index']       = array();
				$site['metadata_checked']           = $now;
			}
		}

		if ( $health_due && self::sync_has_time( $deadline ) ) {
			$health                   = self::fetch_site_health( $site, $deadline );
			$site['health_attempted'] = $now;
			$site['health_error']     = is_wp_error( $health ) ? $health->get_error_message() : '';
			if ( is_wp_error( $health ) ) {
				$partial        = $health->get_error_data();
				$site['health'] = array_merge( $site['health'], is_array( $partial ) ? $partial : array() );
			} else {
				$site['health']         = $health;
				$site['health_checked'] = $now;
			}
		}
		return OpenStation_Fleet_Sync_Policy::succeeded( $site );
	}

	/**
	 * Whether another bounded network operation may begin.
	 *
	 * @param float|null $deadline Wall-clock deadline or null outside cron.
	 * @return bool
	 * @phpstan-impure Reads the current wall clock on every call.
	 */
	private static function sync_has_time( $deadline ) {
		return null === $deadline || microtime( true ) < $deadline;
	}

	/**
	 * Refresh a small, resumable slice of the fleet through WordPress Cron.
	 *
	 * Keeping each run bounded prevents a large fleet from turning a front-end
	 * request that happens to spawn WP-Cron into a long remote-request chain.
	 */
	public static function run_scheduled_checks() {
		$started = microtime( true );
		$lock    = OpenStation_Fleet_Repository::acquire_lock( 'cron', 0 );
		if ( false === $lock ) {
			return;
		}

		$original_user = get_current_user_id();
		update_option(
			'openstation_fleet_last_sync_run',
			array(
				'started'  => time(),
				'finished' => 0,
			),
			false
		);
		try {
			$jobs     = array();
			$user_ids = OpenStation_Fleet_Repository::user_ids();
			foreach ( $user_ids as $user_id ) {
				$user_id = (int) $user_id;
				if ( ! user_can( $user_id, self::CAPABILITY ) ) {
					continue;
				}
				wp_set_current_user( $user_id );
				foreach ( OpenStation_Fleet_Repository::site_ids( $user_id, array( __CLASS__, 'normalize_site_record' ) ) as $site_id ) {
					$jobs[] = array(
						'user_id' => $user_id,
						'site_id' => sanitize_key( $site_id ),
					);
				}
			}

			$cursor = sanitize_text_field( (string) get_option( self::CRON_CURSOR, '' ) );
			$start  = 0;
			foreach ( $jobs as $index => $job ) {
				if ( $cursor === $job['user_id'] . ':' . $job['site_id'] ) {
					$start = $index + 1;
					break;
				}
			}
			$completed = $start;
			$job_count = count( $jobs );
			for ( $index = $start; $index < $job_count; ++$index ) {
				if ( $index > $start && microtime( true ) - $started >= OpenStation_Fleet_Sync_Policy::RUN_BUDGET ) {
					break;
				}
				$job = $jobs[ $index ];
				wp_set_current_user( $job['user_id'] );
				$site = self::get_site( $job['site_id'] );
				if ( ! is_array( $site ) ) {
					update_option( self::CRON_CURSOR, $job['user_id'] . ':' . $job['site_id'], false );
					$completed = $index + 1;
					continue;
				}
				$before       = count( self::attention_reasons( $site ) );
				$search_state = OpenStation_Fleet_Sync_Policy::due( $site, 'metadata', time(), false )
					? self::get_site_search( $job['site_id'], $site )
					: null;
				$site         = self::refresh_site( $site, false, $started + OpenStation_Fleet_Sync_Policy::RUN_BUDGET, $search_state );
				$after        = count( self::attention_reasons( $site ) );
				if ( is_array( $search_state ) && ! self::save_site_search( $job['site_id'], $site, $search_state ) ) {
					update_option( self::CRON_CURSOR, $job['user_id'] . ':' . $job['site_id'], false );
					$completed = $index + 1;
					continue;
				}
				if ( ! self::save_site( $job['site_id'], $site, self::remote_state_fields() ) ) {
					update_option( self::CRON_CURSOR, $job['user_id'] . ':' . $job['site_id'], false );
					$completed = $index + 1;
					continue;
				}
				if ( $before !== $after ) {
					self::record_activity(
						$job['site_id'],
						$site,
						'health',
						$after > $before ? __( 'New attention item detected.', 'fleet-for-openstation' ) : __( 'A Fleet attention item was resolved.', 'fleet-for-openstation' ),
						$after > $before ? 'warning' : 'success'
					);
				}
				update_option( self::CRON_CURSOR, $job['user_id'] . ':' . $job['site_id'], false );
				$completed = $index + 1;
			}
			if ( $completed >= $job_count ) {
				delete_option( self::CRON_CURSOR );
			}
		} finally {
			update_option(
				'openstation_fleet_last_sync_run',
				array(
					'started'  => (int) $started,
					'finished' => time(),
				),
				false
			);
			wp_set_current_user( $original_user );
			OpenStation_Fleet_Repository::release_lock( $lock );
		}
	}

	/**
	 * Summarize REST routes advertised by a connected site.
	 *
	 * @param array $root REST index document.
	 * @return array
	 */
	private static function discover_capabilities( $root ) {
		$routes     = isset( $root['routes'] ) && is_array( $root['routes'] ) ? array_keys( $root['routes'] ) : array();
		$namespaces = isset( $root['namespaces'] ) && is_array( $root['namespaces'] ) ? array_values( array_map( 'sanitize_text_field', $root['namespaces'] ) ) : array();
		$has        = static function ( $route ) use ( $routes ) {
			return in_array( $route, $routes, true );
		};
		return array(
			'batch'       => $has( '/batch/v1' ),
			'abilities'   => in_array( 'wp-abilities/v1', $namespaces, true ),
			'search'      => $has( '/wp/v2/search' ),
			'posts'       => $has( '/wp/v2/posts' ),
			'pages'       => $has( '/wp/v2/pages' ),
			'comments'    => $has( '/wp/v2/comments' ),
			'media'       => $has( '/wp/v2/media' ),
			'plugins'     => $has( '/wp/v2/plugins' ),
			'users'       => $has( '/wp/v2/users' ),
			'settings'    => $has( '/wp/v2/settings' ),
			'themes'      => $has( '/wp/v2/themes' ),
			'types'       => $has( '/wp/v2/types' ),
			'taxonomies'  => $has( '/wp/v2/taxonomies' ),
			'terms'       => $has( '/wp/v2/categories' ) || $has( '/wp/v2/tags' ),
			'navigation'  => $has( '/wp/v2/navigation' ) || $has( '/wp/v2/menus' ),
			'widgets'     => $has( '/wp/v2/widgets' ) || $has( '/wp/v2/sidebars' ),
			'templates'   => $has( '/wp/v2/templates' ) || $has( '/wp/v2/template-parts' ),
			'patterns'    => $has( '/wp/v2/blocks' ) || $has( '/wp/v2/block-patterns/patterns' ),
			'blocks'      => $has( '/wp/v2/block-types' ),
			'styles'      => 0 < count(
				array_filter(
					$routes,
					static function ( $route ) {
						return 0 === strpos( $route, '/wp/v2/global-styles/' ); }
				)
			),
			'fonts'       => $has( '/wp/v2/font-families' ),
			'statuses'    => $has( '/wp/v2/statuses' ),
			'site_health' => $has( '/wp-site-health/v1/tests/background-updates' ),
			'route_count' => count( $routes ),
			'namespaces'  => $namespaces,
		);
	}

	/**
	 * Read the authenticated environment facts exposed by Core's Abilities API.
	 *
	 * WordPress 6.9 introduced the API and WordPress 7.1 made its REST-facing
	 * schemas and public exposure contract friendlier to external clients. This
	 * call adds richer environment facts to the REST-index capability baseline.
	 *
	 * @param array      $site Connected site record with discovered capabilities.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array
	 */
	private static function fetch_core_environment( $site, $deadline = null ) {
		if ( empty( $site['capabilities']['abilities'] ) ) {
			return array();
		}

		$environment = self::remote_request( $site, 'GET', 'wp-abilities/v1/abilities/core/get-environment-info/run', null, $deadline );
		if ( is_wp_error( $environment ) || ! is_array( $environment ) ) {
			return array();
		}

		$clean = array();
		foreach ( array( 'environment', 'php_version', 'db_server_info', 'wp_version' ) as $key ) {
			if ( isset( $environment[ $key ] ) && is_scalar( $environment[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $environment[ $key ] );
			}
		}
		return $clean;
	}

	/**
	 * Normalize the live REST index into a safe, searchable route catalog.
	 *
	 * WordPress and plugins remain the source of truth: Fleet does not keep a
	 * parallel endpoint registry, and a route disappearing from the managed
	 * site disappears from this list on the next request.
	 *
	 * @param array $root REST index document.
	 * @return array
	 */
	private static function api_route_catalog( $root ) {
		$allowed_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
		$catalog         = array();
		$routes          = isset( $root['routes'] ) && is_array( $root['routes'] ) ? $root['routes'] : array();

		foreach ( $routes as $route => $definition ) {
			if ( ! is_string( $route ) || ! is_array( $definition ) ) {
				continue;
			}
			$methods = isset( $definition['methods'] ) && is_array( $definition['methods'] ) ? $definition['methods'] : array();
			$args    = array();
			foreach ( isset( $definition['endpoints'] ) && is_array( $definition['endpoints'] ) ? $definition['endpoints'] : array() as $endpoint ) {
				if ( ! is_array( $endpoint ) ) {
					continue;
				}
				if ( isset( $endpoint['methods'] ) && is_array( $endpoint['methods'] ) ) {
					$methods = array_merge( $methods, $endpoint['methods'] );
				}
				if ( isset( $endpoint['args'] ) && is_array( $endpoint['args'] ) ) {
					$args = array_merge( $args, array_keys( $endpoint['args'] ) );
				}
			}
			$methods = array_values( array_intersect( $allowed_methods, array_unique( array_map( 'strtoupper', array_map( 'strval', $methods ) ) ) ) );
			if ( empty( $methods ) ) {
				continue;
			}
			$catalog[] = array(
				'route'     => '/' . ltrim( $route, '/' ),
				'namespace' => isset( $definition['namespace'] ) ? sanitize_text_field( $definition['namespace'] ) : '',
				'methods'   => $methods,
				'arg_count' => count( array_unique( $args ) ),
			);
		}

		usort(
			$catalog,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['route'], $b['route'] );
			}
		);
		return $catalog;
	}

	/**
	 * Read the WordPress version reported by the Core REST index.
	 *
	 * @param array $root REST index document.
	 * @return string
	 */
	private static function wordpress_version_from_root( $root ) {
		$generator = isset( $root['generator'] ) ? (string) $root['generator'] : '';
		return preg_match( '/[?&]v=([0-9]+(?:\.[0-9]+){1,3})/', $generator, $matches ) ? $matches[1] : '';
	}

	/**
	 * Run the Site Health tests that Core exposes through REST.
	 *
	 * @param array      $site Connected site record.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array|WP_Error
	 */
	private static function fetch_site_health( &$site, $deadline = null ) {
		if ( empty( $site['capabilities']['site_health'] ) ) {
			return new WP_Error( 'fleet_health_unavailable', __( 'Site Health is unavailable. Previous findings may be out of date.', 'fleet-for-openstation' ) );
		}
		// Core's authorization-header test expects the literal user:pwd probe
		// from its cookie-authenticated admin screen. An Application Password
		// necessarily fails that comparison despite successful authentication.
		$tests     = array(
			'background-updates'   => 'wp-site-health/v1/tests/background-updates',
			'loopback-requests'    => 'wp-site-health/v1/tests/loopback-requests',
			'https-status'         => 'wp-site-health/v1/tests/https-status',
			'dotorg-communication' => 'wp-site-health/v1/tests/dotorg-communication',
		);
		$responses = self::remote_get_map( $site, $tests, $deadline );
		$results   = array();
		foreach ( $responses as $key => $result ) {
			if ( is_wp_error( $result ) || empty( $result['status'] ) ) {
				continue;
			}
			$status = sanitize_key( $result['status'] );
			if ( ! in_array( $status, array( 'good', 'recommended', 'critical' ), true ) ) {
				continue;
			}
			$results[ $key ] = array(
				'label'  => isset( $result['label'] ) ? sanitize_text_field( wp_strip_all_tags( $result['label'] ) ) : $key,
				'status' => $status,
			);
		}
		return count( $results ) === count( $tests ) ? $results : new WP_Error( 'fleet_health_incomplete', __( 'Some Site Health checks could not finish. Previous findings are retained; health is not fully verified.', 'fleet-for-openstation' ), $results );
	}

	/**
	 * Return the empty persisted shape for one site's operations inbox.
	 *
	 * @return array
	 */
	private static function empty_inbox_summary() {
		$empty_collection = array(
			'count' => 0,
			'items' => array(),
			'error' => '',
		);
		return array(
			'checked'          => 0,
			'pending_comments' => $empty_collection,
			'drafts'           => $empty_collection,
			'pending_posts'    => $empty_collection,
			'scheduled_posts'  => $empty_collection,
		);
	}

	/**
	 * Normalize a stored operations summary introduced by a later version.
	 *
	 * @param array $summary Stored summary.
	 * @return array
	 */
	private static function normalize_inbox_summary( $summary ) {
		$normalized            = self::empty_inbox_summary();
		$summary               = is_array( $summary ) ? $summary : array();
		$normalized['checked'] = max( 0, isset( $summary['checked'] ) ? (int) $summary['checked'] : 0 );
		foreach ( array( 'pending_comments', 'drafts', 'pending_posts', 'scheduled_posts' ) as $key ) {
			$collection         = isset( $summary[ $key ] ) && is_array( $summary[ $key ] ) ? $summary[ $key ] : array();
			$normalized[ $key ] = array(
				'count' => max( 0, isset( $collection['count'] ) ? (int) $collection['count'] : 0 ),
				'items' => isset( $collection['items'] ) && is_array( $collection['items'] ) ? array_slice( $collection['items'], 0, 5 ) : array(),
				'error' => isset( $collection['error'] ) ? sanitize_text_field( $collection['error'] ) : '',
			);
		}
		return $normalized;
	}

	/**
	 * Fetch the operational collections WordPress Core already exposes.
	 *
	 * The Core batch controller keeps this to one authenticated HTTP request on
	 * modern WordPress sites. Fleet falls back to the same individual Core
	 * collection routes when the batch route is unavailable.
	 *
	 * @param array      $site Site record.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array|WP_Error Cached collections or an authentication failure.
	 */
	private static function fetch_inbox_summary( $site, $deadline = null ) {
		$summary = self::empty_inbox_summary();
		// The public API index can succeed after a password is revoked. Verify
		// the current account even on content-free sites. Core's /users/me
		// route does not opt into batching; keep collections in their own batch.
		$account = self::remote_request( $site, 'GET', 'wp/v2/users/me?context=edit&_fields=id,capabilities', null, $deadline );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		if ( empty( $account['id'] ) || empty( $account['capabilities']['manage_options'] ) ) {
			return new WP_Error( 'openstation_fleet_access_unverified', __( 'Fleet could not verify administrator access. Check the connection or approve it again.', 'fleet-for-openstation' ) );
		}
		$requests = array();
		$keys     = array();
		if ( self::supports( $site, 'comments' ) ) {
			$keys[]     = 'pending_comments';
			$requests[] = array(
				'method' => 'GET',
				'path'   => '/wp/v2/comments?' . http_build_query(
					array(
						'context'  => 'edit',
						'status'   => 'hold',
						'per_page' => 5,
						'orderby'  => 'date',
						'order'    => 'desc',
						'_fields'  => 'id,author_name,content,date,status,post',
					),
					'',
					'&'
				),
			);
		}
		if ( self::supports( $site, 'posts' ) ) {
			foreach ( array(
				'drafts'          => 'draft',
				'pending_posts'   => 'pending',
				'scheduled_posts' => 'future',
			) as $key => $status ) {
				$keys[]     = $key;
				$requests[] = array(
					'method' => 'GET',
					'path'   => '/wp/v2/posts?' . http_build_query(
						array(
							'context'  => 'edit',
							'status'   => $status,
							'per_page' => 5,
							'orderby'  => 'date',
							'order'    => 'desc',
							'_fields'  => 'id,title,status,date,type',
						),
						'',
						'&'
					),
				);
			}
		}
		$summary['checked'] = time();
		if ( empty( $requests ) ) {
			return $summary;
		}
		$responses = self::remote_batch( $site, $requests, $deadline );
		if ( is_wp_error( $responses ) ) {
			return $responses;
		}
		foreach ( $keys as $index => $key ) {
			$summary[ $key ] = self::collection_summary( isset( $responses[ $index ] ) ? $responses[ $index ] : array() );
		}
		return $summary;
	}

	/**
	 * Fetch named Core REST resources, using the batch controller when the
	 * managed site advertises it. Each result remains independently usable so
	 * one unavailable collection does not hide successful siblings.
	 *
	 * @param array      $site  Site record.
	 * @param array      $paths Map of result keys to REST paths.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array Map of result keys to response bodies or WP_Error objects.
	 */
	private static function remote_get_map( $site, $paths, $deadline = null ) {
		if ( empty( $paths ) ) {
			return array();
		}

		$requests = array();
		foreach ( $paths as $path ) {
			$requests[] = array(
				'method' => 'GET',
				'path'   => '/' . ltrim( (string) $path, '/' ),
			);
		}

		$responses = self::remote_batch( $site, $requests, $deadline );
		if ( is_wp_error( $responses ) ) {
			return array_fill_keys( array_keys( $paths ), $responses );
		}

		$results = array();
		foreach ( array_keys( $paths ) as $index => $key ) {
			$response = isset( $responses[ $index ] ) && is_array( $responses[ $index ] ) ? $responses[ $index ] : array();
			if ( ! empty( $response['error'] ) ) {
				$results[ $key ] = new WP_Error( 'openstation_fleet_remote_error', sanitize_text_field( $response['error'] ) );
				continue;
			}
			$results[ $key ] = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		}
		return $results;
	}

	/**
	 * Send existing REST requests through Core's batch route where available.
	 *
	 * @param array      $site     Site record.
	 * @param array      $requests Core REST subrequests.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array Response envelopes in request order.
	 */
	private static function remote_batch( $site, $requests, $deadline = null ) {
		if ( empty( $requests ) ) {
			return array();
		}
		if ( self::supports( $site, 'batch' ) ) {
			$result = self::remote_request( $site, 'POST', 'batch/v1', array( 'requests' => array_values( $requests ) ), $deadline );
			if ( ! is_wp_error( $result ) && isset( $result['responses'] ) && is_array( $result['responses'] ) && count( $result['responses'] ) === count( $requests ) ) {
				$envelopes = array();
				foreach ( $result['responses'] as $response ) {
					if ( ! is_array( $response ) ) {
						$envelopes[] = array(
							'status'  => 0,
							'headers' => array(),
							'body'    => array(),
							'error'   => __( 'WordPress returned an incomplete batch response.', 'fleet-for-openstation' ),
						);
						continue;
					}
					$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
					$body   = isset( $response['body'] ) ? $response['body'] : array();
					$error  = '';
					if ( $status < 200 || $status >= 300 ) {
						// translators: %d: remote HTTP status code.
						$error = sprintf( __( 'WordPress returned HTTP %d.', 'fleet-for-openstation' ), $status );
					}
					$envelopes[] = array(
						'status'  => $status,
						'headers' => isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array(),
						'body'    => is_array( $body ) ? $body : array(),
						'error'   => $error,
					);
				}
				return $envelopes;
			}
		}

		$envelopes = array();
		foreach ( $requests as $request ) {
			$method      = isset( $request['method'] ) ? strtoupper( (string) $request['method'] ) : 'GET';
			$path        = isset( $request['path'] ) ? ltrim( (string) $request['path'], '/' ) : '';
			$body        = isset( $request['body'] ) && is_array( $request['body'] ) ? $request['body'] : null;
			$result      = self::remote_request( $site, $method, $path, $body, $deadline );
			$envelopes[] = array(
				'status'  => is_wp_error( $result ) ? 0 : 200,
				'headers' => array(),
				'body'    => is_wp_error( $result ) ? array() : $result,
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
			);
		}
		return $envelopes;
	}

	/**
	 * Convert a Core collection response envelope into a bounded inbox entry.
	 *
	 * @param array $response Response envelope.
	 * @return array
	 */
	private static function collection_summary( $response ) {
		$body    = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		$count   = count( $body );
		$headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
		foreach ( $headers as $name => $value ) {
			if ( 'x-wp-total' === strtolower( (string) $name ) ) {
				$count = max( $count, (int) ( is_array( $value ) ? reset( $value ) : $value ) );
				break;
			}
		}
		return array(
			'count' => max( 0, $count ),
			'items' => array_slice( $body, 0, 5 ),
			'error' => isset( $response['error'] ) ? sanitize_text_field( $response['error'] ) : '',
		);
	}

	/**
	 * Count every cached operation and existing attention reason for one site.
	 *
	 * @param array $site Site record.
	 * @return int
	 */
	private static function inbox_item_count( $site ) {
		$inbox = self::normalize_inbox_summary( isset( $site['inbox'] ) ? $site['inbox'] : array() );
		$count = count( self::attention_reasons( $site ) );
		foreach ( array( 'pending_comments', 'drafts', 'pending_posts', 'scheduled_posts' ) as $key ) {
			$count += $inbox[ $key ]['count'];
			if ( ! empty( $inbox[ $key ]['error'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Decide whether one friendly workspace surface is supported remotely.
	 *
	 * @param array  $site Connected site record.
	 * @param string $capability Capability key.
	 * @return bool
	 */
	private static function supports( $site, $capability ) {
		return empty( $site['capabilities'] ) || ! array_key_exists( $capability, $site['capabilities'] ) || ! empty( $site['capabilities'][ $capability ] );
	}

	/**
	 * Return actionable reasons a site needs attention.
	 *
	 * @param array $site Connected site record.
	 * @return array
	 */
	private static function attention_reasons( $site ) {
		$reasons = array();
		if ( ! empty( $site['health_error'] ) ) {
			$reasons[] = array( 'health-stale', $site['health_error'], 'recommended' );
		}
		if ( ! empty( $site['error'] ) ) {
			$reasons[] = array( 'connection', __( 'Connection check failed', 'fleet-for-openstation' ), 'critical' );
		}
		$status = isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : 'unknown';
		if ( 'active' !== $status ) {
			$reasons[] = array( 'openstation', __( 'OpenStation needs to be installed or activated', 'fleet-for-openstation' ), 'critical' );
		}
		foreach ( isset( $site['health'] ) && is_array( $site['health'] ) ? $site['health'] : array() as $key => $test ) {
			if ( isset( $test['status'] ) && in_array( $test['status'], array( 'critical', 'recommended' ), true ) ) {
				$reasons[] = array( sanitize_key( $key ), isset( $test['label'] ) ? $test['label'] : $key, $test['status'] );
			}
		}
		$remote_version = isset( $site['wordpress_version'] ) ? $site['wordpress_version'] : '';
		if ( $remote_version && version_compare( $remote_version, get_bloginfo( 'version' ), '<' ) ) {
			// translators: %s: remote WordPress version.
			$reasons[] = array( 'wordpress', sprintf( __( 'WordPress %s is older than the hub', 'fleet-for-openstation' ), $remote_version ), 'recommended' );
		}
		return $reasons;
	}

	/**
	 * Send an authenticated request to a target site's Core REST API.
	 *
	 * @param array      $site   Site record.
	 * @param string     $method HTTP method.
	 * @param string     $path   REST route without leading slash.
	 * @param array|null $body   Optional JSON body.
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return array|WP_Error
	 */
	private static function remote_request( &$site, $method, $path, $body = null, $deadline = null ) {
		$timeout = self::request_timeout( $deadline );
		if ( false === $timeout ) {
			return new WP_Error( 'openstation_fleet_sync_budget', __( 'The Fleet synchronization time budget was reached.', 'fleet-for-openstation' ) );
		}
		return OpenStation_Fleet_REST_Client::request( $site, $method, $path, $body, $timeout );
	}

	/**
	 * Bound one HTTP timeout to the remaining cron wall-clock budget.
	 *
	 * @param float|null $deadline Optional wall-clock deadline.
	 * @return int|false Timeout in seconds, or false after the deadline.
	 * @phpstan-impure Reads the current wall clock.
	 */
	private static function request_timeout( $deadline ) {
		if ( null === $deadline ) {
			return OpenStation_Fleet_REST_Client::REQUEST_TIMEOUT;
		}
		$remaining = (float) $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			return false;
		}
		return max( 1, min( OpenStation_Fleet_REST_Client::REQUEST_TIMEOUT, (int) ceil( $remaining ) ) );
	}

	/**
	 * Build the Application Password authorization URL.
	 *
	 * Add_query_arg() expects new values to already be URL-encoded. Encoding
	 * nested callback URLs keeps their state and nonce arguments inside the
	 * success and rejection URLs instead of leaking them into the outer query.
	 *
	 * @param string $endpoint Authorization endpoint.
	 * @param array  $args     Authorization arguments.
	 * @return string
	 */
	private static function authorization_url( $endpoint, $args ) {
		// Keep Core's approval page top-level even for an opted-in OpenStation
		// user. This documented per-request flag does not change their preference.
		$args['desktop_mode_classic'] = '1';
		foreach ( $args as $key => $value ) {
			$args[ $key ] = rawurlencode( (string) $value );
		}

		return add_query_arg( $args, $endpoint );
	}

	/**
	 * Read a short-lived managed-tab response shared by concurrent windows.
	 *
	 * @param string $site_id Site id.
	 * @param string $section Tab section.
	 * @return array|false
	 */
	private static function read_cache_get( $site_id, $section ) {
		$cached = get_transient( self::read_cache_key( $site_id, $section ) );
		return is_array( $cached ) ? $cached : false;
	}

	/**
	 * Cache only successful array models; errors should be retried immediately.
	 *
	 * @param string $site_id Site id.
	 * @param string $section Tab section.
	 * @param mixed  $result Response model.
	 * @return mixed
	 */
	private static function read_cache_set( $site_id, $section, $result ) {
		if ( is_array( $result ) && ! self::contains_wp_error( $result ) ) {
			set_transient( self::read_cache_key( $site_id, $section ), $result, 30 );
		}
		return $result;
	}

	/**
	 * Invalidate every managed tab after local or remote mutation.
	 *
	 * @param string $site_id Site id.
	 */
	private static function invalidate_read_cache( $site_id ) {
		foreach ( array( 'overview', 'content', 'content-types', 'media', 'comments', 'plugins', 'users', 'settings', 'design', 'api' ) as $section ) {
			delete_transient( self::read_cache_key( $site_id, $section ) );
		}
	}

	/**
	 * Detect an error nested in a response model.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	private static function contains_wp_error( $value ) {
		if ( is_wp_error( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( self::contains_wp_error( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a user-scoped transient key below WordPress's key length limit.
	 *
	 * @param string $site_id Site id.
	 * @param string $section Section.
	 * @return string
	 */
	private static function read_cache_key( $site_id, $section ) {
		return 'os_fleet_' . get_current_user_id() . '_' . substr( hash( 'sha256', sanitize_key( $site_id ) . ':' . sanitize_key( $section ) ), 0, 24 );
	}

	/**
	 * Fetch one compact connection directly.
	 *
	 * @param string $site_id Fleet site id.
	 */
	private static function get_site( $site_id ) {
		if ( 0 === strpos( $site_id, 'shared_' ) ) {
			return OpenStation_Fleet_Access::resolve( $site_id ); }
		return OpenStation_Fleet_Repository::get( get_current_user_id(), $site_id, array( __CLASS__, 'normalize_site_record' ) );
	}

	/** Fetch only the current user's compact site id index. */
	private static function get_site_ids() {
		return array_merge( OpenStation_Fleet_Repository::site_ids( get_current_user_id(), array( __CLASS__, 'normalize_site_record' ) ), OpenStation_Fleet_Access::ids() );
	}

	/**
	 * Fetch a connected site's separately persisted search state.
	 *
	 * @param string $site_id Fleet site id.
	 * @param array  $site    Compact connection record.
	 */
	private static function get_site_search( $site_id, $site ) {
		if ( ! empty( $site['_fleet_alias'] ) ) {
			return array(
				'search_index'       => array(),
				'search_index_state' => array(),
				'storage_revision'   => 0,
			); }
		return OpenStation_Fleet_Repository::get_search_state(
			get_current_user_id(),
			$site_id,
			isset( $site['connection_generation'] ) ? $site['connection_generation'] : '',
			array( __CLASS__, 'normalize_site_record' )
		);
	}

	/**
	 * Persist a connected site's separately stored search state.
	 *
	 * @param string $site_id Fleet site id.
	 * @param array  $site    Compact connection record.
	 * @param array  $search  Search payload.
	 */
	private static function save_site_search( $site_id, $site, $search ) {
		if ( ! empty( $site['_fleet_alias'] ) ) {
			return true; }
		return OpenStation_Fleet_Repository::save_search_state(
			get_current_user_id(),
			$site_id,
			isset( $site['connection_generation'] ) ? $site['connection_generation'] : '',
			isset( $search['search_index_state'] ) ? $search['search_index_state'] : array(),
			isset( $search['search_index'] ) ? $search['search_index'] : array(),
			array( __CLASS__, 'normalize_site_record' ),
			isset( $search['storage_revision'] ) ? (int) $search['storage_revision'] : 0
		);
	}

	/**
	 * Merge one site record into the latest user metadata.
	 *
	 * The compare-and-swap retry protects changes made in two independent Fleet
	 * windows from replacing each other's site records after remote I/O.
	 *
	 * @param string $site_id Site id.
	 * @param array  $site    Site record.
	 * @param array  $replace_fields Local fields intentionally changed by this request.
	 * @param bool   $allow_create Whether a missing record may be created.
	 * @return bool Whether the record was persisted.
	 */
	private static function save_site( $site_id, $site, $replace_fields = array(), $allow_create = false ) {
		if ( ! empty( $site['_fleet_alias'] ) ) {
			$live = OpenStation_Fleet_Access::resolve( $site['_fleet_alias'] );
			if ( ! $live || $live['connection_generation'] !== $site['connection_generation'] || array_diff( $replace_fields, self::remote_state_fields() ) ) {
				return false; }
			return ! $replace_fields || OpenStation_Fleet_Repository::save( $live['_fleet_owner'], $live['_fleet_source'], $site, $replace_fields, array( __CLASS__, 'normalize_site_record' ) );
		}
		$replace_fields = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $replace_fields ) ) ) );
		return OpenStation_Fleet_Repository::save(
			get_current_user_id(),
			$site_id,
			$site,
			$replace_fields,
			array( __CLASS__, 'normalize_site_record' ),
			$allow_create
		);
	}

	/**
	 * Site fields owned by a remote status refresh.
	 *
	 * @return string[]
	 */
	private static function remote_state_fields() {
		return array(
			'capabilities',
			'environment',
			'health',
			'health_checked',
			'health_attempted',
			'health_error',
			'status_checked',
			'metadata_checked',
			'sync_failures',
			'next_retry',
			'inbox',
			'wordpress_version',
			'openstation',
			'last_checked',
			'error',
		);
	}

	/**
	 * Remove one connected site without replacing concurrent sibling changes.
	 *
	 * @param string $site_id    Site id.
	 * @param string $generation Expected connection generation.
	 * @return bool Whether the record was removed.
	 */
	private static function remove_site( $site_id, $generation = '' ) {
		return OpenStation_Fleet_Repository::remove( get_current_user_id(), $site_id, $generation );
	}

	/**
	 * Compare and swap one per-user metadata value with bounded retries.
	 *
	 * @param string   $key     Metadata key.
	 * @param callable $mutator Pure callback receiving the latest value.
	 * @return bool Whether the mutation was persisted.
	 */
	private static function mutate_user_meta( $key, $mutator ) {
		$user_id = get_current_user_id();
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$current = get_user_meta( $user_id, $key, true );
			$next    = call_user_func( $mutator, $current );
			if ( $next === $current ) {
				return true;
			}
			if ( false !== update_user_meta( $user_id, $key, $next, $current ) ) {
				return true;
			}
			wp_cache_delete( $user_id, 'user_meta' );
		}
		return false;
	}

	/**
	 * Add defaults introduced after a site was first connected.
	 *
	 * @param array $site Stored site record.
	 * @return array
	 */
	public static function normalize_site_record( $site ) {
		$site                       = wp_parse_args(
			$site,
			array(
				'capabilities'          => array(),
				'environment'           => array(),
				'health'                => array(),
				'health_checked'        => 0,
				'health_attempted'      => 0,
				'health_error'          => '',
				'status_checked'        => 0,
				'metadata_checked'      => 0,
				'sync_failures'         => 0,
				'next_retry'            => 0,
				'inbox'                 => self::empty_inbox_summary(),
				'wordpress_version'     => '',
				'connection_generation' => '',
				'agency'                => array(),
				'views'                 => array(),
				'setup_status'          => 'ready',
			)
		);
		$site['views']              = is_array( $site['views'] ) ? array_slice( $site['views'], 0, 12, true ) : array();
		$site['agency']             = wp_parse_args(
			is_array( $site['agency'] ) ? $site['agency'] : array(),
			array(
				'client_name' => '',
				'tags'        => array(),
				'plan_status' => 'none',
				'notes'       => '',
				'favorite'    => false,
			)
		);
		$site['agency']['tags']     = is_array( $site['agency']['tags'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $site['agency']['tags'] ) ) ) : array();
		$site['agency']['favorite'] = ! empty( $site['agency']['favorite'] );
		$site['inbox']              = self::normalize_inbox_summary( $site['inbox'] );
		if ( '' === (string) $site['connection_generation'] && ! empty( $site['credential_uuid'] ) ) {
			$site['connection_generation'] = sanitize_text_field( (string) $site['credential_uuid'] );
		} else {
			$site['connection_generation'] = sanitize_text_field( (string) $site['connection_generation'] );
		}
		foreach ( array( 'health_checked', 'health_attempted', 'status_checked', 'metadata_checked', 'sync_failures', 'next_retry' ) as $integer_key ) {
			$site[ $integer_key ] = max( 0, (int) $site[ $integer_key ] );
		}
		$site['environment'] = is_array( $site['environment'] ) ? array_intersect_key( $site['environment'], array_flip( array( 'environment', 'php_version', 'db_server_info', 'wp_version' ) ) ) : array();
		$site['environment'] = array_map( 'sanitize_text_field', $site['environment'] );
		if (
			! empty( $site['site_url'] )
			&& ! empty( $site['rest_url'] )
			&& self::url_origin( $site['rest_url'] ) !== self::url_origin( $site['site_url'] )
		) {
			$site['rest_url'] = false !== strpos( (string) $site['rest_url'], 'rest_route=' )
				? add_query_arg( 'rest_route', '/', trailingslashit( $site['site_url'] ) )
				: trailingslashit( $site['site_url'] ) . 'wp-json/';
		}
		return $site;
	}

	/**
	 * Add a bounded local activity entry for agency accountability.
	 *
	 * @param string $site_id Site id.
	 * @param array  $site    Site record.
	 * @param string $action  Machine action label.
	 * @param string $message Human-readable summary.
	 * @param string $status  success, warning, or error.
	 */
	private static function record_activity( $site_id, $site, $action, $message, $status = 'success' ) {
		$event = array(
			'id'        => wp_generate_uuid4(),
			'time'      => time(),
			'site_id'   => sanitize_key( $site_id ),
			'site_name' => isset( $site['name'] ) ? sanitize_text_field( $site['name'] ) : '',
			'action'    => sanitize_key( $action ),
			'message'   => sanitize_text_field( $message ),
			'status'    => in_array( $status, array( 'success', 'warning', 'error' ), true ) ? $status : 'success',
			'actor'     => wp_get_current_user()->display_name,
		);
		self::mutate_user_meta(
			OpenStation_Fleet_Repository::activity_meta_key(),
			static function ( $events ) use ( $event ) {
				$events = is_array( $events ) ? $events : array();
				array_unshift( $events, $event );
				return array_slice( $events, 0, 100 );
			}
		);
	}

	/**
	 * Get recent Fleet activity for the current user.
	 *
	 * @return array
	 */
	private static function get_activity() {
		$events = get_user_meta( get_current_user_id(), OpenStation_Fleet_Repository::activity_meta_key(), true );
		return is_array( $events ) ? array_slice( $events, 0, 100 ) : array();
	}
	/**
	 * Get a stable UUID used to identify this Fleet installation.
	 *
	 * @return string
	 */
	private static function get_app_id() {
		$app_id = get_user_meta( get_current_user_id(), OpenStation_Fleet_Repository::app_id_meta_key(), true );
		if ( ! wp_is_uuid( $app_id ) ) {
			$app_id = wp_generate_uuid4();
			update_user_meta( get_current_user_id(), OpenStation_Fleet_Repository::app_id_meta_key(), $app_id );
		}
		return $app_id;
	}

	/**
	 * Build a short deterministic local id for a site.
	 *
	 * @param string $url Site URL.
	 * @return string
	 */
	private static function site_id( $url ) {
		return substr( hash( 'sha256', $url ), 0, 16 );
	}

	/**
	 * Build the transient key for an authorization attempt.
	 *
	 * @param int    $user_id User id.
	 * @param string $state   Random state UUID.
	 * @return string
	 */
	private static function pending_key( $user_id, $state ) {
		return 'openstation_fleet_' . (int) $user_id . '_' . substr( hash( 'sha256', $state ), 0, 20 );
	}

	/**
	 * Return a URL's scheme, host, and port.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Verify an endpoint is HTTPS and shares the site's origin.
	 *
	 * @param string $candidate Endpoint URL.
	 * @param string $site_url Site URL.
	 * @return bool
	 */
	private static function is_same_origin_https_url( $candidate, $site_url ) {
		return 0 === strpos( (string) $candidate, 'https://' ) && self::url_origin( $candidate ) === self::url_origin( $site_url );
	}

	/**
	 * Check whether a URL is this WordPress installation.
	 *
	 * Exact URLs are compared so path-based multisite installations can still
	 * connect sibling sites on the same host.
	 *
	 * @param string $candidate Normalized site URL.
	 * @return bool
	 */
	private static function is_hub_site( $candidate ) {
		foreach ( array( home_url(), site_url() ) as $local_url ) {
			$local_url = self::normalize_site_url( $local_url );
			if ( ! is_wp_error( $local_url ) && $candidate === $local_url ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read a scalar request value without allowing arrays into string APIs.
	 *
	 * @param array  $source Request source.
	 * @param string $key    Field name.
	 * @return string
	 */
	private static function request_string( $source, $key ) {
		return isset( $source[ $key ] ) && is_string( $source[ $key ] )
			? (string) wp_unslash( $source[ $key ] )
			: '';
	}

	/**
	 * Return from WordPress's external authorization screen to OpenStation.
	 *
	 * @param string $site_id Optional connection to open after approval.
	 */
	private static function return_to_openstation( $site_id = '' ) {
		wp_safe_redirect( $site_id ? add_query_arg( 'fleet_connected', sanitize_key( $site_id ), openstation_shell_url() ) : openstation_shell_url() );
		exit;
	}

	/**
	 * Stop a failed authorization with a fixed, non-secret Core error screen.
	 *
	 * @param string $code Fixed error code.
	 */
	private static function fail_authorization( $code ) {
		$message = self::authorization_failure_message( $code );
		$target  = openstation_shell_url();
		wp_die(
			wp_kses_post( '<p>' . esc_html( $message ) . '</p><p><a class="button button-primary" href="' . esc_url( $target ) . '">' . esc_html__( 'Return to OpenStation', 'fleet-for-openstation' ) . '</a></p>' ),
			esc_html__( 'Fleet could not connect the site', 'fleet-for-openstation' ),
			array( 'response' => 400 )
		);
	}

	/**
	 * Return truthful cleanup guidance for an authorization failure.
	 *
	 * @param string $code Fixed error code.
	 * @return string User-facing failure guidance.
	 */
	private static function authorization_failure_message( $code ) {
		$messages = array(
			'authorization_failed'        => __( 'The authorization response was invalid. Start the connection again from Fleet.', 'fleet-for-openstation' ),
			'authorization_expired'       => __( 'The authorization attempt expired. Start the connection again from Fleet.', 'fleet-for-openstation' ),
			'authorization_rejected'      => __( 'The site connection was not approved.', 'fleet-for-openstation' ),
			'encryption_failed'           => __( 'This server could not securely store the new WordPress credential. Fleet could not confirm automatic cleanup, so revoke the Fleet credential under Users → Profile → Application Passwords on the managed site before trying again.', 'fleet-for-openstation' ),
			'credential_failed'           => __( 'WordPress issued a credential, but Fleet could not verify it. Revoke the Fleet credential under Users → Profile → Application Passwords on the managed site, then check whether its server forwards Authorization headers.', 'fleet-for-openstation' ),
			'administrator_required'      => __( 'Fleet needs an administrator connection to manage the full WordPress site. The new credential was revoked; reconnect while signed in as an administrator.', 'fleet-for-openstation' ),
			'administrator_revoke_failed' => __( 'Fleet needs an administrator connection, and WordPress did not confirm cleanup of the new credential. Revoke the Fleet credential under Users → Profile → Application Passwords on the managed site before reconnecting as an administrator.', 'fleet-for-openstation' ),
			'duplicate_revoke_failed'     => __( 'That site is already connected, but WordPress did not confirm cleanup of the extra credential. Revoke the newest Fleet credential under Users → Profile → Application Passwords on the managed site.', 'fleet-for-openstation' ),
			'storage_failed'              => __( 'Fleet could not save the connection, so it revoked the new WordPress credential. Start the connection again.', 'fleet-for-openstation' ),
			'storage_revoke_failed'       => __( 'Fleet could not save the connection, and WordPress did not confirm cleanup of the new credential. Revoke the Fleet credential under Users → Profile → Application Passwords on the managed site before trying again.', 'fleet-for-openstation' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['authorization_failed'];
	}
}
