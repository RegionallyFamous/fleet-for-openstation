<?php
/**
 * Fleet screen and WordPress-to-WordPress orchestration.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Implements the experimental Fleet feature with WordPress Core primitives.
 */
final class OpenStation_Fleet {
	const CAPABILITY       = 'manage_options';
	const MENU_SLUG        = 'fleet-for-openstation';
	const USER_META_SITES  = 'openstation_fleet_sites';
	const USER_META_APP_ID = 'openstation_fleet_app_id';
	const PLUGIN_SLUG      = 'desktop-mode';
	const PLUGIN_REST_ID   = 'desktop-mode/desktop-mode';
	const OAUTH_SCOPE      = 'site:manage';

	/**
	 * Register the admin screen and form handlers.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_openstation_fleet_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_openstation_fleet_authorized', array( __CLASS__, 'handle_authorized' ) );
		add_action( 'admin_post_openstation_fleet_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_openstation_fleet_install', array( __CLASS__, 'handle_install' ) );
		add_action( 'admin_post_openstation_fleet_update_settings', array( __CLASS__, 'handle_update_settings' ) );
		add_action( 'admin_post_openstation_fleet_update_content', array( __CLASS__, 'handle_update_content' ) );
		add_action( 'admin_post_openstation_fleet_update_plugin', array( __CLASS__, 'handle_update_plugin' ) );
		add_action( 'admin_post_openstation_fleet_api_request', array( __CLASS__, 'handle_api_request' ) );
		add_action( 'admin_post_openstation_fleet_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * Register a normal wp-admin page. OpenStation already presents normal
	 * admin pages as windows, so Fleet needs no private window API.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Fleet for OpenStation', 'fleet-for-openstation' ),
			__( 'Fleet', 'fleet-for-openstation' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-site-alt3',
			58
		);
	}

	/**
	 * Load Fleet's window UI only on its own admin screen.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( function_exists( 'openstation_is_shell_request' ) && openstation_is_shell_request() && wp_script_is( 'openstation', 'registered' ) ) {
			wp_add_inline_script(
				'openstation',
				"(function(wp){if(!wp||!wp.hooks){return;}wp.hooks.addFilter('os.window.geometry','fleet-for-openstation/default-size',function(g,c){if(c.baseId!=='admin-php-page-fleet-for-openstation'){return g;}var a=c.workArea,w=Math.min(1040,Math.max(640,a.width-48)),h=Math.min(760,Math.max(480,a.height-48));return Object.assign({},g,{x:a.x+Math.max(24,(a.width-w)/2),y:a.y+Math.max(24,(a.height-h)/2),width:w,height:h,state:'normal'});});})(window.wp);",
				'before'
			);
		}

		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'fleet-for-openstation-admin',
			OPENSTATION_FLEET_URL . 'assets/admin.css',
			array( 'dashicons' ),
			OPENSTATION_FLEET_VERSION
		);
	}

	/**
	 * Render the fleet or one connected site's remote workspace.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}

		$sites   = self::get_sites();
		$notice  = sanitize_key( self::request_string( $_GET, 'fleet_notice' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice code.
		$site_id = sanitize_key( self::request_string( $_GET, 'site_id' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only workspace selector.
		$section = sanitize_key( self::request_string( $_GET, 'fleet_section' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only workspace selector.

		if ( '' !== $site_id && isset( $sites[ $site_id ] ) && is_array( $sites[ $site_id ] ) ) {
			self::render_site_workspace( $site_id, $sites[ $site_id ], $section, $notice );
			return;
		}

		$ready_count = 0;
		foreach ( $sites as $site ) {
			if ( empty( $site['error'] ) && 'active' === ( isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : '' ) ) {
				++$ready_count;
			}
		}
		?>
		<div class="wrap fleet-app">
			<header class="fleet-hero">
				<div class="fleet-hero__copy">
					<span class="fleet-eyebrow"><?php esc_html_e( 'OpenStation agency tools', 'fleet-for-openstation' ); ?></span>
					<h1><?php esc_html_e( 'Fleet', 'fleet-for-openstation' ); ?></h1>
					<p><?php esc_html_e( 'Connect your WordPress sites, then manage each one without leaving this window.', 'fleet-for-openstation' ); ?></p>
				</div>
				<div class="fleet-hub-card">
					<span class="fleet-status-dot" aria-hidden="true"></span>
					<span>
						<strong><?php esc_html_e( 'Fleet hub', 'fleet-for-openstation' ); ?></strong>
						<small><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></small>
					</span>
				</div>
			</header>

			<?php self::render_notice( $notice ); ?>

			<div class="fleet-summary" role="list" aria-label="<?php esc_attr_e( 'Fleet summary', 'fleet-for-openstation' ); ?>">
				<div class="fleet-summary__item" role="listitem">
					<strong><?php echo esc_html( count( $sites ) ); ?></strong>
					<span><?php esc_html_e( 'Connected sites', 'fleet-for-openstation' ); ?></span>
				</div>
				<div class="fleet-summary__item" role="listitem">
					<strong><?php echo esc_html( $ready_count ); ?></strong>
					<span><?php esc_html_e( 'OpenStation ready', 'fleet-for-openstation' ); ?></span>
				</div>
				<div class="fleet-summary__item" role="listitem">
					<strong><?php esc_html_e( 'OAuth', 'fleet-for-openstation' ); ?></strong>
					<span><?php esc_html_e( 'Preferred connection', 'fleet-for-openstation' ); ?></span>
				</div>
			</div>

			<section class="fleet-panel fleet-connect-panel" aria-labelledby="fleet-connect-title">
				<div class="fleet-panel__heading">
					<div>
						<h2 id="fleet-connect-title"><?php esc_html_e( 'Add a WordPress site', 'fleet-for-openstation' ); ?></h2>
						<p><?php esc_html_e( 'Approve a secure, revocable OAuth connection on the site you want to manage.', 'fleet-for-openstation' ); ?></p>
					</div>
				</div>
				<form class="fleet-connect-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" target="_top">
					<input type="hidden" name="action" value="openstation_fleet_connect">
					<?php wp_nonce_field( 'openstation_fleet_connect' ); ?>
					<label class="screen-reader-text" for="fleet-for-openstation-site-url"><?php esc_html_e( 'WordPress site URL', 'fleet-for-openstation' ); ?></label>
					<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
					<input id="fleet-for-openstation-site-url" name="site_url" type="url" inputmode="url" placeholder="https://example.com" required>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Connect site', 'fleet-for-openstation' ); ?></button>
				</form>
			</section>

			<section class="fleet-sites" aria-labelledby="fleet-sites-title">
				<div class="fleet-section-heading">
					<div>
						<span class="fleet-eyebrow"><?php esc_html_e( 'Your network', 'fleet-for-openstation' ); ?></span>
						<h2 id="fleet-sites-title"><?php esc_html_e( 'Connected sites', 'fleet-for-openstation' ); ?></h2>
					</div>
					<span class="fleet-count"><?php echo esc_html( count( $sites ) ); ?></span>
				</div>
				<?php if ( empty( $sites ) ) : ?>
					<div class="fleet-empty">
						<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
						<h3><?php esc_html_e( 'Your fleet starts here', 'fleet-for-openstation' ); ?></h3>
						<p><?php esc_html_e( 'Connect a site above to open its remote management workspace.', 'fleet-for-openstation' ); ?></p>
					</div>
				<?php else : ?>
					<div class="fleet-site-list">
						<?php foreach ( $sites as $id => $site ) : ?>
							<?php self::render_site_card( (string) $id, $site ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Start OAuth when OpenStation advertises it, with an Application Password
	 * fallback for sites that still need OpenStation installed.
	 */
	public static function handle_connect() {
		self::guard_action( 'openstation_fleet_connect' );
		if ( 'https' !== wp_parse_url( admin_url(), PHP_URL_SCHEME ) ) {
			self::redirect( 'hub_https_required' );
		}

		$raw_url  = self::request_string( $_POST, 'site_url' );
		$site_url = self::normalize_site_url( $raw_url );
		if ( is_wp_error( $site_url ) ) {
			self::redirect( 'invalid_url' );
		}
		if ( self::is_hub_site( $site_url ) ) {
			self::redirect( 'self_site' );
		}

		$discovery = self::discover_site( $site_url );
		if ( is_wp_error( $discovery ) ) {
			self::redirect( 'discovery_failed' );
		}
		if ( self::is_hub_site( $discovery['site_url'] ) ) {
			self::redirect( 'self_site' );
		}

		$state    = wp_generate_uuid4();
		$callback = add_query_arg(
			array(
				'action'   => 'openstation_fleet_authorized',
				'state'    => $state,
				'_wpnonce' => wp_create_nonce( 'openstation_fleet_authorized_' . $state ),
			),
			admin_url( 'admin-post.php' )
		);
		$discovery['callback'] = $callback;

		if ( 'oauth' === $discovery['auth_type'] ) {
			$verifier = self::random_value( 32 );
			$sealed   = self::seal_secret( $verifier );
			if ( is_wp_error( $sealed ) ) {
				self::redirect( 'encryption_failed' );
			}
			$discovery['code_verifier'] = $sealed;
			$authorize                  = self::authorization_url(
				$discovery['authorization_url'],
				array(
					'response_type'         => 'code',
					'client_id'             => self::get_app_id(),
					'redirect_uri'          => $callback,
					'scope'                 => self::OAUTH_SCOPE,
					'state'                 => $state,
					'code_challenge'        => self::base64url_encode( hash( 'sha256', $verifier, true ) ),
					'code_challenge_method' => 'S256',
				)
			);
			$verifier = null;
		} else {
			$reject   = add_query_arg( 'rejected', '1', $callback );
			$app_name = sprintf(
				/* translators: %s: hostname of the Fleet hub. */
				__( 'Fleet for OpenStation on %s', 'fleet-for-openstation' ),
				wp_parse_url( home_url(), PHP_URL_HOST )
			);
			$authorize = self::authorization_url(
				$discovery['authorization_url'],
				array(
					'app_name'    => $app_name,
					'app_id'      => self::get_app_id(),
					'success_url' => $callback,
					'reject_url'  => $reject,
				)
			);
		}

		set_transient(
			self::pending_key( get_current_user_id(), $state ),
			$discovery,
			10 * MINUTE_IN_SECONDS
		);

		wp_redirect( $authorize ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated same-origin external authorization endpoint.
		exit;
	}

	/**
	 * Exchange an OAuth code or store the bootstrap Application Password.
	 */
	public static function handle_authorized() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}

		$state = sanitize_text_field( self::request_string( $_GET, 'state' ) );
		if ( ! wp_is_uuid( $state ) ) {
			self::redirect( 'authorization_failed' );
		}
		check_admin_referer( 'openstation_fleet_authorized_' . $state );

		$key     = self::pending_key( get_current_user_id(), $state );
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) ) {
			self::redirect( 'authorization_expired' );
		}
		if ( isset( $_GET['rejected'] ) || 'false' === self::request_string( $_GET, 'success' ) || '' !== self::request_string( $_GET, 'error' ) ) {
			self::redirect( 'authorization_rejected' );
		}
		if ( 'oauth' === $pending['auth_type'] ) {
			self::complete_oauth_authorization( $pending );
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
			self::redirect( 'authorization_failed' );
		}

		$sealed = self::seal_secret( $password );
		if ( is_wp_error( $sealed ) ) {
			self::redirect( 'encryption_failed' );
		}

		$site = array(
			'name'            => $pending['name'],
			'site_url'        => $returned_url,
			'rest_url'        => $pending['rest_url'],
			'auth_type'       => 'application-password',
			'user_login'      => $username,
			'secret'          => $sealed,
			'credential_uuid' => '',
			'openstation'     => array( 'status' => 'unknown' ),
			'last_checked'    => 0,
			'error'           => '',
		);

		$credential = self::remote_request( $site, 'GET', 'wp/v2/users/me/application-passwords/introspect' );
		if ( is_wp_error( $credential ) || empty( $credential['uuid'] ) ) {
			self::redirect( 'credential_failed' );
		}
		$site['credential_uuid'] = sanitize_text_field( $credential['uuid'] );
		$site                     = self::refresh_site( $site );

		$sites                    = self::get_sites();
		$sites[ self::site_id( $returned_url ) ] = $site;
		self::save_sites( $sites );
		self::redirect( 'connected' );
	}

	/**
	 * Refresh one site's cached plugin status.
	 */
	public static function handle_check() {
		self::guard_action( 'openstation_fleet_check' );
		list( $id, $site, $sites ) = self::requested_site();
		$sites[ $id ]              = self::refresh_site( $site );
		self::save_sites( $sites );
		self::redirect( empty( $sites[ $id ]['error'] ) ? 'checked' : 'check_failed' );
	}

	/**
	 * Install or activate OpenStation using the Core Plugins REST API.
	 */
	public static function handle_install() {
		self::guard_action( 'openstation_fleet_install' );
		list( $id, $site, $sites ) = self::requested_site();

		$plugins = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' );
		if ( is_wp_error( $plugins ) ) {
			$site['error']        = $plugins->get_error_message();
			$site['last_checked'] = time();
			$sites[ $id ]         = $site;
			self::save_sites( $sites );
			self::redirect( 'install_failed' );
		}

		$status = self::inspect_plugins( $plugins );
		if ( 'active' === $status['status'] ) {
			$site['openstation']  = $status;
			$site['error']        = '';
			$site['last_checked'] = time();
			$sites[ $id ]         = $site;
			self::save_sites( $sites );
			self::redirect( 'already_active' );
		}

		if ( 'missing' === $status['status'] ) {
			$result = self::remote_request(
				$site,
				'POST',
				'wp/v2/plugins',
				array(
					'slug'   => self::PLUGIN_SLUG,
					'status' => 'active',
				)
			);
		} else {
			$result = self::remote_request(
				$site,
				'POST',
				'wp/v2/plugins/' . self::PLUGIN_REST_ID,
				array( 'status' => 'active' )
			);
		}

		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			$notice        = 'install_failed';
		} else {
			$site['openstation'] = self::inspect_plugins( array( $result ) );
			$site['error']       = '';
			$notice              = 'installed';
		}
		$site['last_checked'] = time();
		$sites[ $id ]         = $site;
		self::save_sites( $sites );
		self::redirect( $notice );
	}

	/**
	 * Update a connected site's Core WordPress settings.
	 */
	public static function handle_update_settings() {
		self::guard_action( 'openstation_fleet_update_settings' );
		list( $id, $site, $sites ) = self::requested_site();

		$settings = array(
			'title'           => sanitize_text_field( self::request_string( $_POST, 'title' ) ),
			'description'     => sanitize_text_field( self::request_string( $_POST, 'description' ) ),
			'timezone_string' => sanitize_text_field( self::request_string( $_POST, 'timezone_string' ) ),
			'date_format'     => sanitize_text_field( self::request_string( $_POST, 'date_format' ) ),
			'time_format'     => sanitize_text_field( self::request_string( $_POST, 'time_format' ) ),
			'start_of_week'   => absint( self::request_string( $_POST, 'start_of_week' ) ),
		);
		$result   = self::remote_request( $site, 'POST', 'wp/v2/settings', $settings );

		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			$notice        = 'settings_failed';
		} else {
			$site['name']  = '' !== $settings['title'] ? $settings['title'] : $site['name'];
			$site['error'] = '';
			$notice        = 'settings_updated';
		}
		$sites[ $id ] = $site;
		self::save_sites( $sites );
		self::redirect_workspace( $notice, $id, 'settings' );
	}

	/**
	 * Update a post or page on a connected site through Core REST.
	 */
	public static function handle_update_content() {
		self::guard_action( 'openstation_fleet_update_content' );
		list( $id, $site, $sites ) = self::requested_site();

		$content_type = sanitize_key( self::request_string( $_POST, 'content_type' ) );
		$content_id   = absint( self::request_string( $_POST, 'content_id' ) );
		$status       = sanitize_key( self::request_string( $_POST, 'status' ) );
		$title        = sanitize_text_field( self::request_string( $_POST, 'title' ) );
		$allowed      = in_array( $content_type, array( 'posts', 'pages' ), true )
			&& $content_id > 0
			&& in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'trash' ), true );

		if ( ! $allowed ) {
			self::redirect_workspace( 'content_failed', $id, 'content' );
		}

		$result = self::remote_request(
			$site,
			'POST',
			'wp/v2/' . $content_type . '/' . $content_id,
			array(
				'title'  => $title,
				'status' => $status,
			)
		);
		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			$notice        = 'content_failed';
		} else {
			$site['error'] = '';
			$notice        = 'content_updated';
		}
		$sites[ $id ] = $site;
		self::save_sites( $sites );
		self::redirect_workspace( $notice, $id, 'content' );
	}

	/**
	 * Activate or deactivate an installed plugin on a connected site.
	 */
	public static function handle_update_plugin() {
		self::guard_action( 'openstation_fleet_update_plugin' );
		list( $id, $site, $sites ) = self::requested_site();

		$plugin = self::request_string( $_POST, 'plugin' );
		$status = sanitize_key( self::request_string( $_POST, 'status' ) );
		if ( ! preg_match( '#^[a-z0-9._-]+(?:/[a-z0-9._-]+)?$#i', $plugin ) || ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			self::redirect_workspace( 'plugin_failed', $id, 'plugins' );
		}

		$route  = implode( '/', array_map( 'rawurlencode', explode( '/', $plugin ) ) );
		$result = self::remote_request( $site, 'POST', 'wp/v2/plugins/' . $route, array( 'status' => $status ) );
		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			$notice        = 'plugin_failed';
		} else {
			$site['error'] = '';
			$notice        = 'plugin_updated';
			if ( self::PLUGIN_REST_ID === $plugin ) {
				$site['openstation'] = self::inspect_plugins( array( $result ) );
			}
		}
		$site['last_checked'] = time();
		$sites[ $id ]         = $site;
		self::save_sites( $sites );
		self::redirect_workspace( $notice, $id, 'plugins' );
	}

	/**
	 * Run an advanced request against any REST route on the connected site.
	 */
	public static function handle_api_request() {
		self::guard_action( 'openstation_fleet_api_request' );
		list( $id, $site, $sites ) = self::requested_site();

		$method = strtoupper( sanitize_key( self::request_string( $_POST, 'api_method' ) ) );
		$route  = trim( self::request_string( $_POST, 'api_route' ) );
		$raw    = trim( self::request_string( $_POST, 'api_body' ) );
		if (
			! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true )
			|| '' === $route
			|| false !== strpos( $route, '://' )
			|| 0 === strpos( $route, '//' )
		) {
			self::redirect_workspace( 'api_failed', $id, 'api' );
		}

		$body = null;
		if ( '' !== $raw ) {
			$body = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $body ) ) {
				self::store_api_result( $id, new WP_Error( 'openstation_fleet_invalid_json', __( 'The request body must be a JSON object or array.', 'fleet-for-openstation' ) ) );
				self::redirect_workspace( 'api_failed', $id, 'api' );
			}
		}

		$result       = self::remote_request( $site, $method, ltrim( $route, '/' ), $body );
		$site['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$sites[ $id ]  = $site;
		self::save_sites( $sites );
		self::store_api_result( $id, $result, $method, $route );
		self::redirect_workspace( is_wp_error( $result ) ? 'api_failed' : 'api_complete', $id, 'api' );
	}

	/**
	 * Revoke the remote OAuth grant or Application Password before forgetting
	 * the site.
	 */
	public static function handle_disconnect() {
		self::guard_action( 'openstation_fleet_disconnect' );
		list( $id, $site, $sites ) = self::requested_site();

		if ( 'oauth' === self::site_auth_type( $site ) ) {
			$revoked = self::revoke_oauth_connection( $site );
		} else {
			$uuid = isset( $site['credential_uuid'] ) ? rawurlencode( $site['credential_uuid'] ) : '';
			if ( '' === $uuid ) {
				self::redirect( 'disconnect_failed' );
			}
			$revoked = self::remote_request( $site, 'DELETE', 'wp/v2/users/me/application-passwords/' . $uuid );
		}
		if ( is_wp_error( $revoked ) ) {
			$site['error'] = $revoked->get_error_message();
			$sites[ $id ]  = $site;
			self::save_sites( $sites );
			self::redirect( 'disconnect_failed' );
		}

		unset( $sites[ $id ] );
		self::save_sites( $sites );
		self::redirect( 'disconnected' );
	}

	/**
	 * Normalize and validate a public HTTPS WordPress site URL.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error
	 */
	public static function normalize_site_url( $url ) {
		$url   = trim( (string) $url );
		$parts = parse_url( $url );
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
	 * Discover REST and authorization endpoints from the Core REST index.
	 * OpenStation OAuth is preferred; Core Application Passwords remain the
	 * bootstrap path when OpenStation is not installed yet.
	 *
	 * @param string $site_url Normalized URL.
	 * @return array|WP_Error
	 */
	private static function discover_site( $site_url ) {
		$endpoints = array(
			trailingslashit( $site_url ) . 'wp-json/',
			add_query_arg( 'rest_route', '/', trailingslashit( $site_url ) ),
		);

		foreach ( $endpoints as $rest_url ) {
			$response = wp_safe_remote_get(
				$rest_url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'headers'     => array( 'Accept' => 'application/json' ),
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

			$name       = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : wp_parse_url( $reported_url, PHP_URL_HOST );
			$oauth_info = isset( $data['authentication']['openstation-fleet-oauth'] ) && is_array( $data['authentication']['openstation-fleet-oauth'] )
				? $data['authentication']['openstation-fleet-oauth']
				: array();
			$metadata   = isset( $oauth_info['metadata'] ) ? self::discover_oauth_metadata( $oauth_info['metadata'], $reported_url ) : null;
			if ( is_array( $metadata ) ) {
				return array(
					'name'                 => $name,
					'site_url'             => $reported_url,
					'rest_url'             => $rest_url,
					'auth_type'            => 'oauth',
					'issuer'               => $metadata['issuer'],
					'authorization_url'    => $metadata['authorization_endpoint'],
					'token_endpoint'       => $metadata['token_endpoint'],
					'revocation_endpoint'  => $metadata['revocation_endpoint'],
				);
			}

			$authorization_url = isset( $data['authentication']['application-passwords']['endpoints']['authorization'] )
				? $data['authentication']['application-passwords']['endpoints']['authorization']
				: '';
			if ( self::is_same_origin_https_url( $authorization_url, $reported_url ) ) {
				return array(
					'name'              => $name,
					'site_url'          => $reported_url,
					'rest_url'          => $rest_url,
					'auth_type'         => 'application-password',
					'authorization_url' => esc_url_raw( $authorization_url ),
				);
			}
		}

		return new WP_Error( 'openstation_fleet_discovery_failed', __( 'Could not discover a supported secure connection on that site.', 'fleet-for-openstation' ) );
	}

	/**
	 * Fetch and validate OpenStation authorization server metadata.
	 *
	 * @param string $metadata_url Metadata document URL.
	 * @param string $site_url     Reported WordPress site URL.
	 * @return array|null
	 */
	private static function discover_oauth_metadata( $metadata_url, $site_url ) {
		if ( ! self::is_same_origin_https_url( $metadata_url, $site_url ) ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$metadata_url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$required = array( 'issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) || ! self::is_same_origin_https_url( $data[ $field ], $site_url ) ) {
				return null;
			}
		}
		if (
			empty( $data['code_challenge_methods_supported'] )
			|| ! in_array( 'S256', (array) $data['code_challenge_methods_supported'], true )
			|| empty( $data['scopes_supported'] )
			|| ! in_array( self::OAUTH_SCOPE, (array) $data['scopes_supported'], true )
		) {
			return null;
		}
		return $data;
	}

	/**
	 * Refresh cached OpenStation status for one site.
	 *
	 * @param array $site Site record.
	 * @return array
	 */
	private static function refresh_site( $site ) {
		$plugins              = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' );
		$site['last_checked'] = time();
		if ( is_wp_error( $plugins ) ) {
			$site['error'] = $plugins->get_error_message();
			return $site;
		}

		$site['openstation'] = self::inspect_plugins( $plugins );
		$site['error']       = '';
		return $site;
	}

	/**
	 * Send an authenticated request to a target site's Core REST API.
	 *
	 * @param array       $site   Site record.
	 * @param string      $method HTTP method.
	 * @param string      $path   REST route without leading slash.
	 * @param array|null  $body   Optional JSON body.
	 * @return array|WP_Error
	 */
	private static function remote_request( &$site, $method, $path, $body = null ) {
		$auth_type = self::site_auth_type( $site );
		if ( 'oauth' === $auth_type ) {
			$credential = self::oauth_access_token( $site );
			if ( is_wp_error( $credential ) ) {
				return $credential;
			}
			$authorization = 'Bearer ' . $credential;
		} else {
			$credential = self::open_secret( isset( $site['secret'] ) ? (string) $site['secret'] : '' );
			if ( is_wp_error( $credential ) ) {
				return $credential;
			}
			$authorization = 'Basic ' . base64_encode( (string) $site['user_login'] . ':' . $credential );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => array(
				'Accept'        => 'application/json',
				'Authorization' => $authorization,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$args['data_format']             = 'body';
		}

		$response = wp_safe_remote_request( self::api_url( $site, $path ), $args );
		$credential = null;
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && 'oauth' === $auth_type ) {
			$refreshed = self::refresh_oauth_tokens( $site, true );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$args['headers']['Authorization'] = 'Bearer ' . $refreshed;
			$response                         = wp_safe_remote_request( self::api_url( $site, $path ), $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] )
				? wp_strip_all_tags( $data['message'] )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The remote site returned HTTP %d.', 'fleet-for-openstation' ),
					$code
				);
			return new WP_Error( 'openstation_fleet_remote_error', $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build a REST endpoint for pretty or plain permalink sites.
	 *
	 * @param array  $site Site record.
	 * @param string $path REST route.
	 * @return string
	 */
	private static function api_url( $site, $path ) {
		$path = ltrim( $path, '/' );
		if ( false !== strpos( (string) $site['rest_url'], 'rest_route=' ) ) {
			$parts = explode( '?', $path, 2 );
			$url   = add_query_arg( 'rest_route', '/' . $parts[0], trailingslashit( $site['site_url'] ) );
			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $query );
				$url = add_query_arg( $query, $url );
			}
			return $url;
		}

		return trailingslashit( (string) $site['rest_url'] ) . $path;
	}

	/**
	 * Build the Application Password authorization URL.
	 *
	 * add_query_arg() expects new values to already be URL-encoded. Encoding
	 * nested callback URLs keeps their state and nonce arguments inside the
	 * success and rejection URLs instead of leaking them into the outer query.
	 *
	 * @param string $endpoint Authorization endpoint.
	 * @param array  $args     Authorization arguments.
	 * @return string
	 */
	private static function authorization_url( $endpoint, $args ) {
		foreach ( $args as $key => $value ) {
			$args[ $key ] = rawurlencode( (string) $value );
		}

		return add_query_arg( $args, $endpoint );
	}

	/**
	 * Complete the Authorization Code + PKCE exchange and save the site.
	 *
	 * @param array $pending Validated discovery and temporary verifier state.
	 * @return void
	 */
	private static function complete_oauth_authorization( $pending ) {
		$code = self::request_string( $_GET, 'code' );
		$iss  = untrailingslashit( self::request_string( $_GET, 'iss' ) );
		if ( '' === $code || '' === $iss || ! hash_equals( (string) $pending['issuer'], $iss ) ) {
			self::redirect( 'authorization_failed' );
		}

		$verifier = self::open_secret( isset( $pending['code_verifier'] ) ? (string) $pending['code_verifier'] : '' );
		if ( is_wp_error( $verifier ) ) {
			self::redirect( 'authorization_failed' );
		}
		$response = wp_safe_remote_post(
			$pending['token_endpoint'],
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => self::get_app_id(),
					'redirect_uri'  => $pending['callback'],
					'code_verifier' => $verifier,
				),
			)
		);
		$verifier = null;
		$tokens   = self::parse_oauth_token_response( $response );
		if ( is_wp_error( $tokens ) ) {
			self::redirect( 'credential_failed' );
		}

		$site = array(
			'name'            => $pending['name'],
			'site_url'        => $pending['site_url'],
			'rest_url'        => $pending['rest_url'],
			'auth_type'       => 'oauth',
			'client_id'       => self::get_app_id(),
			'oauth'           => array(
				'issuer'              => $pending['issuer'],
				'token_endpoint'      => $pending['token_endpoint'],
				'revocation_endpoint' => $pending['revocation_endpoint'],
				'scope'               => self::OAUTH_SCOPE,
			),
			'openstation'     => array( 'status' => 'unknown' ),
			'last_checked'    => 0,
			'error'           => '',
		);
		$stored = self::store_oauth_tokens( $site, $tokens );
		if ( is_wp_error( $stored ) ) {
			self::redirect( 'encryption_failed' );
		}
		$site = $stored;

		$profile = self::remote_request( $site, 'GET', 'wp/v2/users/me?context=edit' );
		if ( is_wp_error( $profile ) || empty( $profile['id'] ) ) {
			self::redirect( 'credential_failed' );
		}
		$site['user_login'] = isset( $profile['username'] ) ? sanitize_user( $profile['username'], true ) : '';
		$site               = self::refresh_site( $site );
		$sites              = self::get_sites();
		$sites[ self::site_id( $site['site_url'] ) ] = $site;
		self::save_sites( $sites );
		self::redirect( 'connected' );
	}

	/**
	 * Return an OAuth access token, refreshing it before expiry.
	 *
	 * @param array $site Site record, updated when tokens rotate.
	 * @return string|WP_Error
	 */
	private static function oauth_access_token( &$site ) {
		$expires = isset( $site['oauth']['access_expires_at'] ) ? (int) $site['oauth']['access_expires_at'] : 0;
		if ( $expires > time() + 30 ) {
			return self::open_secret( isset( $site['oauth']['access_token'] ) ? (string) $site['oauth']['access_token'] : '' );
		}
		return self::refresh_oauth_tokens( $site, true );
	}

	/**
	 * Rotate the site's refresh token and persist the replacement pair.
	 *
	 * @param array $site  Site record.
	 * @param bool  $force Force refresh even if the cached access token is live.
	 * @return string|WP_Error Plaintext access token on success.
	 */
	private static function refresh_oauth_tokens( &$site, $force = false ) {
		if ( ! $force && isset( $site['oauth']['access_expires_at'] ) && (int) $site['oauth']['access_expires_at'] > time() + 30 ) {
			return self::open_secret( (string) $site['oauth']['access_token'] );
		}

		$refresh = self::open_secret( isset( $site['oauth']['refresh_token'] ) ? (string) $site['oauth']['refresh_token'] : '' );
		if ( is_wp_error( $refresh ) ) {
			return $refresh;
		}
		$response = wp_safe_remote_post(
			$site['oauth']['token_endpoint'],
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => $site['client_id'],
				),
			)
		);
		$refresh = null;
		$tokens  = self::parse_oauth_token_response( $response );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$stored = self::store_oauth_tokens( $site, $tokens );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$site = $stored;
		self::persist_site_record( $site );
		return self::open_secret( (string) $site['oauth']['access_token'] );
	}

	/**
	 * Validate a token endpoint response.
	 *
	 * @param array|WP_Error $response HTTP API response.
	 * @return array|WP_Error
	 */
	private static function parse_oauth_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && ! empty( $data['error_description'] )
				? sanitize_text_field( $data['error_description'] )
				: __( 'The managed site rejected the OAuth token request.', 'fleet-for-openstation' );
			return new WP_Error( 'openstation_fleet_oauth_token_failed', $message, array( 'status' => $code ) );
		}
		if (
			empty( $data['access_token'] )
			|| empty( $data['refresh_token'] )
			|| 'bearer' !== strtolower( isset( $data['token_type'] ) ? (string) $data['token_type'] : '' )
			|| self::OAUTH_SCOPE !== ( isset( $data['scope'] ) ? (string) $data['scope'] : '' )
			|| empty( $data['expires_in'] )
		) {
			return new WP_Error( 'openstation_fleet_oauth_token_invalid', __( 'The managed site returned an incomplete OAuth token response.', 'fleet-for-openstation' ) );
		}
		return $data;
	}

	/**
	 * Encrypt an OAuth token pair into a site record.
	 *
	 * @param array $site   Site record.
	 * @param array $tokens Validated token response.
	 * @return array|WP_Error
	 */
	private static function store_oauth_tokens( $site, $tokens ) {
		$access  = self::seal_secret( (string) $tokens['access_token'] );
		$refresh = self::seal_secret( (string) $tokens['refresh_token'] );
		if ( is_wp_error( $access ) || is_wp_error( $refresh ) ) {
			return new WP_Error( 'openstation_fleet_oauth_encrypt_failed', __( 'Fleet could not encrypt the OAuth tokens.', 'fleet-for-openstation' ) );
		}
		$site['oauth']['access_token']      = $access;
		$site['oauth']['refresh_token']     = $refresh;
		$site['oauth']['access_expires_at'] = time() + max( 1, absint( $tokens['expires_in'] ) );
		if ( isset( $tokens['refresh_expires_in'] ) ) {
			$site['oauth']['refresh_expires_at'] = time() + max( 1, absint( $tokens['refresh_expires_in'] ) );
		}
		return $site;
	}

	/**
	 * Revoke the OAuth grant represented by the stored refresh token.
	 *
	 * @param array $site Site record.
	 * @return true|WP_Error
	 */
	private static function revoke_oauth_connection( $site ) {
		$refresh = self::open_secret( isset( $site['oauth']['refresh_token'] ) ? (string) $site['oauth']['refresh_token'] : '' );
		if ( is_wp_error( $refresh ) ) {
			return $refresh;
		}
		$response = wp_safe_remote_post(
			$site['oauth']['revocation_endpoint'],
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'body'        => array(
					'token'           => $refresh,
					'token_type_hint' => 'refresh_token',
					'client_id'       => $site['client_id'],
				),
			)
		);
		$refresh = null;
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300
			? true
			: new WP_Error( 'openstation_fleet_oauth_revoke_failed', __( 'The managed site did not revoke the OAuth connection.', 'fleet-for-openstation' ), array( 'status' => $code ) );
	}

	/**
	 * Resolve legacy records that predate the explicit auth_type field.
	 *
	 * @param array $site Site record.
	 * @return string
	 */
	private static function site_auth_type( $site ) {
		return isset( $site['auth_type'] ) && 'oauth' === $site['auth_type'] ? 'oauth' : 'application-password';
	}

	/**
	 * Persist one rotated OAuth site record immediately.
	 *
	 * @param array $site Site record.
	 * @return void
	 */
	private static function persist_site_record( $site ) {
		if ( empty( $site['site_url'] ) ) {
			return;
		}
		$sites                                      = self::get_sites();
		$sites[ self::site_id( $site['site_url'] ) ] = $site;
		self::save_sites( $sites );
	}

	/**
	 * Generate a base64url random value.
	 *
	 * @param int $bytes Random byte count.
	 * @return string
	 */
	private static function random_value( $bytes ) {
		return self::base64url_encode( random_bytes( $bytes ) );
	}

	/**
	 * Encode raw bytes as unpadded base64url.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Encrypt a remote credential using sodium bundled by WordPress.
	 *
	 * @param string $secret Plaintext secret.
	 * @return string|WP_Error
	 */
	private static function seal_secret( $secret ) {
		self::load_sodium();
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return new WP_Error( 'openstation_fleet_no_crypto', __( 'This server cannot securely store the remote credential.', 'fleet-for-openstation' ) );
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $secret, $nonce, self::secret_key() );
		return 'v1:' . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored remote credential.
	 *
	 * @param string $sealed Sealed value.
	 * @return string|WP_Error
	 */
	private static function open_secret( $sealed ) {
		self::load_sodium();
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || 0 !== strpos( $sealed, 'v1:' ) ) {
			return new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'fleet-for-openstation' ) );
		}

		$payload = base64_decode( substr( $sealed, 3 ), true );
		if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'fleet-for-openstation' ) );
		}

		$nonce  = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$secret = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret_key() );
		return false === $secret
			? new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'fleet-for-openstation' ) )
			: $secret;
	}

	/**
	 * Load WordPress's sodium compatibility layer if PHP lacks sodium.
	 */
	private static function load_sodium() {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';
			if ( file_exists( $compat ) ) {
				require_once $compat;
			}
		}
	}

	/**
	 * Derive an encryption key from the site's authentication salt.
	 *
	 * @return string
	 */
	private static function secret_key() {
		return hash_hmac( 'sha256', 'fleet-for-openstation', wp_salt( 'auth' ), true );
	}

	/**
	 * Render one connected site card.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_site_card( $id, $site ) {
		$status     = isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : 'unknown';
		$version    = isset( $site['openstation']['version'] ) ? $site['openstation']['version'] : '';
		$auth_label = 'oauth' === self::site_auth_type( $site )
			? __( 'OAuth', 'fleet-for-openstation' )
			: __( 'Application Password', 'fleet-for-openstation' );
		$labels  = array(
			'active'   => $version ? sprintf( __( 'OpenStation %s', 'fleet-for-openstation' ), $version ) : __( 'OpenStation active', 'fleet-for-openstation' ),
			'inactive' => __( 'Installed, inactive', 'fleet-for-openstation' ),
			'missing'  => __( 'OpenStation not installed', 'fleet-for-openstation' ),
			'unknown'  => __( 'Not checked', 'fleet-for-openstation' ),
		);
		$manage_url = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'site_id' => $id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<article class="fleet-site-card">
			<div class="fleet-site-card__identity">
				<span class="fleet-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span></span>
				<div>
					<h3><?php echo esc_html( $site['name'] ); ?></h3>
					<a href="<?php echo esc_url( $site['site_url'] ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				</div>
			</div>
			<div class="fleet-site-card__state">
				<span class="fleet-pill fleet-pill--<?php echo esc_attr( $status ); ?>"><span class="fleet-status-dot" aria-hidden="true"></span><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'] ); ?></span>
				<small>
					<?php
					if ( $site['last_checked'] ) {
						printf(
							/* translators: 1: human-readable time difference, 2: authentication type. */
							esc_html__( 'Checked %1$s ago · %2$s', 'fleet-for-openstation' ),
							esc_html( human_time_diff( $site['last_checked'], time() ) ),
							esc_html( $auth_label )
						);
					} else {
						printf(
							/* translators: %s: authentication type. */
							esc_html__( 'Not checked · %s', 'fleet-for-openstation' ),
							esc_html( $auth_label )
						);
					}
					?>
				</small>
			</div>
			<?php if ( ! empty( $site['error'] ) ) : ?>
				<p class="fleet-site-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html( $site['error'] ); ?></p>
			<?php endif; ?>
			<p class="fleet-site-card__help"><?php esc_html_e( 'Manage this site through its authenticated WordPress APIs without opening another wp-admin.', 'fleet-for-openstation' ); ?></p>
			<div class="fleet-site-card__actions">
				<a class="button button-primary fleet-manage-button" href="<?php echo esc_url( $manage_url ); ?>">
					<?php esc_html_e( 'Manage site', 'fleet-for-openstation' ); ?>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</a>
				<?php self::render_action_form( 'check', $id, __( 'Check', 'fleet-for-openstation' ) ); ?>
				<?php if ( 'active' !== $status ) : ?>
					<?php self::render_action_form( 'install', $id, 'inactive' === $status ? __( 'Activate OpenStation', 'fleet-for-openstation' ) : __( 'Install OpenStation', 'fleet-for-openstation' ) ); ?>
				<?php endif; ?>
				<?php self::render_action_form( 'disconnect', $id, __( 'Disconnect', 'fleet-for-openstation' ), 'danger' ); ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Render the selected site's remote management workspace.
	 *
	 * @param string $id      Site id.
	 * @param array  $site    Site record.
	 * @param string $section Requested workspace section.
	 * @param string $notice  Notice code.
	 */
	private static function render_site_workspace( $id, $site, $section, $notice ) {
		$sections = array(
			'overview' => __( 'Overview', 'fleet-for-openstation' ),
			'content'  => __( 'Content', 'fleet-for-openstation' ),
			'plugins'  => __( 'Plugins', 'fleet-for-openstation' ),
			'settings' => __( 'Settings', 'fleet-for-openstation' ),
			'api'      => __( 'API', 'fleet-for-openstation' ),
		);
		if ( ! isset( $sections[ $section ] ) ) {
			$section = 'overview';
		}
		$back_url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap fleet-app fleet-workspace">
			<header class="fleet-workspace-header">
				<div class="fleet-workspace-header__identity">
					<a class="fleet-back" href="<?php echo esc_url( $back_url ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All sites', 'fleet-for-openstation' ); ?></a>
					<div class="fleet-workspace-title">
						<span class="fleet-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span></span>
						<div>
							<span class="fleet-eyebrow"><?php esc_html_e( 'Managing remote site', 'fleet-for-openstation' ); ?></span>
							<h1><?php echo esc_html( $site['name'] ); ?></h1>
							<p><?php echo esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ); ?> · <?php echo esc_html( $site['user_login'] ); ?></p>
						</div>
					</div>
				</div>
				<div class="fleet-remote-badge"><span class="fleet-status-dot" aria-hidden="true"></span><?php esc_html_e( 'Remote context', 'fleet-for-openstation' ); ?></div>
			</header>

			<?php self::render_notice( $notice ); ?>
			<?php if ( ! empty( $site['error'] ) ) : ?>
				<div class="fleet-inline-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><?php echo esc_html( $site['error'] ); ?></span></div>
			<?php endif; ?>

			<nav class="fleet-tabs" aria-label="<?php esc_attr_e( 'Site management', 'fleet-for-openstation' ); ?>">
				<?php foreach ( $sections as $key => $label ) : ?>
					<a class="<?php echo $section === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::workspace_url( $id, $key ) ); ?>" <?php echo $section === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<main class="fleet-workspace-body">
				<?php
				switch ( $section ) {
					case 'content':
						self::render_content_workspace( $id, $site );
						break;
					case 'plugins':
						self::render_plugins_workspace( $id, $site );
						break;
					case 'settings':
						self::render_settings_workspace( $id, $site );
						break;
					case 'api':
						self::render_api_workspace( $id, $site );
						break;
					default:
						self::render_overview_workspace( $id, $site );
						break;
				}
				?>
			</main>
		</div>
		<?php
	}

	/**
	 * Render remote-site overview.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_overview_workspace( $id, $site ) {
		$settings = self::remote_request( $site, 'GET', 'wp/v2/settings' );
		$posts    = self::remote_request( $site, 'GET', 'wp/v2/posts?context=edit&per_page=5&orderby=modified&order=desc&_fields=id,title,status,modified' );
		$pages    = self::remote_request( $site, 'GET', 'wp/v2/pages?context=edit&per_page=5&orderby=modified&order=desc&_fields=id,title,status,modified' );
		$status   = isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : 'unknown';
		?>
		<div class="fleet-workspace-intro">
			<div>
				<span class="fleet-eyebrow"><?php esc_html_e( 'Site overview', 'fleet-for-openstation' ); ?></span>
				<h2><?php esc_html_e( 'Work on this site', 'fleet-for-openstation' ); ?></h2>
				<p><?php esc_html_e( 'Every action below runs against the selected site, not the Fleet hub.', 'fleet-for-openstation' ); ?></p>
			</div>
			<span class="fleet-pill fleet-pill--<?php echo esc_attr( $status ); ?>"><span class="fleet-status-dot" aria-hidden="true"></span><?php echo 'active' === $status ? esc_html__( 'OpenStation ready', 'fleet-for-openstation' ) : esc_html__( 'OpenStation needs attention', 'fleet-for-openstation' ); ?></span>
		</div>

		<div class="fleet-overview-grid">
			<a class="fleet-task-card" href="<?php echo esc_url( self::workspace_url( $id, 'content' ) ); ?>">
				<span class="fleet-task-card__icon"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span></span>
				<span><strong><?php esc_html_e( 'Content', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Update post and page titles or publishing status.', 'fleet-for-openstation' ); ?></small></span>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</a>
			<a class="fleet-task-card" href="<?php echo esc_url( self::workspace_url( $id, 'plugins' ) ); ?>">
				<span class="fleet-task-card__icon"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span></span>
				<span><strong><?php esc_html_e( 'Plugins', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Activate or deactivate installed plugins.', 'fleet-for-openstation' ); ?></small></span>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</a>
			<a class="fleet-task-card" href="<?php echo esc_url( self::workspace_url( $id, 'settings' ) ); ?>">
				<span class="fleet-task-card__icon"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span></span>
				<span><strong><?php esc_html_e( 'Settings', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Change the site identity, timezone, and formats.', 'fleet-for-openstation' ); ?></small></span>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</a>
			<a class="fleet-task-card" href="<?php echo esc_url( self::workspace_url( $id, 'api' ) ); ?>">
				<span class="fleet-task-card__icon"><span class="dashicons dashicons-rest-api" aria-hidden="true"></span></span>
				<span><strong><?php esc_html_e( 'Full API', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Use any REST route your connected account can access.', 'fleet-for-openstation' ); ?></small></span>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</a>
		</div>

		<section class="fleet-panel fleet-overview-panel">
			<div class="fleet-panel__heading">
				<div><h2><?php esc_html_e( 'Remote WordPress', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Live information returned by the connected site.', 'fleet-for-openstation' ); ?></p></div>
			</div>
			<?php if ( is_wp_error( $settings ) ) : ?>
				<?php self::render_remote_error( $settings ); ?>
			<?php else : ?>
				<dl class="fleet-details">
					<div><dt><?php esc_html_e( 'Site title', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( isset( $settings['title'] ) ? $settings['title'] : $site['name'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Site address', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( $site['site_url'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Timezone', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( ! empty( $settings['timezone_string'] ) ? $settings['timezone_string'] : __( 'UTC offset', 'fleet-for-openstation' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Authenticated as', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( $site['user_login'] ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Connection', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( 'oauth' === self::site_auth_type( $site ) ? __( 'OAuth with rotating tokens', 'fleet-for-openstation' ) : __( 'Application Password bootstrap', 'fleet-for-openstation' ) ); ?></dd></div>
				</dl>
			<?php endif; ?>
		</section>

		<section class="fleet-panel">
			<div class="fleet-panel__heading"><div><h2><?php esc_html_e( 'Recently changed', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'The latest posts and pages from this site.', 'fleet-for-openstation' ); ?></p></div></div>
			<?php if ( is_wp_error( $posts ) || is_wp_error( $pages ) ) : ?>
				<?php self::render_remote_error( is_wp_error( $posts ) ? $posts : $pages ); ?>
			<?php else : ?>
				<?php self::render_recent_content( array_merge( $posts, $pages ) ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render posts and pages with small Core REST editing forms.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_content_workspace( $id, $site ) {
		$posts = self::remote_request( $site, 'GET', 'wp/v2/posts?context=edit&per_page=20&orderby=modified&order=desc&_fields=id,title,status,modified,type' );
		$pages = self::remote_request( $site, 'GET', 'wp/v2/pages?context=edit&per_page=20&orderby=modified&order=desc&_fields=id,title,status,modified,type' );
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote content', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Posts and pages', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Update titles and publishing status directly on the selected site.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<?php if ( is_wp_error( $posts ) || is_wp_error( $pages ) ) : ?>
			<?php self::render_remote_error( is_wp_error( $posts ) ? $posts : $pages ); ?>
		<?php else : ?>
			<section class="fleet-panel fleet-content-panel">
				<div class="fleet-panel__heading"><div><h2><?php esc_html_e( 'Posts', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'The 20 most recently changed posts.', 'fleet-for-openstation' ); ?></p></div><span class="fleet-count"><?php echo esc_html( count( $posts ) ); ?></span></div>
				<?php self::render_content_forms( $id, $posts, 'posts' ); ?>
			</section>
			<section class="fleet-panel fleet-content-panel">
				<div class="fleet-panel__heading"><div><h2><?php esc_html_e( 'Pages', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'The 20 most recently changed pages.', 'fleet-for-openstation' ); ?></p></div><span class="fleet-count"><?php echo esc_html( count( $pages ) ); ?></span></div>
				<?php self::render_content_forms( $id, $pages, 'pages' ); ?>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render editable remote content rows.
	 *
	 * @param string $site_id     Site id.
	 * @param array  $items       Core REST post records.
	 * @param string $content_type REST collection name.
	 */
	private static function render_content_forms( $site_id, $items, $content_type ) {
		if ( empty( $items ) ) {
			?>
			<div class="fleet-panel-empty"><?php esc_html_e( 'Nothing here yet.', 'fleet-for-openstation' ); ?></div>
			<?php
			return;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$title = isset( $item['title']['raw'] ) ? $item['title']['raw'] : ( isset( $item['title']['rendered'] ) ? wp_strip_all_tags( $item['title']['rendered'] ) : '' );
			?>
			<form class="fleet-content-row" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="openstation_fleet_update_content">
				<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>">
				<input type="hidden" name="content_id" value="<?php echo esc_attr( $item['id'] ); ?>">
				<input type="hidden" name="content_type" value="<?php echo esc_attr( $content_type ); ?>">
				<?php wp_nonce_field( 'openstation_fleet_update_content' ); ?>
				<div class="fleet-content-row__main">
					<label for="fleet-title-<?php echo esc_attr( $content_type . '-' . $item['id'] ); ?>"><?php esc_html_e( 'Title', 'fleet-for-openstation' ); ?></label>
					<input id="fleet-title-<?php echo esc_attr( $content_type . '-' . $item['id'] ); ?>" name="title" type="text" value="<?php echo esc_attr( $title ); ?>" required>
					<small><?php echo ! empty( $item['modified'] ) ? esc_html( sprintf( __( 'Modified %s ago', 'fleet-for-openstation' ), human_time_diff( strtotime( $item['modified'] ), time() ) ) ) : ''; ?></small>
				</div>
				<div class="fleet-content-row__status">
					<label for="fleet-status-<?php echo esc_attr( $content_type . '-' . $item['id'] ); ?>"><?php esc_html_e( 'Status', 'fleet-for-openstation' ); ?></label>
					<select id="fleet-status-<?php echo esc_attr( $content_type . '-' . $item['id'] ); ?>" name="status">
						<option value="publish" <?php selected( isset( $item['status'] ) ? $item['status'] : '', 'publish' ); ?>><?php esc_html_e( 'Published', 'fleet-for-openstation' ); ?></option>
						<option value="draft" <?php selected( isset( $item['status'] ) ? $item['status'] : '', 'draft' ); ?>><?php esc_html_e( 'Draft', 'fleet-for-openstation' ); ?></option>
						<option value="pending" <?php selected( isset( $item['status'] ) ? $item['status'] : '', 'pending' ); ?>><?php esc_html_e( 'Pending review', 'fleet-for-openstation' ); ?></option>
						<option value="private" <?php selected( isset( $item['status'] ) ? $item['status'] : '', 'private' ); ?>><?php esc_html_e( 'Private', 'fleet-for-openstation' ); ?></option>
						<option value="trash" <?php selected( isset( $item['status'] ) ? $item['status'] : '', 'trash' ); ?>><?php esc_html_e( 'Trash', 'fleet-for-openstation' ); ?></option>
					</select>
				</div>
				<button class="button button-secondary" type="submit"><?php esc_html_e( 'Update', 'fleet-for-openstation' ); ?></button>
			</form>
			<?php
		}
	}

	/**
	 * Render installed remote plugins and activation controls.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_plugins_workspace( $id, $site ) {
		$plugins = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' );
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote plugins', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Installed plugins', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Activate and deactivate plugins on this site through WordPress Core.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<?php if ( is_wp_error( $plugins ) ) : ?>
			<?php self::render_remote_error( $plugins ); ?>
		<?php else : ?>
			<div class="fleet-plugin-list">
				<?php foreach ( $plugins as $plugin ) : ?>
					<?php
					if ( ! is_array( $plugin ) || empty( $plugin['plugin'] ) ) {
						continue;
					}
					$plugin_id       = (string) $plugin['plugin'];
					$plugin_status   = isset( $plugin['status'] ) ? (string) $plugin['status'] : 'inactive';
					$is_openstation  = self::PLUGIN_REST_ID === $plugin_id;
					$is_network      = 'network-active' === $plugin_status;
					$is_active       = in_array( $plugin_status, array( 'active', 'network-active' ), true );
					$next_status     = $is_active ? 'inactive' : 'active';
					$action_label    = $is_active ? __( 'Deactivate', 'fleet-for-openstation' ) : __( 'Activate', 'fleet-for-openstation' );
					$display_name    = isset( $plugin['name'] ) ? wp_strip_all_tags( $plugin['name'] ) : $plugin_id;
					?>
					<article class="fleet-plugin-card">
						<span class="fleet-plugin-card__icon"><span class="dashicons <?php echo $is_openstation ? 'dashicons-desktop' : 'dashicons-admin-plugins'; ?>" aria-hidden="true"></span></span>
						<div class="fleet-plugin-card__copy">
							<div><h3><?php echo esc_html( $display_name ); ?></h3><?php if ( $is_openstation ) : ?><span class="fleet-mini-badge"><?php esc_html_e( 'OpenStation', 'fleet-for-openstation' ); ?></span><?php endif; ?></div>
							<p><?php echo ! empty( $plugin['description']['raw'] ) ? esc_html( wp_trim_words( wp_strip_all_tags( $plugin['description']['raw'] ), 22 ) ) : esc_html( $plugin_id ); ?></p>
							<small><?php echo ! empty( $plugin['version'] ) ? esc_html( sprintf( __( 'Version %s', 'fleet-for-openstation' ), $plugin['version'] ) ) : ''; ?></small>
						</div>
						<div class="fleet-plugin-card__action">
							<span class="fleet-pill fleet-pill--<?php echo $is_active ? 'active' : 'inactive'; ?>"><span class="fleet-status-dot" aria-hidden="true"></span><?php echo $is_active ? esc_html__( 'Active', 'fleet-for-openstation' ) : esc_html__( 'Inactive', 'fleet-for-openstation' ); ?></span>
							<?php if ( ! $is_network && ! ( $is_openstation && $is_active ) ) : ?>
								<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
									<input type="hidden" name="action" value="openstation_fleet_update_plugin">
									<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
									<input type="hidden" name="plugin" value="<?php echo esc_attr( $plugin_id ); ?>">
									<input type="hidden" name="status" value="<?php echo esc_attr( $next_status ); ?>">
									<?php wp_nonce_field( 'openstation_fleet_update_plugin' ); ?>
									<button class="button button-secondary" type="submit"><?php echo esc_html( $action_label ); ?></button>
								</form>
							<?php elseif ( $is_network ) : ?>
								<small><?php esc_html_e( 'Network managed', 'fleet-for-openstation' ); ?></small>
							<?php else : ?>
								<small><?php esc_html_e( 'Required for Fleet', 'fleet-for-openstation' ); ?></small>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render editable Core site settings.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_settings_workspace( $id, $site ) {
		$settings = self::remote_request( $site, 'GET', 'wp/v2/settings' );
		$weekdays = array(
			__( 'Sunday', 'fleet-for-openstation' ),
			__( 'Monday', 'fleet-for-openstation' ),
			__( 'Tuesday', 'fleet-for-openstation' ),
			__( 'Wednesday', 'fleet-for-openstation' ),
			__( 'Thursday', 'fleet-for-openstation' ),
			__( 'Friday', 'fleet-for-openstation' ),
			__( 'Saturday', 'fleet-for-openstation' ),
		);
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote settings', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Site settings', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'These values belong to the selected site, not the Fleet hub.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<?php if ( is_wp_error( $settings ) ) : ?>
			<?php self::render_remote_error( $settings ); ?>
		<?php else : ?>
			<form class="fleet-panel fleet-settings-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="openstation_fleet_update_settings">
				<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'openstation_fleet_update_settings' ); ?>
				<div class="fleet-field fleet-field--wide"><label for="fleet-setting-title"><?php esc_html_e( 'Site title', 'fleet-for-openstation' ); ?></label><input id="fleet-setting-title" name="title" type="text" value="<?php echo esc_attr( isset( $settings['title'] ) ? $settings['title'] : '' ); ?>" required></div>
				<div class="fleet-field fleet-field--wide"><label for="fleet-setting-description"><?php esc_html_e( 'Tagline', 'fleet-for-openstation' ); ?></label><input id="fleet-setting-description" name="description" type="text" value="<?php echo esc_attr( isset( $settings['description'] ) ? $settings['description'] : '' ); ?>"></div>
				<div class="fleet-settings-grid">
					<div class="fleet-field"><label for="fleet-setting-timezone"><?php esc_html_e( 'Timezone', 'fleet-for-openstation' ); ?></label><input id="fleet-setting-timezone" name="timezone_string" type="text" placeholder="America/Chicago" value="<?php echo esc_attr( isset( $settings['timezone_string'] ) ? $settings['timezone_string'] : '' ); ?>"></div>
					<div class="fleet-field"><label for="fleet-setting-week"><?php esc_html_e( 'Week starts on', 'fleet-for-openstation' ); ?></label><select id="fleet-setting-week" name="start_of_week"><?php foreach ( $weekdays as $day_number => $day_name ) : ?><option value="<?php echo esc_attr( $day_number ); ?>" <?php selected( isset( $settings['start_of_week'] ) ? (int) $settings['start_of_week'] : 0, $day_number ); ?>><?php echo esc_html( $day_name ); ?></option><?php endforeach; ?></select></div>
					<div class="fleet-field"><label for="fleet-setting-date"><?php esc_html_e( 'Date format', 'fleet-for-openstation' ); ?></label><input id="fleet-setting-date" name="date_format" type="text" value="<?php echo esc_attr( isset( $settings['date_format'] ) ? $settings['date_format'] : 'F j, Y' ); ?>"></div>
					<div class="fleet-field"><label for="fleet-setting-time"><?php esc_html_e( 'Time format', 'fleet-for-openstation' ); ?></label><input id="fleet-setting-time" name="time_format" type="text" value="<?php echo esc_attr( isset( $settings['time_format'] ) ? $settings['time_format'] : 'g:i a' ); ?>"></div>
				</div>
				<div class="fleet-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save site settings', 'fleet-for-openstation' ); ?></button><span><?php esc_html_e( 'Saved directly to the connected WordPress site.', 'fleet-for-openstation' ); ?></span></div>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the full REST API console for routes without a dedicated Fleet UI.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_api_workspace( $id, $site ) {
		$result = self::take_api_result( $id );
		?>
		<div class="fleet-workspace-intro">
			<div>
				<span class="fleet-eyebrow"><?php esc_html_e( 'Full WordPress API', 'fleet-for-openstation' ); ?></span>
				<h2><?php esc_html_e( 'API console', 'fleet-for-openstation' ); ?></h2>
				<p><?php esc_html_e( 'Use any REST route the connected WordPress account can access, including routes registered by plugins.', 'fleet-for-openstation' ); ?></p>
			</div>
		</div>
		<div class="fleet-api-layout">
			<form class="fleet-panel fleet-api-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="openstation_fleet_api_request">
				<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'openstation_fleet_api_request' ); ?>
				<div class="fleet-api-route">
					<label for="fleet-api-method" class="screen-reader-text"><?php esc_html_e( 'HTTP method', 'fleet-for-openstation' ); ?></label>
					<select id="fleet-api-method" name="api_method">
						<?php foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>"><?php echo esc_html( $method ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="fleet-api-route" class="screen-reader-text"><?php esc_html_e( 'REST route', 'fleet-for-openstation' ); ?></label>
					<input id="fleet-api-route" name="api_route" type="text" placeholder="wp/v2/users?context=edit" required>
				</div>
				<div class="fleet-field fleet-field--wide">
					<label for="fleet-api-body"><?php esc_html_e( 'JSON body', 'fleet-for-openstation' ); ?> <small><?php esc_html_e( '(optional)', 'fleet-for-openstation' ); ?></small></label>
					<textarea id="fleet-api-body" name="api_body" rows="12" spellcheck="false" placeholder="{&#10;  &quot;title&quot;: &quot;Updated from Fleet&quot;&#10;}"></textarea>
				</div>
				<div class="fleet-form-actions">
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Send API request', 'fleet-for-openstation' ); ?></button>
					<span><?php echo esc_html( trailingslashit( $site['rest_url'] ) ); ?></span>
				</div>
			</form>

			<section class="fleet-panel fleet-api-response">
				<div class="fleet-panel__heading">
					<div><h2><?php esc_html_e( 'Response', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'The latest response is kept only long enough to display it here.', 'fleet-for-openstation' ); ?></p></div>
				</div>
				<?php if ( empty( $result ) ) : ?>
					<div class="fleet-panel-empty"><?php esc_html_e( 'Send a request to see its JSON response.', 'fleet-for-openstation' ); ?></div>
				<?php else : ?>
					<p><code><?php echo esc_html( $result['method'] . ' /' . ltrim( $result['route'], '/' ) ); ?></code></p>
					<pre><code><?php echo esc_html( wp_json_encode( $result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></code></pre>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Render a compact list of recent posts and pages.
	 *
	 * @param array $items Core REST post records.
	 */
	private static function render_recent_content( $items ) {
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( isset( $b['modified'] ) ? $b['modified'] : '', isset( $a['modified'] ) ? $a['modified'] : '' );
			}
		);
		$items = array_slice( $items, 0, 6 );
		if ( empty( $items ) ) {
			?><div class="fleet-panel-empty"><?php esc_html_e( 'No posts or pages yet.', 'fleet-for-openstation' ); ?></div><?php
			return;
		}
		?>
		<div class="fleet-recent-list">
			<?php foreach ( $items as $item ) : ?>
				<div class="fleet-recent-item">
					<span class="fleet-recent-item__icon"><span class="dashicons <?php echo isset( $item['type'] ) && 'page' === $item['type'] ? 'dashicons-admin-page' : 'dashicons-admin-post'; ?>" aria-hidden="true"></span></span>
					<span><strong><?php echo esc_html( isset( $item['title']['rendered'] ) ? wp_strip_all_tags( $item['title']['rendered'] ) : __( '(Untitled)', 'fleet-for-openstation' ) ); ?></strong><small><?php echo esc_html( ucfirst( isset( $item['status'] ) ? $item['status'] : 'unknown' ) ); ?></small></span>
					<time><?php echo ! empty( $item['modified'] ) ? esc_html( human_time_diff( strtotime( $item['modified'] ), time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) ) : ''; ?></time>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a remote request error in the workspace.
	 *
	 * @param WP_Error $error Remote request error.
	 */
	private static function render_remote_error( $error ) {
		?>
		<div class="fleet-remote-error"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><div><strong><?php esc_html_e( 'The remote site did not answer this request.', 'fleet-for-openstation' ); ?></strong><p><?php echo esc_html( $error->get_error_message() ); ?></p></div></div>
		<?php
	}

	/**
	 * Build a URL for a connected site's workspace section.
	 *
	 * @param string $id      Site id.
	 * @param string $section Workspace section.
	 * @return string
	 */
	private static function workspace_url( $id, $section = 'overview' ) {
		return add_query_arg(
			array(
				'page'          => self::MENU_SLUG,
				'site_id'       => sanitize_key( $id ),
				'fleet_section' => sanitize_key( $section ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render a small nonce-protected action form.
	 *
	 * @param string $action Action suffix.
	 * @param string $id     Site id.
	 * @param string $label  Button label.
	 * @param string $class  Button class suffix.
	 */
	private static function render_action_form( $action, $id, $label, $class = 'secondary' ) {
		$button_class = 'primary' === $class ? 'button-primary' : 'button-secondary';
		if ( 'danger' === $class ) {
			$button_class = 'fleet-button-danger';
		}
		?>
		<form class="fleet-action-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( 'openstation_fleet_' . $action ); ?>">
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'openstation_fleet_' . $action ); ?>
			<button class="button <?php echo esc_attr( $button_class ); ?>" type="submit"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render a notice selected from a fixed code map.
	 *
	 * @param string $code Notice code.
	 */
	private static function render_notice( $code ) {
		$messages = array(
			'connected'              => array( 'success', __( 'Site connected.', 'fleet-for-openstation' ) ),
			'checked'                => array( 'success', __( 'Site status refreshed.', 'fleet-for-openstation' ) ),
			'installed'              => array( 'success', __( 'OpenStation installed and activated.', 'fleet-for-openstation' ) ),
			'already_active'         => array( 'info', __( 'OpenStation is already active.', 'fleet-for-openstation' ) ),
			'settings_updated'       => array( 'success', __( 'The remote site settings were saved.', 'fleet-for-openstation' ) ),
			'content_updated'        => array( 'success', __( 'The remote content was updated.', 'fleet-for-openstation' ) ),
			'plugin_updated'         => array( 'success', __( 'The remote plugin status was updated.', 'fleet-for-openstation' ) ),
			'api_complete'           => array( 'success', __( 'The remote API request completed.', 'fleet-for-openstation' ) ),
			'disconnected'           => array( 'success', __( 'Remote authorization revoked and site disconnected.', 'fleet-for-openstation' ) ),
			'invalid_url'            => array( 'error', __( 'Enter a valid public HTTPS WordPress site URL.', 'fleet-for-openstation' ) ),
			'hub_https_required'     => array( 'error', __( 'The Fleet hub must use HTTPS for secure authorization.', 'fleet-for-openstation' ) ),
			'self_site'              => array( 'warning', __( 'This site is the Fleet hub, so it cannot be connected to itself.', 'fleet-for-openstation' ) ),
			'discovery_failed'       => array( 'error', __( 'Fleet could not find a supported secure authorization endpoint on that site.', 'fleet-for-openstation' ) ),
			'authorization_failed'   => array( 'error', __( 'The authorization response was invalid.', 'fleet-for-openstation' ) ),
			'authorization_expired'  => array( 'error', __( 'The authorization attempt expired. Try connecting again.', 'fleet-for-openstation' ) ),
			'authorization_rejected' => array( 'warning', __( 'The site connection was not approved.', 'fleet-for-openstation' ) ),
			'encryption_failed'      => array( 'error', __( 'This server could not securely store the credential.', 'fleet-for-openstation' ) ),
			'credential_failed'      => array( 'error', __( 'WordPress issued a credential, but Fleet could not verify it. Check whether the server forwards Authorization headers.', 'fleet-for-openstation' ) ),
			'check_failed'           => array( 'error', __( 'Fleet could not refresh that site.', 'fleet-for-openstation' ) ),
			'install_failed'         => array( 'error', __( 'OpenStation could not be installed. Review the site error below.', 'fleet-for-openstation' ) ),
			'settings_failed'        => array( 'error', __( 'Fleet could not save the remote site settings.', 'fleet-for-openstation' ) ),
			'content_failed'         => array( 'error', __( 'Fleet could not update that remote content.', 'fleet-for-openstation' ) ),
			'plugin_failed'          => array( 'error', __( 'Fleet could not update that remote plugin.', 'fleet-for-openstation' ) ),
			'api_failed'             => array( 'error', __( 'The remote API request failed.', 'fleet-for-openstation' ) ),
			'disconnect_failed'      => array( 'error', __( 'Fleet could not revoke the remote authorization, so the site remains connected.', 'fleet-for-openstation' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $messages[ $code ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $code ][1] ); ?></p></div>
		<?php
	}

	/**
	 * Require capability and nonce for an admin action.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function guard_action( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Resolve the submitted site id.
	 *
	 * @return array Tuple of id, site, and all sites.
	 */
	private static function requested_site() {
		$id    = sanitize_key( self::request_string( $_POST, 'site_id' ) );
		$sites = self::get_sites();
		if ( '' === $id || ! isset( $sites[ $id ] ) || ! is_array( $sites[ $id ] ) ) {
			wp_die( esc_html__( 'That Fleet site does not exist.', 'fleet-for-openstation' ) );
		}
		return array( $id, $sites[ $id ], $sites );
	}

	/**
	 * Get current user's connected sites.
	 *
	 * @return array
	 */
	private static function get_sites() {
		$sites = get_user_meta( get_current_user_id(), self::USER_META_SITES, true );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Save current user's connected sites.
	 *
	 * @param array $sites Site records.
	 */
	private static function save_sites( $sites ) {
		update_user_meta( get_current_user_id(), self::USER_META_SITES, $sites );
	}

	/**
	 * Store one API console result for the redirect back to Fleet.
	 *
	 * @param string         $site_id Site id.
	 * @param array|WP_Error $result  Remote result.
	 * @param string         $method  HTTP method.
	 * @param string         $route   REST route.
	 * @return void
	 */
	private static function store_api_result( $site_id, $result, $method = '', $route = '' ) {
		$data = is_wp_error( $result )
			? array(
				'error'   => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			)
			: $result;
		if ( strlen( (string) wp_json_encode( $data ) ) > 100000 ) {
			$data = array(
				'error'   => 'response_too_large',
				'message' => __( 'The response exceeded Fleet’s 100 KB display limit.', 'fleet-for-openstation' ),
			);
		}
		set_transient(
			self::api_result_key( $site_id ),
			array(
				'method' => $method,
				'route'  => $route,
				'data'   => $data,
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Consume the latest API console result.
	 *
	 * @param string $site_id Site id.
	 * @return array|null
	 */
	private static function take_api_result( $site_id ) {
		$key    = self::api_result_key( $site_id );
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Build a per-user transient key for API console output.
	 *
	 * @param string $site_id Site id.
	 * @return string
	 */
	private static function api_result_key( $site_id ) {
		return 'openstation_fleet_api_' . get_current_user_id() . '_' . sanitize_key( $site_id );
	}

	/**
	 * Get a stable UUID used to identify this Fleet installation.
	 *
	 * @return string
	 */
	private static function get_app_id() {
		$app_id = get_user_meta( get_current_user_id(), self::USER_META_APP_ID, true );
		if ( ! wp_is_uuid( $app_id ) ) {
			$app_id = wp_generate_uuid4();
			update_user_meta( get_current_user_id(), self::USER_META_APP_ID, $app_id );
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
	 * Redirect back to Fleet with a fixed notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( 'fleet_notice', sanitize_key( $notice ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Redirect back to a selected site's remote workspace.
	 *
	 * @param string $notice  Notice code.
	 * @param string $site_id Site id.
	 * @param string $section Workspace section.
	 */
	private static function redirect_workspace( $notice, $site_id, $section ) {
		wp_safe_redirect(
			add_query_arg(
				'fleet_notice',
				sanitize_key( $notice ),
				self::workspace_url( $site_id, $section )
			)
		);
		exit;
	}
}
