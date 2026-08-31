<?php
/**
 * Fleet screen and WordPress-to-WordPress orchestration.
 *
 * @package OpenStationFleet
 */

defined( 'ABSPATH' ) || exit;

/**
 * Implements the experimental Fleet feature with WordPress Core primitives.
 */
final class OpenStation_Fleet {
	const CAPABILITY       = 'manage_options';
	const MENU_SLUG        = 'openstation-fleet';
	const USER_META_SITES  = 'openstation_fleet_sites';
	const USER_META_APP_ID = 'openstation_fleet_app_id';
	const PLUGIN_SLUG      = 'desktop-mode';
	const PLUGIN_FILE      = 'desktop-mode/desktop-mode.php';

	/**
	 * Register the admin screen and form handlers.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_openstation_fleet_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_openstation_fleet_authorized', array( __CLASS__, 'handle_authorized' ) );
		add_action( 'admin_post_openstation_fleet_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_openstation_fleet_install', array( __CLASS__, 'handle_install' ) );
		add_action( 'admin_post_openstation_fleet_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * Register a normal wp-admin page. OpenStation already presents normal
	 * admin pages as windows, so Fleet needs no private window API.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'OpenStation Fleet', 'openstation-fleet' ),
			__( 'Fleet', 'openstation-fleet' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-site-alt3',
			58
		);
	}

	/**
	 * Render the fleet screen with Core admin markup and forms.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'openstation-fleet' ) );
		}

		$sites  = self::get_sites();
		$notice = sanitize_key( self::request_string( $_GET, 'fleet_notice' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice code.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OpenStation Fleet', 'openstation-fleet' ); ?></h1>
			<p><?php esc_html_e( 'Connect client sites with a revocable WordPress Application Password, then install and activate OpenStation through the WordPress Core Plugins API.', 'openstation-fleet' ); ?></p>

			<?php self::render_notice( $notice ); ?>

			<h2><?php esc_html_e( 'Connect a site', 'openstation-fleet' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="openstation_fleet_connect">
				<?php wp_nonce_field( 'openstation_fleet_connect' ); ?>
				<label class="screen-reader-text" for="openstation-fleet-site-url"><?php esc_html_e( 'WordPress site URL', 'openstation-fleet' ); ?></label>
				<input class="regular-text" id="openstation-fleet-site-url" name="site_url" type="url" inputmode="url" placeholder="https://example.com" required>
				<?php submit_button( __( 'Connect site', 'openstation-fleet' ), 'primary', 'submit', false ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'The site must use HTTPS and have Application Passwords enabled. You will approve access on that site.', 'openstation-fleet' ); ?></p>

			<h2><?php esc_html_e( 'Connected sites', 'openstation-fleet' ); ?></h2>
			<?php if ( empty( $sites ) ) : ?>
				<p><?php esc_html_e( 'No sites connected yet.', 'openstation-fleet' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Site', 'openstation-fleet' ); ?></th>
							<th scope="col"><?php esc_html_e( 'OpenStation', 'openstation-fleet' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Checked', 'openstation-fleet' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'openstation-fleet' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sites as $id => $site ) : ?>
							<?php self::render_site_row( (string) $id, $site ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Start the WordPress Core Application Password authorization flow.
	 */
	public static function handle_connect() {
		self::guard_action( 'openstation_fleet_connect' );

		$raw_url  = self::request_string( $_POST, 'site_url' );
		$site_url = self::normalize_site_url( $raw_url );
		if ( is_wp_error( $site_url ) ) {
			self::redirect( 'invalid_url' );
		}

		$discovery = self::discover_site( $site_url );
		if ( is_wp_error( $discovery ) ) {
			self::redirect( 'discovery_failed' );
		}

		$state = wp_generate_uuid4();
		set_transient(
			self::pending_key( get_current_user_id(), $state ),
			$discovery,
			10 * MINUTE_IN_SECONDS
		);

		$callback = add_query_arg(
			array(
				'action'   => 'openstation_fleet_authorized',
				'state'    => $state,
				'_wpnonce' => wp_create_nonce( 'openstation_fleet_authorized_' . $state ),
			),
			admin_url( 'admin-post.php' )
		);
		$reject   = add_query_arg( 'rejected', '1', $callback );
		$app_name = sprintf(
			/* translators: %s: hostname of the Fleet hub. */
			__( 'OpenStation Fleet on %s', 'openstation-fleet' ),
			wp_parse_url( home_url(), PHP_URL_HOST )
		);
		$authorize = add_query_arg(
			array(
				'app_name'    => $app_name,
				'app_id'      => self::get_app_id(),
				'success_url' => $callback,
				'reject_url'  => $reject,
			),
			$discovery['authorization_url']
		);

		wp_redirect( $authorize ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated same-origin external authorization endpoint.
		exit;
	}

	/**
	 * Store the credential returned by the Core authorization screen.
	 */
	public static function handle_authorized() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'openstation-fleet' ) );
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
		if ( isset( $_GET['rejected'] ) || 'false' === self::request_string( $_GET, 'success' ) ) {
			self::redirect( 'authorization_rejected' );
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
				'wp/v2/plugins/' . rawurlencode( $status['plugin'] ),
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
	 * Revoke the remote Application Password before forgetting the site.
	 */
	public static function handle_disconnect() {
		self::guard_action( 'openstation_fleet_disconnect' );
		list( $id, $site, $sites ) = self::requested_site();

		$uuid = isset( $site['credential_uuid'] ) ? rawurlencode( $site['credential_uuid'] ) : '';
		if ( '' === $uuid ) {
			self::redirect( 'disconnect_failed' );
		}
		$revoked = self::remote_request( $site, 'DELETE', 'wp/v2/users/me/application-passwords/' . $uuid );
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
			return new WP_Error( 'openstation_fleet_invalid_url', __( 'Enter a public HTTPS site URL without query parameters or credentials.', 'openstation-fleet' ) );
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
			if ( self::PLUGIN_FILE !== $file && 'desktop-mode' !== $textdomain ) {
				continue;
			}

			$remote_status = isset( $plugin['status'] ) ? (string) $plugin['status'] : 'inactive';
			return array(
				'status'  => in_array( $remote_status, array( 'active', 'network-active' ), true ) ? 'active' : 'inactive',
				'plugin'  => $file ? $file : self::PLUGIN_FILE,
				'version' => isset( $plugin['version'] ) ? (string) $plugin['version'] : '',
			);
		}

		return array(
			'status'  => 'missing',
			'plugin'  => self::PLUGIN_FILE,
			'version' => '',
		);
	}

	/**
	 * Discover REST and authorization endpoints from the Core REST index.
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
			$authorization_url = isset( $data['authentication']['application-passwords']['endpoints']['authorization'] )
				? $data['authentication']['application-passwords']['endpoints']['authorization']
				: '';
			$reported_url = isset( $data['url'] ) ? self::normalize_site_url( $data['url'] ) : $site_url;
			if (
				is_wp_error( $reported_url ) ||
				! self::is_same_origin_https_url( $authorization_url, $reported_url )
			) {
				continue;
			}

			return array(
				'name'              => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : wp_parse_url( $reported_url, PHP_URL_HOST ),
				'site_url'          => $reported_url,
				'rest_url'          => $rest_url,
				'authorization_url' => esc_url_raw( $authorization_url ),
			);
		}

		return new WP_Error( 'openstation_fleet_discovery_failed', __( 'Could not discover Application Password support on that site.', 'openstation-fleet' ) );
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
	private static function remote_request( $site, $method, $path, $body = null ) {
		$password = self::open_secret( isset( $site['secret'] ) ? (string) $site['secret'] : '' );
		if ( is_wp_error( $password ) ) {
			return $password;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( (string) $site['user_login'] . ':' . $password ),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$args['data_format']             = 'body';
		}

		$response = wp_safe_remote_request( self::api_url( $site, $path ), $args );
		$password = null;
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] )
				? wp_strip_all_tags( $data['message'] )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The remote site returned HTTP %d.', 'openstation-fleet' ),
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
	 * Encrypt an Application Password using sodium bundled by WordPress.
	 *
	 * @param string $secret Plaintext secret.
	 * @return string|WP_Error
	 */
	private static function seal_secret( $secret ) {
		self::load_sodium();
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return new WP_Error( 'openstation_fleet_no_crypto', __( 'This server cannot securely store the Application Password.', 'openstation-fleet' ) );
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $secret, $nonce, self::secret_key() );
		return 'v1:' . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored Application Password.
	 *
	 * @param string $sealed Sealed value.
	 * @return string|WP_Error
	 */
	private static function open_secret( $sealed ) {
		self::load_sodium();
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || 0 !== strpos( $sealed, 'v1:' ) ) {
			return new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'openstation-fleet' ) );
		}

		$payload = base64_decode( substr( $sealed, 3 ), true );
		if ( false === $payload || strlen( $payload ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'openstation-fleet' ) );
		}

		$nonce  = substr( $payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$secret = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret_key() );
		return false === $secret
			? new WP_Error( 'openstation_fleet_invalid_secret', __( 'The stored credential cannot be read. Reconnect this site.', 'openstation-fleet' ) )
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
		return hash_hmac( 'sha256', 'openstation-fleet', wp_salt( 'auth' ), true );
	}

	/**
	 * Render one site table row.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_site_row( $id, $site ) {
		$status  = isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : 'unknown';
		$version = isset( $site['openstation']['version'] ) ? $site['openstation']['version'] : '';
		$labels  = array(
			'active'   => $version ? sprintf( __( 'Active — %s', 'openstation-fleet' ), $version ) : __( 'Active', 'openstation-fleet' ),
			'inactive' => __( 'Installed, inactive', 'openstation-fleet' ),
			'missing'  => __( 'Not installed', 'openstation-fleet' ),
			'unknown'  => __( 'Not checked', 'openstation-fleet' ),
		);
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( trailingslashit( $site['site_url'] ) . 'wp-admin/' ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $site['name'] ); ?></a></strong><br>
				<a href="<?php echo esc_url( $site['site_url'] ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $site['site_url'] ); ?></a><br>
				<span class="description"><?php echo esc_html( $site['user_login'] ); ?></span>
				<?php if ( ! empty( $site['error'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Error:', 'openstation-fleet' ); ?></strong> <?php echo esc_html( $site['error'] ); ?></p>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'] ); ?></td>
			<td><?php echo $site['last_checked'] ? esc_html( human_time_diff( $site['last_checked'], time() ) . ' ' . __( 'ago', 'openstation-fleet' ) ) : '&mdash;'; ?></td>
			<td>
				<?php self::render_action_form( 'check', $id, __( 'Check', 'openstation-fleet' ) ); ?>
				<?php if ( 'active' !== $status ) : ?>
					<?php self::render_action_form( 'install', $id, 'inactive' === $status ? __( 'Activate OpenStation', 'openstation-fleet' ) : __( 'Install OpenStation', 'openstation-fleet' ), 'primary' ); ?>
				<?php endif; ?>
				<?php self::render_action_form( 'disconnect', $id, __( 'Disconnect', 'openstation-fleet' ), 'link-delete' ); ?>
			</td>
		</tr>
		<?php
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
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin:0 4px 4px 0">
			<input type="hidden" name="action" value="<?php echo esc_attr( 'openstation_fleet_' . $action ); ?>">
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'openstation_fleet_' . $action ); ?>
			<button class="button <?php echo esc_attr( 'button-' . $class ); ?>" type="submit"><?php echo esc_html( $label ); ?></button>
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
			'connected'              => array( 'success', __( 'Site connected.', 'openstation-fleet' ) ),
			'checked'                => array( 'success', __( 'Site status refreshed.', 'openstation-fleet' ) ),
			'installed'              => array( 'success', __( 'OpenStation installed and activated.', 'openstation-fleet' ) ),
			'already_active'         => array( 'info', __( 'OpenStation is already active.', 'openstation-fleet' ) ),
			'disconnected'           => array( 'success', __( 'Application Password revoked and site disconnected.', 'openstation-fleet' ) ),
			'invalid_url'            => array( 'error', __( 'Enter a valid public HTTPS WordPress site URL.', 'openstation-fleet' ) ),
			'discovery_failed'       => array( 'error', __( 'Fleet could not find an enabled Application Password authorization endpoint on that site.', 'openstation-fleet' ) ),
			'authorization_failed'   => array( 'error', __( 'The authorization response was invalid.', 'openstation-fleet' ) ),
			'authorization_expired'  => array( 'error', __( 'The authorization attempt expired. Try connecting again.', 'openstation-fleet' ) ),
			'authorization_rejected' => array( 'warning', __( 'The site connection was not approved.', 'openstation-fleet' ) ),
			'encryption_failed'      => array( 'error', __( 'This server could not securely store the credential.', 'openstation-fleet' ) ),
			'credential_failed'      => array( 'error', __( 'WordPress issued a credential, but Fleet could not verify it. Check whether the server forwards Authorization headers.', 'openstation-fleet' ) ),
			'check_failed'           => array( 'error', __( 'Fleet could not refresh that site.', 'openstation-fleet' ) ),
			'install_failed'         => array( 'error', __( 'OpenStation could not be installed. Review the site error below.', 'openstation-fleet' ) ),
			'disconnect_failed'      => array( 'error', __( 'Fleet could not revoke the remote Application Password, so the site remains connected.', 'openstation-fleet' ) ),
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
			wp_die( esc_html__( 'You are not allowed to manage Fleet.', 'openstation-fleet' ) );
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
			wp_die( esc_html__( 'That Fleet site does not exist.', 'openstation-fleet' ) );
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
}
