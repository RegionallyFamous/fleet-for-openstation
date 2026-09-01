<?php
/**
 * Native OpenStation App Framework presentation and action bridge.
 *
 * @package FleetForOpenStation
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic output is escaped by OpenStation\App\Html through self::e().

/**
 * Keeps App Framework definitions thin and credentials server-side.
 */
final class OpenStation_Fleet_App {
	/**
	 * Set the managed-site id and window title at mount time.
	 *
	 * @param OpenStation\App\State $state App state.
	 * @param OpenStation\App\Os    $os    Host handle.
	 */
	public static function mount_site( $state, $os ) {
		$id   = sanitize_key( (string) $os->param( 'site_id', '' ) );
		$site = OpenStation_Fleet::app_site( $id );
		$state->set( 'site_id', $id );
		if ( is_wp_error( $site ) ) {
			$state->set( 'notice', $site->get_error_message() );
			return;
		}
		$os->title( $site['name'] );
	}

	/**
	 * Handle a Fleet hub interaction.
	 *
	 * @param string                 $action Action name.
	 * @param OpenStation\App\State $state  App state.
	 * @param OpenStation\App\Os    $os     Host handle.
	 * @param array                  $args   Dispatch arguments.
	 */
	public static function hub_action( $action, $state, $os, $args ) {
		$values = self::values( $args );
		$state->set( 'notice', '' );

		if ( 'connect' === $action ) {
			$result = OpenStation_Fleet::app_connect( isset( $values['site_url'] ) ? $values['site_url'] : '' );
			if ( is_wp_error( $result ) ) {
				$state->set( 'notice', $result->get_error_message() );
				return;
			}
			if ( ! empty( $result['site_id'] ) ) {
				self::open_site( $os, $result['site_id'] );
				$os->toast( __( 'That site is already connected.', 'fleet-for-openstation' ) );
				return;
			}
			$os->effects->add( 'fleet-authorize', array( 'url' => esc_url_raw( $result['authorization_url'] ) ) );
			return;
		}

		if ( 'search' === $action ) {
			$state->set( 'query', sanitize_text_field( isset( $values['query'] ) ? $values['query'] : '' ) );
			return;
		}

		if ( 'open-site' === $action ) {
			self::open_site( $os, isset( $args['site-id'] ) ? $args['site-id'] : ( isset( $args['siteId'] ) ? $args['siteId'] : '' ) );
			return;
		}

		if ( 'open-workspace' === $action ) {
			$raw_ids = isset( $args['site-ids'] ) ? json_decode( (string) $args['site-ids'], true ) : ( isset( $args['siteIds'] ) ? $args['siteIds'] : array() );
			$all_ids = is_array( $raw_ids ) ? array_values( array_unique( array_map( 'sanitize_key', $raw_ids ) ) ) : array();
			$ids     = array_slice( $all_ids, 0, 8 );
			$os->effects->add( 'fleet-open-workspace', array( 'siteIds' => $ids ) );
			if ( count( $all_ids ) > count( $ids ) ) {
				$os->toast( __( 'Fleet opened the first eight sites. Open any remaining site from the Sites tab.', 'fleet-for-openstation' ) );
			}
			return;
		}

		$id = sanitize_key( isset( $args['site-id'] ) ? $args['site-id'] : ( isset( $args['siteId'] ) ? $args['siteId'] : '' ) );
		$map = array(
			'refresh-site' => 'refresh',
			'favorite'     => 'favorite',
			'install'      => 'install-openstation',
			'disconnect'   => 'disconnect',
		);
		if ( ! isset( $map[ $action ] ) ) {
			$state->set( 'notice', __( 'Fleet did not recognize that action.', 'fleet-for-openstation' ) );
			return;
		}
		$result = OpenStation_Fleet::app_action( $id, $map[ $action ] );
		if ( is_wp_error( $result ) ) {
			$state->set( 'notice', $result->get_error_message() );
			return;
		}
		$os->toast( isset( $result['message'] ) && $result['message'] ? $result['message'] : __( 'Fleet updated the site.', 'fleet-for-openstation' ) );
	}

	/**
	 * Handle an interaction inside one managed-site window.
	 *
	 * @param string                 $action Action name.
	 * @param OpenStation\App\State $state  App state.
	 * @param OpenStation\App\Os    $os     Host handle.
	 * @param array                  $args   Dispatch arguments.
	 */
	public static function site_action( $action, $state, $os, $args ) {
		$id = sanitize_key( (string) $state->get( 'site_id' ) );
		$state->set( 'notice', '' );
		if ( 'open-hub' === $action ) {
			$os->open( 'fleet-for-openstation' );
			return;
		}
		if ( 'open-site-url' === $action ) {
			$site = OpenStation_Fleet::app_site( $id );
			if ( ! is_wp_error( $site ) ) {
				$os->open_url( $site['url'], $site['name'] );
			}
			return;
		}

		$map = array(
			'refresh'             => 'refresh',
			'finish-setup'        => 'finish-setup',
			'install-openstation' => 'install-openstation',
			'save-content'        => 'content',
			'save-comment'        => 'comment',
			'save-media'          => 'media',
			'save-settings'       => 'settings',
			'save-agency'         => 'agency',
			'change-plugin'       => 'plugin',
			'install-plugin'      => 'install-plugin',
			'create-user'         => 'create-user',
			'save-user'           => 'user',
			'api-request'         => 'api',
			'disconnect'          => 'disconnect',
		);
		if ( ! isset( $map[ $action ] ) ) {
			$state->set( 'notice', __( 'Fleet did not recognize that action.', 'fleet-for-openstation' ) );
			return;
		}
		$values = array_merge( self::values( $args ), $args );
		unset( $values['values'] );
		$result = OpenStation_Fleet::app_action( $id, $map[ $action ], $values );
		if ( is_wp_error( $result ) ) {
			$state->set( 'notice', $result->get_error_message() );
			return;
		}
		if ( 'api-request' === $action ) {
			$payload = isset( $result['data'] ) ? $result['data'] : array();
			$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$state->set( 'response', strlen( (string) $json ) > 100000 ? __( 'The response exceeded Fleet’s 100 KB display limit.', 'fleet-for-openstation' ) : (string) $json );
		}
		if ( ! empty( $result['disconnected'] ) ) {
			$os->open( 'fleet-for-openstation' );
			$os->close();
			return;
		}
		$site = OpenStation_Fleet::app_site( $id );
		if ( ! is_wp_error( $site ) ) {
			$os->title( $site['name'] );
		}
		$os->toast( isset( $result['message'] ) && $result['message'] ? $result['message'] : __( 'Changes saved.', 'fleet-for-openstation' ) );
	}

	/**
	 * Render the hub's Sites tab.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
	public static function render_sites( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'sites' );
		self::hub_header( $data, $state, __( 'Your sites', 'fleet-for-openstation' ), __( 'Every WordPress site you look after, ready in its own window.', 'fleet-for-openstation' ) );
		if ( empty( $data['sites'] ) ) {
			?>
			<os-empty-state icon="dashicons-admin-site-alt3" heading="<?php echo self::e( __( 'Connect your first site', 'fleet-for-openstation' ) ); ?>">
				<?php echo self::e( __( 'Approve a secure WordPress Core connection once, then manage the site without hopping between dashboards.', 'fleet-for-openstation' ) ); ?>
			</os-empty-state>
			<?php
			self::connect_form();
			return;
		}
		// translators: %d: number of connected sites needing attention.
		$attention_label = $data['counts']['attention'] ? sprintf( _n( '%d needs attention', '%d need attention', $data['counts']['attention'], 'fleet-for-openstation' ), $data['counts']['attention'] ) : __( 'All clear', 'fleet-for-openstation' );
		?>
		<div class="fleet-native-toolbar">
			<p><?php echo self::e( __( 'Connection status', 'fleet-for-openstation' ) ); ?></p>
			<os-badge tone="<?php echo $data['counts']['attention'] ? 'warning' : 'success'; ?>"><?php echo self::e( $attention_label ); ?></os-badge>
		</div>
		<div class="fleet-native-sites">
			<?php foreach ( $data['sites'] as $site ) : ?>
				<?php self::site_card( $site ); ?>
			<?php endforeach; ?>
		</div>
		<details class="fleet-native-connect-details">
			<summary><?php echo self::e( __( 'Connect another site', 'fleet-for-openstation' ) ); ?></summary>
			<?php self::connect_form(); ?>
		</details>
		<?php
	}

	/** Render the hub Inbox tab. */
	public static function render_inbox( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'inbox' );
		self::hub_header( $data, $state, __( 'Inbox', 'fleet-for-openstation' ), __( 'Work waiting across every connected site.', 'fleet-for-openstation' ) );
		$shown = 0;
		foreach ( $data['sites'] as $site ) {
			if ( 0 === $site['inbox_count'] ) {
				continue;
			}
			++$shown;
			?>
			<os-card class="fleet-native-inbox-card" os-action="open-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>">
				<div class="fleet-native-card-head"><span><strong><?php echo self::e( $site['name'] ); ?></strong><small><?php echo self::e( $site['host'] ); ?></small></span><os-badge tone="warning"><?php echo self::e( $site['inbox_count'] ); ?></os-badge></div>
				<div class="fleet-native-inbox-counts">
					<?php foreach ( array( 'pending_comments', 'drafts', 'pending_posts', 'scheduled_posts' ) as $key ) : ?>
						<?php
						$count = isset( $site['inbox'][ $key ]['count'] ) ? (int) $site['inbox'][ $key ]['count'] : 0;
						if ( 'pending_comments' === $key ) {
							$label = _n( 'comment', 'comments', $count, 'fleet-for-openstation' );
						} elseif ( 'drafts' === $key ) {
							$label = _n( 'draft', 'drafts', $count, 'fleet-for-openstation' );
						} elseif ( 'pending_posts' === $key ) {
							$label = _n( 'review', 'reviews', $count, 'fleet-for-openstation' );
						} else {
							$label = __( 'scheduled', 'fleet-for-openstation' );
						}
						?>
						<?php if ( $count ) : ?><span><strong><?php echo self::e( $count ); ?></strong> <?php echo self::e( $label ); ?></span><?php endif; ?>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $site['attention'] as $reason ) : ?><p class="fleet-native-alert" data-tone="<?php echo 'critical' === $reason[2] ? 'danger' : 'warning'; ?>"><?php echo self::e( $reason[1] ); ?></p><?php endforeach; ?>
			</os-card>
			<?php
		}
		if ( 0 === $shown ) {
			?><os-empty-state icon="dashicons-yes-alt" heading="<?php echo self::e( __( 'Nothing needs you right now', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Fleet will keep checking site health, pending comments, drafts, and scheduled work.', 'fleet-for-openstation' ) ); ?></os-empty-state><?php
		}
	}

	/** Render the hub Search tab. */
	public static function render_search( $state ) {
		$query = sanitize_text_field( (string) $state->get( 'query' ) );
		$data  = OpenStation_Fleet::app_hub_data( 'search', $query );
		self::hub_header( $data, $state, __( 'Search every site', 'fleet-for-openstation' ), __( 'Find content, media, comments, and people from one place.', 'fleet-for-openstation' ) );
		?>
		<os-form os-action="search" submit-label="<?php echo self::e( __( 'Search Fleet', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1" class="fleet-native-search-form">
			<os-text-field name="query" label="<?php echo self::e( __( 'Search query', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( $query ); ?>" placeholder="<?php echo self::e( __( 'Page title, person, media, or comment', 'fleet-for-openstation' ) ); ?>" required></os-text-field>
		</os-form>
		<?php if ( strlen( $query ) < 2 ) : ?>
			<os-empty-state icon="dashicons-search" heading="<?php echo self::e( __( 'Search the whole fleet', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Enter at least two characters. Fleet searches the secure index refreshed during site checks.', 'fleet-for-openstation' ) ); ?></os-empty-state>
		<?php else : ?>
			<div class="fleet-native-search-results">
			<?php $matches = 0; foreach ( $data['search'] as $group ) : $matches += count( $group['items'] ); ?>
				<section class="fleet-native-result-group">
					<header><strong><?php echo self::e( $group['site_name'] ); ?></strong><os-badge><?php echo self::e( count( $group['items'] ) ); ?></os-badge></header>
					<?php if ( $group['error'] ) : ?><os-notice tone="danger"><?php echo self::e( $group['error'] ); ?></os-notice><?php endif; ?>
				<?php foreach ( $group['items'] as $item ) : ?><button type="button" class="fleet-native-result" os-action="open-site" os-arg-site-id="<?php echo self::e( $group['site_id'] ); ?>"><span class="dashicons <?php echo self::e( $item['icon'] ); ?>" aria-hidden="true"></span><span><strong><?php echo self::e( $item['title'] ); ?></strong><small><?php echo self::e( $item['meta'] ); ?></small></span></button><?php endforeach; ?>
				</section>
			<?php endforeach; ?>
			</div>
			<?php if ( 0 === $matches ) : ?>
				<?php // translators: %s: fleet search query. ?>
				<os-empty-state icon="dashicons-search" heading="<?php echo self::e( __( 'No matches', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( sprintf( __( 'Nothing matched “%s” on the connected sites.', 'fleet-for-openstation' ), $query ) ); ?></os-empty-state>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/** Render the hub Workspaces tab. */
	public static function render_workspaces( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'workspaces' );
		self::hub_header( $data, $state, __( 'Client workspaces', 'fleet-for-openstation' ), __( 'Open a client’s sites as separate windows, ready side by side.', 'fleet-for-openstation' ) );
		if ( empty( $data['workspaces'] ) ) {
			?><os-empty-state icon="dashicons-portfolio" heading="<?php echo self::e( __( 'No client workspaces yet', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Add a client name to a site’s Agency profile and Fleet will build the workspace automatically.', 'fleet-for-openstation' ) ); ?></os-empty-state><?php
			return;
		}
		?><div class="fleet-native-workspaces"><?php foreach ( $data['workspaces'] as $client => $sites ) : ?>
			<?php // translators: %d: number of sites in a client workspace. ?>
			<?php $workspace_count = sprintf( _n( '%d site', '%d sites', count( $sites ), 'fleet-for-openstation' ), count( $sites ) ); ?>
			<os-card class="fleet-native-workspace-card">
				<div class="fleet-native-card-head"><span><strong><?php echo self::e( $client ); ?></strong><small><?php echo self::e( $workspace_count ); ?></small></span><span class="dashicons dashicons-portfolio" aria-hidden="true"></span></div>
				<ul><?php foreach ( $sites as $site ) : ?><li><span class="fleet-native-dot"></span><?php echo self::e( $site['name'] ); ?></li><?php endforeach; ?></ul>
				<os-button variant="primary" os-action="open-workspace" os-arg-site-ids="<?php echo self::j( array_column( $sites, 'id' ) ); ?>"><?php echo self::e( __( 'Open workspace', 'fleet-for-openstation' ) ); ?></os-button>
			</os-card>
		<?php endforeach; ?></div><?php
	}

	/** Render the hub Activity tab. */
	public static function render_activity( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'activity' );
		self::hub_header( $data, $state, __( 'Activity', 'fleet-for-openstation' ), __( 'A local record of what Fleet changed and where.', 'fleet-for-openstation' ) );
		if ( empty( $data['activity'] ) ) {
			?><os-empty-state icon="dashicons-clock" heading="<?php echo self::e( __( 'No activity yet', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Checks and changes will appear here as you use Fleet.', 'fleet-for-openstation' ) ); ?></os-empty-state><?php
			return;
		}
		?><div class="fleet-native-activity"><?php foreach ( $data['activity'] as $event ) : ?>
			<article data-tone="<?php echo 'error' === $event['status'] ? 'danger' : ( 'warning' === $event['status'] ? 'warning' : 'neutral' ); ?>">
				<span class="fleet-native-activity-icon dashicons <?php echo 'error' === $event['status'] ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
				<span><strong><?php echo self::e( $event['message'] ); ?></strong><small><?php echo self::e( trim( $event['site_name'] . ' · ' . human_time_diff( $event['time'], time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) ) ); ?></small></span>
			</article>
		<?php endforeach; ?></div><?php
	}

	/** Render one managed-site tab. */
	public static function render_site( $section, $state ) {
		$id   = sanitize_key( (string) $state->get( 'site_id' ) );
		$site = OpenStation_Fleet::app_site( $id );
		if ( is_wp_error( $site ) ) {
			?><os-empty-state icon="dashicons-warning" heading="<?php echo self::e( __( 'Site unavailable', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( $site->get_error_message() ); ?></os-empty-state><?php
			return;
		}
		self::site_header( $site, $state );
		if ( 'pending' === $site['setup_status'] ) {
			?><section class="fleet-native-setup"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong><?php echo self::e( __( 'Connection approved', 'fleet-for-openstation' ) ); ?></strong><p><?php echo self::e( __( 'Finish the connection, then this site is ready to manage from Fleet.', 'fleet-for-openstation' ) ); ?></p></div><os-button variant="primary" os-action="finish-setup"><?php echo self::e( __( 'Finish connection', 'fleet-for-openstation' ) ); ?></os-button></section><?php
			return;
		}
		$data = OpenStation_Fleet::app_site_data( $id, $section );
		if ( is_wp_error( $data ) ) {
			self::error( $data );
			return;
		}
		switch ( $section ) {
			case 'content': self::render_content( $data ); break;
			case 'media': self::render_media( $data ); break;
			case 'comments': self::render_comments( $data ); break;
			case 'plugins': self::render_plugins( $data ); break;
			case 'users': self::render_users( $data ); break;
			case 'settings': self::render_settings( $data ); break;
			case 'design': self::render_design( $data ); break;
			case 'agency': self::render_agency( $data, $site ); break;
			case 'api': self::render_api( $data, $state ); break;
			default: self::render_overview( $data, $site ); break;
		}
	}

	/** Render overview data. */
	private static function render_overview( $data, $site ) {
		$settings = isset( $data['settings'] ) ? $data['settings'] : array();
		$php_version = ! empty( $site['environment']['php_version'] ) ? $site['environment']['php_version'] : __( 'Unknown', 'fleet-for-openstation' );
		$recent   = array_merge( self::items( isset( $data['posts'] ) ? $data['posts'] : array() ), self::items( isset( $data['pages'] ) ? $data['pages'] : array() ) );
		usort( $recent, static function ( $a, $b ) { return strcmp( isset( $b['modified'] ) ? $b['modified'] : '', isset( $a['modified'] ) ? $a['modified'] : '' ); } );
		// translators: %d: number of site issues.
		$issue_label = empty( $site['attention'] ) ? __( 'Healthy', 'fleet-for-openstation' ) : sprintf( _n( '%d issue', '%d issues', count( $site['attention'] ), 'fleet-for-openstation' ), count( $site['attention'] ) );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Site overview', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'At a glance', 'fleet-for-openstation' ) ); ?></h2></span><os-badge tone="<?php echo empty( $site['attention'] ) ? 'success' : 'warning'; ?>"><?php echo self::e( $issue_label ); ?></os-badge></div>
		<div class="fleet-native-stat-grid">
			<div><small><?php echo self::e( __( 'WordPress', 'fleet-for-openstation' ) ); ?></small><strong><?php echo self::e( $site['wordpress_version'] ? $site['wordpress_version'] : __( 'Unknown', 'fleet-for-openstation' ) ); ?></strong></div>
			<div><small><?php echo self::e( __( 'PHP', 'fleet-for-openstation' ) ); ?></small><strong><?php echo self::e( $php_version ); ?></strong></div>
			<div><small><?php echo self::e( __( 'REST routes', 'fleet-for-openstation' ) ); ?></small><strong><?php echo self::e( isset( $site['capabilities']['route_count'] ) ? $site['capabilities']['route_count'] : 0 ); ?></strong></div>
			<div><small><?php echo self::e( __( 'Site title', 'fleet-for-openstation' ) ); ?></small><strong><?php echo self::e( is_wp_error( $settings ) ? $site['name'] : ( isset( $settings['title'] ) ? $settings['title'] : $site['name'] ) ); ?></strong></div>
		</div>
		<?php foreach ( $site['attention'] as $reason ) : ?><os-notice tone="<?php echo 'critical' === $reason[2] ? 'danger' : 'warning'; ?>"><?php echo self::e( $reason[1] ); ?></os-notice><?php endforeach; ?>
		<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Recently changed', 'fleet-for-openstation' ) ); ?></h3><span><?php echo self::e( __( 'Posts and pages', 'fleet-for-openstation' ) ); ?></span></header>
		<?php if ( empty( $recent ) ) : ?><p class="fleet-native-empty-row"><?php echo self::e( __( 'No recent content was returned.', 'fleet-for-openstation' ) ); ?></p><?php else : foreach ( array_slice( $recent, 0, 8 ) as $item ) : ?><div class="fleet-native-list-row"><span><strong><?php echo self::e( self::title( $item ) ); ?></strong><small><?php echo self::e( ucfirst( isset( $item['type'] ) ? $item['type'] : 'content' ) ); ?></small></span><os-badge><?php echo self::e( isset( $item['status'] ) ? $item['status'] : '' ); ?></os-badge></div><?php endforeach; endif; ?>
		</section>
		<?php
	}

	/** Render posts and pages. */
	private static function render_content( $data ) {
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Posts and pages', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Publishing', 'fleet-for-openstation' ) ); ?></h2></span></div><?php
		foreach ( array( 'posts' => __( 'Posts', 'fleet-for-openstation' ), 'pages' => __( 'Pages', 'fleet-for-openstation' ) ) as $key => $label ) {
			$items = self::items( isset( $data[ $key ] ) ? $data[ $key ] : array() );
			?><section class="fleet-native-panel"><header><h3><?php echo self::e( $label ); ?></h3><os-badge><?php echo self::e( count( $items ) ); ?></os-badge></header><?php
			if ( isset( $data[ $key ] ) && is_wp_error( $data[ $key ] ) ) { self::error( $data[ $key ] ); }
			if ( empty( $items ) && ! ( isset( $data[ $key ] ) && is_wp_error( $data[ $key ] ) ) ) {
				// translators: %s: content collection label, such as posts or pages.
				$empty_label = sprintf( __( 'No %s were returned.', 'fleet-for-openstation' ), strtolower( $label ) );
				?><p class="fleet-native-empty-row"><?php echo self::e( $empty_label ); ?></p><?php
			}
			foreach ( $items as $item ) {
				if ( empty( $item['id'] ) ) { continue; }
				?>
				<os-form class="fleet-native-row-form" os-action="save-content" submit-label="<?php echo self::e( __( 'Save', 'fleet-for-openstation' ) ); ?>" show-reset="false">
					<input type="hidden" name="content_type" value="<?php echo self::e( $key ); ?>"><input type="hidden" name="content_id" value="<?php echo self::e( $item['id'] ); ?>">
					<os-text-field name="title" label="<?php echo self::e( __( 'Title', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( self::title( $item ) ); ?>" required></os-text-field>
					<?php self::status_select( 'status', __( 'Status', 'fleet-for-openstation' ), isset( $item['status'] ) ? $item['status'] : 'draft', array( 'publish' => __( 'Published', 'fleet-for-openstation' ), 'draft' => __( 'Draft', 'fleet-for-openstation' ), 'pending' => __( 'Pending review', 'fleet-for-openstation' ), 'future' => __( 'Scheduled', 'fleet-for-openstation' ), 'private' => __( 'Private', 'fleet-for-openstation' ), 'trash' => __( 'Trash', 'fleet-for-openstation' ) ) ); ?>
				</os-form>
				<?php
			}
			?></section><?php
		}
	}

	/** Render comments. */
	private static function render_comments( $data ) {
		$comments = self::items( isset( $data['comments'] ) ? $data['comments'] : array() );
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Comments', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Moderation queue', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $comments ) ); ?></os-badge></div><?php
		if ( isset( $data['comments'] ) && is_wp_error( $data['comments'] ) ) { self::error( $data['comments'] ); return; }
		if ( empty( $comments ) ) { ?><os-empty-state icon="dashicons-admin-comments" heading="<?php echo self::e( __( 'No comments found', 'fleet-for-openstation' ) ); ?>"></os-empty-state><?php return; }
		?><div class="fleet-native-stack"><?php foreach ( $comments as $comment ) : if ( empty( $comment['id'] ) ) { continue; } ?>
			<os-form class="fleet-native-comment" os-action="save-comment" submit-label="<?php echo self::e( __( 'Update', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
				<div slot="header"><div class="fleet-native-card-head"><span><strong><?php echo self::e( isset( $comment['author_name'] ) ? $comment['author_name'] : __( 'Anonymous', 'fleet-for-openstation' ) ); ?></strong><small><?php echo self::e( ! empty( $comment['date'] ) ? human_time_diff( strtotime( $comment['date'] ), time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) : '' ); ?></small></span><os-badge><?php echo self::e( isset( $comment['status'] ) ? $comment['status'] : 'hold' ); ?></os-badge></div><p><?php echo self::e( wp_trim_words( wp_strip_all_tags( isset( $comment['content']['rendered'] ) ? $comment['content']['rendered'] : '' ), 48 ) ); ?></p></div>
				<input type="hidden" name="comment_id" value="<?php echo self::e( $comment['id'] ); ?>">
				<?php self::status_select( 'status', __( 'Moderation', 'fleet-for-openstation' ), isset( $comment['status'] ) ? $comment['status'] : 'hold', array( 'approved' => __( 'Approved', 'fleet-for-openstation' ), 'hold' => __( 'Pending', 'fleet-for-openstation' ), 'spam' => __( 'Spam', 'fleet-for-openstation' ), 'trash' => __( 'Trash', 'fleet-for-openstation' ) ) ); ?>
			</os-form>
		<?php endforeach; ?></div><?php
	}

	/** Render media metadata. */
	private static function render_media( $data ) {
		$media = self::items( isset( $data['media'] ) ? $data['media'] : array() );
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'WordPress media', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Media library', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $media ) ); ?></os-badge></div><?php
		if ( isset( $data['media'] ) && is_wp_error( $data['media'] ) ) { self::error( $data['media'] ); return; }
		if ( empty( $media ) ) { ?><os-empty-state icon="dashicons-format-image" heading="<?php echo self::e( __( 'No media found', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Images and files from this site will appear here.', 'fleet-for-openstation' ) ); ?></os-empty-state><?php return; }
		?><div class="fleet-native-media-grid"><?php foreach ( $media as $item ) : if ( empty( $item['id'] ) ) { continue; } ?>
			<os-form class="fleet-native-media-card" os-action="save-media" submit-label="<?php echo self::e( __( 'Save details', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
				<div slot="header" class="fleet-native-media-preview"><?php if ( 'image' === ( isset( $item['media_type'] ) ? $item['media_type'] : '' ) && ! empty( $item['source_url'] ) ) : ?><img src="<?php echo self::e( $item['source_url'] ); ?>" alt=""><?php else : ?><span class="dashicons dashicons-media-default" aria-hidden="true"></span><?php endif; ?></div>
				<input type="hidden" name="media_id" value="<?php echo self::e( $item['id'] ); ?>">
				<os-text-field name="title" label="<?php echo self::e( __( 'Title', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( self::title( $item ) ); ?>"></os-text-field>
				<os-text-field name="alt_text" label="<?php echo self::e( __( 'Alt text', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $item['alt_text'] ) ? $item['alt_text'] : '' ); ?>"></os-text-field>
				<os-textarea name="caption" label="<?php echo self::e( __( 'Caption', 'fleet-for-openstation' ) ); ?>" rows="3" value="<?php echo self::e( isset( $item['caption']['raw'] ) ? $item['caption']['raw'] : '' ); ?>"></os-textarea>
			</os-form>
		<?php endforeach; ?></div><?php
	}

	/** Render plugins. */
	private static function render_plugins( $data ) {
		$plugins = self::items( isset( $data['plugins'] ) ? $data['plugins'] : array() );
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'WordPress extensions', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Plugins', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $plugins ) ); ?></os-badge></div>
		<os-form class="fleet-native-panel" os-action="install-plugin" submit-label="<?php echo self::e( __( 'Install plugin', 'fleet-for-openstation' ) ); ?>" show-reset="false">
			<div slot="header"><h3><?php echo self::e( __( 'Install from WordPress.org', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'Use the directory slug, such as akismet.', 'fleet-for-openstation' ) ); ?></p></div>
			<os-text-field name="plugin_slug" label="<?php echo self::e( __( 'Plugin slug', 'fleet-for-openstation' ) ); ?>" placeholder="plugin-slug" required></os-text-field>
			<?php self::status_select( 'status', __( 'After installation', 'fleet-for-openstation' ), 'active', array( 'active' => __( 'Install and activate', 'fleet-for-openstation' ), 'inactive' => __( 'Install only', 'fleet-for-openstation' ) ) ); ?>
		</os-form><?php
		if ( isset( $data['plugins'] ) && is_wp_error( $data['plugins'] ) ) { self::error( $data['plugins'] ); return; }
		if ( empty( $plugins ) ) { ?><p class="fleet-native-empty-row fleet-native-panel"><?php echo self::e( __( 'No plugins were returned by this site.', 'fleet-for-openstation' ) ); ?></p><?php return; }
		?><div class="fleet-native-stack"><?php foreach ( $plugins as $plugin ) : if ( empty( $plugin['plugin'] ) ) { continue; } $active = in_array( isset( $plugin['status'] ) ? $plugin['status'] : '', array( 'active', 'network-active' ), true ); ?>
			<?php // translators: %s: installed plugin version. ?>
			<?php $version_label = isset( $plugin['version'] ) ? sprintf( __( 'Version %s', 'fleet-for-openstation' ), $plugin['version'] ) : $plugin['plugin']; ?>
				<article class="fleet-native-plugin-row"><span class="fleet-native-plugin-icon dashicons <?php echo OpenStation_Fleet::PLUGIN_REST_ID === $plugin['plugin'] ? 'dashicons-desktop' : 'dashicons-admin-plugins'; ?>" aria-hidden="true"></span><span><strong><?php echo self::e( isset( $plugin['name'] ) ? wp_strip_all_tags( $plugin['name'] ) : $plugin['plugin'] ); ?></strong><small><?php echo self::e( $version_label ); ?></small></span><os-badge tone="<?php echo $active ? 'success' : 'neutral'; ?>"><?php echo self::e( $active ? __( 'Active', 'fleet-for-openstation' ) : __( 'Inactive', 'fleet-for-openstation' ) ); ?></os-badge>
			<?php if ( 'network-active' !== ( isset( $plugin['status'] ) ? $plugin['status'] : '' ) && ! ( OpenStation_Fleet::PLUGIN_REST_ID === $plugin['plugin'] && $active ) ) : ?><os-button variant="secondary" os-action="change-plugin" os-arg-plugin="<?php echo self::e( $plugin['plugin'] ); ?>" os-arg-status="<?php echo $active ? 'inactive' : 'active'; ?>"><?php echo self::e( $active ? __( 'Deactivate', 'fleet-for-openstation' ) : __( 'Activate', 'fleet-for-openstation' ) ); ?></os-button><?php endif; ?>
			</article>
		<?php endforeach; ?></div><?php
	}

	/** Render users. */
	private static function render_users( $data ) {
		$users = self::items( isset( $data['users'] ) ? $data['users'] : array() );
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'People and access', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Users', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $users ) ); ?></os-badge></div>
		<os-form class="fleet-native-panel" os-action="create-user" submit-label="<?php echo self::e( __( 'Create user', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="2">
			<div slot="header"><h3><?php echo self::e( __( 'Add a user', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'The password is sent once and is never stored by Fleet.', 'fleet-for-openstation' ) ); ?></p></div>
			<os-text-field name="username" label="<?php echo self::e( __( 'Username', 'fleet-for-openstation' ) ); ?>" required></os-text-field><os-text-field name="email" type="email" label="<?php echo self::e( __( 'Email', 'fleet-for-openstation' ) ); ?>" required></os-text-field><os-text-field name="password" type="password" label="<?php echo self::e( __( 'Password', 'fleet-for-openstation' ) ); ?>" required></os-text-field><?php self::role_select( 'role', 'subscriber' ); ?>
		</os-form><?php
		if ( isset( $data['users'] ) && is_wp_error( $data['users'] ) ) { self::error( $data['users'] ); return; }
		if ( empty( $users ) ) { ?><p class="fleet-native-empty-row fleet-native-panel"><?php echo self::e( __( 'No users were returned by this site.', 'fleet-for-openstation' ) ); ?></p><?php return; }
		?><div class="fleet-native-stack"><?php foreach ( $users as $user ) : if ( empty( $user['id'] ) ) { continue; } ?>
			<os-form class="fleet-native-row-form" os-action="save-user" submit-label="<?php echo self::e( __( 'Save', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="3">
				<div slot="header" class="fleet-native-user-head"><?php if ( ! empty( $user['avatar_urls']['48'] ) ) : ?><img src="<?php echo self::e( $user['avatar_urls']['48'] ); ?>" alt=""><?php endif; ?><span><strong><?php echo self::e( isset( $user['username'] ) ? $user['username'] : '' ); ?></strong><small><?php echo self::e( implode( ', ', isset( $user['roles'] ) && is_array( $user['roles'] ) ? $user['roles'] : array() ) ); ?></small></span></div>
				<input type="hidden" name="user_id" value="<?php echo self::e( $user['id'] ); ?>"><os-text-field name="name" label="<?php echo self::e( __( 'Display name', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $user['name'] ) ? $user['name'] : '' ); ?>" required></os-text-field><os-text-field name="email" type="email" label="<?php echo self::e( __( 'Email', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $user['email'] ) ? $user['email'] : '' ); ?>" required></os-text-field><?php self::role_select( 'role', isset( $user['roles'][0] ) ? $user['roles'][0] : 'subscriber' ); ?>
			</os-form>
		<?php endforeach; ?></div><?php
	}

	/** Render settings. */
	private static function render_settings( $data ) {
		$settings = isset( $data['settings'] ) ? $data['settings'] : array();
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'WordPress settings', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Site identity and time', 'fleet-for-openstation' ) ); ?></h2></span></div><?php
		if ( is_wp_error( $settings ) ) { self::error( $settings ); return; }
		?><os-form class="fleet-native-panel" os-action="save-settings" submit-label="<?php echo self::e( __( 'Save site settings', 'fleet-for-openstation' ) ); ?>" show-reset="false">
			<os-text-field name="title" label="<?php echo self::e( __( 'Site title', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['title'] ) ? $settings['title'] : '' ); ?>" full-width required></os-text-field>
			<os-text-field name="description" label="<?php echo self::e( __( 'Tagline', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['description'] ) ? $settings['description'] : '' ); ?>" full-width></os-text-field>
			<os-text-field name="timezone_string" label="<?php echo self::e( __( 'Timezone', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['timezone_string'] ) ? $settings['timezone_string'] : '' ); ?>" placeholder="America/Chicago"></os-text-field>
			<?php self::status_select( 'start_of_week', __( 'Week starts on', 'fleet-for-openstation' ), (string) ( isset( $settings['start_of_week'] ) ? $settings['start_of_week'] : 0 ), array( '0' => __( 'Sunday', 'fleet-for-openstation' ), '1' => __( 'Monday', 'fleet-for-openstation' ), '2' => __( 'Tuesday', 'fleet-for-openstation' ), '3' => __( 'Wednesday', 'fleet-for-openstation' ), '4' => __( 'Thursday', 'fleet-for-openstation' ), '5' => __( 'Friday', 'fleet-for-openstation' ), '6' => __( 'Saturday', 'fleet-for-openstation' ) ) ); ?>
			<os-text-field name="date_format" label="<?php echo self::e( __( 'Date format', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['date_format'] ) ? $settings['date_format'] : 'F j, Y' ); ?>"></os-text-field><os-text-field name="time_format" label="<?php echo self::e( __( 'Time format', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['time_format'] ) ? $settings['time_format'] : 'g:i a' ); ?>"></os-text-field>
		</os-form><?php
	}

	/** Render the modern Core design inventory. */
	private static function render_design( $data ) {
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Themes and the Site Editor', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Site design', 'fleet-for-openstation' ) ); ?></h2></span></div>
		<os-notice tone="info"><?php echo self::e( __( 'This live inventory uses Core themes, templates, template parts, and Navigation APIs. Use Explorer for schema-driven edits to any advertised design route.', 'fleet-for-openstation' ) ); ?></os-notice>
		<div class="fleet-native-design-grid"><?php foreach ( array( 'themes' => __( 'Active theme', 'fleet-for-openstation' ), 'templates' => __( 'Templates', 'fleet-for-openstation' ), 'template_parts' => __( 'Template parts', 'fleet-for-openstation' ), 'navigation' => __( 'Navigation', 'fleet-for-openstation' ), 'font_families' => __( 'Font library', 'fleet-for-openstation' ), 'patterns' => __( 'Synced patterns', 'fleet-for-openstation' ) ) as $key => $label ) : $items = self::items( isset( $data[ $key ] ) ? $data[ $key ] : array() ); ?>
			<section class="fleet-native-panel"><header><h3><?php echo self::e( $label ); ?></h3><os-badge><?php echo self::e( count( $items ) ); ?></os-badge></header>
			<?php if ( isset( $data[ $key ] ) && is_wp_error( $data[ $key ] ) ) : ?><p class="fleet-native-empty-row"><?php echo self::e( __( 'Not exposed by this site.', 'fleet-for-openstation' ) ); ?></p><?php elseif ( empty( $items ) ) : ?><p class="fleet-native-empty-row"><?php echo self::e( __( 'Nothing returned.', 'fleet-for-openstation' ) ); ?></p><?php else : foreach ( array_slice( $items, 0, 10 ) as $item ) : ?><div class="fleet-native-list-row"><span><strong><?php echo self::e( self::title( $item ) ); ?></strong><small><?php echo self::e( isset( $item['slug'] ) ? $item['slug'] : ( isset( $item['stylesheet'] ) ? $item['stylesheet'] : '' ) ); ?></small></span></div><?php endforeach; endif; ?>
			</section>
		<?php endforeach; ?></div>
		<?php if ( ! empty( $data['global_styles'] ) && ! is_wp_error( $data['global_styles'] ) ) : ?><section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Global Styles', 'fleet-for-openstation' ) ); ?></h3><os-badge tone="success"><?php echo self::e( __( 'Available', 'fleet-for-openstation' ) ); ?></os-badge></header><p class="fleet-native-empty-row"><?php echo self::e( __( 'The active block theme exposes editable settings and styles through WordPress Core.', 'fleet-for-openstation' ) ); ?></p></section><?php endif; ?><?php
	}

	/** Render hub-only agency metadata. */
	private static function render_agency( $data, $site ) {
		$agency = isset( $data['agency'] ) ? $data['agency'] : array();
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Private to your hub', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Client notes', 'fleet-for-openstation' ) ); ?></h2></span></div>
		<div class="fleet-native-two-column"><os-form class="fleet-native-panel" os-action="save-agency" submit-label="<?php echo self::e( __( 'Save agency profile', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
			<os-text-field name="client_name" label="<?php echo self::e( __( 'Client name', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $agency['client_name'] ) ? $agency['client_name'] : '' ); ?>"></os-text-field>
			<?php self::status_select( 'plan_status', __( 'Maintenance plan', 'fleet-for-openstation' ), isset( $agency['plan_status'] ) ? $agency['plan_status'] : 'none', array( 'none' => __( 'Not assigned', 'fleet-for-openstation' ), 'active' => __( 'Active', 'fleet-for-openstation' ), 'paused' => __( 'Paused', 'fleet-for-openstation' ), 'ended' => __( 'Ended', 'fleet-for-openstation' ) ) ); ?>
			<os-text-field name="tags" label="<?php echo self::e( __( 'Tags', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( ! empty( $agency['tags'] ) ? implode( ', ', $agency['tags'] ) : '' ); ?>" placeholder="client, ecommerce, priority"></os-text-field>
			<os-textarea name="notes" label="<?php echo self::e( __( 'Private notes', 'fleet-for-openstation' ) ); ?>" rows="6" value="<?php echo self::e( isset( $agency['notes'] ) ? $agency['notes'] : '' ); ?>"></os-textarea>
			<os-checkbox name="favorite" label="<?php echo self::e( __( 'Favorite this site', 'fleet-for-openstation' ) ); ?>" <?php echo ! empty( $agency['favorite'] ) ? 'checked' : ''; ?>></os-checkbox>
		</os-form><section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Connection inventory', 'fleet-for-openstation' ) ); ?></h3></header><dl class="fleet-native-facts"><div><dt><?php echo self::e( __( 'WordPress', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( $site['wordpress_version'] ? $site['wordpress_version'] : __( 'Not reported', 'fleet-for-openstation' ) ); ?></dd></div><div><dt><?php echo self::e( __( 'REST routes', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( isset( $site['capabilities']['route_count'] ) ? $site['capabilities']['route_count'] : 0 ); ?></dd></div><div><dt><?php echo self::e( __( 'Namespaces', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( ! empty( $site['capabilities']['namespaces'] ) ? implode( ', ', array_slice( $site['capabilities']['namespaces'], 0, 12 ) ) : __( 'Not checked', 'fleet-for-openstation' ) ); ?></dd></div></dl></section></div><?php
	}

	/** Render schema-discovered routes and WordPress 6.9+ Abilities. */
	private static function render_api( $data, $state ) {
		$catalog   = isset( $data['catalog'] ) ? $data['catalog'] : array();
		$abilities = isset( $data['abilities'] ) ? $data['abilities'] : array();
		if ( is_array( $abilities ) && isset( $abilities['abilities'] ) && is_array( $abilities['abilities'] ) ) {
			$abilities = $abilities['abilities'];
		}
		$response  = (string) $state->get( 'response' );
		?><div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Developer tools', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'API Explorer', 'fleet-for-openstation' ) ); ?></h2></span><?php if ( ! is_wp_error( $catalog ) ) : ?><os-badge><?php echo self::e( count( $catalog ) ); ?> <?php echo self::e( __( 'routes', 'fleet-for-openstation' ) ); ?></os-badge><?php endif; ?></div>
		<os-notice tone="info"><?php echo self::e( __( 'Fleet reads the site’s live REST index and uses the approved account’s permissions. WordPress 6.9+ Abilities are discovered automatically, and supported Core Abilities are used for richer site facts.', 'fleet-for-openstation' ) ); ?></os-notice>
		<div class="fleet-native-two-column"><section class="fleet-native-api-controls"><os-form class="fleet-native-panel" os-action="api-request" submit-label="<?php echo self::e( __( 'Read route', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
			<div slot="header"><h3><?php echo self::e( __( 'Read from WordPress', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'GET requests do not change the managed site.', 'fleet-for-openstation' ) ); ?></p></div>
			<input type="hidden" name="api_method" value="GET">
			<os-text-field name="api_route" label="<?php echo self::e( __( 'REST route', 'fleet-for-openstation' ) ); ?>" placeholder="wp/v2/posts?context=edit" required></os-text-field>
		</os-form><details class="fleet-native-api-write"><summary><?php echo self::e( __( 'Write to a route', 'fleet-for-openstation' ) ); ?></summary><os-form class="fleet-native-panel" os-action="api-request" submit-label="<?php echo self::e( __( 'Review and send', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1" os-confirm="<?php echo self::e( __( 'This authenticated request can change or delete data on the managed site.', 'fleet-for-openstation' ) ); ?>" os-confirm-title="<?php echo self::e( __( 'Send write request?', 'fleet-for-openstation' ) ); ?>" os-confirm-label="<?php echo self::e( __( 'Send request', 'fleet-for-openstation' ) ); ?>" os-confirm-danger>
			<?php self::status_select( 'api_method', __( 'HTTP method', 'fleet-for-openstation' ), 'POST', array( 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE' ) ); ?>
			<os-text-field name="api_route" label="<?php echo self::e( __( 'REST route', 'fleet-for-openstation' ) ); ?>" placeholder="wp/v2/posts/123" required></os-text-field>
			<os-textarea name="api_body" label="<?php echo self::e( __( 'JSON body', 'fleet-for-openstation' ) ); ?>" rows="8" placeholder="{ &quot;title&quot;: &quot;Updated from Fleet&quot; }"></os-textarea>
		</os-form></details></section><section class="fleet-native-panel fleet-native-response"><header><h3><?php echo self::e( __( 'Response', 'fleet-for-openstation' ) ); ?></h3></header><?php if ( '' === $response ) : ?><p class="fleet-native-empty-row"><?php echo self::e( __( 'Send a request to see its JSON response.', 'fleet-for-openstation' ) ); ?></p><?php else : ?><pre><code><?php echo self::e( $response ); ?></code></pre><?php endif; ?></section></div>
		<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Core Abilities', 'fleet-for-openstation' ) ); ?></h3><os-badge><?php echo self::e( is_wp_error( $abilities ) ? 0 : count( $abilities ) ); ?></os-badge></header><?php if ( is_wp_error( $abilities ) ) : ?><p class="fleet-native-empty-row"><?php echo self::e( __( 'This site does not expose the WordPress Abilities API.', 'fleet-for-openstation' ) ); ?></p><?php else : foreach ( array_slice( $abilities, 0, 20 ) as $ability ) : ?><div class="fleet-native-list-row"><span><strong><?php echo self::e( isset( $ability['label'] ) ? $ability['label'] : ( isset( $ability['name'] ) ? $ability['name'] : __( 'Ability', 'fleet-for-openstation' ) ) ); ?></strong><small><?php echo self::e( isset( $ability['name'] ) ? $ability['name'] : '' ); ?></small></span><?php if ( ! empty( $ability['meta']['annotations']['readonly'] ) ) : ?><os-badge><?php echo self::e( __( 'Read only', 'fleet-for-openstation' ) ); ?></os-badge><?php endif; ?></div><?php endforeach; endif; ?></section>
		<?php if ( is_wp_error( $catalog ) ) : self::error( $catalog ); else : ?><section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Advertised REST routes', 'fleet-for-openstation' ) ); ?></h3><os-badge><?php echo self::e( count( $catalog ) ); ?></os-badge></header><div class="fleet-native-route-list"><?php foreach ( array_slice( $catalog, 0, 120 ) as $route ) : ?><div><code><?php echo self::e( $route['route'] ); ?></code><small><?php echo self::e( implode( ' · ', $route['methods'] ) ); ?></small></div><?php endforeach; ?></div></section><?php endif; ?>
		<?php
	}

	/** Shared native hub header. */
	private static function hub_header( $data, $state, $title, $description ) {
		self::notice( $state );
		// translators: %d: number of connected WordPress sites.
		$connected_label = sprintf( _n( '%d connected site', '%d connected sites', $data['counts']['sites'], 'fleet-for-openstation' ), $data['counts']['sites'] );
		?>
		<header class="fleet-native-hero"><div class="fleet-native-mark"><span class="dashicons dashicons-networking" aria-hidden="true"></span></div><span><small><?php echo self::e( __( 'Fleet command center', 'fleet-for-openstation' ) ); ?></small><h1><?php echo self::e( $title ); ?></h1><p><?php echo self::e( $description ); ?></p></span><div class="fleet-native-hero-count" aria-label="<?php echo self::e( $connected_label ); ?>"><strong><?php echo self::e( $data['counts']['sites'] ); ?></strong><small><?php echo self::e( __( 'connected', 'fleet-for-openstation' ) ); ?></small></div></header>
		<?php
	}

	/** Shared managed-site header. */
	private static function site_header( $site, $state ) {
		self::notice( $state );
		?>
		<header class="fleet-native-site-header"><div class="fleet-native-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><i class="<?php echo empty( $site['error'] ) ? 'is-good' : 'is-bad'; ?>" aria-hidden="true"></i></div><span><small><?php echo self::e( __( 'Connected WordPress site', 'fleet-for-openstation' ) ); ?></small><h1><?php echo self::e( $site['name'] ); ?></h1><p><?php echo self::e( $site['host'] . ( $site['wordpress_version'] ? ' · WordPress ' . $site['wordpress_version'] : '' ) ); ?></p></span><div class="fleet-native-site-badges"><os-badge tone="<?php echo empty( $site['error'] ) ? 'success' : 'danger'; ?>"><?php echo self::e( empty( $site['error'] ) ? __( 'Connected', 'fleet-for-openstation' ) : __( 'Connection issue', 'fleet-for-openstation' ) ); ?></os-badge><?php if ( 'active' === ( isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : '' ) ) : ?><os-badge><?php echo self::e( __( 'OpenStation installed', 'fleet-for-openstation' ) ); ?></os-badge><?php endif; ?></div></header>
		<?php if ( $site['error'] ) : ?><os-notice tone="danger"><?php echo self::e( $site['error'] ); ?></os-notice><?php endif; ?>
		<?php
	}

	/** Render a site card. */
	private static function site_card( $site ) {
		$tone = $site['error'] || ! empty( $site['attention'] ) ? 'warning' : 'success';
		?>
		<article class="fleet-native-site-card" data-tone="<?php echo self::e( $tone ); ?>">
			<button type="button" class="fleet-native-site-open" os-action="open-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>"><span class="fleet-native-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><i class="<?php echo $site['error'] ? 'is-bad' : 'is-good'; ?>" aria-hidden="true"></i></span><span><strong><?php echo self::e( $site['name'] ); ?></strong><small><?php echo self::e( $site['host'] ); ?></small></span></button>
			<div class="fleet-native-site-meta"><span><?php echo self::e( $site['wordpress_version'] ? 'WordPress ' . $site['wordpress_version'] : __( 'WordPress version unknown', 'fleet-for-openstation' ) ); ?></span><?php if ( $site['agency']['client_name'] ) : ?><os-badge><?php echo self::e( $site['agency']['client_name'] ); ?></os-badge><?php endif; ?></div>
			<?php if ( $site['error'] ) : ?><p class="fleet-native-alert" data-tone="danger"><?php echo self::e( $site['error'] ); ?></p><?php elseif ( ! empty( $site['attention'] ) ) : ?><p class="fleet-native-alert" data-tone="warning"><?php echo self::e( $site['attention'][0][1] ); ?></p><?php endif; ?>
			<footer><os-button variant="ghost" os-action="favorite" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>" icon="dashicons-star-<?php echo $site['agency']['favorite'] ? 'filled' : 'empty'; ?>"><?php echo self::e( $site['agency']['favorite'] ? __( 'Favorited', 'fleet-for-openstation' ) : __( 'Favorite', 'fleet-for-openstation' ) ); ?></os-button><span class="fleet-native-card-actions"><os-button variant="secondary" os-action="refresh-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>"><?php echo self::e( __( 'Check', 'fleet-for-openstation' ) ); ?></os-button><os-button variant="primary" os-action="open-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>"><?php echo self::e( __( 'Manage', 'fleet-for-openstation' ) ); ?></os-button></span></footer>
		</article>
		<?php
	}

	/** Render the connection form. */
	private static function connect_form() {
		?>
		<os-form class="fleet-native-connect" os-action="connect" submit-label="<?php echo self::e( __( 'Connect site', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
			<div slot="header"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span><h3><?php echo self::e( __( 'Connect another WordPress site', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'The managed site needs only WordPress Core and HTTPS—no Fleet plugin installation.', 'fleet-for-openstation' ) ); ?></p></span></div>
			<os-text-field name="site_url" type="url" label="<?php echo self::e( __( 'Site address', 'fleet-for-openstation' ) ); ?>" placeholder="https://client.example" required></os-text-field>
		</os-form>
		<?php
	}

	/** Add an open-site custom effect. */
	private static function open_site( $os, $id ) {
		$id   = sanitize_key( $id );
		$site = OpenStation_Fleet::app_site( $id );
		if ( is_wp_error( $site ) ) {
			$os->toast( $site->get_error_message() );
			return;
		}
		$os->effects->add( 'fleet-open-site', array( 'siteId' => $id, 'title' => $site['name'] ) );
	}

	/** Normalize os-form values. */
	private static function values( $args ) {
		return isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
	}

	/** Render a state notice. */
	private static function notice( $state ) {
		$notice = sanitize_text_field( (string) $state->get( 'notice' ) );
		if ( '' !== $notice ) {
			?><os-notice tone="danger"><?php echo self::e( $notice ); ?></os-notice><?php
		}
	}

	/** Render a remote error. */
	private static function error( $error ) {
		?><os-notice tone="danger"><?php echo self::e( is_wp_error( $error ) ? $error->get_error_message() : __( 'The managed site returned an unexpected response.', 'fleet-for-openstation' ) ); ?></os-notice><?php
	}

	/** Convert a possible WP_Error collection to a list. */
	private static function items( $value ) {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/** Return a plain title from a Core REST record. */
	private static function title( $item ) {
		$title = isset( $item['title'] ) ? $item['title'] : '';
		if ( is_array( $title ) ) {
			$title = isset( $title['raw'] ) && is_scalar( $title['raw'] ) && '' !== (string) $title['raw']
				? $title['raw']
				: ( isset( $title['rendered'] ) && is_scalar( $title['rendered'] ) ? $title['rendered'] : '' );
		}
		if ( '' === (string) $title && isset( $item['name'] ) ) {
			$name  = $item['name'];
			$title = is_scalar( $name ) ? $name : ( is_array( $name ) && isset( $name['rendered'] ) && is_scalar( $name['rendered'] ) ? $name['rendered'] : '' );
		}
		return '' !== (string) $title ? sanitize_text_field( wp_strip_all_tags( $title ) ) : __( '(Untitled)', 'fleet-for-openstation' );
	}

	/** Render a named os-select. */
	private static function status_select( $name, $label, $current, $options ) {
		?><os-select name="<?php echo self::e( $name ); ?>" label="<?php echo self::e( $label ); ?>" value="<?php echo self::e( $current ); ?>"><?php foreach ( $options as $value => $option_label ) : ?><os-option value="<?php echo self::e( $value ); ?>"><?php echo self::e( $option_label ); ?></os-option><?php endforeach; ?></os-select><?php
	}

	/** Render the common WordPress role picker. */
	private static function role_select( $name, $current ) {
		self::status_select( $name, __( 'Role', 'fleet-for-openstation' ), $current, array( 'administrator' => __( 'Administrator', 'fleet-for-openstation' ), 'editor' => __( 'Editor', 'fleet-for-openstation' ), 'author' => __( 'Author', 'fleet-for-openstation' ), 'contributor' => __( 'Contributor', 'fleet-for-openstation' ), 'subscriber' => __( 'Subscriber', 'fleet-for-openstation' ) ) );
	}

	/** Escape dynamic text and attribute values with the framework helper. */
	private static function e( $value ) {
		return \OpenStation\App\Html\esc( (string) $value );
	}

	/** Encode a dynamic JSON attribute with the framework helper. */
	private static function j( $value ) {
		return \OpenStation\App\Html\json( $value );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
