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
	 * @param string                $action Action name.
	 * @param OpenStation\App\State $state  App state.
	 * @param OpenStation\App\Os    $os     Host handle.
	 * @param array                 $args   Dispatch arguments.
	 */
	public static function hub_action( $action, $state, $os, $args ) {
		$values = self::values( $args );
		$state->set( 'notice', '' );
		if ( self::connection_action( $action, $state, $os ) ) {
			return;
		}

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
			$state->set( 'connection', $result );
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

		$id  = sanitize_key( isset( $args['site-id'] ) ? $args['site-id'] : ( isset( $args['siteId'] ) ? $args['siteId'] : '' ) );
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
	 * @param string                $action Action name.
	 * @param OpenStation\App\State $state  App state.
	 * @param OpenStation\App\Os    $os     Host handle.
	 * @param array                 $args   Dispatch arguments.
	 */
	public static function site_action( $action, $state, $os, $args ) {
		$id = sanitize_key( (string) $state->get( 'site_id' ) );
		$state->set( 'notice', '' );
		$state->set( 'saved', '' );
		if ( self::connection_action( $action, $state, $os ) ) {
			return;
		}
		if ( 'reconnect' === $action ) {
			$site   = OpenStation_Fleet::app_site( $id );
			$result = is_wp_error( $site ) ? $site : OpenStation_Fleet::app_connect( $site['url'], true );
			$state->set( is_wp_error( $result ) ? 'notice' : 'connection', is_wp_error( $result ) ? $result->get_error_message() : $result );
			return;
		}
		$values = array_merge( self::values( $args ), $args );
		unset( $values['values'] );
		if ( in_array( $action, array( 'save-view', 'apply-view', 'delete-view' ), true ) ) {
			$state->set( 'views_open', true );
			$collections = (array) $state->get( 'collections' );
			$options     = isset( $collections['content'] ) ? $collections['content'] : array();
			$views       = OpenStation_Fleet::work_views( $id, 'apply-view' === $action ? 'read' : ( 'save-view' === $action ? 'save' : 'delete' ), array_merge( $options, $values ) );
			if ( is_wp_error( $views ) ) {
				$state->set( 'notice', $views->get_error_message() );
			} elseif ( 'apply-view' === $action ) {
				$key = isset( $values['view_id'] ) ? $values['view_id'] : '';
				if ( isset( $views[ $key ] ) ) {
					$collections['content'] = array_merge( $views[ $key ], array( 'page' => 1 ) );
					$state->set( 'collections', $collections );
				}
			} else {
				$state->set( 'saved', __( 'Saved views updated for this site.', 'fleet-for-openstation' ) );
			}
			return;
		}
		if ( in_array( $action, array( 'cancel-review', 'close-history' ), true ) ) {
			$state->set( 'review', array() );
			$state->set( 'history', array() );
			$state->set( 'revision', array() );
			return;
		}
		if ( 'revision-history' === $action ) {
			$page   = max( 1, isset( $values['page'] ) ? absint( $values['page'] ) : 1 );
			$result = OpenStation_Fleet::content_revisions( $id, (array) $state->get( 'editor' ), 0, $page );
			if ( is_wp_error( $result ) ) {
				$state->set( 'notice', $result->get_error_message() );
			} else {
				$result['page'] = $page;
				$state->set( 'history', $result );
			}
			return;
		}
		if ( 'preview-revision' === $action || 'use-revision' === $action ) {
			$editor            = (array) $state->get( 'editor' );
			$selected_revision = (array) $state->get( 'revision' );
			$revision_id       = 'use-revision' === $action ? absint( isset( $selected_revision['id'] ) ? $selected_revision['id'] : 0 ) : absint( isset( $values['revision_id'] ) ? $values['revision_id'] : 0 );
			$result            = OpenStation_Fleet::content_revisions( $id, $editor, $revision_id );
			if ( ! $revision_id || is_wp_error( $result ) ) {
				$state->set( 'notice', is_wp_error( $result ) ? $result->get_error_message() : __( 'Choose a revision.', 'fleet-for-openstation' ) );
				return;
			}
			if ( 'preview-revision' === $action ) {
				$state->set( 'revision', $result );
			} else {
				$fields = OpenStation_Fleet_Content::editable( $result );
				foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
					$editor[ $field ] = $fields[ $field ];
				}
				$state->set( 'editor', $editor );
				$state->set( 'history', array() );
				$state->set( 'revision', array() );
				$os->effects->add( 'fleet-editor-dirty', array() );
				$os->toast( __( 'Revision loaded into the editor. Nothing has been saved yet.', 'fleet-for-openstation' ) );
			}
			return;
		}
		$confirming = 'confirm-content' === $action;
		if ( $confirming ) {
			$review = (array) $state->get( 'review' );
			$values = array_merge(
				(array) $state->get( 'editor' ),
				array(
					'review_token'   => isset( $review['token'] ) ? $review['token'] : '',
					'review_expires' => isset( $review['expires'] ) ? $review['expires'] : 0,
				)
			);
			$args   = $values;
			$action = 'save-content';
		}
		if ( 'save-content' === $action && ! $confirming ) {
			$editor = (array) $state->get( 'editor' );
			foreach ( array_keys( $editor ) as $key ) {
				if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && strlen( $values[ $key ] ) <= 200000 ) {
					$editor[ $key ] = $values[ $key ];
				}
			}
			$state->set( 'editor', $editor );
			if ( in_array( isset( $values['status'] ) ? $values['status'] : '', array( 'publish', 'future' ), true ) || in_array( isset( $editor['original_status'] ) ? $editor['original_status'] : '', array( 'publish', 'future' ), true ) ) {
				$result = OpenStation_Fleet::content_review( $id, $values );
				$state->set( is_wp_error( $result ) ? 'notice' : 'review', is_wp_error( $result ) ? $result->get_error_message() : $result );
				return;
			}
		}
		if ( 'browse' === $action ) {
			$section = isset( $values['section'] ) ? sanitize_key( $values['section'] ) : 'content';
			if ( in_array( $section, array( 'content', 'comments', 'media', 'users' ), true ) ) {
				$collections             = (array) $state->get( 'collections' );
				$previous                = isset( $collections[ $section ] ) && is_array( $collections[ $section ] ) ? $collections[ $section ] : array();
				$collections[ $section ] = array_merge( $previous, array_intersect_key( $values, array_flip( array( 'type', 'page', 'search', 'status', 'period' ) ) ) );
				$state->set( 'collections', $collections );
			}
			return;
		}
		if ( in_array( $action, array( 'new-content', 'edit-content', 'close-editor' ), true ) ) {
			$editor = array();
			if ( 'close-editor' !== $action ) {
				$type  = isset( $values['type'] ) ? sanitize_key( $values['type'] ) : 'posts';
				$types = OpenStation_Fleet::app_site_data( $id, 'content-types' );
				if ( is_wp_error( $types ) || ! isset( $types[ $type ] ) ) {
					$state->set( 'notice', __( 'That content type is unavailable.', 'fleet-for-openstation' ) );
					return;
				}
				$row      = isset( $values['row'] ) && is_array( $values['row'] ) ? $values['row'] : array();
				$selected = 'new-content' === $action ? 0 : absint( isset( $row['id'] ) ? $row['id'] : 0 );
				$item     = $selected ? OpenStation_Fleet::app_site_data(
					$id,
					'content',
					array(
						'type'     => $type,
						'selected' => $selected,
					)
				) : array( 'item' => array() );
				if ( is_wp_error( $item ) ) {
					$state->set( 'notice', $item->get_error_message() );
					return;
				}
				$editor = array_merge(
					OpenStation_Fleet_Content::editable( $item['item'] ),
					array(
						'content_type'    => $type,
						'content_id'      => $selected,
						'fingerprint'     => $selected ? OpenStation_Fleet_Content::fingerprint( $item['item'] ) : '',
						'request_id'      => wp_generate_uuid4(),
						'original_status' => isset( $item['item']['status'] ) ? $item['item']['status'] : 'draft',
						'descriptor'      => $types[ $type ],
					)
				);
			}
			$state->set( 'editor', $editor );
			$state->set( 'review', array() );
			$state->set( 'history', array() );
			$state->set( 'revision', array() );
			$os->effects->add( 'fleet-editor-clean', array() );
			return;
		}
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
			'trash-content'       => 'trash-content',
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
			if ( 'save-content' === $action ) {
				$editor = (array) $state->get( 'editor' );
				foreach ( array_keys( $editor ) as $key ) {
					if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && strlen( $values[ $key ] ) <= 200000 ) {
						$editor[ $key ] = $values[ $key ];
					}
				}
				$state->set( 'editor', $editor );
			}
			$state->set( 'notice', $result->get_error_message() );
			return;
		}
		if ( 'save-content' === $action || 'trash-content' === $action ) {
			$state->set( 'editor', array() );
			$state->set( 'review', array() );
			$os->effects->add( 'fleet-editor-clean', array() );
		}
		$state->set( 'saved', isset( $result['message'] ) ? $result['message'] : '' );
		$os->announce( 'fleet', 'updated', array() );
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

	/** Render local, explicitly redacted support information. */
	public static function render_support() {
		$report = wp_json_encode( OpenStation_Fleet::diagnostics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Help and compatibility', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Keep Fleet running', 'fleet-for-openstation' ) ); ?></h2></span></div>
		<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Before sharing a report', 'fleet-for-openstation' ) ); ?></h3></header><p class="fleet-native-empty-row"><?php echo self::e( __( 'This report includes only versions, readiness checks and counts. It excludes site addresses, people, notes, credentials and error bodies. Nothing is sent automatically. Review it before sharing.', 'fleet-for-openstation' ) ); ?></p>
		<os-textarea label="<?php echo self::e( __( 'Redacted support report', 'fleet-for-openstation' ) ); ?>" rows="15" readonly value="<?php echo self::e( $report ); ?>"></os-textarea></section>
		<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Troubleshooting', 'fleet-for-openstation' ) ); ?></h3></header><p class="fleet-native-empty-row"><?php echo self::e( __( 'Access revoked? Use Repair connection from the site window. Installation blocked? Fix the host permissions and retry Finish setup. Checks delayed? Verify WordPress cron or your host’s scheduled cron job.', 'fleet-for-openstation' ) ); ?></p><p class="fleet-native-empty-row"><a href="https://github.com/RegionallyFamous/fleet-for-openstation/wiki/Troubleshooting" target="_blank" rel="noopener noreferrer"><?php echo self::e( __( 'Read the troubleshooting guide', 'fleet-for-openstation' ) ); ?></a></p></section>
		<?php
	}

	/**
	 * Render the hub's Sites tab.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
	public static function render_sites( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'sites' );
		self::hub_header( $data, $state, __( 'Your sites', 'fleet-for-openstation' ), __( 'Every WordPress site you look after, ready in its own window.', 'fleet-for-openstation' ) );
		if ( self::connection_review( $state ) ) {
			return;
		}
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

	/**
	 * Render the hub Inbox tab.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
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
						<?php
						if ( $count ) :
							?>
							<span><strong><?php echo self::e( $count ); ?></strong> <?php echo self::e( $label ); ?></span><?php endif; ?>
					<?php endforeach; ?>
				</div>
				<?php
				foreach ( $site['attention'] as $reason ) :
					?>
					<p class="fleet-native-alert" data-tone="<?php echo 'critical' === $reason[2] ? 'danger' : 'warning'; ?>"><?php echo self::e( $reason[1] ); ?></p><?php endforeach; ?>
			</os-card>
			<?php
		}
		if ( 0 === $shown ) {
			?>
			<os-empty-state icon="dashicons-yes-alt" heading="<?php echo self::e( __( 'Nothing needs you right now', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Fleet will keep checking site health, pending comments, drafts, and scheduled work.', 'fleet-for-openstation' ) ); ?></os-empty-state>
			<?php
		}
	}

	/**
	 * Render the hub Search tab.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
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
			<?php
			$matches = 0;
			foreach ( $data['search'] as $group ) :
				$matches += count( $group['items'] );
				?>
				<section class="fleet-native-result-group">
					<header><strong><?php echo self::e( $group['site_name'] ); ?></strong><os-badge><?php echo self::e( count( $group['items'] ) ); ?></os-badge></header>
								<?php
								if ( $group['error'] ) :
									?>
									<os-notice tone="danger"><?php echo self::e( $group['error'] ); ?></os-notice><?php endif; ?>
				<?php
				foreach ( $group['items'] as $item ) :
					?>
					<button type="button" class="fleet-native-result" os-action="open-site" os-arg-site-id="<?php echo self::e( $group['site_id'] ); ?>"><span class="dashicons <?php echo self::e( $item['icon'] ); ?>" aria-hidden="true"></span><span><strong><?php echo self::e( $item['title'] ); ?></strong><small><?php echo self::e( $item['meta'] ); ?></small></span></button><?php endforeach; ?>
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

	/**
	 * Render the hub Workspaces tab.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
	public static function render_workspaces( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'workspaces' );
		self::hub_header( $data, $state, __( 'Client workspaces', 'fleet-for-openstation' ), __( 'Open a client’s sites as separate windows, ready side by side.', 'fleet-for-openstation' ) );
		if ( empty( $data['workspaces'] ) ) {
			?>
			<os-empty-state icon="dashicons-portfolio" heading="<?php echo self::e( __( 'No client workspaces yet', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Add a client name to a site’s Agency profile and Fleet will build the workspace automatically.', 'fleet-for-openstation' ) ); ?></os-empty-state>
			<?php
			return;
		}
		?>
		<div class="fleet-native-workspaces"><?php foreach ( $data['workspaces'] as $client => $sites ) : ?>
			<?php // translators: %d: number of sites in a client workspace. ?>
			<?php $workspace_count = sprintf( _n( '%d site', '%d sites', count( $sites ), 'fleet-for-openstation' ), count( $sites ) ); ?>
			<os-card class="fleet-native-workspace-card">
				<div class="fleet-native-card-head"><span><strong><?php echo self::e( $client ); ?></strong><small><?php echo self::e( $workspace_count ); ?></small></span><span class="dashicons dashicons-portfolio" aria-hidden="true"></span></div>
				<ul>
				<?php
				foreach ( $sites as $site ) :
					?>
					<li><span class="fleet-native-dot"></span><?php echo self::e( $site['name'] ); ?></li><?php endforeach; ?></ul>
				<os-button variant="primary" os-action="open-workspace" os-arg-site-ids="<?php echo self::j( array_column( $sites, 'id' ) ); ?>"><?php echo self::e( __( 'Open workspace', 'fleet-for-openstation' ) ); ?></os-button>
			</os-card>
		<?php endforeach; ?></div>
		<?php
	}

	/**
	 * Render the hub Activity tab.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
	public static function render_activity( $state ) {
		$data = OpenStation_Fleet::app_hub_data( 'activity' );
		self::hub_header( $data, $state, __( 'Activity', 'fleet-for-openstation' ), __( 'A local record of what Fleet changed and where.', 'fleet-for-openstation' ) );
		if ( empty( $data['activity'] ) ) {
			?>
			<os-empty-state icon="dashicons-clock" heading="<?php echo self::e( __( 'No activity yet', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Checks and changes will appear here as you use Fleet.', 'fleet-for-openstation' ) ); ?></os-empty-state>
			<?php
			return;
		}
		?>
		<div class="fleet-native-activity"><?php foreach ( $data['activity'] as $event ) : ?>
			<article data-tone="<?php echo 'error' === $event['status'] ? 'danger' : ( 'warning' === $event['status'] ? 'warning' : 'neutral' ); ?>">
				<span class="fleet-native-activity-icon dashicons <?php echo 'error' === $event['status'] ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
				<span><strong><?php echo self::e( $event['message'] ); ?></strong><small><?php echo self::e( trim( $event['site_name'] . ' · ' . human_time_diff( $event['time'], time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) ) ); ?></small></span>
			</article>
		<?php endforeach; ?></div>
		<?php
	}

	/**
	 * Render one managed-site tab.
	 *
	 * @param string                $section Managed-site section name.
	 * @param OpenStation\App\State $state   App state.
	 */
	public static function render_site( $section, $state ) {
		$id   = sanitize_key( (string) $state->get( 'site_id' ) );
		$site = OpenStation_Fleet::app_site( $id );
		if ( is_wp_error( $site ) ) {
			?>
			<os-empty-state icon="dashicons-warning" heading="<?php echo self::e( __( 'Site unavailable', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( $site->get_error_message() ); ?></os-empty-state>
			<?php
			return;
		}
		self::site_header( $site, $state );
		if ( self::connection_review( $state ) ) {
			return;
		}
		if ( $state->get( 'saved' ) ) {
			echo '<os-notice tone="success" role="status">' . self::e( $state->get( 'saved' ) ) . '</os-notice>';
		}
		if ( 'pending' === $site['setup_status'] || 'error' === $site['setup_status'] ) {
			?>
			<section class="fleet-native-setup"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong><?php echo self::e( __( 'Connection approved', 'fleet-for-openstation' ) ); ?></strong><p><?php echo self::e( __( 'Next, Fleet will install or activate OpenStation and check this site. You can safely retry this step if setup fails.', 'fleet-for-openstation' ) ); ?></p></div><os-button variant="primary" os-action="finish-setup"><?php echo self::e( __( 'Finish setup', 'fleet-for-openstation' ) ); ?></os-button><os-button os-action="reconnect"><?php echo self::e( __( 'Repair connection', 'fleet-for-openstation' ) ); ?></os-button></section>
			<?php
			return;
		}
		if ( 'content' === $section && $state->get( 'editor' ) ) {
			if ( $state->get( 'review' ) ) {
				self::render_content_review( (array) $state->get( 'editor' ), (array) $state->get( 'review' ), $site );
			} elseif ( $state->get( 'history' ) ) {
				self::render_revisions( $state );
			} else {
				self::render_editor( (array) $state->get( 'editor' ) );
			}
			return;
		}
		$collections = (array) $state->get( 'collections' );
		$options     = isset( $collections[ $section ] ) ? $collections[ $section ] : array();
		if ( in_array( $section, array( 'content', 'comments', 'media', 'users' ), true ) ) {
			$types = 'content' === $section ? OpenStation_Fleet::app_site_data( $id, 'content-types' ) : array();
			if ( is_wp_error( $types ) ) {
				self::error( $types );
				return;
			}
			self::collection_filters( $section, $options, $types );
			if ( 'content' === $section ) {
				self::render_work_views( $id, (bool) $state->get( 'views_open' ) );
			}
		}
		$data = OpenStation_Fleet::app_site_data( $id, $section, $options );
		if ( is_wp_error( $data ) ) {
			self::error( $data );
			echo '<p>' . self::e( __( 'Check the site and your WordPress permissions. If access was revoked, repair the connection.', 'fleet-for-openstation' ) ) . '</p><os-button os-action="reconnect">' . self::e( __( 'Repair connection', 'fleet-for-openstation' ) ) . '</os-button>';
			return;
		}
		switch ( $section ) {
			case 'content':
				self::render_content( $data );
				break;
			case 'media':
				self::render_media( $data );
				break;
			case 'comments':
				self::render_comments( $data );
				break;
			case 'plugins':
				self::render_plugins( $data );
				break;
			case 'users':
				self::render_users( $data );
				break;
			case 'settings':
				self::render_settings( $data );
				break;
			case 'design':
				self::render_design( $data );
				break;
			case 'agency':
				self::render_agency( $data, $site );
				break;
			case 'api':
				self::render_api( $data, $state );
				break;
			default:
				self::render_overview( $data, $site );
				break;
		}
		if ( isset( $data['pagination'] ) ) {
			self::pagination( $section, $data['pagination'] );
		}
	}

	/**
	 * Render overview data.
	 *
	 * @param array $data Managed-site overview data.
	 * @param array $site Stored site record.
	 */
	private static function render_overview( $data, $site ) {
		$settings    = isset( $data['settings'] ) ? $data['settings'] : array();
		$php_version = ! empty( $site['environment']['php_version'] ) ? $site['environment']['php_version'] : __( 'Unknown', 'fleet-for-openstation' );
		$recent      = array_merge( self::items( isset( $data['posts'] ) ? $data['posts'] : array() ), self::items( isset( $data['pages'] ) ? $data['pages'] : array() ) );
		usort(
			$recent,
			static function ( $a, $b ) {
				return strcmp( isset( $b['modified'] ) ? $b['modified'] : '', isset( $a['modified'] ) ? $a['modified'] : '' );
			}
		);
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
		<?php
		foreach ( $site['attention'] as $reason ) :
			?>
			<os-notice tone="<?php echo 'critical' === $reason[2] ? 'danger' : 'warning'; ?>"><?php echo self::e( $reason[1] ); ?></os-notice><?php endforeach; ?>
		<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Recently changed', 'fleet-for-openstation' ) ); ?></h3><span><?php echo self::e( __( 'Posts and pages', 'fleet-for-openstation' ) ); ?></span></header>
		<?php
		if ( empty( $recent ) ) :
			?>
			<p class="fleet-native-empty-row"><?php echo self::e( __( 'No recent content was returned.', 'fleet-for-openstation' ) ); ?></p>
			<?php
			else :
				foreach ( array_slice( $recent, 0, 8 ) as $item ) :
					?>
	<div class="fleet-native-list-row"><span><strong><?php echo self::e( self::title( $item ) ); ?></strong><small><?php echo self::e( ucfirst( isset( $item['type'] ) ? $item['type'] : 'content' ) ); ?></small></span><os-badge><?php echo self::e( isset( $item['status'] ) ? $item['status'] : '' ); ?></os-badge></div>
					<?php
	endforeach;
endif;
			?>
		</section>
		<?php
	}

	/**
	 * Render posts and pages.
	 *
	 * @param array $data Managed-site content data.
	 */
	private static function render_content( $data ) {
		$type    = $data['type'];
		$items   = self::items( $data[ $type ] );
		$rows    = array_map(
			static function ( $item ) {
				return array(
					'id'       => $item['id'],
					'title'    => self::title( $item ),
					'status'   => $item['status'],
					'modified' => str_replace( 'T', ' ', $item['modified'] ),
				);
			},
			$items
		);
		$columns = array(
			array(
				'key'      => 'title',
				'label'    => __( 'Title', 'fleet-for-openstation' ),
				'sortable' => true,
			),
			array(
				'key'      => 'status',
				'label'    => __( 'Status', 'fleet-for-openstation' ),
				'sortable' => true,
				'width'    => '110px',
			),
			array(
				'key'      => 'modified',
				'label'    => __( 'Modified (site time)', 'fleet-for-openstation' ),
				'sortable' => true,
				'width'    => '180px',
			),
		);
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Publishing', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( $data['descriptor']['name'] ); ?></h2></span><os-button variant="primary" os-action="new-content" os-arg-type="<?php echo self::e( $type ); ?>"><?php echo self::e( 'pages' === $type ? __( 'New page', 'fleet-for-openstation' ) : ( 'posts' === $type ? __( 'New post', 'fleet-for-openstation' ) : __( 'New item', 'fleet-for-openstation' ) ) ); ?></os-button></div>
		<os-table class="fleet-native-content-list" os-key="<?php echo self::e( $type ); ?>" os-action="edit-content" os-arg-type="<?php echo self::e( $type ); ?>" os-prop-columns="<?php echo self::j( $columns ); ?>" os-prop-data="<?php echo self::j( $rows ); ?>" sticky-header empty="<?php echo self::e( __( 'No matching content. Try another search or status.', 'fleet-for-openstation' ) ); ?>"></os-table>
		<?php
	}
	/**
	 * Render comments.
	 *
	 * @param array $data Managed-site comment data.
	 */
	private static function render_comments( $data ) {
		$comments = self::items( isset( $data['comments'] ) ? $data['comments'] : array() );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Comments', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Moderation queue', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $comments ) ); ?></os-badge></div>
		<?php
		if ( isset( $data['comments'] ) && is_wp_error( $data['comments'] ) ) {
			self::error( $data['comments'] );
			return; }
		if ( empty( $comments ) ) {
			?>
				<os-empty-state icon="dashicons-admin-comments" heading="<?php echo self::e( __( 'No comments found', 'fleet-for-openstation' ) ); ?>"></os-empty-state>
				<?php
				return; }
		?>
		<div class="fleet-native-stack">
		<?php
		foreach ( $comments as $comment ) :
			if ( empty( $comment['id'] ) ) {
				continue; }
			?>
			<os-form class="fleet-native-comment" os-action="save-comment" submit-label="<?php echo self::e( __( 'Update', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
				<div slot="header"><div class="fleet-native-card-head"><span><strong><?php echo self::e( isset( $comment['author_name'] ) ? $comment['author_name'] : __( 'Anonymous', 'fleet-for-openstation' ) ); ?></strong><small><?php echo self::e( ! empty( $comment['date'] ) ? human_time_diff( strtotime( $comment['date'] ), time() ) . ' ' . __( 'ago', 'fleet-for-openstation' ) : '' ); ?></small></span><os-badge><?php echo self::e( isset( $comment['status'] ) ? $comment['status'] : 'hold' ); ?></os-badge></div><p><?php echo self::e( wp_trim_words( wp_strip_all_tags( isset( $comment['content']['rendered'] ) ? $comment['content']['rendered'] : '' ), 48 ) ); ?></p></div>
				<input type="hidden" name="comment_id" value="<?php echo self::e( $comment['id'] ); ?>">
			<?php
			self::status_select(
				'status',
				__( 'Moderation', 'fleet-for-openstation' ),
				isset( $comment['status'] ) ? $comment['status'] : 'hold',
				array(
					'approved' => __( 'Approved', 'fleet-for-openstation' ),
					'hold'     => __( 'Pending', 'fleet-for-openstation' ),
					'spam'     => __( 'Spam', 'fleet-for-openstation' ),
					'trash'    => __( 'Trash', 'fleet-for-openstation' ),
				)
			);
			?>
			</os-form>
		<?php endforeach; ?></div>
		<?php
	}

	/**
	 * Render media metadata.
	 *
	 * @param array $data Managed-site media data.
	 */
	private static function render_media( $data ) {
		$media = self::items( isset( $data['media'] ) ? $data['media'] : array() );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'WordPress media', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Media library', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $media ) ); ?></os-badge></div>
		<?php
		if ( isset( $data['media'] ) && is_wp_error( $data['media'] ) ) {
			self::error( $data['media'] );
			return; }
		if ( empty( $media ) ) {
			?>
				<os-empty-state icon="dashicons-format-image" heading="<?php echo self::e( __( 'No media found', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Images and files from this site will appear here.', 'fleet-for-openstation' ) ); ?></os-empty-state>
				<?php
				return; }
		?>
		<div class="fleet-native-media-grid">
		<?php
		foreach ( $media as $item ) :
			if ( empty( $item['id'] ) ) {
				continue; }
			?>
			<os-form class="fleet-native-media-card" os-action="save-media" submit-label="<?php echo self::e( __( 'Save details', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
				<div slot="header" class="fleet-native-media-preview">
				<?php
				if ( 'image' === ( isset( $item['media_type'] ) ? $item['media_type'] : '' ) && ! empty( $item['source_url'] ) ) :
					?>
					<img src="<?php echo self::e( $item['source_url'] ); ?>" alt="">
					<?php
					else :
						?>
					<span class="dashicons dashicons-media-default" aria-hidden="true"></span><?php endif; ?></div>
				<input type="hidden" name="media_id" value="<?php echo self::e( $item['id'] ); ?>">
				<os-text-field name="title" label="<?php echo self::e( __( 'Title', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( self::title( $item ) ); ?>"></os-text-field>
				<os-text-field name="alt_text" label="<?php echo self::e( __( 'Alt text', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $item['alt_text'] ) ? $item['alt_text'] : '' ); ?>"></os-text-field>
				<os-textarea name="caption" label="<?php echo self::e( __( 'Caption', 'fleet-for-openstation' ) ); ?>" rows="3" value="<?php echo self::e( isset( $item['caption']['raw'] ) ? $item['caption']['raw'] : '' ); ?>"></os-textarea>
			</os-form>
		<?php endforeach; ?></div>
		<?php
	}

	/**
	 * Render plugins.
	 *
	 * @param array $data Managed-site plugin data.
	 */
	private static function render_plugins( $data ) {
		$plugins = self::items( isset( $data['plugins'] ) ? $data['plugins'] : array() );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'WordPress extensions', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Plugins', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $plugins ) ); ?></os-badge></div>
		<os-form class="fleet-native-panel" os-action="install-plugin" submit-label="<?php echo self::e( __( 'Install plugin', 'fleet-for-openstation' ) ); ?>" show-reset="false">
			<div slot="header"><h3><?php echo self::e( __( 'Install from WordPress.org', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'Use the directory slug, such as akismet.', 'fleet-for-openstation' ) ); ?></p></div>
			<os-text-field name="plugin_slug" label="<?php echo self::e( __( 'Plugin slug', 'fleet-for-openstation' ) ); ?>" placeholder="plugin-slug" required></os-text-field>
			<?php
			self::status_select(
				'status',
				__( 'After installation', 'fleet-for-openstation' ),
				'active',
				array(
					'active'   => __( 'Install and activate', 'fleet-for-openstation' ),
					'inactive' => __( 'Install only', 'fleet-for-openstation' ),
				)
			);
			?>
		</os-form>
		<?php
		if ( isset( $data['plugins'] ) && is_wp_error( $data['plugins'] ) ) {
			self::error( $data['plugins'] );
			return; }
		if ( empty( $plugins ) ) {
			?>
			<p class="fleet-native-empty-row fleet-native-panel"><?php echo self::e( __( 'No plugins were returned by this site.', 'fleet-for-openstation' ) ); ?></p>
			<?php
			return; }
		?>
		<div class="fleet-native-stack">
		<?php
		foreach ( $plugins as $plugin ) :
			if ( empty( $plugin['plugin'] ) ) {
				continue;
			} $active = in_array( isset( $plugin['status'] ) ? $plugin['status'] : '', array( 'active', 'network-active' ), true );
			?>
			<?php // translators: %s: installed plugin version. ?>
			<?php $version_label = isset( $plugin['version'] ) ? sprintf( __( 'Version %s', 'fleet-for-openstation' ), $plugin['version'] ) : $plugin['plugin']; ?>
				<article class="fleet-native-plugin-row"><span class="fleet-native-plugin-icon dashicons <?php echo OpenStation_Fleet::PLUGIN_REST_ID === $plugin['plugin'] ? 'dashicons-desktop' : 'dashicons-admin-plugins'; ?>" aria-hidden="true"></span><span><strong><?php echo self::e( isset( $plugin['name'] ) ? wp_strip_all_tags( $plugin['name'] ) : $plugin['plugin'] ); ?></strong><small><?php echo self::e( $version_label ); ?></small></span><os-badge tone="<?php echo $active ? 'success' : 'neutral'; ?>"><?php echo self::e( $active ? __( 'Active', 'fleet-for-openstation' ) : __( 'Inactive', 'fleet-for-openstation' ) ); ?></os-badge>
			<?php
			if ( 'network-active' !== ( isset( $plugin['status'] ) ? $plugin['status'] : '' ) && ! ( OpenStation_Fleet::PLUGIN_REST_ID === $plugin['plugin'] && $active ) ) :
				?>
				<os-button variant="secondary" os-action="change-plugin" os-arg-plugin="<?php echo self::e( $plugin['plugin'] ); ?>" os-arg-status="<?php echo $active ? 'inactive' : 'active'; ?>"><?php echo self::e( $active ? __( 'Deactivate', 'fleet-for-openstation' ) : __( 'Activate', 'fleet-for-openstation' ) ); ?></os-button><?php endif; ?>
			</article>
		<?php endforeach; ?></div>
		<?php
	}

	/**
	 * Render users.
	 *
	 * @param array $data Managed-site user data.
	 */
	private static function render_users( $data ) {
		$users = self::items( isset( $data['users'] ) ? $data['users'] : array() );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'People and access', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Users', 'fleet-for-openstation' ) ); ?></h2></span><os-badge><?php echo self::e( count( $users ) ); ?></os-badge></div>
		<os-form class="fleet-native-panel" os-action="create-user" submit-label="<?php echo self::e( __( 'Create user', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="2">
			<div slot="header"><h3><?php echo self::e( __( 'Add a user', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'The password is sent once and is never stored by Fleet.', 'fleet-for-openstation' ) ); ?></p></div>
			<os-text-field name="username" label="<?php echo self::e( __( 'Username', 'fleet-for-openstation' ) ); ?>" required></os-text-field><os-text-field name="email" type="email" label="<?php echo self::e( __( 'Email', 'fleet-for-openstation' ) ); ?>" required></os-text-field><os-text-field name="password" type="password" label="<?php echo self::e( __( 'Password', 'fleet-for-openstation' ) ); ?>" required></os-text-field><?php self::role_select( 'role', 'subscriber' ); ?>
		</os-form>
		<?php
		if ( isset( $data['users'] ) && is_wp_error( $data['users'] ) ) {
			self::error( $data['users'] );
			return; }
		if ( empty( $users ) ) {
			?>
			<p class="fleet-native-empty-row fleet-native-panel"><?php echo self::e( __( 'No users were returned by this site.', 'fleet-for-openstation' ) ); ?></p>
			<?php
			return; }
		?>
		<div class="fleet-native-stack">
		<?php
		foreach ( $users as $user ) :
			if ( empty( $user['id'] ) ) {
				continue; }
			?>
			<os-form class="fleet-native-row-form" os-action="save-user" submit-label="<?php echo self::e( __( 'Save', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="3">
				<div slot="header" class="fleet-native-user-head">
				<?php
				if ( ! empty( $user['avatar_urls']['48'] ) ) :
					?>
					<img src="<?php echo self::e( $user['avatar_urls']['48'] ); ?>" alt=""><?php endif; ?><span><strong><?php echo self::e( isset( $user['username'] ) ? $user['username'] : '' ); ?></strong><small><?php echo self::e( implode( ', ', isset( $user['roles'] ) && is_array( $user['roles'] ) ? $user['roles'] : array() ) ); ?></small></span></div>
				<input type="hidden" name="user_id" value="<?php echo self::e( $user['id'] ); ?>"><os-text-field name="name" label="<?php echo self::e( __( 'Display name', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $user['name'] ) ? $user['name'] : '' ); ?>" required></os-text-field><os-text-field name="email" type="email" label="<?php echo self::e( __( 'Email', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $user['email'] ) ? $user['email'] : '' ); ?>" required></os-text-field><?php self::role_select( 'role', isset( $user['roles'][0] ) ? $user['roles'][0] : 'subscriber' ); ?>
			</os-form>
		<?php endforeach; ?></div>
		<?php
	}

	/**
	 * Render settings.
	 *
	 * @param array $data Managed-site settings data.
	 */
	private static function render_settings( $data ) {
		$settings = isset( $data['settings'] ) ? $data['settings'] : array();
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'WordPress settings', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Site identity and time', 'fleet-for-openstation' ) ); ?></h2></span></div>
		<?php
		if ( is_wp_error( $settings ) ) {
			self::error( $settings );
			return; }
		?>
		<os-form class="fleet-native-panel" os-action="save-settings" submit-label="<?php echo self::e( __( 'Save site settings', 'fleet-for-openstation' ) ); ?>" show-reset="false">
			<os-text-field name="title" label="<?php echo self::e( __( 'Site title', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['title'] ) ? $settings['title'] : '' ); ?>" full-width required></os-text-field>
			<os-text-field name="description" label="<?php echo self::e( __( 'Tagline', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['description'] ) ? $settings['description'] : '' ); ?>" full-width></os-text-field>
			<os-text-field name="timezone" label="<?php echo self::e( __( 'Timezone', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['timezone'] ) ? $settings['timezone'] : '' ); ?>" placeholder="America/Chicago"></os-text-field>
			<?php
			self::status_select(
				'start_of_week',
				__( 'Week starts on', 'fleet-for-openstation' ),
				(string) ( isset( $settings['start_of_week'] ) ? $settings['start_of_week'] : 0 ),
				array(
					'0' => __( 'Sunday', 'fleet-for-openstation' ),
					'1' => __( 'Monday', 'fleet-for-openstation' ),
					'2' => __( 'Tuesday', 'fleet-for-openstation' ),
					'3' => __( 'Wednesday', 'fleet-for-openstation' ),
					'4' => __( 'Thursday', 'fleet-for-openstation' ),
					'5' => __( 'Friday', 'fleet-for-openstation' ),
					'6' => __( 'Saturday', 'fleet-for-openstation' ),
				)
			);
			?>
			<os-text-field name="date_format" label="<?php echo self::e( __( 'Date format', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['date_format'] ) ? $settings['date_format'] : 'F j, Y' ); ?>"></os-text-field><os-text-field name="time_format" label="<?php echo self::e( __( 'Time format', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $settings['time_format'] ) ? $settings['time_format'] : 'g:i a' ); ?>"></os-text-field>
		</os-form>
		<?php
	}

	/**
	 * Render the modern Core design inventory.
	 *
	 * @param array $data Managed-site design data.
	 */
	private static function render_design( $data ) {
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Themes and the Site Editor', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Site design', 'fleet-for-openstation' ) ); ?></h2></span></div>
		<os-notice tone="info"><?php echo self::e( __( 'This live inventory uses Core themes, templates, template parts, and Navigation APIs. Use Explorer for schema-driven edits to any advertised design route.', 'fleet-for-openstation' ) ); ?></os-notice>
		<div class="fleet-native-design-grid">
		<?php
		foreach ( array(
			'themes'         => __( 'Active theme', 'fleet-for-openstation' ),
			'templates'      => __( 'Templates', 'fleet-for-openstation' ),
			'template_parts' => __( 'Template parts', 'fleet-for-openstation' ),
			'navigation'     => __( 'Navigation', 'fleet-for-openstation' ),
			'font_families'  => __( 'Font library', 'fleet-for-openstation' ),
			'patterns'       => __( 'Synced patterns', 'fleet-for-openstation' ),
		) as $key => $label ) :
									$items = self::items( isset( $data[ $key ] ) ? $data[ $key ] : array() );
			?>
			<section class="fleet-native-panel"><header><h3><?php echo self::e( $label ); ?></h3><os-badge><?php echo self::e( count( $items ) ); ?></os-badge></header>
									<?php
									if ( isset( $data[ $key ] ) && is_wp_error( $data[ $key ] ) ) :
										?>
										<p class="fleet-native-empty-row"><?php echo self::e( __( 'Not exposed by this site.', 'fleet-for-openstation' ) ); ?></p>
										<?php
										elseif ( empty( $items ) ) :
											?>
										<p class="fleet-native-empty-row"><?php echo self::e( __( 'Nothing returned.', 'fleet-for-openstation' ) ); ?></p>
											<?php
										else :
											foreach ( array_slice( $items, 0, 10 ) as $item ) :
												?>
	<div class="fleet-native-list-row"><span><strong><?php echo self::e( self::title( $item ) ); ?></strong><small><?php echo self::e( isset( $item['slug'] ) ? $item['slug'] : ( isset( $item['stylesheet'] ) ? $item['stylesheet'] : '' ) ); ?></small></span></div>
												<?php
	endforeach;
endif;
										?>
			</section>
		<?php endforeach; ?></div>
		<?php
		if ( ! empty( $data['global_styles'] ) && ! is_wp_error( $data['global_styles'] ) ) :
			?>
			<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Global Styles', 'fleet-for-openstation' ) ); ?></h3><os-badge tone="success"><?php echo self::e( __( 'Available', 'fleet-for-openstation' ) ); ?></os-badge></header><p class="fleet-native-empty-row"><?php echo self::e( __( 'The active block theme exposes editable settings and styles through WordPress Core.', 'fleet-for-openstation' ) ); ?></p></section><?php endif; ?>
		<?php
	}

	/**
	 * Render hub-only agency metadata.
	 *
	 * @param array $data Managed-site agency data.
	 * @param array $site Stored site record.
	 */
	private static function render_agency( $data, $site ) {
		$agency = isset( $data['agency'] ) ? $data['agency'] : array();
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Private to your hub', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Client notes', 'fleet-for-openstation' ) ); ?></h2></span></div>
		<div class="fleet-native-two-column"><os-form class="fleet-native-panel" os-action="save-agency" submit-label="<?php echo self::e( __( 'Save agency profile', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
			<os-text-field name="client_name" label="<?php echo self::e( __( 'Client name', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $agency['client_name'] ) ? $agency['client_name'] : '' ); ?>"></os-text-field>
			<?php
			self::status_select(
				'plan_status',
				__( 'Maintenance plan', 'fleet-for-openstation' ),
				isset( $agency['plan_status'] ) ? $agency['plan_status'] : 'none',
				array(
					'none'   => __( 'Not assigned', 'fleet-for-openstation' ),
					'active' => __( 'Active', 'fleet-for-openstation' ),
					'paused' => __( 'Paused', 'fleet-for-openstation' ),
					'ended'  => __( 'Ended', 'fleet-for-openstation' ),
				)
			);
			?>
			<os-text-field name="tags" label="<?php echo self::e( __( 'Tags', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( ! empty( $agency['tags'] ) ? implode( ', ', $agency['tags'] ) : '' ); ?>" placeholder="client, ecommerce, priority"></os-text-field>
			<os-textarea name="notes" label="<?php echo self::e( __( 'Private notes', 'fleet-for-openstation' ) ); ?>" rows="6" value="<?php echo self::e( isset( $agency['notes'] ) ? $agency['notes'] : '' ); ?>"></os-textarea>
			<os-checkbox name="favorite" label="<?php echo self::e( __( 'Favorite this site', 'fleet-for-openstation' ) ); ?>" <?php echo ! empty( $agency['favorite'] ) ? 'checked' : ''; ?>></os-checkbox>
		</os-form><section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Connection inventory', 'fleet-for-openstation' ) ); ?></h3></header><dl class="fleet-native-facts"><div><dt><?php echo self::e( __( 'WordPress', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( $site['wordpress_version'] ? $site['wordpress_version'] : __( 'Not reported', 'fleet-for-openstation' ) ); ?></dd></div><div><dt><?php echo self::e( __( 'REST routes', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( isset( $site['capabilities']['route_count'] ) ? $site['capabilities']['route_count'] : 0 ); ?></dd></div><div><dt><?php echo self::e( __( 'Namespaces', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( ! empty( $site['capabilities']['namespaces'] ) ? implode( ', ', array_slice( $site['capabilities']['namespaces'], 0, 12 ) ) : __( 'Not checked', 'fleet-for-openstation' ) ); ?></dd></div></dl></section></div>
		<?php
	}

	/**
	 * Render schema-discovered routes and Core Abilities.
	 *
	 * @param array                 $data  Managed-site API data.
	 * @param OpenStation\App\State $state App state.
	 */
	private static function render_api( $data, $state ) {
		$catalog   = isset( $data['catalog'] ) ? $data['catalog'] : array();
		$abilities = isset( $data['abilities'] ) ? $data['abilities'] : array();
		if ( is_array( $abilities ) && isset( $abilities['abilities'] ) && is_array( $abilities['abilities'] ) ) {
			$abilities = $abilities['abilities'];
		}
		$response = (string) $state->get( 'response' );
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Developer tools', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'API Explorer', 'fleet-for-openstation' ) ); ?></h2></span>
		<?php
		if ( ! is_wp_error( $catalog ) ) :
			?>
			<os-badge><?php echo self::e( count( $catalog ) ); ?> <?php echo self::e( __( 'routes', 'fleet-for-openstation' ) ); ?></os-badge><?php endif; ?></div>
		<os-notice tone="info"><?php echo self::e( __( 'Fleet reads the site’s live REST index and uses the approved account’s permissions. Core Abilities are discovered automatically and used for richer site facts when exposed by WordPress.', 'fleet-for-openstation' ) ); ?></os-notice>
		<div class="fleet-native-two-column"><section class="fleet-native-api-controls"><os-form class="fleet-native-panel" os-action="api-request" submit-label="<?php echo self::e( __( 'Read route', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
			<div slot="header"><h3><?php echo self::e( __( 'Read from WordPress', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'GET requests do not change the managed site.', 'fleet-for-openstation' ) ); ?></p></div>
			<input type="hidden" name="api_method" value="GET">
			<os-text-field name="api_route" label="<?php echo self::e( __( 'REST route', 'fleet-for-openstation' ) ); ?>" placeholder="wp/v2/posts?context=edit" required></os-text-field>
		</os-form><details class="fleet-native-api-write"><summary><?php echo self::e( __( 'Write to a route', 'fleet-for-openstation' ) ); ?></summary><os-form class="fleet-native-panel" os-action="api-request" submit-label="<?php echo self::e( __( 'Review and send', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1" os-confirm="<?php echo self::e( __( 'This authenticated request can change or delete data on the managed site.', 'fleet-for-openstation' ) ); ?>" os-confirm-title="<?php echo self::e( __( 'Send write request?', 'fleet-for-openstation' ) ); ?>" os-confirm-label="<?php echo self::e( __( 'Send request', 'fleet-for-openstation' ) ); ?>" os-confirm-danger>
			<?php
			self::status_select(
				'api_method',
				__( 'HTTP method', 'fleet-for-openstation' ),
				'POST',
				array(
					'POST'   => 'POST',
					'PUT'    => 'PUT',
					'PATCH'  => 'PATCH',
					'DELETE' => 'DELETE',
				)
			);
			?>
			<os-text-field name="api_route" label="<?php echo self::e( __( 'REST route', 'fleet-for-openstation' ) ); ?>" placeholder="wp/v2/posts/123" required></os-text-field>
			<os-textarea name="api_body" label="<?php echo self::e( __( 'JSON body', 'fleet-for-openstation' ) ); ?>" rows="8" placeholder="{ &quot;title&quot;: &quot;Updated from Fleet&quot; }"></os-textarea>
		</os-form></details></section><section class="fleet-native-panel fleet-native-response"><header><h3><?php echo self::e( __( 'Response', 'fleet-for-openstation' ) ); ?></h3></header>
		<?php
		if ( '' === $response ) :
			?>
			<p class="fleet-native-empty-row"><?php echo self::e( __( 'Send a request to see its JSON response.', 'fleet-for-openstation' ) ); ?></p>
			<?php
			else :
				?>
			<pre><code><?php echo self::e( $response ); ?></code></pre><?php endif; ?></section></div>
		<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Core Abilities', 'fleet-for-openstation' ) ); ?></h3><os-badge><?php echo self::e( is_wp_error( $abilities ) ? 0 : count( $abilities ) ); ?></os-badge></header>
		<?php
		if ( is_wp_error( $abilities ) ) :
			?>
			<p class="fleet-native-empty-row"><?php echo self::e( __( 'This site does not expose the WordPress Abilities API.', 'fleet-for-openstation' ) ); ?></p>
			<?php
			else :
				foreach ( array_slice( $abilities, 0, 20 ) as $ability ) :
					?>
	<div class="fleet-native-list-row"><span><strong><?php echo self::e( isset( $ability['label'] ) ? $ability['label'] : ( isset( $ability['name'] ) ? $ability['name'] : __( 'Ability', 'fleet-for-openstation' ) ) ); ?></strong><small><?php echo self::e( isset( $ability['name'] ) ? $ability['name'] : '' ); ?></small></span>
					<?php
					if ( ! empty( $ability['meta']['annotations']['readonly'] ) ) :
						?>
				<os-badge><?php echo self::e( __( 'Read only', 'fleet-for-openstation' ) ); ?></os-badge><?php endif; ?></div>
					<?php
				endforeach;
endif;
			?>
			</section>
		<?php
		if ( is_wp_error( $catalog ) ) :
			self::error( $catalog ); else :
				?>
			<section class="fleet-native-panel"><header><h3><?php echo self::e( __( 'Advertised REST routes', 'fleet-for-openstation' ) ); ?></h3><os-badge><?php echo self::e( count( $catalog ) ); ?></os-badge></header><div class="fleet-native-route-list">
				<?php
				foreach ( array_slice( $catalog, 0, 120 ) as $route ) :
					?>
	<div><code><?php echo self::e( $route['route'] ); ?></code><small><?php echo self::e( implode( ' · ', $route['methods'] ) ); ?></small></div><?php endforeach; ?></div></section><?php endif; ?>
		<?php
	}

	/**
	 * Render the shared native hub header.
	 *
	 * @param array                 $data        Hub data.
	 * @param OpenStation\App\State $state       App state.
	 * @param string                $title       Header title.
	 * @param string                $description Header description.
	 */
	private static function hub_header( $data, $state, $title, $description ) {
		self::notice( $state );
		// translators: %d: number of connected WordPress sites.
		$connected_label = sprintf( _n( '%d connected site', '%d connected sites', $data['counts']['sites'], 'fleet-for-openstation' ), $data['counts']['sites'] );
		?>
		<header class="fleet-native-hero"><div class="fleet-native-mark"><span class="dashicons dashicons-networking" aria-hidden="true"></span></div><span><small><?php echo self::e( __( 'Fleet command center', 'fleet-for-openstation' ) ); ?></small><h1><?php echo self::e( $title ); ?></h1><p><?php echo self::e( $description ); ?></p></span><div class="fleet-native-hero-count" aria-label="<?php echo self::e( $connected_label ); ?>"><strong><?php echo self::e( $data['counts']['sites'] ); ?></strong><small><?php echo self::e( __( 'connected', 'fleet-for-openstation' ) ); ?></small></div></header>
		<?php
	}

	/**
	 * Render the shared managed-site header.
	 *
	 * @param array                 $site  Stored site record.
	 * @param OpenStation\App\State $state App state.
	 */
	private static function site_header( $site, $state ) {
		self::notice( $state );
		?>
		<header class="fleet-native-site-header"><div class="fleet-native-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><i class="<?php echo empty( $site['error'] ) ? 'is-good' : 'is-bad'; ?>" aria-hidden="true"></i></div><span><small><?php echo self::e( __( 'Connected WordPress site', 'fleet-for-openstation' ) ); ?></small><h1><?php echo self::e( $site['name'] ); ?></h1><p><?php echo self::e( $site['host'] . ( $site['wordpress_version'] ? ' · WordPress ' . $site['wordpress_version'] : '' ) ); ?></p></span><div class="fleet-native-site-badges"><os-badge tone="<?php echo empty( $site['error'] ) ? 'success' : 'danger'; ?>"><?php echo self::e( empty( $site['error'] ) ? __( 'Connected', 'fleet-for-openstation' ) : __( 'Connection issue', 'fleet-for-openstation' ) ); ?></os-badge>
		<?php
		if ( 'active' === ( isset( $site['openstation']['status'] ) ? $site['openstation']['status'] : '' ) ) :
			?>
			<os-badge><?php echo self::e( __( 'OpenStation installed', 'fleet-for-openstation' ) ); ?></os-badge><?php endif; ?></div></header>
		<?php
		if ( $site['error'] ) :
			?>
			<os-notice tone="danger"><?php echo self::e( $site['error'] ); ?></os-notice><?php endif; ?>
		<?php
	}

	/**
	 * Render a site card.
	 *
	 * @param array $site Stored site record.
	 */
	private static function site_card( $site ) {
		$tone = $site['error'] || ! empty( $site['attention'] ) ? 'warning' : 'success';
		?>
		<article class="fleet-native-site-card" data-tone="<?php echo self::e( $tone ); ?>">
			<button type="button" class="fleet-native-site-open" os-action="open-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>"><span class="fleet-native-site-icon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><i class="<?php echo $site['error'] ? 'is-bad' : 'is-good'; ?>" aria-hidden="true"></i></span><span><strong><?php echo self::e( $site['name'] ); ?></strong><small><?php echo self::e( $site['host'] ); ?></small></span></button>
			<div class="fleet-native-site-meta"><span><?php echo self::e( $site['wordpress_version'] ? 'WordPress ' . $site['wordpress_version'] : __( 'WordPress version unknown', 'fleet-for-openstation' ) ); ?></span>
			<?php
			if ( $site['agency']['client_name'] ) :
				?>
				<os-badge><?php echo self::e( $site['agency']['client_name'] ); ?></os-badge><?php endif; ?></div>
			<?php
			if ( $site['error'] ) :
				?>
				<p class="fleet-native-alert" data-tone="danger"><?php echo self::e( $site['error'] ); ?></p>
				<?php
				elseif ( ! empty( $site['attention'] ) ) :
					?>
				<p class="fleet-native-alert" data-tone="warning"><?php echo self::e( $site['attention'][0][1] ); ?></p><?php endif; ?>
			<footer><os-button variant="ghost" os-action="favorite" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>" icon="dashicons-star-<?php echo $site['agency']['favorite'] ? 'filled' : 'empty'; ?>"><?php echo self::e( $site['agency']['favorite'] ? __( 'Favorited', 'fleet-for-openstation' ) : __( 'Favorite', 'fleet-for-openstation' ) ); ?></os-button><span class="fleet-native-card-actions"><os-button variant="secondary" os-action="refresh-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>"><?php echo self::e( __( 'Check', 'fleet-for-openstation' ) ); ?></os-button><os-button variant="primary" os-action="open-site" os-arg-site-id="<?php echo self::e( $site['id'] ); ?>"><?php echo self::e( __( 'Manage', 'fleet-for-openstation' ) ); ?></os-button></span></footer>
		</article>
		<?php
	}

	/**
	 * Render the focused source editor, using native controls.
	 *
	 * @param array $editor Editor model.
	 */
	private static function render_editor( $editor ) {
		?>
		<div class="fleet-native-section-heading"><span><small><?php echo self::e( 'pages' === $editor['content_type'] ? __( 'Page editor', 'fleet-for-openstation' ) : __( 'Post editor', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( $editor['content_id'] ? __( 'Edit content', 'fleet-for-openstation' ) : __( 'Start a draft', 'fleet-for-openstation' ) ); ?></h2></span><os-button os-action="close-editor"><?php echo self::e( __( 'Back to list', 'fleet-for-openstation' ) ); ?></os-button></div>
		<p class="fleet-native-editor-help"><?php echo self::e( __( 'Source editor · HTML and WordPress block markup. Publishing and scheduling open a review before anything is written.', 'fleet-for-openstation' ) ); ?></p>
		<os-form class="fleet-native-editor" os-action="save-content" submit-label="<?php echo self::e( __( 'Save to WordPress', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
		<?php foreach ( array( 'content_type', 'content_id', 'fingerprint', 'request_id' ) as $key ) : ?>
			<input type="hidden" name="<?php echo self::e( $key ); ?>" value="<?php echo self::e( $editor[ $key ] ); ?>">
		<?php endforeach; ?>
			<os-text-field name="title" label="<?php echo self::e( __( 'Title', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( $editor['title'] ); ?>" required></os-text-field>
			<os-textarea name="content" label="<?php echo self::e( __( 'Content source', 'fleet-for-openstation' ) ); ?>" rows="8" value="<?php echo self::e( $editor['content'] ); ?>"></os-textarea>
			<div class="fleet-native-editor-meta">
			<?php
			self::status_select(
				'status',
				__( 'Status', 'fleet-for-openstation' ),
				$editor['status'],
				array(
					'draft'   => __( 'Draft', 'fleet-for-openstation' ),
					'pending' => __( 'Pending review', 'fleet-for-openstation' ),
					'publish' => __( 'Published', 'fleet-for-openstation' ),
					'private' => __( 'Private', 'fleet-for-openstation' ),
					'future'  => __( 'Scheduled', 'fleet-for-openstation' ),
				)
			);
			?>
			<os-text-field name="date_gmt" label="<?php echo self::e( __( 'Publish date (UTC)', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( $editor['date_gmt'] ); ?>" placeholder="2026-12-31T14:30:00"></os-text-field>
			</div>
			<details class="fleet-native-editor-details"><summary><?php echo self::e( __( 'Excerpt and URL slug', 'fleet-for-openstation' ) ); ?></summary>
				<os-text-field name="slug" label="<?php echo self::e( __( 'URL slug', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( $editor['slug'] ); ?>"></os-text-field>
				<?php if ( ! empty( $editor['descriptor']['supports']['excerpt'] ) ) : ?>
					<os-textarea name="excerpt" label="<?php echo self::e( __( 'Excerpt source', 'fleet-for-openstation' ) ); ?>" rows="3" value="<?php echo self::e( $editor['excerpt'] ); ?>"></os-textarea>
				<?php else : ?>
					<input type="hidden" name="excerpt" value="">
				<?php endif; ?>
			</details>
			<os-save-status class="fleet-native-save-state" phase="idle" mode="pill" idle-label="<?php echo self::e( __( 'Save when you are ready', 'fleet-for-openstation' ) ); ?>"></os-save-status>
		</os-form>
		<?php if ( $editor['content_id'] && ! empty( $editor['descriptor']['supports']['revisions'] ) ) : ?>
			<os-button os-action="revision-history"><?php echo self::e( __( 'Revision history', 'fleet-for-openstation' ) ); ?></os-button>
		<?php endif; ?>
		<?php if ( $editor['content_id'] && 'trash' !== $editor['status'] ) : ?>
			<os-button variant="ghost" os-action="trash-content" os-arg-content_type="<?php echo self::e( $editor['content_type'] ); ?>" os-arg-content_id="<?php echo self::e( $editor['content_id'] ); ?>" os-arg-fingerprint="<?php echo self::e( $editor['fingerprint'] ); ?>" os-confirm="<?php echo self::e( __( 'Move this item to WordPress Trash? You can restore it from the Trash filter.', 'fleet-for-openstation' ) ); ?>"><?php echo self::e( __( 'Move to Trash', 'fleet-for-openstation' ) ); ?></os-button>
		<?php endif; ?>
		<?php
	}

	/**
	 * Review before a public or scheduled write; destination is server-resolved.
	 *
	 * @param array $editor Submitted source.
	 * @param array $review Signed review data.
	 * @param array $site Public site model.
	 */
	private static function render_content_review( $editor, $review, $site ) {
		$status_labels = array(
			'publish' => __( 'Published', 'fleet-for-openstation' ),
			'future'  => __( 'Scheduled', 'fleet-for-openstation' ),
			'draft'   => __( 'Draft', 'fleet-for-openstation' ),
			'pending' => __( 'Pending review', 'fleet-for-openstation' ),
			'private' => __( 'Private', 'fleet-for-openstation' ),
		);
		?>
		<section class="fleet-native-publish-review fleet-native-editor-action">
			<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Review before saving', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( $editor['title'] ); ?></h2></span></div>
			<dl class="fleet-native-review-summary"><div><dt><?php echo self::e( __( 'Destination', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( $site['url'] ); ?></dd></div><div><dt><?php echo self::e( __( 'Status', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( $status_labels[ $editor['status'] ] ?? $editor['status'] ); ?></dd></div><div><dt><?php echo self::e( __( 'Publish date', 'fleet-for-openstation' ) ); ?></dt><dd><?php echo self::e( $review['when'] ); ?></dd></div></dl>
			<p class="fleet-native-editor-help"><?php echo self::e( __( 'Nothing has been written yet. Confirm only when this is the right site. The review expires after ten minutes; WordPress is checked again before saving.', 'fleet-for-openstation' ) ); ?></p>
			<div class="fleet-native-review-actions"><os-button variant="primary" os-action="confirm-content"><?php echo self::e( __( 'Confirm and save', 'fleet-for-openstation' ) ); ?></os-button><os-button os-action="cancel-review"><?php echo self::e( __( 'Keep editing', 'fleet-for-openstation' ) ); ?></os-button></div>
			<?php self::content_diff( $review['before'], $editor ); ?>
		</section>
		<?php
	}

	/**
	 * Core's escaped diff renderer, bounded to avoid expensive huge comparisons.
	 *
	 * @param array $before Original field values.
	 * @param array $after Proposed field values.
	 */
	private static function content_diff( $before, $after ) {
		$labels  = array(
			'title'    => __( 'Title', 'fleet-for-openstation' ),
			'content'  => __( 'Content source', 'fleet-for-openstation' ),
			'excerpt'  => __( 'Excerpt', 'fleet-for-openstation' ),
			'slug'     => __( 'URL slug', 'fleet-for-openstation' ),
			'status'   => __( 'Status', 'fleet-for-openstation' ),
			'date_gmt' => __( 'Publish date (UTC)', 'fleet-for-openstation' ),
		);
		$changed = false;
		foreach ( $labels as $key => $label ) {
			$old = isset( $before[ $key ] ) ? (string) $before[ $key ] : '';
			$new = isset( $after[ $key ] ) ? (string) $after[ $key ] : '';
			if ( $old === $new ) {
				continue;
			}
			$changed = true;
			echo '<details class="fleet-native-diff" open><summary>' . self::e( $label ) . '</summary>';
			if ( strlen( $old ) + strlen( $new ) > 24000 ) {
				echo '<p>' . self::e( __( 'Long source: showing the first 12,000 characters of each version. Review the full source in the editor before saving.', 'fleet-for-openstation' ) ) . '</p>';
				echo '<div class="fleet-native-diff-long"><pre>' . self::e( substr( $old, 0, 12000 ) ) . '</pre><pre>' . self::e( substr( $new, 0, 12000 ) ) . '</pre></div>';
			} else {
				echo wp_kses_post(
					wp_text_diff(
						$old,
						$new,
						array(
							'title_left'  => __( 'Before', 'fleet-for-openstation' ),
							'title_right' => __( 'After', 'fleet-for-openstation' ),
						)
					)
				);
			}
			echo '</details>';
		}
		if ( ! $changed ) {
			echo '<p>' . self::e( __( 'No changes to the supported fields.', 'fleet-for-openstation' ) ) . '</p>';
		}
	}

	/**
	 * Browse and compare Core revisions without performing a restore write.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
	private static function render_revisions( $state ) {
		$history  = (array) $state->get( 'history' );
		$revision = (array) $state->get( 'revision' );
		$editor   = (array) $state->get( 'editor' );
		?>
		<section class="fleet-native-revisions fleet-native-editor-action">
			<div class="fleet-native-section-heading"><span><small><?php echo self::e( __( 'Recovery', 'fleet-for-openstation' ) ); ?></small><h2><?php echo self::e( __( 'Revision history', 'fleet-for-openstation' ) ); ?></h2></span><os-button os-action="close-history"><?php echo self::e( __( 'Back to editor', 'fleet-for-openstation' ) ); ?></os-button></div>
			<p class="fleet-native-editor-help"><?php echo self::e( __( 'Compare an earlier version, then load its title, content and supported excerpt into the editor. Status, date and URL stay unchanged. Nothing is saved automatically.', 'fleet-for-openstation' ) ); ?></p>
			<?php if ( $revision ) : ?>
				<h3><?php echo self::e( str_replace( 'T', ' ', $revision['date_gmt'] ) . ' UTC' ); ?></h3>
				<?php self::content_diff( $editor, array_merge( $editor, array_intersect_key( OpenStation_Fleet_Content::editable( $revision ), array_flip( array( 'title', 'content', 'excerpt' ) ) ) ) ); ?>
				<os-button variant="primary" os-action="use-revision"><?php echo self::e( __( 'Use this revision', 'fleet-for-openstation' ) ); ?></os-button>
			<?php endif; ?>
			<div class="fleet-native-panel">
			<?php foreach ( $history['items'] as $item ) : ?>
				<div class="fleet-native-list-row"><span><?php echo self::e( str_replace( 'T', ' ', $item['date_gmt'] ) . ' UTC' ); ?></span><os-button os-action="preview-revision" os-arg-revision_id="<?php echo self::e( $item['id'] ); ?>"><?php echo self::e( __( 'Compare revision', 'fleet-for-openstation' ) ); ?></os-button></div>
			<?php endforeach; ?>
			<?php
			if ( empty( $history['items'] ) ) :
				?>
				<p class="fleet-native-empty-row"><?php echo self::e( __( 'No revisions are available yet.', 'fleet-for-openstation' ) ); ?></p><?php endif; ?>
			</div>
			<div class="fleet-native-review-actions">
			<?php
			if ( $history['page'] > 1 ) :
				?>
				<os-button os-action="revision-history" os-arg-page="<?php echo self::e( $history['page'] - 1 ); ?>"><?php echo self::e( __( 'Newer revisions', 'fleet-for-openstation' ) ); ?></os-button><?php endif; ?>
			<?php
			if ( $history['page'] * 12 < $history['total'] ) :
				?>
				<os-button os-action="revision-history" os-arg-page="<?php echo self::e( $history['page'] + 1 ); ?>"><?php echo self::e( __( 'Older revisions', 'fleet-for-openstation' ) ); ?></os-button><?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Small saved-filter drawer, owned by this hub user and destination site.
	 *
	 * @param string $id Connected site id.
	 * @param bool   $open Keep view controls open after a view action.
	 */
	private static function render_work_views( $id, $open ) {
		$views = OpenStation_Fleet::work_views( $id );
		if ( is_wp_error( $views ) ) {
			return;
		}
		?>
		<details class="fleet-native-saved-views" <?php echo $open ? 'open' : ''; ?>><summary><?php echo self::e( __( 'Saved work views', 'fleet-for-openstation' ) ); ?></summary>
			<p><?php echo self::e( __( 'Save the currently applied filters for this site. Views belong to you; results are fetched live, one page at a time.', 'fleet-for-openstation' ) ); ?></p>
			<?php foreach ( $views as $key => $view ) : ?>
				<div class="fleet-native-list-row"><os-button os-action="apply-view" os-arg-view_id="<?php echo self::e( $key ); ?>"><?php echo self::e( $view['name'] ); ?></os-button><os-button variant="ghost" os-action="delete-view" os-arg-view_id="<?php echo self::e( $key ); ?>"><?php echo self::e( __( 'Remove', 'fleet-for-openstation' ) . ' ' . $view['name'] ); ?></os-button></div>
			<?php endforeach; ?>
			<os-form os-action="save-view" submit-label="<?php echo self::e( __( 'Save view', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1"><os-text-field name="view_name" label="<?php echo self::e( __( 'View name', 'fleet-for-openstation' ) ); ?>" placeholder="<?php echo self::e( __( 'Pending review', 'fleet-for-openstation' ) ); ?>" required></os-text-field></os-form>
		</details>
		<?php
	}

	/**
	 * Search/filter one bounded collection, independent for each window.
	 *
	 * @param string $section Collection section.
	 * @param array  $options Current filters.
	 * @param array  $types Discovered content types.
	 */
	private static function collection_filters( $section, $options, $types = array() ) {
		?>
		<os-form class="fleet-native-filters" os-action="browse" submit-label="<?php echo self::e( __( 'Apply filters', 'fleet-for-openstation' ) ); ?>" show-reset="false">
			<input type="hidden" name="section" value="<?php echo self::e( $section ); ?>"><input type="hidden" name="page" value="1">
			<?php
			if ( 'content' === $section ) {
				self::status_select(
					'type',
					__( 'Content type', 'fleet-for-openstation' ),
					isset( $options['type'] ) ? $options['type'] : 'posts',
					array_map(
						static function ( $type ) {
							return $type['name']; },
						$types
					)
				);
				self::status_select(
					'status',
					__( 'Status', 'fleet-for-openstation' ),
					isset( $options['status'] ) ? $options['status'] : 'any',
					array(
						'any'     => __( 'All except Trash', 'fleet-for-openstation' ),
						'draft'   => __( 'Draft', 'fleet-for-openstation' ),
						'publish' => __( 'Published', 'fleet-for-openstation' ),
						'pending' => __( 'Pending review', 'fleet-for-openstation' ),
						'private' => __( 'Private', 'fleet-for-openstation' ),
						'future'  => __( 'Scheduled', 'fleet-for-openstation' ),
						'trash'   => __( 'Trash', 'fleet-for-openstation' ),
					)
				);
				self::status_select(
					'period',
					__( 'Publish date', 'fleet-for-openstation' ),
					isset( $options['period'] ) ? $options['period'] : 'all',
					array(
						'all'  => __( 'Any date', 'fleet-for-openstation' ),
						'week' => __( 'This week (site time)', 'fleet-for-openstation' ),
					)
				);
			} elseif ( 'comments' === $section ) {
				self::status_select(
					'status',
					__( 'Status', 'fleet-for-openstation' ),
					isset( $options['status'] ) ? $options['status'] : 'all',
					array(
						'all'     => __( 'All', 'fleet-for-openstation' ),
						'hold'    => __( 'Pending', 'fleet-for-openstation' ),
						'approve' => __( 'Approved', 'fleet-for-openstation' ),
						'spam'    => __( 'Spam', 'fleet-for-openstation' ),
						'trash'   => __( 'Trash', 'fleet-for-openstation' ),
					)
				);
			}
			?>
			<os-text-field name="search" label="<?php echo self::e( __( 'Search this site', 'fleet-for-openstation' ) ); ?>" value="<?php echo self::e( isset( $options['search'] ) ? $options['search'] : '' ); ?>"></os-text-field>
		</os-form>
		<?php
	}

	/**
	 * Render Core pagination without a silent item cap.
	 *
	 * @param string $section Collection section.
	 * @param array  $data Collection envelope.
	 */
	private static function pagination( $section, $data ) {
		// translators: 1: page number, 2: page count, 3: total matching items.
		$label = sprintf( __( 'Page %1$d of %2$d · %3$d items', 'fleet-for-openstation' ), $data['page'], max( 1, $data['pages'] ), $data['total'] );
		?>
		<nav class="fleet-native-pagination" aria-label="<?php echo self::e( __( 'Collection pages', 'fleet-for-openstation' ) ); ?>"><span><?php echo self::e( $label ); ?></span><span>
		<?php
		if ( $data['page'] > 1 ) :
			?>
			<os-button os-action="browse" os-arg-section="<?php echo self::e( $section ); ?>" os-arg-page="<?php echo self::e( $data['page'] - 1 ); ?>"><?php echo self::e( __( 'Previous', 'fleet-for-openstation' ) ); ?></os-button><?php endif; ?>
		<?php
		if ( $data['page'] < $data['pages'] ) :
			?>
			<os-button os-action="browse" os-arg-section="<?php echo self::e( $section ); ?>" os-arg-page="<?php echo self::e( $data['page'] + 1 ); ?>"><?php echo self::e( __( 'Next', 'fleet-for-openstation' ) ); ?></os-button><?php endif; ?>
		</span></nav>
		<?php
	}

	/**
	 * Dispatch the reviewed, expiring Core approval.
	 *
	 * @param string                $action Action name.
	 * @param OpenStation\App\State $state State.
	 * @param OpenStation\App\Os    $os Host handle.
	 * @return bool
	 */
	private static function connection_action( $action, $state, $os ) {
		if ( 'cancel-connect' === $action ) {
			$state->set( 'connection', array() );
			return true;
		}
		if ( 'authorize' !== $action ) {
			return false;
		}
		$connection = (array) $state->get( 'connection' );
		$url        = OpenStation_Fleet::app_authorize( isset( $connection['ticket'] ) ? $connection['ticket'] : null );
		if ( is_wp_error( $url ) ) {
			$state->set( 'notice', $url->get_error_message() );
			$state->set( 'connection', array() );
		} else {
			$os->effects->add( 'fleet-authorize', array( 'url' => $url ) );
		}
		return true;
	}

	/**
	 * Show what passed and what the user is about to approve.
	 *
	 * @param OpenStation\App\State $state State.
	 * @return bool
	 */
	private static function connection_review( $state ) {
		$connection = (array) $state->get( 'connection' );
		if ( empty( $connection['authorization_url'] ) ) {
			return false;
		}
		?>
		<section class="fleet-native-connection-review">
			<os-badge tone="success"><?php echo self::e( __( 'Ready for approval', 'fleet-for-openstation' ) ); ?></os-badge>
			<h2><?php echo self::e( $connection['name'] ); ?></h2><p><?php echo self::e( $connection['url'] ); ?></p>
			<ol><li><?php echo self::e( __( 'Checked: HTTPS, WordPress REST API, and Application Password approval.', 'fleet-for-openstation' ) ); ?></li><li><?php echo self::e( __( 'Next: sign in on this site as an administrator and approve Fleet. WordPress may ask for your login; Fleet never receives that password.', 'fleet-for-openstation' ) ); ?></li><li><?php echo self::e( __( 'Then: return here to finish OpenStation setup. No Fleet plugin is installed on the managed site.', 'fleet-for-openstation' ) ); ?></li></ol>
			<os-notice tone="warning"><?php echo self::e( __( 'This is administrator-level access, not a limited OAuth scope. The separate Application Password has no automatic expiry. You can revoke it on WordPress or disconnect it in Fleet.', 'fleet-for-openstation' ) ); ?></os-notice>
			<div class="fleet-native-review-actions"><os-button variant="primary" os-action="authorize"><?php echo self::e( __( 'Continue to WordPress', 'fleet-for-openstation' ) ); ?></os-button><os-button os-action="cancel-connect"><?php echo self::e( __( 'Cancel', 'fleet-for-openstation' ) ); ?></os-button></div>
		</section>
		<?php
		return true;
	}

	/** Render the connection form. */
	private static function connect_form() {
		?>
		<os-form class="fleet-native-connect" os-action="connect" submit-label="<?php echo self::e( __( 'Check connection', 'fleet-for-openstation' ) ); ?>" show-reset="false" columns="1">
			<div slot="header"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span><h3><?php echo self::e( __( 'Connect another WordPress site', 'fleet-for-openstation' ) ); ?></h3><p><?php echo self::e( __( 'The managed site needs only WordPress Core and HTTPS—no Fleet plugin installation.', 'fleet-for-openstation' ) ); ?></p></span></div>
			<os-text-field name="site_url" type="url" label="<?php echo self::e( __( 'Site address', 'fleet-for-openstation' ) ); ?>" placeholder="https://client.example" required></os-text-field>
		</os-form>
		<?php
	}

	/**
	 * Add an open-site custom effect.
	 *
	 * @param OpenStation\App\Os $os Host handle.
	 * @param string             $id Site identifier.
	 */
	private static function open_site( $os, $id ) {
		$id   = sanitize_key( $id );
		$site = OpenStation_Fleet::app_site( $id );
		if ( is_wp_error( $site ) ) {
			$os->toast( $site->get_error_message() );
			return;
		}
		$os->effects->add(
			'fleet-open-site',
			array(
				'siteId' => $id,
				'title'  => $site['name'],
			)
		);
	}

	/**
	 * Normalize os-form values.
	 *
	 * @param array $args Dispatch arguments.
	 */
	private static function values( $args ) {
		return isset( $args['values'] ) && is_array( $args['values'] ) ? $args['values'] : array();
	}

	/**
	 * Render a state notice.
	 *
	 * @param OpenStation\App\State $state App state.
	 */
	private static function notice( $state ) {
		$notice = sanitize_text_field( (string) $state->get( 'notice' ) );
		if ( '' !== $notice ) {
			?>
			<os-notice tone="danger"><?php echo self::e( $notice ); ?></os-notice>
			<?php
		}
	}

	/**
	 * Render a remote error.
	 *
	 * @param WP_Error|mixed $error Remote error value.
	 */
	private static function error( $error ) {
		?>
		<os-notice tone="danger"><?php echo self::e( is_wp_error( $error ) ? $error->get_error_message() : __( 'The managed site returned an unexpected response.', 'fleet-for-openstation' ) ); ?></os-notice>
		<?php
	}

	/**
	 * Convert a possible WP_Error collection to a list.
	 *
	 * @param mixed $value Possible collection.
	 */
	private static function items( $value ) {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Return a plain title from a Core REST record.
	 *
	 * @param array $item Core REST record.
	 */
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

	/**
	 * Render a named os-select.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Field label.
	 * @param string $current Current value.
	 * @param array  $options Available options.
	 */
	private static function status_select( $name, $label, $current, $options ) {
		?>
		<os-select name="<?php echo self::e( $name ); ?>" label="<?php echo self::e( $label ); ?>" value="<?php echo self::e( $current ); ?>">
		<?php
		foreach ( $options as $value => $option_label ) :
			?>
			<os-option value="<?php echo self::e( $value ); ?>"><?php echo self::e( $option_label ); ?></os-option><?php endforeach; ?></os-select>
		<?php
	}

	/**
	 * Render the common WordPress role picker.
	 *
	 * @param string $name    Field name.
	 * @param string $current Current role.
	 */
	private static function role_select( $name, $current ) {
		self::status_select(
			$name,
			__( 'Role', 'fleet-for-openstation' ),
			$current,
			array(
				'administrator' => __( 'Administrator', 'fleet-for-openstation' ),
				'editor'        => __( 'Editor', 'fleet-for-openstation' ),
				'author'        => __( 'Author', 'fleet-for-openstation' ),
				'contributor'   => __( 'Contributor', 'fleet-for-openstation' ),
				'subscriber'    => __( 'Subscriber', 'fleet-for-openstation' ),
			)
		);
	}

	/**
	 * Escape dynamic text and attribute values with the framework helper.
	 *
	 * @param mixed $value Dynamic value.
	 */
	private static function e( $value ) {
		return \OpenStation\App\Html\esc( (string) $value );
	}

	/**
	 * Encode a dynamic JSON attribute with the framework helper.
	 *
	 * @param mixed $value Dynamic value.
	 */
	private static function j( $value ) {
		return \OpenStation\App\Html\json( $value );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
