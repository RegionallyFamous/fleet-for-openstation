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
	const USER_META_EVENTS = 'openstation_fleet_activity';
	const USER_META_APP_ID = 'openstation_fleet_app_id';
	const PLUGIN_SLUG      = 'desktop-mode';
	const PLUGIN_REST_ID   = 'desktop-mode/desktop-mode';
	const CRON_HOOK        = 'openstation_fleet_scheduled_check';
	const CRON_SCHEDULE    = 'openstation_fleet_15_minutes';

	/**
	 * Register the admin screen and form handlers.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_checks' ) );
		add_action( 'admin_post_openstation_fleet_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_openstation_fleet_authorized', array( __CLASS__, 'handle_authorized' ) );
		add_action( 'admin_post_openstation_fleet_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_openstation_fleet_install', array( __CLASS__, 'handle_install' ) );
		add_action( 'admin_post_openstation_fleet_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_openstation_fleet_update_profile', array( __CLASS__, 'handle_update_profile' ) );
		add_action( 'admin_post_openstation_fleet_toggle_favorite', array( __CLASS__, 'handle_toggle_favorite' ) );
		add_action( 'admin_post_openstation_fleet_update_settings', array( __CLASS__, 'handle_update_settings' ) );
		add_action( 'admin_post_openstation_fleet_update_content', array( __CLASS__, 'handle_update_content' ) );
		add_action( 'admin_post_openstation_fleet_update_plugin', array( __CLASS__, 'handle_update_plugin' ) );
		add_action( 'admin_post_openstation_fleet_install_plugin', array( __CLASS__, 'handle_install_plugin' ) );
		add_action( 'admin_post_openstation_fleet_update_comment', array( __CLASS__, 'handle_update_comment' ) );
		add_action( 'admin_post_openstation_fleet_update_media', array( __CLASS__, 'handle_update_media' ) );
		add_action( 'admin_post_openstation_fleet_upload_media', array( __CLASS__, 'handle_upload_media' ) );
		add_action( 'admin_post_openstation_fleet_create_user', array( __CLASS__, 'handle_create_user' ) );
		add_action( 'admin_post_openstation_fleet_update_user', array( __CLASS__, 'handle_update_user' ) );
		add_action( 'admin_post_openstation_fleet_api_request', array( __CLASS__, 'handle_api_request' ) );
		add_action( 'admin_post_openstation_fleet_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'wp_ajax_openstation_fleet_finish_setup', array( __CLASS__, 'handle_finish_setup' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Add Fleet's lightweight background-check interval.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Fleet)', 'fleet-for-openstation' ),
		);
		return $schedules;
	}

	/**
	 * Remove Fleet's recurring job when the plugin is deactivated.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
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
				"(function(wp){if(!wp||!wp.hooks){return;}wp.hooks.addFilter('os.window.geometry','fleet-for-openstation/default-size',function(g,c){var id=c.baseId||'';if(id!=='admin-php-page-fleet-for-openstation'&&id.indexOf('fleet-site-')!==0){return g;}var a=c.workArea,w=Math.min(1040,Math.max(640,a.width-48)),h=Math.min(760,Math.max(480,a.height-48));return Object.assign({},g,{x:a.x+Math.max(24,(a.width-w)/2),y:a.y+Math.max(24,(a.height-h)/2),width:w,height:h,state:'normal'});});})(window.wp);",
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
		wp_enqueue_script(
			'fleet-for-openstation-admin',
			OPENSTATION_FLEET_URL . 'assets/admin.js',
			array(),
			OPENSTATION_FLEET_VERSION,
			true
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
		$view    = sanitize_key( self::request_string( $_GET, 'fleet_view' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only hub view.
		$launch_id = sanitize_key( self::request_string( $_GET, 'fleet_launch_site' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only shell launch request.
		$view    = 'attention' === $view ? 'inbox' : $view;
		$view    = in_array( $view, array( 'sites', 'inbox', 'search', 'workspaces', 'activity' ), true ) ? $view : 'sites';

		if ( '' !== $site_id && isset( $sites[ $site_id ] ) && is_array( $sites[ $site_id ] ) ) {
			if ( 'pending' === $sites[ $site_id ]['setup_status'] ) {
				self::render_site_setup( $site_id, $sites[ $site_id ] );
				return;
			}
			self::render_site_workspace( $site_id, $sites[ $site_id ], $section, $notice );
			return;
		}

		$ready_count     = 0;
		$attention_count = 0;
		$inbox_count     = 0;
		foreach ( $sites as $site ) {
			if ( empty( $site['error'] ) && 'active' === ( isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : '' ) ) {
				++$ready_count;
			}
			if ( ! empty( self::attention_reasons( $site ) ) ) {
				++$attention_count;
			}
			$inbox_count += self::inbox_item_count( $site );
		}
		$display_sites = self::filter_sites( $sites, $view );
		?>
		<div class="wrap fleet-app">
			<?php if ( '' !== $launch_id && isset( $sites[ $launch_id ] ) ) : ?>
				<a hidden href="<?php echo esc_url( self::workspace_url( $launch_id ) ); ?>" data-fleet-auto-open data-fleet-window-id="<?php echo esc_attr( self::site_window_id( $launch_id ) ); ?>" data-fleet-window-title="<?php echo esc_attr( $sites[ $launch_id ]['name'] ); ?>" data-fleet-return-url="<?php echo esc_url( self::hub_url( array( 'fleet_notice' => $notice ) ) ); ?>"><?php esc_html_e( 'Open managed site', 'fleet-for-openstation' ); ?></a>
			<?php endif; ?>
			<header class="fleet-commandbar">
				<div class="fleet-hero__copy">
					<span class="fleet-product-mark" aria-hidden="true"><span class="dashicons dashicons-networking"></span></span>
					<div>
						<span class="fleet-eyebrow"><?php esc_html_e( 'OpenStation agency tools', 'fleet-for-openstation' ); ?></span>
						<h1><?php esc_html_e( 'Fleet', 'fleet-for-openstation' ); ?></h1>
						<p><?php esc_html_e( 'Manage every WordPress site from one station.', 'fleet-for-openstation' ); ?></p>
					</div>
				</div>
				<details class="fleet-connect-popover">
					<summary class="button button-primary"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Connect site', 'fleet-for-openstation' ); ?></summary>
					<div class="fleet-connect-popover__body">
						<div>
							<span class="fleet-eyebrow"><?php esc_html_e( 'New connection', 'fleet-for-openstation' ); ?></span>
							<h2><?php esc_html_e( 'Add a WordPress site', 'fleet-for-openstation' ); ?></h2>
							<p><?php esc_html_e( 'Fleet uses WordPress’s native approval screen, then installs and activates OpenStation automatically.', 'fleet-for-openstation' ); ?></p>
						</div>
						<ol class="fleet-connect-steps">
							<li><span>1</span><?php esc_html_e( 'Enter the site address', 'fleet-for-openstation' ); ?></li>
							<li><span>2</span><?php esc_html_e( 'Approve Fleet in WordPress', 'fleet-for-openstation' ); ?></li>
							<li><span>3</span><?php esc_html_e( 'Return connected and ready', 'fleet-for-openstation' ); ?></li>
						</ol>
						<form class="fleet-connect-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" target="_top">
							<input type="hidden" name="action" value="openstation_fleet_connect">
							<?php wp_nonce_field( 'openstation_fleet_connect' ); ?>
							<label for="fleet-for-openstation-site-url"><?php esc_html_e( 'WordPress site URL', 'fleet-for-openstation' ); ?></label>
							<div class="fleet-connect-form__control">
								<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
								<input id="fleet-for-openstation-site-url" name="site_url" type="url" inputmode="url" placeholder="https://example.com" required>
							</div>
							<button class="button button-primary" type="submit"><?php esc_html_e( 'Continue on site', 'fleet-for-openstation' ); ?></button>
						</form>
						<p class="fleet-connect-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'You never enter a WordPress password into Fleet. If you are not already signed in, WordPress will ask you to sign in on that site.', 'fleet-for-openstation' ); ?></p>
					</div>
				</details>
			</header>

			<?php self::render_notice( $notice ); ?>

			<div class="fleet-summary" role="list" aria-label="<?php esc_attr_e( 'Fleet summary', 'fleet-for-openstation' ); ?>">
				<div class="fleet-summary__hub" role="listitem">
					<span class="fleet-status-dot" aria-hidden="true"></span>
					<span><strong><?php esc_html_e( 'Fleet hub', 'fleet-for-openstation' ); ?></strong><small><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></small></span>
				</div>
				<div class="fleet-summary__item" role="listitem">
					<strong><?php echo esc_html( count( $sites ) ); ?></strong>
					<span><?php esc_html_e( 'Connected sites', 'fleet-for-openstation' ); ?></span>
				</div>
				<div class="fleet-summary__item" role="listitem">
					<strong><?php echo esc_html( $ready_count ); ?></strong>
					<span><?php esc_html_e( 'OpenStation ready', 'fleet-for-openstation' ); ?></span>
				</div>
				<div class="fleet-summary__item fleet-summary__item--attention" role="listitem">
					<strong><?php echo esc_html( $attention_count ); ?></strong>
					<span><?php esc_html_e( 'Need attention', 'fleet-for-openstation' ); ?></span>
				</div>
			</div>

			<nav class="fleet-hub-tabs" aria-label="<?php esc_attr_e( 'Fleet views', 'fleet-for-openstation' ); ?>">
				<a class="<?php echo 'sites' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::hub_url( array( 'fleet_view' => 'sites' ) ) ); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'All sites', 'fleet-for-openstation' ); ?></a>
				<a class="<?php echo 'inbox' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::hub_url( array( 'fleet_view' => 'inbox' ) ) ); ?>"><span class="dashicons dashicons-inbox" aria-hidden="true"></span><?php esc_html_e( 'Inbox', 'fleet-for-openstation' ); ?><span class="fleet-tab-count"><?php echo esc_html( $inbox_count ); ?></span></a>
				<a class="<?php echo 'search' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::hub_url( array( 'fleet_view' => 'search' ) ) ); ?>"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( 'Search', 'fleet-for-openstation' ); ?></a>
				<a class="<?php echo 'workspaces' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::hub_url( array( 'fleet_view' => 'workspaces' ) ) ); ?>"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span><?php esc_html_e( 'Workspaces', 'fleet-for-openstation' ); ?></a>
				<a class="<?php echo 'activity' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::hub_url( array( 'fleet_view' => 'activity' ) ) ); ?>"><span class="dashicons dashicons-backup" aria-hidden="true"></span><?php esc_html_e( 'Activity', 'fleet-for-openstation' ); ?></a>
			</nav>

			<?php if ( 'activity' === $view ) : ?>
				<?php self::render_activity_view(); ?>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php if ( 'inbox' === $view ) : ?>
				<?php self::render_inbox_view( $sites ); ?>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php if ( 'search' === $view ) : ?>
				<?php self::render_search_view( $sites ); ?>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php if ( 'workspaces' === $view ) : ?>
				<?php self::render_workspaces_view( $sites ); ?>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php self::render_hub_filters( $sites, $view ); ?>

			<section class="fleet-board" aria-labelledby="fleet-sites-title">
				<?php self::render_network_map( $display_sites ); ?>
				<div class="fleet-manifest">
					<div class="fleet-section-heading">
						<div>
							<span class="fleet-eyebrow"><?php esc_html_e( 'Site manifest', 'fleet-for-openstation' ); ?></span>
							<h2 id="fleet-sites-title"><?php esc_html_e( 'Connected sites', 'fleet-for-openstation' ); ?></h2>
						</div>
						<form id="fleet-bulk-form" class="fleet-bulk-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
							<input type="hidden" name="action" value="openstation_fleet_bulk">
							<?php wp_nonce_field( 'openstation_fleet_bulk' ); ?>
							<label class="screen-reader-text" for="fleet-bulk-action"><?php esc_html_e( 'Bulk action', 'fleet-for-openstation' ); ?></label>
							<select id="fleet-bulk-action" name="bulk_action"><option value="check"><?php esc_html_e( 'Check selected', 'fleet-for-openstation' ); ?></option><option value="install"><?php esc_html_e( 'Install or activate OpenStation', 'fleet-for-openstation' ); ?></option></select>
							<button class="button button-secondary" type="submit"><?php esc_html_e( 'Apply', 'fleet-for-openstation' ); ?></button>
						</form>
					</div>
					<?php if ( empty( $display_sites ) ) : ?>
						<div class="fleet-empty">
							<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
							<h3><?php echo empty( $sites ) ? esc_html__( 'Your fleet starts here', 'fleet-for-openstation' ) : esc_html__( 'No sites match this view', 'fleet-for-openstation' ); ?></h3>
							<p><?php echo empty( $sites ) ? esc_html__( 'Use Connect site to open your first remote management workspace.', 'fleet-for-openstation' ) : esc_html__( 'Clear the filters or check another Fleet view.', 'fleet-for-openstation' ); ?></p>
						</div>
					<?php else : ?>
						<div class="fleet-site-list">
							<?php foreach ( $display_sites as $id => $site ) : ?>
								<?php self::render_site_card( (string) $id, $site ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render a compact visual map of the hub and the first five connected sites.
	 *
	 * @param array $sites Connected site records.
	 */
	private static function render_network_map( $sites ) {
		$positions = array(
			array( 50, 13 ),
			array( 83, 39 ),
			array( 70, 80 ),
			array( 30, 80 ),
			array( 17, 39 ),
		);
		$map_sites = array_slice( $sites, 0, count( $positions ), true );
		?>
		<div class="fleet-map" aria-label="<?php esc_attr_e( 'Fleet network chart', 'fleet-for-openstation' ); ?>">
			<div class="fleet-map__heading">
				<span class="fleet-eyebrow"><?php esc_html_e( 'Network chart', 'fleet-for-openstation' ); ?></span>
				<span class="fleet-map__legend"><span class="fleet-status-dot" aria-hidden="true"></span><?php esc_html_e( 'Hub online', 'fleet-for-openstation' ); ?></span>
			</div>
			<div class="fleet-map__canvas">
				<svg class="fleet-map__lines" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
					<?php $map_index = 0; ?>
					<?php foreach ( $map_sites as $site ) : ?>
						<line x1="50" y1="50" x2="<?php echo esc_attr( $positions[ $map_index ][0] ); ?>" y2="<?php echo esc_attr( $positions[ $map_index ][1] ); ?>"></line>
						<?php ++$map_index; ?>
					<?php endforeach; ?>
				</svg>
				<div class="fleet-map__hub">
					<span class="dashicons dashicons-networking" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Fleet hub', 'fleet-for-openstation' ); ?></strong>
				</div>
				<?php $map_index = 0; ?>
				<?php foreach ( $map_sites as $id => $site ) : ?>
					<?php
					$status     = isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : 'unknown';
					$manage_url = add_query_arg( array( 'page' => self::MENU_SLUG, 'site_id' => (string) $id ), admin_url( 'admin.php' ) );
					?>
					<a class="fleet-map__site fleet-map__site--<?php echo esc_attr( $status ); ?>" style="--fleet-x: <?php echo esc_attr( $positions[ $map_index ][0] ); ?>%; --fleet-y: <?php echo esc_attr( $positions[ $map_index ][1] ); ?>%;" href="<?php echo esc_url( $manage_url ); ?>" target="<?php echo esc_attr( self::site_window_id( $id ) ); ?>" data-fleet-window-id="<?php echo esc_attr( self::site_window_id( $id ) ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>">
						<span class="fleet-map__site-dot"><span class="fleet-status-dot" aria-hidden="true"></span></span>
						<strong><?php echo esc_html( $site['name'] ); ?></strong>
					</a>
					<?php ++$map_index; ?>
				<?php endforeach; ?>
				<?php if ( empty( $map_sites ) ) : ?>
					<p class="fleet-map__empty"><?php esc_html_e( 'Your connected sites will gather around this hub.', 'fleet-for-openstation' ); ?></p>
				<?php elseif ( count( $sites ) > count( $map_sites ) ) : ?>
					<span class="fleet-map__overflow"><?php printf( esc_html__( '+%d more', 'fleet-for-openstation' ), esc_html( count( $sites ) - count( $map_sites ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<p class="fleet-map__caption"><?php esc_html_e( 'Select a site to open or focus its own remote WordPress window.', 'fleet-for-openstation' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Apply hub search, agency filters, and the attention view.
	 *
	 * @param array  $sites Connected sites.
	 * @param string $view  Hub view.
	 * @return array
	 */
	private static function filter_sites( $sites, $view ) {
		$search   = strtolower( sanitize_text_field( self::request_string( $_GET, 'fleet_search' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$client   = sanitize_text_field( self::request_string( $_GET, 'fleet_client' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$tag      = sanitize_text_field( self::request_string( $_GET, 'fleet_tag' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$plan     = sanitize_key( self::request_string( $_GET, 'fleet_plan' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$favorites = '1' === self::request_string( $_GET, 'fleet_favorites' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$filtered = array();
		foreach ( $sites as $id => $site ) {
			$agency = $site['agency'];
			if ( 'attention' === $view && empty( self::attention_reasons( $site ) ) ) {
				continue;
			}
			if ( $favorites && empty( $agency['favorite'] ) ) {
				continue;
			}
			if ( '' !== $client && $client !== $agency['client_name'] ) {
				continue;
			}
			if ( '' !== $tag && ! in_array( $tag, $agency['tags'], true ) ) {
				continue;
			}
			if ( '' !== $plan && $plan !== $agency['plan_status'] ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = strtolower( implode( ' ', array( $site['name'], $site['site_url'], $agency['client_name'], implode( ' ', $agency['tags'] ), $agency['notes'] ) ) );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}
			$filtered[ $id ] = $site;
		}
		uasort(
			$filtered,
			static function ( $a, $b ) {
				$favorite = (int) ! empty( $b['agency']['favorite'] ) <=> (int) ! empty( $a['agency']['favorite'] );
				return 0 !== $favorite ? $favorite : strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $filtered;
	}

	/**
	 * Render agency filters using a normal Core GET form.
	 *
	 * @param array  $sites Connected sites.
	 * @param string $view  Current hub view.
	 */
	private static function render_hub_filters( $sites, $view ) {
		$clients = array();
		$tags    = array();
		foreach ( $sites as $site ) {
			if ( '' !== $site['agency']['client_name'] ) {
				$clients[] = $site['agency']['client_name'];
			}
			$tags = array_merge( $tags, $site['agency']['tags'] );
		}
		$clients = array_values( array_unique( $clients ) );
		$tags    = array_values( array_unique( $tags ) );
		sort( $clients, SORT_NATURAL | SORT_FLAG_CASE );
		sort( $tags, SORT_NATURAL | SORT_FLAG_CASE );
		$current_search = sanitize_text_field( self::request_string( $_GET, 'fleet_search' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$current_client = sanitize_text_field( self::request_string( $_GET, 'fleet_client' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$current_tag    = sanitize_text_field( self::request_string( $_GET, 'fleet_tag' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$current_plan   = sanitize_key( self::request_string( $_GET, 'fleet_plan' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		?>
		<form class="fleet-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<input type="hidden" name="fleet_view" value="<?php echo esc_attr( $view ); ?>">
			<label class="fleet-filter-search"><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Search sites', 'fleet-for-openstation' ); ?></span><input name="fleet_search" type="search" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search sites, clients, notes…', 'fleet-for-openstation' ); ?>"></label>
			<label><span class="screen-reader-text"><?php esc_html_e( 'Client', 'fleet-for-openstation' ); ?></span><select name="fleet_client"><option value=""><?php esc_html_e( 'All clients', 'fleet-for-openstation' ); ?></option><?php foreach ( $clients as $client ) : ?><option value="<?php echo esc_attr( $client ); ?>" <?php selected( $current_client, $client ); ?>><?php echo esc_html( $client ); ?></option><?php endforeach; ?></select></label>
			<label><span class="screen-reader-text"><?php esc_html_e( 'Tag', 'fleet-for-openstation' ); ?></span><select name="fleet_tag"><option value=""><?php esc_html_e( 'All tags', 'fleet-for-openstation' ); ?></option><?php foreach ( $tags as $site_tag ) : ?><option value="<?php echo esc_attr( $site_tag ); ?>" <?php selected( $current_tag, $site_tag ); ?>><?php echo esc_html( $site_tag ); ?></option><?php endforeach; ?></select></label>
			<label><span class="screen-reader-text"><?php esc_html_e( 'Maintenance plan', 'fleet-for-openstation' ); ?></span><select name="fleet_plan"><option value=""><?php esc_html_e( 'All plans', 'fleet-for-openstation' ); ?></option><?php foreach ( array( 'active' => __( 'Active plan', 'fleet-for-openstation' ), 'paused' => __( 'Paused plan', 'fleet-for-openstation' ), 'ended' => __( 'Ended plan', 'fleet-for-openstation' ), 'none' => __( 'No plan', 'fleet-for-openstation' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_plan, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label class="fleet-favorite-filter"><input name="fleet_favorites" type="checkbox" value="1" <?php checked( self::request_string( $_GET, 'fleet_favorites' ), '1' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter. ?>><?php esc_html_e( 'Favorites', 'fleet-for-openstation' ); ?></label>
			<button class="button button-secondary" type="submit"><?php esc_html_e( 'Filter', 'fleet-for-openstation' ); ?></button>
			<a class="fleet-filter-clear" href="<?php echo esc_url( self::hub_url( array( 'fleet_view' => $view ) ) ); ?>"><?php esc_html_e( 'Clear', 'fleet-for-openstation' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Render recent local Fleet activity.
	 */
	private static function render_activity_view() {
		$events = self::get_activity();
		?>
		<section class="fleet-activity-view">
			<div class="fleet-section-heading"><div><span class="fleet-eyebrow"><?php esc_html_e( 'Hub history', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Recent activity', 'fleet-for-openstation' ); ?></h2></div><span class="fleet-count"><?php echo esc_html( count( $events ) ); ?></span></div>
			<?php if ( empty( $events ) ) : ?>
				<div class="fleet-empty"><span class="dashicons dashicons-backup" aria-hidden="true"></span><h3><?php esc_html_e( 'No activity yet', 'fleet-for-openstation' ); ?></h3><p><?php esc_html_e( 'Fleet will record connection, maintenance, and management actions here.', 'fleet-for-openstation' ); ?></p></div>
			<?php else : ?>
				<div class="fleet-activity-list">
					<?php foreach ( $events as $event ) : ?>
						<div class="fleet-activity-item fleet-activity-item--<?php echo esc_attr( isset( $event['status'] ) ? $event['status'] : 'success' ); ?>">
							<span class="fleet-activity-icon"><span class="dashicons <?php echo isset( $event['status'] ) && 'error' === $event['status'] ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span></span>
							<span><strong><?php echo esc_html( isset( $event['site_name'] ) ? $event['site_name'] : __( 'Fleet', 'fleet-for-openstation' ) ); ?></strong><small><?php echo esc_html( isset( $event['message'] ) ? $event['message'] : '' ); ?></small></span>
							<span class="fleet-activity-meta"><?php echo esc_html( isset( $event['actor'] ) ? $event['actor'] : '' ); ?><time><?php echo ! empty( $event['time'] ) ? esc_html( human_time_diff( $event['time'], time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) ) : ''; ?></time></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render the cached, Core-powered work queue across connected sites.
	 *
	 * @param array $sites Connected sites.
	 */
	private static function render_inbox_view( $sites ) {
		$last_checked = 0;
		$totals = array(
			'attention'        => 0,
			'pending_comments' => 0,
			'editorial'        => 0,
			'scheduled'        => 0,
		);
		foreach ( $sites as $site ) {
			$inbox                       = self::normalize_inbox_summary( isset( $site['inbox'] ) ? $site['inbox'] : array() );
			$last_checked                 = max( $last_checked, (int) $inbox['checked'] );
			$totals['attention']        += count( self::attention_reasons( $site ) );
			$totals['pending_comments'] += $inbox['pending_comments']['count'];
			$totals['editorial']        += $inbox['drafts']['count'] + $inbox['pending_posts']['count'];
			$totals['scheduled']        += $inbox['scheduled_posts']['count'];
			foreach ( array( 'pending_comments', 'drafts', 'pending_posts', 'scheduled_posts' ) as $key ) {
				if ( ! empty( $inbox[ $key ]['error'] ) ) {
					++$totals['attention'];
				}
			}
		}
		$total = array_sum( $totals );
		?>
		<section class="fleet-operations-view">
			<div class="fleet-section-heading">
				<div><span class="fleet-eyebrow"><?php esc_html_e( 'WordPress work queue', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Fleet Inbox', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Comments, editorial work, scheduled posts, connection issues, and Core Site Health findings from every connected site.', 'fleet-for-openstation' ); ?></p></div>
				<div class="fleet-view-meta">
					<?php if ( $last_checked ) : ?><span><span class="dashicons dashicons-update" aria-hidden="true"></span><?php printf( esc_html__( 'Updated %s ago', 'fleet-for-openstation' ), esc_html( human_time_diff( $last_checked, time() ) ) ); ?></span><?php endif; ?>
					<span class="fleet-count"><?php printf( esc_html( _n( '%d item', '%d items', $total, 'fleet-for-openstation' ) ), esc_html( $total ) ); ?></span>
				</div>
			</div>
			<div class="fleet-inbox-summary" role="list" aria-label="<?php esc_attr_e( 'Inbox summary', 'fleet-for-openstation' ); ?>">
				<div role="listitem"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong><?php echo esc_html( $totals['attention'] ); ?></strong><small><?php esc_html_e( 'Health and connection', 'fleet-for-openstation' ); ?></small></div>
				<div role="listitem"><span class="dashicons dashicons-admin-comments" aria-hidden="true"></span><strong><?php echo esc_html( $totals['pending_comments'] ); ?></strong><small><?php esc_html_e( 'Comments awaiting review', 'fleet-for-openstation' ); ?></small></div>
				<div role="listitem"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span><strong><?php echo esc_html( $totals['editorial'] ); ?></strong><small><?php esc_html_e( 'Drafts and pending posts', 'fleet-for-openstation' ); ?></small></div>
				<div role="listitem"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><strong><?php echo esc_html( $totals['scheduled'] ); ?></strong><small><?php esc_html_e( 'Scheduled posts', 'fleet-for-openstation' ); ?></small></div>
			</div>

			<?php if ( empty( $sites ) ) : ?>
				<div class="fleet-empty"><span class="dashicons dashicons-inbox" aria-hidden="true"></span><h3><?php esc_html_e( 'Connect a site to build your inbox', 'fleet-for-openstation' ); ?></h3><p><?php esc_html_e( 'Fleet uses only the REST collections WordPress already provides.', 'fleet-for-openstation' ); ?></p></div>
			<?php elseif ( 0 === $total ) : ?>
				<div class="fleet-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><h3><?php esc_html_e( 'The fleet is clear', 'fleet-for-openstation' ); ?></h3><p><?php esc_html_e( 'No cached work or health findings need attention. Use Check now on a site whenever you want a fresh reading.', 'fleet-for-openstation' ); ?></p></div>
			<?php else : ?>
				<div class="fleet-inbox-sites">
					<div class="fleet-inbox-sites__heading"><strong><?php esc_html_e( 'Site work groups', 'fleet-for-openstation' ); ?></strong><span><?php esc_html_e( 'Open a row to work in that site’s persistent window.', 'fleet-for-openstation' ); ?></span></div>
					<?php foreach ( $sites as $id => $site ) : ?>
						<?php
						$inbox     = self::normalize_inbox_summary( isset( $site['inbox'] ) ? $site['inbox'] : array() );
						$attention = self::attention_reasons( $site );
						$count     = self::inbox_item_count( $site );
						if ( 0 === $count ) {
							continue;
						}
						$window_id = self::site_window_id( $id );
						?>
						<article class="fleet-inbox-site">
							<header>
								<div><span class="fleet-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span></span><span><strong><?php echo esc_html( $site['name'] ); ?></strong><small><?php echo esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ); ?><?php if ( $inbox['checked'] ) : ?> · <?php printf( esc_html__( 'checked %s ago', 'fleet-for-openstation' ), esc_html( human_time_diff( $inbox['checked'], time() ) ) ); ?><?php endif; ?></small></span></div>
								<div class="fleet-inbox-site__actions"><span class="fleet-site-work-count"><?php printf( esc_html( _n( '%d item', '%d items', $count, 'fleet-for-openstation' ) ), esc_html( $count ) ); ?></span><a class="button button-secondary" href="<?php echo esc_url( self::workspace_url( $id ) ); ?>" target="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-id="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>"><?php esc_html_e( 'Open site', 'fleet-for-openstation' ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>
							</header>
							<div class="fleet-inbox-groups">
								<?php if ( ! empty( $attention ) ) : ?>
									<a class="fleet-inbox-group fleet-inbox-group--attention" href="<?php echo esc_url( self::workspace_url( $id ) ); ?>" target="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-id="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><strong><?php printf( esc_html( _n( '%d health item', '%d health items', count( $attention ), 'fleet-for-openstation' ) ), esc_html( count( $attention ) ) ); ?></strong><small><?php echo esc_html( implode( ' · ', array_slice( wp_list_pluck( $attention, 1 ), 0, 2 ) ) ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2 fleet-row-arrow" aria-hidden="true"></span></a>
								<?php endif; ?>
								<?php
								$groups = array(
									'pending_comments' => array( 'dashicons-admin-comments', __( 'Comments awaiting review', 'fleet-for-openstation' ), 'comments' ),
									'drafts'           => array( 'dashicons-edit-page', __( 'Draft posts', 'fleet-for-openstation' ), 'content' ),
									'pending_posts'    => array( 'dashicons-clock', __( 'Posts awaiting publication', 'fleet-for-openstation' ), 'content' ),
									'scheduled_posts'  => array( 'dashicons-calendar-alt', __( 'Scheduled posts', 'fleet-for-openstation' ), 'content' ),
								);
								foreach ( $groups as $key => $definition ) :
									if ( empty( $inbox[ $key ]['count'] ) && empty( $inbox[ $key ]['error'] ) ) {
										continue;
									}
									$examples = array();
									foreach ( array_slice( $inbox[ $key ]['items'], 0, 2 ) as $item ) {
										if ( 'pending_comments' === $key ) {
											$examples[] = isset( $item['author_name'] ) ? sanitize_text_field( $item['author_name'] ) : __( 'Comment', 'fleet-for-openstation' );
										} else {
											$examples[] = ! empty( $item['title']['rendered'] ) ? sanitize_text_field( wp_strip_all_tags( $item['title']['rendered'] ) ) : __( '(Untitled)', 'fleet-for-openstation' );
										}
									}
									?>
									<a class="fleet-inbox-group" href="<?php echo esc_url( self::workspace_url( $id, $definition[2] ) ); ?>" target="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-id="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>"><span class="dashicons <?php echo esc_attr( $definition[0] ); ?>" aria-hidden="true"></span><span><strong><?php echo esc_html( $inbox[ $key ]['count'] ); ?> <?php echo esc_html( $definition[1] ); ?></strong><small><?php echo ! empty( $inbox[ $key ]['error'] ) ? esc_html( $inbox[ $key ]['error'] ) : esc_html( implode( ' · ', $examples ) ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2 fleet-row-arrow" aria-hidden="true"></span></a>
								<?php endforeach; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render live search across Core content, users, comments, and media.
	 *
	 * @param array $sites Connected sites.
	 */
	private static function render_search_view( $sites ) {
		$query   = sanitize_text_field( self::request_string( $_GET, 'fleet_query' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$client  = sanitize_text_field( self::request_string( $_GET, 'fleet_client' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search scope.
		$clients = array();
		foreach ( $sites as $site ) {
			if ( ! empty( $site['agency']['client_name'] ) ) {
				$clients[] = $site['agency']['client_name'];
			}
		}
		$clients = array_values( array_unique( $clients ) );
		sort( $clients, SORT_NATURAL | SORT_FLAG_CASE );
		?>
		<section class="fleet-search-view">
			<div class="fleet-section-heading"><div><span class="fleet-eyebrow"><?php esc_html_e( 'Live Core REST search', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Search the fleet', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Find content, media, comments, and people without opening each WordPress site first.', 'fleet-for-openstation' ); ?></p></div></div>
			<form class="fleet-global-search" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="fleet_view" value="search">
				<label><span class="screen-reader-text"><?php esc_html_e( 'Search query', 'fleet-for-openstation' ); ?></span><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" name="fleet_query" value="<?php echo esc_attr( $query ); ?>" minlength="2" placeholder="<?php esc_attr_e( 'Search titles, files, comments, or people…', 'fleet-for-openstation' ); ?>" required></label>
				<select name="fleet_client" aria-label="<?php esc_attr_e( 'Client scope', 'fleet-for-openstation' ); ?>"><option value=""><?php esc_html_e( 'Every connected site', 'fleet-for-openstation' ); ?></option><?php foreach ( $clients as $client_name ) : ?><option value="<?php echo esc_attr( $client_name ); ?>" <?php selected( $client, $client_name ); ?>><?php echo esc_html( $client_name ); ?></option><?php endforeach; ?></select>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Search', 'fleet-for-openstation' ); ?></button>
			</form>
			<?php
			if ( '' === $query ) {
				?><div class="fleet-search-prompt"><span class="dashicons dashicons-search" aria-hidden="true"></span><h3><?php esc_html_e( 'One search, every site', 'fleet-for-openstation' ); ?></h3><p><?php esc_html_e( 'Fleet sends authenticated read-only searches only to the WordPress sites in your chosen scope.', 'fleet-for-openstation' ); ?></p></div><?php
				return;
			}

			$targets = array_filter(
				$sites,
				static function ( $site ) use ( $client ) {
					return '' === $client || $client === $site['agency']['client_name'];
				}
			);
			$limited = count( $targets ) > 25;
			$targets = array_slice( $targets, 0, 25, true );
			$results = array();
			foreach ( $targets as $id => $site ) {
				$results[ $id ] = self::search_site( $site, $query );
			}
			if ( $limited ) : ?>
				<div class="fleet-inline-error"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span><?php esc_html_e( 'This search covers the first 25 matching sites. Choose a client to narrow a larger fleet.', 'fleet-for-openstation' ); ?></span></div>
			<?php endif; ?>
			<div class="fleet-search-results">
				<?php $match_count = 0; ?>
				<?php foreach ( $results as $id => $items ) : ?>
					<?php
					$site      = $sites[ $id ];
					$window_id = self::site_window_id( $id );
					if ( ! is_wp_error( $items ) ) {
						$match_count += count( $items );
					}
					?>
					<article class="fleet-search-site">
						<header><div><strong><?php echo esc_html( $site['name'] ); ?></strong><small><?php echo esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ); ?></small></div><span class="fleet-count"><?php echo is_wp_error( $items ) ? '!' : esc_html( count( $items ) ); ?></span></header>
						<?php if ( is_wp_error( $items ) ) : ?>
							<p class="fleet-search-error"><?php echo esc_html( $items->get_error_message() ); ?></p>
						<?php elseif ( empty( $items ) ) : ?>
							<p class="fleet-search-none"><?php esc_html_e( 'No matches on this site.', 'fleet-for-openstation' ); ?></p>
						<?php else : ?>
							<div class="fleet-search-items">
								<?php foreach ( $items as $item ) : ?>
									<a href="<?php echo esc_url( self::workspace_url( $id, $item['section'] ) ); ?>" target="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-id="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>"><span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span><span><strong><?php echo esc_html( $item['title'] ); ?></strong><small><?php echo esc_html( $item['meta'] ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
			<?php if ( 0 === $match_count && ! empty( $targets ) ) : ?><p class="fleet-search-total"><?php printf( esc_html__( 'No matches for “%s” across the searched sites.', 'fleet-for-openstation' ), esc_html( $query ) ); ?></p><?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Search one site through its existing WordPress Core collections.
	 *
	 * @param array  $site  Site record.
	 * @param string $query Search query.
	 * @return array|WP_Error
	 */
	private static function search_site( $site, $query ) {
		$common   = array( 'search' => $query, 'per_page' => 8 );
		$requests = array();
		$keys     = array();
		if ( self::supports( $site, 'search' ) ) {
			$keys[]     = 'content';
			$requests[] = array( 'method' => 'GET', 'path' => '/wp/v2/search?' . http_build_query( $common, '', '&' ) );
		}
		if ( self::supports( $site, 'posts' ) ) {
			$keys[]     = 'posts';
			$requests[] = array( 'method' => 'GET', 'path' => '/wp/v2/posts?' . http_build_query( $common + array( 'context' => 'edit', 'status' => 'any', '_fields' => 'id,title,status,type' ), '', '&' ) );
		}
		if ( self::supports( $site, 'pages' ) ) {
			$keys[]     = 'pages';
			$requests[] = array( 'method' => 'GET', 'path' => '/wp/v2/pages?' . http_build_query( $common + array( 'context' => 'edit', 'status' => 'any', '_fields' => 'id,title,status,type' ), '', '&' ) );
		}
		if ( self::supports( $site, 'users' ) ) {
			$keys[]     = 'users';
			$requests[] = array( 'method' => 'GET', 'path' => '/wp/v2/users?' . http_build_query( $common + array( 'context' => 'edit', '_fields' => 'id,name,roles' ), '', '&' ) );
		}
		if ( self::supports( $site, 'comments' ) ) {
			$keys[]     = 'comments';
			$requests[] = array( 'method' => 'GET', 'path' => '/wp/v2/comments?' . http_build_query( $common + array( 'context' => 'edit', 'status' => 'all', '_fields' => 'id,author_name,content,date,status' ), '', '&' ) );
		}
		if ( self::supports( $site, 'media' ) ) {
			$keys[]     = 'media';
			$requests[] = array( 'method' => 'GET', 'path' => '/wp/v2/media?' . http_build_query( $common + array( 'context' => 'edit', '_fields' => 'id,title,media_type,mime_type' ), '', '&' ) );
		}
		if ( empty( $requests ) ) {
			return array();
		}
		$responses = self::remote_batch( $site, $requests );
		if ( is_wp_error( $responses ) ) {
			return $responses;
		}
		$named = array();
		$errors = array();
		foreach ( $keys as $index => $key ) {
			$response      = isset( $responses[ $index ] ) && is_array( $responses[ $index ] ) ? $responses[ $index ] : array();
			$named[ $key ] = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
			if ( ! empty( $response['error'] ) ) {
				$errors[] = sanitize_text_field( $response['error'] );
			}
		}
		if ( count( $errors ) === count( $keys ) ) {
			return new WP_Error( 'openstation_fleet_search_failed', reset( $errors ) );
		}
		return self::normalize_search_results( $named );
	}

	/**
	 * Normalize heterogeneous Core search collections for the hub UI.
	 *
	 * @param array $results Named Core REST collection results.
	 * @return array
	 */
	private static function normalize_search_results( $results ) {
		$items = array();
		$seen  = array();
		foreach ( isset( $results['content'] ) && is_array( $results['content'] ) ? $results['content'] : array() as $item ) {
			$subtype = isset( $item['subtype'] ) ? sanitize_key( $item['subtype'] ) : 'content';
			$key_type = 'attachment' === $subtype ? 'media' : $subtype;
			$key     = $key_type . ':' . ( isset( $item['id'] ) ? (string) $item['id'] : md5( wp_json_encode( $item ) ) );
			$seen[ $key ] = true;
			$items[]      = array( 'title' => isset( $item['title'] ) ? sanitize_text_field( wp_strip_all_tags( $item['title'] ) ) : __( '(Untitled)', 'fleet-for-openstation' ), 'meta' => ucfirst( $subtype ), 'section' => 'attachment' === $subtype ? 'media' : 'content', 'icon' => 'attachment' === $subtype ? 'dashicons-format-image' : ( 'page' === $subtype ? 'dashicons-admin-page' : 'dashicons-admin-post' ) );
		}
		foreach ( array( 'posts' => 'post', 'pages' => 'page' ) as $collection => $type ) {
			foreach ( isset( $results[ $collection ] ) && is_array( $results[ $collection ] ) ? $results[ $collection ] : array() as $item ) {
				$key = $type . ':' . ( isset( $item['id'] ) ? (string) $item['id'] : md5( wp_json_encode( $item ) ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$title        = ! empty( $item['title']['rendered'] ) ? sanitize_text_field( wp_strip_all_tags( $item['title']['rendered'] ) ) : __( '(Untitled)', 'fleet-for-openstation' );
				$status       = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : $type;
				$items[]      = array( 'title' => $title, 'meta' => ucfirst( $type ) . ' · ' . ucfirst( $status ), 'section' => 'content', 'icon' => 'page' === $type ? 'dashicons-admin-page' : 'dashicons-admin-post' );
			}
		}
		foreach ( isset( $results['users'] ) && is_array( $results['users'] ) ? $results['users'] : array() as $item ) {
			$roles   = isset( $item['roles'] ) && is_array( $item['roles'] ) ? implode( ', ', array_map( 'sanitize_key', $item['roles'] ) ) : __( 'WordPress user', 'fleet-for-openstation' );
			$items[] = array( 'title' => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : __( 'User', 'fleet-for-openstation' ), 'meta' => $roles, 'section' => 'users', 'icon' => 'dashicons-admin-users' );
		}
		foreach ( isset( $results['comments'] ) && is_array( $results['comments'] ) ? $results['comments'] : array() as $item ) {
			$content = ! empty( $item['content']['rendered'] ) ? sanitize_text_field( wp_strip_all_tags( $item['content']['rendered'] ) ) : __( 'Comment', 'fleet-for-openstation' );
			$items[] = array( 'title' => isset( $item['author_name'] ) ? sanitize_text_field( $item['author_name'] ) : __( 'Comment', 'fleet-for-openstation' ), 'meta' => $content, 'section' => 'comments', 'icon' => 'dashicons-admin-comments' );
		}
		foreach ( isset( $results['media'] ) && is_array( $results['media'] ) ? $results['media'] : array() as $item ) {
			$key = 'media:' . ( isset( $item['id'] ) ? (string) $item['id'] : md5( wp_json_encode( $item ) ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[] = array( 'title' => ! empty( $item['title']['rendered'] ) ? sanitize_text_field( wp_strip_all_tags( $item['title']['rendered'] ) ) : __( '(Untitled media)', 'fleet-for-openstation' ), 'meta' => isset( $item['mime_type'] ) ? sanitize_text_field( $item['mime_type'] ) : __( 'Media', 'fleet-for-openstation' ), 'section' => 'media', 'icon' => 'dashicons-format-image' );
		}
		return array_slice( $items, 0, 24 );
	}

	/**
	 * Render persistent client groups as one-click OpenStation workspaces.
	 *
	 * @param array $sites Connected sites.
	 */
	private static function render_workspaces_view( $sites ) {
		$groups = array();
		foreach ( $sites as $id => $site ) {
			$client = trim( (string) $site['agency']['client_name'] );
			$client = '' !== $client ? $client : __( 'Unassigned sites', 'fleet-for-openstation' );
			if ( ! isset( $groups[ $client ] ) ) {
				$groups[ $client ] = array();
			}
			$groups[ $client ][ $id ] = $site;
		}
		uksort( $groups, 'strnatcasecmp' );
		?>
		<section class="fleet-workspaces-view">
			<div class="fleet-section-heading"><div><span class="fleet-eyebrow"><?php esc_html_e( 'OpenStation window sets', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Client workspaces', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Open every site for a client as a separate, persistent OpenStation window.', 'fleet-for-openstation' ); ?></p></div><span class="fleet-count"><?php echo esc_html( count( $groups ) ); ?></span></div>
			<?php if ( empty( $groups ) ) : ?>
				<div class="fleet-empty"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span><h3><?php esc_html_e( 'No workspaces yet', 'fleet-for-openstation' ); ?></h3><p><?php esc_html_e( 'Connect a site, then give it a client name under Agency to create a reusable workspace.', 'fleet-for-openstation' ); ?></p></div>
			<?php else : ?>
				<div class="fleet-workspace-groups">
					<?php foreach ( $groups as $client => $client_sites ) : ?>
						<article class="fleet-workspace-group">
							<header><span class="fleet-workspace-group__icon"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span></span><span><strong><?php echo esc_html( $client ); ?></strong><small><?php printf( esc_html( _n( '%d connected site', '%d connected sites', count( $client_sites ), 'fleet-for-openstation' ) ), esc_html( count( $client_sites ) ) ); ?></small></span></header>
							<ul><?php foreach ( $client_sites as $site ) : ?><li><span class="fleet-status-dot" aria-hidden="true"></span><?php echo esc_html( $site['name'] ); ?></li><?php endforeach; ?></ul>
							<button class="button button-primary" type="button" data-fleet-open-workspace><?php esc_html_e( 'Open workspace', 'fleet-for-openstation' ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
							<div hidden data-fleet-workspace-links>
								<?php foreach ( $client_sites as $id => $site ) : ?><a href="<?php echo esc_url( self::workspace_url( $id ) ); ?>" target="<?php echo esc_attr( self::site_window_id( $id ) ); ?>" data-fleet-window-id="<?php echo esc_attr( self::site_window_id( $id ) ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>"><?php echo esc_html( $site['name'] ); ?></a><?php endforeach; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Start the WordPress Core Application Password approval flow.
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
		$existing_id = self::site_id( $discovery['site_url'] );
		$sites       = self::get_sites();
		if ( isset( $sites[ $existing_id ] ) ) {
			wp_safe_redirect(
				self::hub_url(
					array(
						'fleet_notice'      => 'already_connected',
						'fleet_launch_site' => $existing_id,
					)
				)
			);
			exit;
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

		set_transient(
			self::pending_key( get_current_user_id(), $state ),
			$discovery,
			10 * MINUTE_IN_SECONDS
		);

		wp_redirect( $authorize ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated same-origin external authorization endpoint.
		exit;
	}

	/**
	 * Store and verify the approved Application Password, then make the site
	 * OpenStation-ready before returning to the hub.
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
		$password = null;
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
			'setup_status'    => 'pending',
			'last_checked'    => 0,
			'error'           => '',
		);

		$credential = self::remote_request( $site, 'GET', 'wp/v2/users/me/application-passwords/introspect' );
		if ( is_wp_error( $credential ) || empty( $credential['uuid'] ) ) {
			self::redirect( 'credential_failed' );
		}
		$site['credential_uuid'] = sanitize_text_field( $credential['uuid'] );
		$site_id          = self::site_id( $returned_url );
		$sites            = self::get_sites();
		$sites[ $site_id ] = $site;
		self::save_sites( $sites );
		self::record_activity( $site_id, $site, 'connected', __( 'Site connected with WordPress Core.', 'fleet-for-openstation' ) );
		self::redirect_workspace( 'connected', $site_id, 'overview' );
	}

	/**
	 * Finish a newly approved site's setup while the browser shows progress.
	 */
	public static function handle_finish_setup() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage Fleet.', 'fleet-for-openstation' ) ), 403 );
		}
		$id = sanitize_key( self::request_string( $_POST, 'site_id' ) );
		check_ajax_referer( 'openstation_fleet_finish_setup_' . $id );
		$sites = self::get_sites();
		if ( '' === $id || ! isset( $sites[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That Fleet site does not exist.', 'fleet-for-openstation' ) ), 404 );
		}

		$site   = $sites[ $id ];
		$result = self::install_openstation( $site );
		if ( is_wp_error( $result ) ) {
			$site['setup_status'] = 'error';
			$sites[ $id ]         = $site;
			self::save_sites( $sites );
			self::record_activity( $id, $site, 'openstation', __( 'Automatic OpenStation installation failed.', 'fleet-for-openstation' ), 'error' );
			wp_send_json_success(
				array(
					'redirect' => self::hub_url( array( 'fleet_notice' => 'connected_install_failed' ) ),
				)
			);
		}

		$site                 = self::refresh_site( $site );
		$site['setup_status'] = empty( $site['error'] ) ? 'ready' : 'error';
		$sites[ $id ]         = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'openstation', empty( $site['error'] ) ? __( 'OpenStation is installed, active, and ready.', 'fleet-for-openstation' ) : __( 'OpenStation was activated, but the first site check failed.', 'fleet-for-openstation' ), empty( $site['error'] ) ? 'success' : 'warning' );
		wp_send_json_success(
			array(
				'redirect' => self::workspace_url( $id, 'overview', empty( $site['error'] ) ? 'connected_ready' : 'check_failed' ),
			)
		);
	}

	/**
	 * Refresh one site's cached plugin status.
	 */
	public static function handle_check() {
		self::guard_action( 'openstation_fleet_check' );
		list( $id, $site, $sites ) = self::requested_site();
		$sites[ $id ]              = self::refresh_site( $site );
		self::save_sites( $sites );
		self::record_activity( $id, $sites[ $id ], 'check', empty( $sites[ $id ]['error'] ) ? __( 'Site status refreshed.', 'fleet-for-openstation' ) : __( 'Site check failed.', 'fleet-for-openstation' ), empty( $sites[ $id ]['error'] ) ? 'success' : 'error' );
		self::redirect( empty( $sites[ $id ]['error'] ) ? 'checked' : 'check_failed' );
	}

	/**
	 * Run a safe action across selected connected sites.
	 */
	public static function handle_bulk() {
		self::guard_action( 'openstation_fleet_bulk' );
		$bulk_action = sanitize_key( self::request_string( $_POST, 'bulk_action' ) );
		$raw_ids     = isset( $_POST['site_ids'] ) && is_array( $_POST['site_ids'] ) ? wp_unslash( $_POST['site_ids'] ) : array();
		$site_ids    = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', $raw_ids ) ) ) ), 0, 25 );
		if ( ! in_array( $bulk_action, array( 'check', 'install' ), true ) || empty( $site_ids ) ) {
			self::redirect( 'bulk_failed' );
		}

		$sites   = self::get_sites();
		$failed  = 0;
		$changed = 0;
		foreach ( $site_ids as $id ) {
			if ( ! isset( $sites[ $id ] ) ) {
				continue;
			}
			$site = $sites[ $id ];
			if ( 'check' === $bulk_action ) {
				$site = self::refresh_site( $site );
				$ok   = empty( $site['error'] );
			} else {
				$result = self::install_openstation( $site );
				$ok     = ! is_wp_error( $result );
			}
			$sites[ $id ] = $site;
			if ( $ok ) {
				++$changed;
			} else {
				++$failed;
			}
			self::record_activity( $id, $site, 'bulk_' . $bulk_action, $ok ? __( 'Fleet bulk action completed.', 'fleet-for-openstation' ) : __( 'Fleet bulk action failed.', 'fleet-for-openstation' ), $ok ? 'success' : 'error' );
		}
		self::save_sites( $sites );
		set_transient( 'openstation_fleet_bulk_' . get_current_user_id(), array( 'changed' => $changed, 'failed' => $failed ), 5 * MINUTE_IN_SECONDS );
		self::redirect( $failed ? 'bulk_partial' : 'bulk_complete' );
	}

	/**
	 * Install or activate OpenStation without redirecting.
	 *
	 * @param array $site Connected site record, updated in place.
	 * @return true|WP_Error
	 */
	private static function install_openstation( &$site ) {
		$plugins = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' );
		if ( is_wp_error( $plugins ) ) {
			$site['error'] = $plugins->get_error_message();
			return $plugins;
		}
		$status = self::inspect_plugins( $plugins );
		if ( 'active' === $status['status'] ) {
			$site['openstation']  = $status;
			$site['error']        = '';
			$site['last_checked'] = time();
			return true;
		}
		$result = 'missing' === $status['status']
			? self::remote_request( $site, 'POST', 'wp/v2/plugins', array( 'slug' => self::PLUGIN_SLUG, 'status' => 'active' ) )
			: self::remote_request( $site, 'POST', 'wp/v2/plugins/' . self::PLUGIN_REST_ID, array( 'status' => 'active' ) );
		$site['last_checked'] = time();
		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			return $result;
		}
		$site['openstation'] = self::inspect_plugins( array( $result ) );
		$site['error']       = '';
		return true;
	}

	/**
	 * Save hub-side agency metadata for a connected site.
	 */
	public static function handle_update_profile() {
		self::guard_action( 'openstation_fleet_update_profile' );
		list( $id, $site, $sites ) = self::requested_site();
		$plan = sanitize_key( self::request_string( $_POST, 'plan_status' ) );
		$tags = preg_split( '/[,\r\n]+/', self::request_string( $_POST, 'tags' ) );
		$site['agency'] = array(
			'client_name' => sanitize_text_field( self::request_string( $_POST, 'client_name' ) ),
			'tags'        => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', is_array( $tags ) ? $tags : array() ) ) ) ),
			'plan_status' => in_array( $plan, array( 'none', 'active', 'paused', 'ended' ), true ) ? $plan : 'none',
			'notes'       => sanitize_textarea_field( self::request_string( $_POST, 'notes' ) ),
			'favorite'    => isset( $_POST['favorite'] ),
		);
		$sites[ $id ] = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'profile', __( 'Agency profile updated.', 'fleet-for-openstation' ) );
		self::redirect_workspace( 'profile_updated', $id, 'agency' );
	}

	/**
	 * Toggle a site's favorite state from the manifest.
	 */
	public static function handle_toggle_favorite() {
		self::guard_action( 'openstation_fleet_toggle_favorite' );
		list( $id, $site, $sites ) = self::requested_site();
		$site['agency']['favorite'] = empty( $site['agency']['favorite'] );
		$sites[ $id ]               = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'favorite', $site['agency']['favorite'] ? __( 'Site added to favorites.', 'fleet-for-openstation' ) : __( 'Site removed from favorites.', 'fleet-for-openstation' ) );
		self::redirect( 'profile_updated' );
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
		self::record_activity(
			$id,
			$site,
			'openstation',
			is_wp_error( $result ) ? __( 'OpenStation installation failed.', 'fleet-for-openstation' ) : __( 'OpenStation installed and activated.', 'fleet-for-openstation' ),
			is_wp_error( $result ) ? 'error' : 'success'
		);
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
		self::record_activity( $id, $site, 'settings', is_wp_error( $result ) ? __( 'Remote settings update failed.', 'fleet-for-openstation' ) : __( 'Remote site settings updated.', 'fleet-for-openstation' ), is_wp_error( $result ) ? 'error' : 'success' );
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
			&& in_array( $status, array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ), true );

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
		self::record_activity( $id, $site, 'content', is_wp_error( $result ) ? __( 'Remote content update failed.', 'fleet-for-openstation' ) : sprintf( __( '%s #%d updated.', 'fleet-for-openstation' ), ucfirst( $content_type ), $content_id ), is_wp_error( $result ) ? 'error' : 'success' );
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
		self::record_activity( $id, $site, 'plugin', is_wp_error( $result ) ? __( 'Remote plugin change failed.', 'fleet-for-openstation' ) : sprintf( __( '%1$s set to %2$s.', 'fleet-for-openstation' ), $plugin, $status ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( $notice, $id, 'plugins' );
	}

	/**
	 * Install a WordPress.org plugin through the Core Plugins endpoint.
	 */
	public static function handle_install_plugin() {
		self::guard_action( 'openstation_fleet_install_plugin' );
		list( $id, $site, $sites ) = self::requested_site();
		$slug   = sanitize_key( self::request_string( $_POST, 'plugin_slug' ) );
		$status = sanitize_key( self::request_string( $_POST, 'status' ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $slug ) || ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			self::redirect_workspace( 'plugin_install_failed', $id, 'plugins' );
		}
		$result = self::remote_request( $site, 'POST', 'wp/v2/plugins', array( 'slug' => $slug, 'status' => $status ) );
		if ( is_wp_error( $result ) ) {
			$site['error'] = $result->get_error_message();
			$notice        = 'plugin_install_failed';
		} else {
			$site['error'] = '';
			$notice        = 'plugin_installed';
			if ( self::PLUGIN_SLUG === $slug ) {
				$site['openstation'] = self::inspect_plugins( array( $result ) );
			}
		}
		$site['last_checked'] = time();
		$sites[ $id ]         = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'plugin_install', is_wp_error( $result ) ? sprintf( __( 'Plugin %s could not be installed.', 'fleet-for-openstation' ), $slug ) : sprintf( __( 'Plugin %s installed.', 'fleet-for-openstation' ), $slug ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( $notice, $id, 'plugins' );
	}

	/**
	 * Moderate a remote comment through Core REST.
	 */
	public static function handle_update_comment() {
		self::guard_action( 'openstation_fleet_update_comment' );
		list( $id, $site, $sites ) = self::requested_site();
		$comment_id = absint( self::request_string( $_POST, 'comment_id' ) );
		$status     = sanitize_key( self::request_string( $_POST, 'status' ) );
		if ( $comment_id < 1 || ! in_array( $status, array( 'approved', 'hold', 'spam', 'trash' ), true ) ) {
			self::redirect_workspace( 'comment_failed', $id, 'comments' );
		}
		$result        = self::remote_request( $site, 'POST', 'wp/v2/comments/' . $comment_id, array( 'status' => $status ) );
		$site['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$sites[ $id ]  = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'comment', is_wp_error( $result ) ? __( 'Comment moderation failed.', 'fleet-for-openstation' ) : sprintf( __( 'Comment moved to %s.', 'fleet-for-openstation' ), $status ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( is_wp_error( $result ) ? 'comment_failed' : 'comment_updated', $id, 'comments' );
	}

	/**
	 * Update remote media metadata.
	 */
	public static function handle_update_media() {
		self::guard_action( 'openstation_fleet_update_media' );
		list( $id, $site, $sites ) = self::requested_site();
		$media_id = absint( self::request_string( $_POST, 'media_id' ) );
		if ( $media_id < 1 ) {
			self::redirect_workspace( 'media_failed', $id, 'media' );
		}
		$result = self::remote_request(
			$site,
			'POST',
			'wp/v2/media/' . $media_id,
			array(
				'title'    => sanitize_text_field( self::request_string( $_POST, 'title' ) ),
				'alt_text' => sanitize_text_field( self::request_string( $_POST, 'alt_text' ) ),
				'caption'  => sanitize_textarea_field( self::request_string( $_POST, 'caption' ) ),
			)
		);
		$site['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$sites[ $id ]  = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'media', is_wp_error( $result ) ? __( 'Media update failed.', 'fleet-for-openstation' ) : __( 'Media details updated.', 'fleet-for-openstation' ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( is_wp_error( $result ) ? 'media_failed' : 'media_updated', $id, 'media' );
	}

	/**
	 * Upload a file directly from the hub request to the managed Media Library.
	 */
	public static function handle_upload_media() {
		self::guard_action( 'openstation_fleet_upload_media' );
		list( $id, $site, $sites ) = self::requested_site();
		$file = isset( $_FILES['media_file'] ) && is_array( $_FILES['media_file'] ) ? $_FILES['media_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below before reading.
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) || empty( $file['size'] ) || (int) $file['size'] > 10 * MB_IN_BYTES ) {
			self::redirect_workspace( 'media_upload_failed', $id, 'media' );
		}
		$filename = sanitize_file_name( isset( $file['name'] ) ? wp_unslash( $file['name'] ) : '' );
		$type     = wp_check_filetype_and_ext( $file['tmp_name'], $filename );
		$mime     = ! empty( $type['type'] ) ? $type['type'] : '';
		if ( '' === $filename || ! preg_match( '#^(image|audio|video)/|^application/pdf$#', $mime ) ) {
			self::redirect_workspace( 'media_upload_failed', $id, 'media' );
		}
		$result        = self::remote_upload( $site, $file['tmp_name'], $filename, $mime );
		$site['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$sites[ $id ]  = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'media_upload', is_wp_error( $result ) ? __( 'Media upload failed.', 'fleet-for-openstation' ) : sprintf( __( '%s uploaded to the Media Library.', 'fleet-for-openstation' ), $filename ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( is_wp_error( $result ) ? 'media_upload_failed' : 'media_uploaded', $id, 'media' );
	}

	/**
	 * Create a remote WordPress user.
	 */
	public static function handle_create_user() {
		self::guard_action( 'openstation_fleet_create_user' );
		list( $id, $site, $sites ) = self::requested_site();
		$username = sanitize_user( self::request_string( $_POST, 'username' ), true );
		$email    = sanitize_email( self::request_string( $_POST, 'email' ) );
		$password = self::request_string( $_POST, 'password' );
		$role     = sanitize_key( self::request_string( $_POST, 'role' ) );
		if ( '' === $username || ! is_email( $email ) || strlen( $password ) < 12 || ! in_array( $role, self::editable_roles(), true ) ) {
			self::redirect_workspace( 'user_failed', $id, 'users' );
		}
		$result   = self::remote_request( $site, 'POST', 'wp/v2/users', array( 'username' => $username, 'email' => $email, 'password' => $password, 'roles' => array( $role ) ) );
		$password = null;
		$site['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$sites[ $id ]  = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'user_create', is_wp_error( $result ) ? __( 'User creation failed.', 'fleet-for-openstation' ) : sprintf( __( 'User %s created.', 'fleet-for-openstation' ), $username ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( is_wp_error( $result ) ? 'user_failed' : 'user_created', $id, 'users' );
	}

	/**
	 * Update a remote user's display name, email, and role.
	 */
	public static function handle_update_user() {
		self::guard_action( 'openstation_fleet_update_user' );
		list( $id, $site, $sites ) = self::requested_site();
		$user_id = absint( self::request_string( $_POST, 'user_id' ) );
		$name    = sanitize_text_field( self::request_string( $_POST, 'name' ) );
		$email   = sanitize_email( self::request_string( $_POST, 'email' ) );
		$role    = sanitize_key( self::request_string( $_POST, 'role' ) );
		if ( $user_id < 1 || '' === $name || ! is_email( $email ) || ! in_array( $role, self::editable_roles(), true ) ) {
			self::redirect_workspace( 'user_failed', $id, 'users' );
		}
		$result        = self::remote_request( $site, 'POST', 'wp/v2/users/' . $user_id, array( 'name' => $name, 'email' => $email, 'roles' => array( $role ) ) );
		$site['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
		$sites[ $id ]  = $site;
		self::save_sites( $sites );
		self::record_activity( $id, $site, 'user_update', is_wp_error( $result ) ? __( 'User update failed.', 'fleet-for-openstation' ) : sprintf( __( 'User #%d updated.', 'fleet-for-openstation' ), $user_id ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( is_wp_error( $result ) ? 'user_failed' : 'user_updated', $id, 'users' );
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
		self::record_activity( $id, $site, 'api', is_wp_error( $result ) ? sprintf( __( '%1$s /%2$s failed.', 'fleet-for-openstation' ), $method, ltrim( $route, '/' ) ) : sprintf( __( '%1$s /%2$s completed.', 'fleet-for-openstation' ), $method, ltrim( $route, '/' ) ), is_wp_error( $result ) ? 'error' : 'success' );
		self::redirect_workspace( is_wp_error( $result ) ? 'api_failed' : 'api_complete', $id, 'api' );
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
		self::record_activity( $id, $site, 'disconnected', __( 'Remote authorization revoked and site disconnected.', 'fleet-for-openstation' ) );
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
	 * Discover REST and Application Password endpoints from the Core REST index.
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

			$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : wp_parse_url( $reported_url, PHP_URL_HOST );
			$authorization_url = isset( $data['authentication']['application-passwords']['endpoints']['authorization'] )
				? $data['authentication']['application-passwords']['endpoints']['authorization']
				: '';
			if ( self::is_same_origin_https_url( $authorization_url, $reported_url ) ) {
				return array(
					'name'              => $name,
					'site_url'          => $reported_url,
					'rest_url'          => $rest_url,
					'authorization_url' => esc_url_raw( $authorization_url ),
				);
			}
		}

		return new WP_Error( 'openstation_fleet_discovery_failed', __( 'Could not find WordPress’s secure Application Password approval on that site.', 'fleet-for-openstation' ) );
	}

	/**
	 * Refresh cached OpenStation status for one site.
	 *
	 * @param array $site           Site record.
	 * @param bool  $refresh_health Run the heavier Core Site Health tests now.
	 * @return array
	 */
	private static function refresh_site( $site, $refresh_health = true ) {
		$site                 = self::normalize_site_record( $site );
		$site['last_checked'] = time();
		$root                 = self::remote_request( $site, 'GET', '' );
		if ( is_wp_error( $root ) ) {
			$site['error'] = $root->get_error_message();
			return $site;
		}

		$site['capabilities']      = self::discover_capabilities( $root );
		$site['wordpress_version'] = self::wordpress_version_from_root( $root );
		$plugins                   = self::remote_request( $site, 'GET', 'wp/v2/plugins?context=edit' );
		if ( is_wp_error( $plugins ) ) {
			$site['error'] = $plugins->get_error_message();
			return $site;
		}

		$site['openstation'] = self::inspect_plugins( $plugins );
		$site['inbox']       = self::fetch_inbox_summary( $site );
		if ( $refresh_health || $site['health_checked'] < time() - ( 6 * HOUR_IN_SECONDS ) ) {
			$site['health']         = self::fetch_site_health( $site );
			$site['health_checked'] = time();
		}
		$site['error'] = '';
		return $site;
	}

	/**
	 * Refresh connected sites for every Fleet user through WordPress Cron.
	 */
	public static function run_scheduled_checks() {
		$user_ids      = get_users(
			array(
				'fields'   => 'ids',
				'meta_key' => self::USER_META_SITES,
			)
		);
		$original_user = get_current_user_id();
		foreach ( $user_ids as $user_id ) {
			wp_set_current_user( (int) $user_id );
			$sites = self::get_sites();
			foreach ( $sites as $id => $site ) {
				$before       = count( self::attention_reasons( $site ) );
				$sites[ $id ] = self::refresh_site( $site, false );
				$after        = count( self::attention_reasons( $sites[ $id ] ) );
				if ( $before !== $after ) {
					self::record_activity(
						(string) $id,
						$sites[ $id ],
						'health',
						$after > $before ? __( 'New attention item detected.', 'fleet-for-openstation' ) : __( 'A Fleet attention item was resolved.', 'fleet-for-openstation' ),
						$after > $before ? 'warning' : 'success'
					);
				}
			}
			self::save_sites( $sites );
		}
		wp_set_current_user( $original_user );
	}

	/**
	 * Summarize REST routes advertised by a connected site.
	 *
	 * @param array $root REST index document.
	 * @return array
	 */
	private static function discover_capabilities( $root ) {
		$routes = isset( $root['routes'] ) && is_array( $root['routes'] ) ? array_keys( $root['routes'] ) : array();
		$has    = static function ( $route ) use ( $routes ) {
			return in_array( $route, $routes, true );
		};
		return array(
			'batch'       => $has( '/batch/v1' ),
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
			'styles'      => 0 < count( array_filter( $routes, static function ( $route ) { return 0 === strpos( $route, '/wp/v2/global-styles/' ); } ) ),
			'fonts'       => $has( '/wp/v2/font-families' ),
			'statuses'    => $has( '/wp/v2/statuses' ),
			'site_health' => $has( '/wp-site-health/v1/tests/background-updates' ),
			'route_count' => count( $routes ),
			'namespaces'  => isset( $root['namespaces'] ) && is_array( $root['namespaces'] ) ? array_values( array_map( 'sanitize_text_field', $root['namespaces'] ) ) : array(),
		);
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
	 * @param array $site Connected site record.
	 * @return array
	 */
	private static function fetch_site_health( &$site ) {
		if ( empty( $site['capabilities']['site_health'] ) ) {
			return array();
		}
		$tests = array(
			'background-updates'   => 'wp-site-health/v1/tests/background-updates',
			'loopback-requests'    => 'wp-site-health/v1/tests/loopback-requests',
			'https-status'         => 'wp-site-health/v1/tests/https-status',
			'dotorg-communication' => 'wp-site-health/v1/tests/dotorg-communication',
			'authorization-header' => 'wp-site-health/v1/tests/authorization-header',
		);
		$results = array();
		foreach ( $tests as $key => $route ) {
			$result = self::remote_request( $site, 'GET', $route );
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
		return $results;
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
			'pending_posts'     => $empty_collection,
			'scheduled_posts'   => $empty_collection,
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
			$collection = isset( $summary[ $key ] ) && is_array( $summary[ $key ] ) ? $summary[ $key ] : array();
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
	 * @param array $site Site record.
	 * @return array
	 */
	private static function fetch_inbox_summary( $site ) {
		$summary  = self::empty_inbox_summary();
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
			foreach ( array( 'drafts' => 'draft', 'pending_posts' => 'pending', 'scheduled_posts' => 'future' ) as $key => $status ) {
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

		$responses = self::remote_batch( $site, $requests );
		if ( is_wp_error( $responses ) ) {
			foreach ( $keys as $key ) {
				$summary[ $key ]['error'] = $responses->get_error_message();
			}
			return $summary;
		}
		foreach ( $keys as $index => $key ) {
			$summary[ $key ] = self::collection_summary( isset( $responses[ $index ] ) ? $responses[ $index ] : array() );
		}
		return $summary;
	}

	/**
	 * Send existing REST requests through Core's batch route where available.
	 *
	 * @param array $site     Site record.
	 * @param array $requests Core REST subrequests.
	 * @return array|WP_Error Response envelopes in request order.
	 */
	private static function remote_batch( $site, $requests ) {
		if ( empty( $requests ) ) {
			return array();
		}
		if ( self::supports( $site, 'batch' ) ) {
			$result = self::remote_request( $site, 'POST', 'batch/v1', array( 'requests' => array_values( $requests ) ) );
			if ( ! is_wp_error( $result ) && isset( $result['responses'] ) && is_array( $result['responses'] ) && count( $result['responses'] ) === count( $requests ) ) {
				$envelopes = array();
				foreach ( $result['responses'] as $response ) {
					$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
					$body   = isset( $response['body'] ) ? $response['body'] : array();
					$error  = '';
					if ( $status < 200 || $status >= 300 ) {
						$error = is_array( $body ) && ! empty( $body['message'] ) ? sanitize_text_field( wp_strip_all_tags( $body['message'] ) ) : sprintf( __( 'WordPress returned HTTP %d.', 'fleet-for-openstation' ), $status );
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
			$method = isset( $request['method'] ) ? strtoupper( (string) $request['method'] ) : 'GET';
			$path   = isset( $request['path'] ) ? ltrim( (string) $request['path'], '/' ) : '';
			$body   = isset( $request['body'] ) && is_array( $request['body'] ) ? $request['body'] : null;
			$result = self::remote_request( $site, $method, $path, $body );
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
			$reasons[] = array( 'wordpress', sprintf( __( 'WordPress %s is older than the hub', 'fleet-for-openstation' ), $remote_version ), 'recommended' );
		}
		return $reasons;
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
		$credential = self::open_secret( isset( $site['secret'] ) ? (string) $site['secret'] : '' );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		$authorization = 'Basic ' . base64_encode( (string) $site['user_login'] . ':' . $credential );

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
	 * Upload one validated local request file to a managed Media Library.
	 *
	 * @param array  $site     Connected site record.
	 * @param string $tmp_name PHP upload temporary path.
	 * @param string $filename Sanitized filename.
	 * @param string $mime     Validated MIME type.
	 * @return array|WP_Error
	 */
	private static function remote_upload( &$site, $tmp_name, $filename, $mime ) {
		$contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local validated upload, not a URL.
		if ( false === $contents ) {
			return new WP_Error( 'openstation_fleet_upload_read_failed', __( 'Fleet could not read the uploaded file.', 'fleet-for-openstation' ) );
		}
		$credential = self::open_secret( isset( $site['secret'] ) ? (string) $site['secret'] : '' );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		$authorization = 'Basic ' . base64_encode( (string) $site['user_login'] . ':' . $credential );
		$args = array(
			'method'      => 'POST',
			'timeout'     => 60,
			'redirection' => 3,
			'headers'     => array(
				'Accept'              => 'application/json',
				'Authorization'       => $authorization,
				'Content-Type'        => $mime,
				'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode( $filename ),
			),
			'body'        => $contents,
			'data_format' => 'body',
		);
		$credential = null;
		$contents   = null;
		$response   = wp_safe_remote_request( self::api_url( $site, 'wp/v2/media' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'openstation_fleet_media_upload_failed', is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : __( 'The managed site rejected the media upload.', 'fleet-for-openstation' ) );
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
	 * Render the short installation handoff after WordPress approval.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Connected site record.
	 */
	private static function render_site_setup( $id, $site ) {
		?>
		<div
			class="wrap fleet-app fleet-setup"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-site-id="<?php echo esc_attr( $id ); ?>"
			data-window-id="<?php echo esc_attr( self::site_window_id( $id ) ); ?>"
			data-window-title="<?php echo esc_attr( $site['name'] ); ?>"
			data-hub-url="<?php echo esc_url( self::hub_url() ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'openstation_fleet_finish_setup_' . $id ) ); ?>"
			data-messages="<?php echo esc_attr( wp_json_encode( array( __( 'Verifying the secure WordPress connection…', 'fleet-for-openstation' ), __( 'Installing OpenStation through WordPress…', 'fleet-for-openstation' ), __( 'Checking the site’s management capabilities…', 'fleet-for-openstation' ), __( 'Preparing the remote workspace…', 'fleet-for-openstation' ) ) ) ); ?>"
			data-complete-message="<?php esc_attr_e( 'OpenStation is ready. Opening the site workspace…', 'fleet-for-openstation' ); ?>"
			data-error-message="<?php esc_attr_e( 'WordPress could not finish the setup request.', 'fleet-for-openstation' ); ?>"
		>
			<div class="fleet-setup__card">
				<div class="fleet-setup__orbit" aria-hidden="true">
					<span class="fleet-setup__hub"><span class="dashicons dashicons-networking"></span></span>
					<span class="fleet-setup__site"><span class="dashicons dashicons-admin-site-alt3"></span></span>
				</div>
				<span class="fleet-eyebrow"><?php esc_html_e( 'Connection approved', 'fleet-for-openstation' ); ?></span>
				<h1><?php printf( esc_html__( 'Bringing %s into Fleet', 'fleet-for-openstation' ), esc_html( $site['name'] ) ); ?></h1>
				<p class="fleet-setup__message" data-fleet-setup-message><?php esc_html_e( 'Verifying the secure WordPress connection…', 'fleet-for-openstation' ); ?></p>
				<ol class="fleet-setup__steps" aria-label="<?php esc_attr_e( 'Setup progress', 'fleet-for-openstation' ); ?>">
					<li class="is-active" data-fleet-setup-step="0"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Connection approved', 'fleet-for-openstation' ); ?></li>
					<li data-fleet-setup-step="1"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Install OpenStation', 'fleet-for-openstation' ); ?></li>
					<li data-fleet-setup-step="2"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Check site capabilities', 'fleet-for-openstation' ); ?></li>
					<li data-fleet-setup-step="3"><span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Open workspace', 'fleet-for-openstation' ); ?></li>
				</ol>
				<p class="fleet-setup__note"><?php esc_html_e( 'Keep this window open. The first WordPress.org download can take a moment.', 'fleet-for-openstation' ); ?></p>
				<div class="fleet-setup__error" data-fleet-setup-error hidden>
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<div><strong><?php esc_html_e( 'Setup needs another try', 'fleet-for-openstation' ); ?></strong><p data-fleet-setup-error-message></p></div>
					<a class="button button-primary" href="<?php echo esc_url( self::hub_url() ); ?>"><?php esc_html_e( 'Return to Fleet', 'fleet-for-openstation' ); ?></a>
				</div>
				<noscript><p><a class="button button-primary" href="<?php echo esc_url( self::hub_url() ); ?>"><?php esc_html_e( 'Return to Fleet to finish setup', 'fleet-for-openstation' ); ?></a></p></noscript>
			</div>
		</div>
		<?php
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
		$attention  = self::attention_reasons( $site );
		$agency     = $site['agency'];
		$auth_label = __( 'Application Password', 'fleet-for-openstation' );
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
		$window_id = self::site_window_id( $id );
		?>
		<article class="fleet-site-card">
			<div class="fleet-site-card__identity">
				<label class="fleet-site-select"><span class="screen-reader-text"><?php printf( esc_html__( 'Select %s', 'fleet-for-openstation' ), esc_html( $site['name'] ) ); ?></span><input form="fleet-bulk-form" name="site_ids[]" type="checkbox" value="<?php echo esc_attr( $id ); ?>"></label>
				<span class="fleet-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><span class="fleet-site-icon__status fleet-site-icon__status--<?php echo esc_attr( $status ); ?>"></span></span>
				<div>
					<h3><?php echo ! empty( $agency['favorite'] ) ? '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>' : ''; ?><?php echo esc_html( $site['name'] ); ?></h3>
					<a href="<?php echo esc_url( $site['site_url'] ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ); ?><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
					<?php if ( $agency['client_name'] || $agency['tags'] ) : ?><small class="fleet-site-card__agency"><?php echo esc_html( implode( ' · ', array_filter( array( $agency['client_name'], implode( ', ', array_slice( $agency['tags'], 0, 2 ) ) ) ) ) ); ?></small><?php endif; ?>
				</div>
			</div>
			<div class="fleet-site-card__state">
				<span class="fleet-pill fleet-pill--<?php echo esc_attr( $status ); ?>"><span class="fleet-status-dot" aria-hidden="true"></span><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'] ); ?></span>
				<?php if ( ! empty( $attention ) ) : ?><span class="fleet-attention-count"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php printf( esc_html( _n( '%d attention item', '%d attention items', count( $attention ), 'fleet-for-openstation' ) ), esc_html( count( $attention ) ) ); ?></span><?php endif; ?>
				<small class="fleet-site-card__checked">
					<?php
					if ( $site['last_checked'] ) {
						printf(
							/* translators: 1: human-readable time difference, 2: authentication type. */
							esc_html__( '%1$s ago · %2$s', 'fleet-for-openstation' ),
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
			<div class="fleet-site-card__actions">
				<?php if ( 'active' === $status ) : ?>
					<a class="button button-primary fleet-manage-button" href="<?php echo esc_url( $manage_url ); ?>" target="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-id="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>">
						<?php esc_html_e( 'Manage', 'fleet-for-openstation' ); ?>
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</a>
				<?php else : ?>
					<?php self::render_action_form( 'install', $id, __( 'Finish OpenStation setup', 'fleet-for-openstation' ), 'primary' ); ?>
					<a class="button button-secondary fleet-manage-button" href="<?php echo esc_url( $manage_url ); ?>" target="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-id="<?php echo esc_attr( $window_id ); ?>" data-fleet-window-title="<?php echo esc_attr( $site['name'] ); ?>"><?php esc_html_e( 'Manage WordPress', 'fleet-for-openstation' ); ?></a>
				<?php endif; ?>
				<details class="fleet-site-menu">
					<summary aria-label="<?php esc_attr_e( 'More site actions', 'fleet-for-openstation' ); ?>"><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span></summary>
					<div class="fleet-site-menu__body">
						<form class="fleet-action-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="openstation_fleet_toggle_favorite"><input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'openstation_fleet_toggle_favorite' ); ?><button class="button button-secondary" type="submit"><?php echo $agency['favorite'] ? esc_html__( 'Remove favorite', 'fleet-for-openstation' ) : esc_html__( 'Add favorite', 'fleet-for-openstation' ); ?></button></form>
						<?php self::render_action_form( 'check', $id, __( 'Check now', 'fleet-for-openstation' ) ); ?>
						<?php self::render_action_form( 'disconnect', $id, __( 'Disconnect', 'fleet-for-openstation' ), 'danger' ); ?>
					</div>
				</details>
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
		$section_definitions = array(
			'overview' => array( __( 'Overview', 'fleet-for-openstation' ), 'dashicons-screenoptions', '' ),
			'content'  => array( __( 'Content', 'fleet-for-openstation' ), 'dashicons-edit-page', 'posts' ),
			'media'    => array( __( 'Media', 'fleet-for-openstation' ), 'dashicons-format-image', 'media' ),
			'comments' => array( __( 'Comments', 'fleet-for-openstation' ), 'dashicons-admin-comments', 'comments' ),
			'plugins'  => array( __( 'Plugins', 'fleet-for-openstation' ), 'dashicons-admin-plugins', 'plugins' ),
			'users'    => array( __( 'Users', 'fleet-for-openstation' ), 'dashicons-admin-users', 'users' ),
			'settings' => array( __( 'Settings', 'fleet-for-openstation' ), 'dashicons-admin-settings', 'settings' ),
			'agency'   => array( __( 'Agency', 'fleet-for-openstation' ), 'dashicons-id-alt', '' ),
			'api'      => array( __( 'Explorer', 'fleet-for-openstation' ), 'dashicons-rest-api', '' ),
		);
		$sections = array();
		foreach ( $section_definitions as $key => $definition ) {
			if ( '' === $definition[2] || self::supports( $site, $definition[2] ) ) {
				$sections[ $key ] = $definition;
			}
		}
		if ( ! isset( $sections[ $section ] ) ) {
			$section = 'overview';
		}
		$back_url = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap fleet-app fleet-workspace">
			<header class="fleet-workspace-header">
				<div class="fleet-workspace-header__identity">
					<a class="fleet-back" href="<?php echo esc_url( $back_url ); ?>" target="fleet-hub" data-fleet-hub-window data-fleet-window-title="<?php esc_attr_e( 'Fleet', 'fleet-for-openstation' ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'Fleet', 'fleet-for-openstation' ); ?></a>
					<div class="fleet-workspace-title">
						<span class="fleet-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><span class="fleet-site-icon__status fleet-site-icon__status--active"></span></span>
						<div>
							<span class="fleet-eyebrow"><?php esc_html_e( 'Remote WordPress context', 'fleet-for-openstation' ); ?></span>
							<h1><?php echo esc_html( $site['name'] ); ?></h1>
							<p><?php echo esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ); ?> · <?php echo esc_html( $site['user_login'] ); ?></p>
						</div>
					</div>
				</div>
				<div class="fleet-remote-badge"><span class="fleet-status-dot" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Remote context', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Application Password', 'fleet-for-openstation' ); ?></small></span></div>
			</header>

			<?php self::render_notice( $notice ); ?>
			<?php if ( ! empty( $site['error'] ) ) : ?>
				<div class="fleet-inline-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><?php echo esc_html( $site['error'] ); ?></span></div>
			<?php endif; ?>

			<nav class="fleet-tabs" aria-label="<?php esc_attr_e( 'Site management', 'fleet-for-openstation' ); ?>">
				<?php foreach ( $sections as $key => $item ) : ?>
					<a class="<?php echo $section === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::workspace_url( $id, $key ) ); ?>" target="<?php echo esc_attr( self::site_window_id( $id ) ); ?>" <?php echo $section === $key ? 'aria-current="page"' : ''; ?>><span class="dashicons <?php echo esc_attr( $item[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $item[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="fleet-context-strip"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span><span><?php printf( esc_html__( 'This window is now operating on %s. Every action below runs against that site.', 'fleet-for-openstation' ), '<strong>' . esc_html( wp_parse_url( $site['site_url'], PHP_URL_HOST ) ) . '</strong>' ); ?></span></div>

			<main class="fleet-workspace-body">
				<?php
					switch ( $section ) {
					case 'content':
						self::render_content_workspace( $id, $site );
						break;
					case 'media':
						self::render_media_workspace( $id, $site );
						break;
					case 'comments':
						self::render_comments_workspace( $id, $site );
						break;
					case 'plugins':
						self::render_plugins_workspace( $id, $site );
						break;
					case 'users':
						self::render_users_workspace( $id, $site );
						break;
					case 'settings':
						self::render_settings_workspace( $id, $site );
						break;
					case 'agency':
						self::render_agency_workspace( $id, $site );
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
		$settings  = self::supports( $site, 'settings' ) ? self::remote_request( $site, 'GET', 'wp/v2/settings' ) : new WP_Error( 'unsupported', __( 'Settings are not exposed by this site.', 'fleet-for-openstation' ) );
		$posts     = self::supports( $site, 'posts' ) ? self::remote_request( $site, 'GET', 'wp/v2/posts?context=edit&status=any&per_page=5&orderby=modified&order=desc&_fields=id,title,status,modified,type' ) : array();
		$pages     = self::supports( $site, 'pages' ) ? self::remote_request( $site, 'GET', 'wp/v2/pages?context=edit&status=any&per_page=5&orderby=modified&order=desc&_fields=id,title,status,modified,type' ) : array();
		$status    = isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : 'unknown';
		$attention = self::attention_reasons( $site );
		$tasks     = array(
			'content'  => array( 'posts', 'dashicons-edit-page', __( 'Content', 'fleet-for-openstation' ), __( 'Update posts and pages.', 'fleet-for-openstation' ) ),
			'media'    => array( 'media', 'dashicons-format-image', __( 'Media', 'fleet-for-openstation' ), __( 'Upload and describe media.', 'fleet-for-openstation' ) ),
			'comments' => array( 'comments', 'dashicons-admin-comments', __( 'Comments', 'fleet-for-openstation' ), __( 'Moderate the conversation.', 'fleet-for-openstation' ) ),
			'plugins'  => array( 'plugins', 'dashicons-admin-plugins', __( 'Plugins', 'fleet-for-openstation' ), __( 'Install and control plugins.', 'fleet-for-openstation' ) ),
			'users'    => array( 'users', 'dashicons-admin-users', __( 'Users', 'fleet-for-openstation' ), __( 'Manage accounts and roles.', 'fleet-for-openstation' ) ),
			'settings' => array( 'settings', 'dashicons-admin-settings', __( 'Settings', 'fleet-for-openstation' ), __( 'Change the site identity.', 'fleet-for-openstation' ) ),
			'agency'   => array( '', 'dashicons-id-alt', __( 'Agency profile', 'fleet-for-openstation' ), __( 'Client details, notes, and tags.', 'fleet-for-openstation' ) ),
			'api'      => array( '', 'dashicons-rest-api', __( 'Everything else', 'fleet-for-openstation' ), __( 'Browse every advertised REST route.', 'fleet-for-openstation' ) ),
		);
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
			<?php foreach ( $tasks as $task_key => $task ) : ?>
				<?php if ( '' !== $task[0] && ! self::supports( $site, $task[0] ) ) : ?><?php continue; ?><?php endif; ?>
				<a class="fleet-task-card" href="<?php echo esc_url( self::workspace_url( $id, $task_key ) ); ?>" target="<?php echo esc_attr( self::site_window_id( $id ) ); ?>">
					<span class="fleet-task-card__icon"><span class="dashicons <?php echo esc_attr( $task[1] ); ?>" aria-hidden="true"></span></span>
					<span><strong><?php echo esc_html( $task[2] ); ?></strong><small><?php echo esc_html( $task[3] ); ?></small></span>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</a>
			<?php endforeach; ?>
		</div>

		<section class="fleet-panel fleet-health-panel">
			<div class="fleet-panel__heading">
				<div><h2><?php esc_html_e( 'Attention and Site Health', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Live checks from WordPress Core and the connection itself.', 'fleet-for-openstation' ); ?></p></div>
				<span class="fleet-count"><?php echo esc_html( count( $attention ) ); ?></span>
			</div>
			<?php if ( empty( $attention ) ) : ?>
				<div class="fleet-health-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span><strong><?php esc_html_e( 'No attention needed', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'The latest available checks are clear.', 'fleet-for-openstation' ); ?></small></span></div>
			<?php else : ?>
				<div class="fleet-attention-list">
					<?php foreach ( $attention as $reason ) : ?>
						<div class="fleet-attention-item fleet-attention-item--<?php echo esc_attr( $reason[2] ); ?>"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><?php echo esc_html( $reason[1] ); ?></span></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

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
					<div><dt><?php esc_html_e( 'Connection', 'fleet-for-openstation' ); ?></dt><dd><?php esc_html_e( 'WordPress Application Password', 'fleet-for-openstation' ); ?></dd></div>
					<div><dt><?php esc_html_e( 'WordPress', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( ! empty( $site['wordpress_version'] ) ? $site['wordpress_version'] : __( 'Not reported', 'fleet-for-openstation' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'REST routes', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( ! empty( $site['capabilities']['route_count'] ) ? $site['capabilities']['route_count'] : __( 'Not checked', 'fleet-for-openstation' ) ); ?></dd></div>
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
		$posts = self::remote_request( $site, 'GET', 'wp/v2/posts?context=edit&status=any&per_page=20&orderby=modified&order=desc&_fields=id,title,status,modified,type' );
		$pages = self::remote_request( $site, 'GET', 'wp/v2/pages?context=edit&status=any&per_page=20&orderby=modified&order=desc&_fields=id,title,status,modified,type' );
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
						<option value="future" <?php selected( isset( $item['status'] ) ? $item['status'] : '', 'future' ); ?>><?php esc_html_e( 'Scheduled', 'fleet-for-openstation' ); ?></option>
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
	 * Render Core comment moderation controls.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_comments_workspace( $id, $site ) {
		$comments = self::remote_request( $site, 'GET', 'wp/v2/comments?context=edit&status=all&per_page=30&orderby=date&order=desc' );
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote comments', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Moderation queue', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Approve, hold, mark as spam, or trash comments through WordPress Core.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<?php if ( is_wp_error( $comments ) ) : ?>
			<?php self::render_remote_error( $comments ); ?>
		<?php elseif ( empty( $comments ) ) : ?>
			<div class="fleet-panel-empty"><?php esc_html_e( 'No comments found.', 'fleet-for-openstation' ); ?></div>
		<?php else : ?>
			<div class="fleet-comment-list">
				<?php foreach ( $comments as $comment ) : ?>
					<?php if ( empty( $comment['id'] ) ) : ?><?php continue; ?><?php endif; ?>
					<form class="fleet-comment-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="openstation_fleet_update_comment">
						<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" name="comment_id" value="<?php echo esc_attr( $comment['id'] ); ?>">
						<?php wp_nonce_field( 'openstation_fleet_update_comment' ); ?>
						<div class="fleet-comment-card__body">
							<div><strong><?php echo esc_html( ! empty( $comment['author_name'] ) ? $comment['author_name'] : __( 'Anonymous', 'fleet-for-openstation' ) ); ?></strong><span class="fleet-mini-badge"><?php echo esc_html( isset( $comment['status'] ) ? $comment['status'] : 'hold' ); ?></span></div>
							<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( isset( $comment['content']['rendered'] ) ? $comment['content']['rendered'] : '' ), 42 ) ); ?></p>
							<small><?php echo ! empty( $comment['date'] ) ? esc_html( human_time_diff( strtotime( $comment['date'] ), time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) ) : ''; ?></small>
						</div>
						<div class="fleet-comment-card__action">
							<label class="screen-reader-text" for="fleet-comment-status-<?php echo esc_attr( $comment['id'] ); ?>"><?php esc_html_e( 'Comment status', 'fleet-for-openstation' ); ?></label>
							<select id="fleet-comment-status-<?php echo esc_attr( $comment['id'] ); ?>" name="status">
								<?php foreach ( array( 'approved' => __( 'Approved', 'fleet-for-openstation' ), 'hold' => __( 'Pending', 'fleet-for-openstation' ), 'spam' => __( 'Spam', 'fleet-for-openstation' ), 'trash' => __( 'Trash', 'fleet-for-openstation' ) ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $comment['status'] ) ? $comment['status'] : 'hold', $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button class="button button-secondary" type="submit"><?php esc_html_e( 'Update', 'fleet-for-openstation' ); ?></button>
						</div>
					</form>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render Media Library upload and metadata controls.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_media_workspace( $id, $site ) {
		$media = self::remote_request( $site, 'GET', 'wp/v2/media?context=edit&per_page=24&orderby=date&order=desc&_fields=id,title,alt_text,caption,media_type,mime_type,source_url,date' );
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote media', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Media Library', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Upload files and maintain accessible media details on this site.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<form class="fleet-panel fleet-upload-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
			<input type="hidden" name="action" value="openstation_fleet_upload_media">
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'openstation_fleet_upload_media' ); ?>
			<div><label for="fleet-media-file"><strong><?php esc_html_e( 'Upload to this site', 'fleet-for-openstation' ); ?></strong></label><small><?php esc_html_e( 'Images, audio, video, or PDF up to 10 MB.', 'fleet-for-openstation' ); ?></small></div>
			<input id="fleet-media-file" name="media_file" type="file" accept="image/*,audio/*,video/*,application/pdf" required>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Upload file', 'fleet-for-openstation' ); ?></button>
		</form>
		<?php if ( is_wp_error( $media ) ) : ?>
			<?php self::render_remote_error( $media ); ?>
		<?php elseif ( empty( $media ) ) : ?>
			<div class="fleet-panel-empty"><?php esc_html_e( 'No media found.', 'fleet-for-openstation' ); ?></div>
		<?php else : ?>
			<div class="fleet-media-grid">
				<?php foreach ( $media as $item ) : ?>
					<?php if ( empty( $item['id'] ) ) : ?><?php continue; ?><?php endif; ?>
					<form class="fleet-media-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="openstation_fleet_update_media">
						<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" name="media_id" value="<?php echo esc_attr( $item['id'] ); ?>">
						<?php wp_nonce_field( 'openstation_fleet_update_media' ); ?>
						<div class="fleet-media-card__preview">
							<?php if ( 'image' === ( isset( $item['media_type'] ) ? $item['media_type'] : '' ) && ! empty( $item['source_url'] ) ) : ?>
								<img src="<?php echo esc_url( $item['source_url'] ); ?>" alt="">
							<?php else : ?>
								<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
							<?php endif; ?>
						</div>
						<div class="fleet-field"><label for="fleet-media-title-<?php echo esc_attr( $item['id'] ); ?>"><?php esc_html_e( 'Title', 'fleet-for-openstation' ); ?></label><input id="fleet-media-title-<?php echo esc_attr( $item['id'] ); ?>" name="title" type="text" value="<?php echo esc_attr( isset( $item['title']['raw'] ) ? $item['title']['raw'] : ( isset( $item['title']['rendered'] ) ? wp_strip_all_tags( $item['title']['rendered'] ) : '' ) ); ?>"></div>
						<div class="fleet-field"><label for="fleet-media-alt-<?php echo esc_attr( $item['id'] ); ?>"><?php esc_html_e( 'Alt text', 'fleet-for-openstation' ); ?></label><input id="fleet-media-alt-<?php echo esc_attr( $item['id'] ); ?>" name="alt_text" type="text" value="<?php echo esc_attr( isset( $item['alt_text'] ) ? $item['alt_text'] : '' ); ?>"></div>
						<div class="fleet-field"><label for="fleet-media-caption-<?php echo esc_attr( $item['id'] ); ?>"><?php esc_html_e( 'Caption', 'fleet-for-openstation' ); ?></label><textarea id="fleet-media-caption-<?php echo esc_attr( $item['id'] ); ?>" name="caption" rows="2"><?php echo esc_textarea( isset( $item['caption']['raw'] ) ? $item['caption']['raw'] : '' ); ?></textarea></div>
						<button class="button button-secondary" type="submit"><?php esc_html_e( 'Save details', 'fleet-for-openstation' ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render Core user and role management.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_users_workspace( $id, $site ) {
		$users = self::remote_request( $site, 'GET', 'wp/v2/users?context=edit&per_page=50&orderby=name&_fields=id,username,name,email,roles,avatar_urls' );
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote users', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Accounts and roles', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Create accounts or update common profile and role fields.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<form class="fleet-panel fleet-user-create" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="openstation_fleet_create_user">
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'openstation_fleet_create_user' ); ?>
			<div class="fleet-panel__heading"><div><h2><?php esc_html_e( 'Add user', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Passwords are sent once over the authenticated connection and are never stored by Fleet.', 'fleet-for-openstation' ); ?></p></div></div>
			<div class="fleet-form-grid fleet-form-grid--four">
				<div class="fleet-field"><label for="fleet-user-username"><?php esc_html_e( 'Username', 'fleet-for-openstation' ); ?></label><input id="fleet-user-username" name="username" type="text" required></div>
				<div class="fleet-field"><label for="fleet-user-email"><?php esc_html_e( 'Email', 'fleet-for-openstation' ); ?></label><input id="fleet-user-email" name="email" type="email" required></div>
				<div class="fleet-field"><label for="fleet-user-password"><?php esc_html_e( 'Password', 'fleet-for-openstation' ); ?></label><input id="fleet-user-password" name="password" type="password" minlength="12" autocomplete="new-password" required></div>
				<div class="fleet-field"><label for="fleet-user-role"><?php esc_html_e( 'Role', 'fleet-for-openstation' ); ?></label><select id="fleet-user-role" name="role"><?php foreach ( self::editable_roles() as $role ) : ?><option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( ucfirst( $role ) ); ?></option><?php endforeach; ?></select></div>
			</div>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Create user', 'fleet-for-openstation' ); ?></button>
		</form>
		<?php if ( is_wp_error( $users ) ) : ?>
			<?php self::render_remote_error( $users ); ?>
		<?php else : ?>
			<div class="fleet-user-list">
				<?php foreach ( $users as $user ) : ?>
					<?php if ( empty( $user['id'] ) ) : ?><?php continue; ?><?php endif; ?>
					<form class="fleet-user-row" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="openstation_fleet_update_user">
						<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( $user['id'] ); ?>">
						<?php wp_nonce_field( 'openstation_fleet_update_user' ); ?>
						<span class="fleet-user-avatar"><?php if ( ! empty( $user['avatar_urls']['48'] ) ) : ?><img src="<?php echo esc_url( $user['avatar_urls']['48'] ); ?>" alt=""><?php else : ?><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php endif; ?></span>
						<div><strong><?php echo esc_html( isset( $user['username'] ) ? $user['username'] : '' ); ?></strong><small><?php echo esc_html( implode( ', ', isset( $user['roles'] ) && is_array( $user['roles'] ) ? $user['roles'] : array() ) ); ?></small></div>
						<div class="fleet-field"><label class="screen-reader-text" for="fleet-user-name-<?php echo esc_attr( $user['id'] ); ?>"><?php esc_html_e( 'Display name', 'fleet-for-openstation' ); ?></label><input id="fleet-user-name-<?php echo esc_attr( $user['id'] ); ?>" name="name" type="text" value="<?php echo esc_attr( isset( $user['name'] ) ? $user['name'] : '' ); ?>" required></div>
						<div class="fleet-field"><label class="screen-reader-text" for="fleet-user-email-<?php echo esc_attr( $user['id'] ); ?>"><?php esc_html_e( 'Email', 'fleet-for-openstation' ); ?></label><input id="fleet-user-email-<?php echo esc_attr( $user['id'] ); ?>" name="email" type="email" value="<?php echo esc_attr( isset( $user['email'] ) ? $user['email'] : '' ); ?>" required></div>
						<div class="fleet-field"><label class="screen-reader-text" for="fleet-user-role-<?php echo esc_attr( $user['id'] ); ?>"><?php esc_html_e( 'Role', 'fleet-for-openstation' ); ?></label><select id="fleet-user-role-<?php echo esc_attr( $user['id'] ); ?>" name="role"><?php foreach ( self::editable_roles() as $role ) : ?><option value="<?php echo esc_attr( $role ); ?>" <?php selected( isset( $user['roles'][0] ) ? $user['roles'][0] : 'subscriber', $role ); ?>><?php echo esc_html( ucfirst( $role ) ); ?></option><?php endforeach; ?></select></div>
						<button class="button button-secondary" type="submit"><?php esc_html_e( 'Save', 'fleet-for-openstation' ); ?></button>
					</form>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render hub-side agency metadata for one site.
	 *
	 * @param string $id   Site id.
	 * @param array  $site Site record.
	 */
	private static function render_agency_workspace( $id, $site ) {
		$agency    = $site['agency'];
		$attention = self::attention_reasons( $site );
		?>
		<div class="fleet-workspace-intro">
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Hub-only details', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Agency profile', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'These notes stay on the Fleet hub and are never sent to the managed site.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<div class="fleet-api-layout">
			<form class="fleet-panel fleet-settings-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="openstation_fleet_update_profile">
				<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'openstation_fleet_update_profile' ); ?>
				<div class="fleet-field"><label for="fleet-client-name"><?php esc_html_e( 'Client name', 'fleet-for-openstation' ); ?></label><input id="fleet-client-name" name="client_name" type="text" value="<?php echo esc_attr( $agency['client_name'] ); ?>"></div>
				<div class="fleet-field"><label for="fleet-plan-status"><?php esc_html_e( 'Maintenance plan', 'fleet-for-openstation' ); ?></label><select id="fleet-plan-status" name="plan_status"><option value="none" <?php selected( $agency['plan_status'], 'none' ); ?>><?php esc_html_e( 'Not assigned', 'fleet-for-openstation' ); ?></option><option value="active" <?php selected( $agency['plan_status'], 'active' ); ?>><?php esc_html_e( 'Active', 'fleet-for-openstation' ); ?></option><option value="paused" <?php selected( $agency['plan_status'], 'paused' ); ?>><?php esc_html_e( 'Paused', 'fleet-for-openstation' ); ?></option><option value="ended" <?php selected( $agency['plan_status'], 'ended' ); ?>><?php esc_html_e( 'Ended', 'fleet-for-openstation' ); ?></option></select></div>
				<div class="fleet-field"><label for="fleet-site-tags"><?php esc_html_e( 'Tags', 'fleet-for-openstation' ); ?></label><input id="fleet-site-tags" name="tags" type="text" value="<?php echo esc_attr( implode( ', ', $agency['tags'] ) ); ?>" placeholder="client, ecommerce, priority"></div>
				<div class="fleet-field"><label for="fleet-site-notes"><?php esc_html_e( 'Private notes', 'fleet-for-openstation' ); ?></label><textarea id="fleet-site-notes" name="notes" rows="7"><?php echo esc_textarea( $agency['notes'] ); ?></textarea></div>
				<label class="fleet-check"><input name="favorite" type="checkbox" value="1" <?php checked( $agency['favorite'] ); ?>><?php esc_html_e( 'Favorite this site', 'fleet-for-openstation' ); ?></label>
				<div class="fleet-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save agency profile', 'fleet-for-openstation' ); ?></button></div>
			</form>
			<section class="fleet-panel">
				<div class="fleet-panel__heading"><div><h2><?php esc_html_e( 'Connection inventory', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Discovered capabilities and current attention items.', 'fleet-for-openstation' ); ?></p></div></div>
				<dl class="fleet-details fleet-details--single">
					<div><dt><?php esc_html_e( 'WordPress', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( $site['wordpress_version'] ? $site['wordpress_version'] : __( 'Not reported', 'fleet-for-openstation' ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'REST routes', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( isset( $site['capabilities']['route_count'] ) ? $site['capabilities']['route_count'] : 0 ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Namespaces', 'fleet-for-openstation' ); ?></dt><dd><?php echo esc_html( ! empty( $site['capabilities']['namespaces'] ) ? implode( ', ', array_slice( $site['capabilities']['namespaces'], 0, 12 ) ) : __( 'Not checked', 'fleet-for-openstation' ) ); ?></dd></div>
				</dl>
				<div class="fleet-attention-list fleet-attention-list--profile">
					<?php if ( empty( $attention ) ) : ?><div class="fleet-health-good"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong><?php esc_html_e( 'No attention needed', 'fleet-for-openstation' ); ?></strong></div><?php endif; ?>
					<?php foreach ( $attention as $reason ) : ?><div class="fleet-attention-item fleet-attention-item--<?php echo esc_attr( $reason[2] ); ?>"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html( $reason[1] ); ?></div><?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
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
			<div><span class="fleet-eyebrow"><?php esc_html_e( 'Remote plugins', 'fleet-for-openstation' ); ?></span><h2><?php esc_html_e( 'Plugins', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'Install WordPress.org plugins, then activate or deactivate them through WordPress Core.', 'fleet-for-openstation' ); ?></p></div>
		</div>
		<form class="fleet-panel fleet-plugin-install" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="openstation_fleet_install_plugin">
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'openstation_fleet_install_plugin' ); ?>
			<div><label for="fleet-plugin-slug"><strong><?php esc_html_e( 'Install from WordPress.org', 'fleet-for-openstation' ); ?></strong></label><small><?php esc_html_e( 'Enter the directory slug, such as akismet.', 'fleet-for-openstation' ); ?></small></div>
			<input id="fleet-plugin-slug" name="plugin_slug" type="text" pattern="[a-z0-9][a-z0-9._-]*" placeholder="plugin-slug" required>
			<label class="screen-reader-text" for="fleet-plugin-install-status"><?php esc_html_e( 'Activation status', 'fleet-for-openstation' ); ?></label>
			<select id="fleet-plugin-install-status" name="status"><option value="active"><?php esc_html_e( 'Install and activate', 'fleet-for-openstation' ); ?></option><option value="inactive"><?php esc_html_e( 'Install only', 'fleet-for-openstation' ); ?></option></select>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Install', 'fleet-for-openstation' ); ?></button>
		</form>
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
		$result  = self::take_api_result( $id );
		$root    = self::remote_request( $site, 'GET', '' );
		$catalog = is_wp_error( $root ) ? array() : self::api_route_catalog( $root );
		$core_routes = count(
			array_filter(
				$catalog,
				static function ( $item ) {
					return 0 === strpos( $item['route'], '/wp/v2' ) || 0 === strpos( $item['route'], '/wp-site-health/v1' );
				}
			)
		);
		$other_routes = count( $catalog ) - $core_routes;
		?>
		<div class="fleet-workspace-intro">
			<div>
				<span class="fleet-eyebrow"><?php esc_html_e( 'Complete available surface', 'fleet-for-openstation' ); ?></span>
				<h2><?php esc_html_e( 'WordPress API Explorer', 'fleet-for-openstation' ); ?></h2>
				<p><?php esc_html_e( 'Browse every REST route this site advertises, including WordPress Core and installed plugins, then run it with the permissions of the approved account.', 'fleet-for-openstation' ); ?></p>
			</div>
		</div>

		<div class="fleet-api-coverage" role="list" aria-label="<?php esc_attr_e( 'Remote API coverage', 'fleet-for-openstation' ); ?>">
			<div role="listitem"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Content', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Posts, pages, media, comments, terms, revisions', 'fleet-for-openstation' ); ?></small></span></div>
			<div role="listitem"><span class="dashicons dashicons-art" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Design', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Templates, navigation, blocks, widgets, styles, fonts', 'fleet-for-openstation' ); ?></small></span></div>
			<div role="listitem"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Administration', 'fleet-for-openstation' ); ?></strong><small><?php esc_html_e( 'Settings, users, plugins, themes, Site Health', 'fleet-for-openstation' ); ?></small></span></div>
			<div role="listitem"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Other namespaces', 'fleet-for-openstation' ); ?></strong><small><?php printf( esc_html__( '%1$d wp/v2 and Site Health routes · %2$d additional routes', 'fleet-for-openstation' ), esc_html( $core_routes ), esc_html( $other_routes ) ); ?></small></span></div>
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
					<input id="fleet-api-route" name="api_route" type="text" list="fleet-api-routes" placeholder="wp/v2/users?context=edit" required>
					<datalist id="fleet-api-routes">
						<?php foreach ( $catalog as $item ) : ?><option value="<?php echo esc_attr( ltrim( $item['route'], '/' ) ); ?>"></option><?php endforeach; ?>
					</datalist>
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

		<section class="fleet-panel fleet-route-explorer">
			<div class="fleet-panel__heading">
				<div><h2><?php esc_html_e( 'Available routes', 'fleet-for-openstation' ); ?></h2><p><?php esc_html_e( 'This inventory comes live from the selected site. Route placeholders such as (?P<id>[\d]+) must be replaced with a real value before sending.', 'fleet-for-openstation' ); ?></p></div>
				<span class="fleet-count"><?php echo esc_html( count( $catalog ) ); ?></span>
			</div>
			<?php if ( is_wp_error( $root ) ) : ?>
				<?php self::render_remote_error( $root ); ?>
			<?php elseif ( empty( $catalog ) ) : ?>
				<div class="fleet-panel-empty"><?php esc_html_e( 'This site did not advertise any usable REST routes.', 'fleet-for-openstation' ); ?></div>
			<?php else : ?>
				<label class="fleet-route-search"><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Filter routes', 'fleet-for-openstation' ); ?></span><input type="search" data-fleet-route-filter placeholder="<?php esc_attr_e( 'Filter routes, namespaces, or methods', 'fleet-for-openstation' ); ?>"></label>
				<div class="fleet-route-list">
					<?php foreach ( $catalog as $item ) : ?>
						<?php $default_method = in_array( 'GET', $item['methods'], true ) ? 'GET' : $item['methods'][0]; ?>
						<button class="fleet-route-card" type="button" data-fleet-api-route="<?php echo esc_attr( ltrim( $item['route'], '/' ) ); ?>" data-fleet-api-method="<?php echo esc_attr( $default_method ); ?>">
							<span class="fleet-route-card__methods"><?php foreach ( $item['methods'] as $method ) : ?><span class="fleet-method fleet-method--<?php echo esc_attr( strtolower( $method ) ); ?>"><?php echo esc_html( $method ); ?></span><?php endforeach; ?></span>
							<code><?php echo esc_html( $item['route'] ); ?></code>
							<span class="fleet-route-card__meta"><?php echo esc_html( $item['namespace'] ? $item['namespace'] : __( 'root', 'fleet-for-openstation' ) ); ?> · <?php printf( esc_html( _n( '%d argument', '%d arguments', $item['arg_count'], 'fleet-for-openstation' ) ), esc_html( $item['arg_count'] ) ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
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
	 * @param string $notice  Optional fixed notice code.
	 * @return string
	 */
	private static function workspace_url( $id, $section = 'overview', $notice = '' ) {
		$args = array(
			'page'          => self::MENU_SLUG,
			'site_id'       => sanitize_key( $id ),
			'fleet_section' => sanitize_key( $section ),
		);
		if ( '' !== $notice ) {
			$args['fleet_notice'] = sanitize_key( $notice );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Build the stable OpenStation window id for one managed site.
	 *
	 * Keeping the site id in the shell identity lets many managed-site
	 * workspaces remain open without changing OpenStation itself.
	 *
	 * @param string $id Fleet site id.
	 * @return string
	 */
	private static function site_window_id( $id ) {
		return 'fleet-site-' . sanitize_key( $id );
	}

	/**
	 * Build a URL for one Fleet hub view.
	 *
	 * @param array $args Optional query arguments.
	 * @return string
	 */
	private static function hub_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
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
			'connected'              => array( 'success', __( 'Site connected and ready to manage.', 'fleet-for-openstation' ) ),
			'connected_ready'        => array( 'success', __( 'Site connected. OpenStation is installed, active, and ready to manage.', 'fleet-for-openstation' ) ),
			'connected_install_failed' => array( 'warning', __( 'The site is connected, but OpenStation could not be installed automatically. You can retry from its site card.', 'fleet-for-openstation' ) ),
			'already_connected'      => array( 'info', __( 'This site is already connected. Fleet opened its workspace instead of creating another credential.', 'fleet-for-openstation' ) ),
			'checked'                => array( 'success', __( 'Site status refreshed.', 'fleet-for-openstation' ) ),
			'bulk_complete'          => array( 'success', __( 'The selected Fleet action completed.', 'fleet-for-openstation' ) ),
			'bulk_partial'           => array( 'warning', __( 'The Fleet action completed with one or more site failures.', 'fleet-for-openstation' ) ),
			'bulk_failed'            => array( 'error', __( 'Choose an action and at least one site.', 'fleet-for-openstation' ) ),
			'installed'              => array( 'success', __( 'OpenStation installed and activated.', 'fleet-for-openstation' ) ),
			'already_active'         => array( 'info', __( 'OpenStation is already active.', 'fleet-for-openstation' ) ),
			'profile_updated'        => array( 'success', __( 'Agency details saved on this Fleet hub.', 'fleet-for-openstation' ) ),
			'settings_updated'       => array( 'success', __( 'The remote site settings were saved.', 'fleet-for-openstation' ) ),
			'content_updated'        => array( 'success', __( 'The remote content was updated.', 'fleet-for-openstation' ) ),
			'plugin_updated'         => array( 'success', __( 'The remote plugin status was updated.', 'fleet-for-openstation' ) ),
			'plugin_installed'       => array( 'success', __( 'The plugin was installed on the managed site.', 'fleet-for-openstation' ) ),
			'comment_updated'        => array( 'success', __( 'The remote comment status was updated.', 'fleet-for-openstation' ) ),
			'media_updated'          => array( 'success', __( 'The remote media details were saved.', 'fleet-for-openstation' ) ),
			'media_uploaded'         => array( 'success', __( 'The file was uploaded to the remote Media Library.', 'fleet-for-openstation' ) ),
			'user_created'           => array( 'success', __( 'The remote user was created.', 'fleet-for-openstation' ) ),
			'user_updated'           => array( 'success', __( 'The remote user was updated.', 'fleet-for-openstation' ) ),
			'api_complete'           => array( 'success', __( 'The remote API request completed.', 'fleet-for-openstation' ) ),
			'disconnected'           => array( 'success', __( 'Remote authorization revoked and site disconnected.', 'fleet-for-openstation' ) ),
			'invalid_url'            => array( 'error', __( 'Enter a valid public HTTPS WordPress site URL.', 'fleet-for-openstation' ) ),
			'hub_https_required'     => array( 'error', __( 'The Fleet hub must use HTTPS for secure authorization.', 'fleet-for-openstation' ) ),
			'self_site'              => array( 'warning', __( 'This site is the Fleet hub, so it cannot be connected to itself.', 'fleet-for-openstation' ) ),
			'discovery_failed'       => array( 'error', __( 'Fleet could not find WordPress’s secure Application Password approval on that site.', 'fleet-for-openstation' ) ),
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
			'plugin_install_failed'  => array( 'error', __( 'Fleet could not install that WordPress.org plugin.', 'fleet-for-openstation' ) ),
			'comment_failed'         => array( 'error', __( 'Fleet could not update that remote comment.', 'fleet-for-openstation' ) ),
			'media_failed'           => array( 'error', __( 'Fleet could not update that remote media item.', 'fleet-for-openstation' ) ),
			'media_upload_failed'    => array( 'error', __( 'Fleet could not upload that file. Check its type and the 10 MB limit.', 'fleet-for-openstation' ) ),
			'user_failed'            => array( 'error', __( 'Fleet could not save that remote user.', 'fleet-for-openstation' ) ),
			'api_failed'             => array( 'error', __( 'The remote API request failed.', 'fleet-for-openstation' ) ),
			'disconnect_failed'      => array( 'error', __( 'Fleet could not revoke the remote authorization, so the site remains connected.', 'fleet-for-openstation' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		?>
		<div class="fleet-notice fleet-notice--<?php echo esc_attr( $messages[ $code ][0] ); ?>" role="status">
			<span class="dashicons <?php echo 'success' === $messages[ $code ][0] ? 'dashicons-yes-alt' : 'dashicons-info-outline'; ?>" aria-hidden="true"></span>
			<p><?php echo esc_html( $messages[ $code ][1] ); ?></p>
		</div>
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
		if ( ! is_array( $sites ) ) {
			return array();
		}
		foreach ( $sites as $id => $site ) {
			if ( is_array( $site ) ) {
				$sites[ $id ] = self::normalize_site_record( $site );
			}
		}
		return $sites;
	}

	/**
	 * Save current user's connected sites.
	 *
	 * @param array $sites Site records.
	 */
	private static function save_sites( $sites ) {
		foreach ( $sites as $id => $site ) {
			if ( is_array( $site ) ) {
				$sites[ $id ] = self::normalize_site_record( $site );
			}
		}
		update_user_meta( get_current_user_id(), self::USER_META_SITES, $sites );
	}

	/**
	 * Add defaults introduced after a site was first connected.
	 *
	 * @param array $site Stored site record.
	 * @return array
	 */
	private static function normalize_site_record( $site ) {
		$site = wp_parse_args(
			$site,
			array(
				'capabilities'      => array(),
				'health'            => array(),
				'health_checked'    => 0,
				'inbox'             => self::empty_inbox_summary(),
				'wordpress_version' => '',
				'agency'            => array(),
				'setup_status'      => 'ready',
			)
		);
		$site['agency'] = wp_parse_args(
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
		$events = get_user_meta( get_current_user_id(), self::USER_META_EVENTS, true );
		$events = is_array( $events ) ? $events : array();
		array_unshift(
			$events,
			array(
				'id'        => wp_generate_uuid4(),
				'time'      => time(),
				'site_id'   => sanitize_key( $site_id ),
				'site_name' => isset( $site['name'] ) ? sanitize_text_field( $site['name'] ) : '',
				'action'    => sanitize_key( $action ),
				'message'   => sanitize_text_field( $message ),
				'status'    => in_array( $status, array( 'success', 'warning', 'error' ), true ) ? $status : 'success',
				'actor'     => wp_get_current_user()->display_name,
			)
		);
		update_user_meta( get_current_user_id(), self::USER_META_EVENTS, array_slice( $events, 0, 100 ) );
	}

	/**
	 * Get recent Fleet activity for the current user.
	 *
	 * @return array
	 */
	private static function get_activity() {
		$events = get_user_meta( get_current_user_id(), self::USER_META_EVENTS, true );
		return is_array( $events ) ? array_slice( $events, 0, 100 ) : array();
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
