<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: tabbed settings page, media library integration, notices and AJAX tools.
 */
final class CDNHub_Admin {

	private $settings;
	private $logger;
	private $uploader;
	private $urls;

	public function __construct( CDNHub_Settings $settings, CDNHub_Logger $logger, CDNHub_Uploader $uploader, CDNHub_URLs $urls ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->uploader = $uploader;
		$this->urls     = $urls;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CDNHUB_FILE ), array( $this, 'action_links' ) );

		// Media library.
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );
		add_action( 'post-upload-ui', array( $this, 'render_upload_ui' ) );
		add_action( 'wp_enqueue_media', array( $this, 'enqueue_media_modal' ) );

		// Actions.
		add_action( 'admin_post_cdnhub_row_sync', array( $this, 'handle_row_sync' ) );
		add_action( 'admin_post_cdnhub_row_local', array( $this, 'handle_row_local' ) );
		add_action( 'admin_post_cdnhub_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'wp_ajax_cdnhub_test', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_cdnhub_sync', array( $this, 'ajax_sync' ) );
		add_action( 'wp_ajax_cdnhub_resync', array( $this, 'ajax_resync' ) );
		add_action( 'wp_ajax_cdnhub_local', array( $this, 'ajax_local' ) );
		add_action( 'wp_ajax_cdnhub_adopt_start', array( $this, 'ajax_adopt_start' ) );
		add_action( 'wp_ajax_cdnhub_adopt', array( $this, 'ajax_adopt' ) );
		add_action( 'wp_ajax_cdnhub_adopt_dismiss', array( $this, 'ajax_adopt_dismiss' ) );
		add_action( 'wp_ajax_cdnhub_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_cdnhub_reinclude', array( $this, 'ajax_reinclude' ) );
	}

	/* --------------------------------------------------------------- plumbing */

	public function menu() {
		add_management_page( 'CDNHub', 'CDNHub', 'manage_options', 'cdnhub', array( $this, 'render_page' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=cdnhub' ) ) . '">Settings</a>' );
		return $links;
	}

	public function register_settings() {
		register_setting(
			'cdnhub_group',
			CDNHub_Settings::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
			)
		);
	}

	public function enqueue( $hook ) {
		$screens = array( 'tools_page_cdnhub', 'upload.php', 'media-new.php', 'post.php', 'post-new.php' );
		if ( ! in_array( $hook, $screens, true ) ) {
			return;
		}
		$this->load_assets();
	}

	public function enqueue_media_modal() {
		$this->load_assets();
	}

	private function load_assets() {
		if ( wp_script_is( 'cdnhub-admin', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'cdnhub-admin', CDNHUB_URL . 'assets/admin.css', array(), CDNHUB_VERSION );
		wp_enqueue_script( 'cdnhub-admin', CDNHUB_URL . 'assets/admin.js', array( 'jquery' ), CDNHUB_VERSION, true );
		wp_localize_script(
			'cdnhub-admin',
			'CDNHub',
			array(
				'ajax'          => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'cdnhub' ),
				'upload_to_cdn' => (int) $this->settings->get( 'upload_to_cdn' ),
				'is_active'     => $this->settings->is_active() ? 1 : 0,
			)
		);
	}

	private function guard_ajax( $cap = 'manage_options' ) {
		check_ajax_referer( 'cdnhub', 'nonce' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to do this.' ), 403 );
		}
	}

	private function flash( $type, $message ) {
		set_transient( 'cdnhub_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	/* ---------------------------------------------------------------- notices */

	public function notices() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$flash = get_transient( 'cdnhub_flash_' . get_current_user_id() );
		if ( is_array( $flash ) ) {
			delete_transient( 'cdnhub_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $flash[0] ), esc_html( $flash[1] ) );
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'upload', 'post', 'tools_page_cdnhub' ), true ) || ! $this->settings->get( 'enabled' ) ) {
			return;
		}
		if ( ! $this->settings->is_ready() ) {
			if ( 'tools_page_cdnhub' !== $screen->base && current_user_can( 'manage_options' ) ) {
				printf(
					'<div class="notice notice-info"><p><strong>CDNHub</strong> is almost ready. <a href="%s">Finish the 2-minute setup</a> to start serving images from GitHub.</p></div>',
					esc_url( admin_url( 'tools.php?page=cdnhub' ) )
				);
			}
			return;
		}
		$counts = $this->uploader->counts();
		if ( $counts['failed'] > 0 && 'tools_page_cdnhub' !== $screen->base ) {
			printf(
				'<div class="notice notice-warning"><p><strong>CDNHub:</strong> %d image(s) could not be uploaded to GitHub. They keep working from your own server%s. <a href="%s">See details</a></p></div>',
				(int) $counts['failed'],
				$this->settings->get( 'auto_retry' ) ? ' and uploads are retried automatically' : '',
				esc_url( admin_url( 'tools.php?page=cdnhub#log' ) )
			);
		}

		if ( current_user_can( 'manage_options' ) && 'tools_page_cdnhub' !== $screen->base && ! get_option( 'cdnhub_adopt_notice' ) ) {
			global $wpdb;
			$q = $this->uploader->unsynced_sql( 'COUNT(*)', 0 );
			if ( $q && (int) $wpdb->get_var( $q ) > 0 ) { // phpcs:ignore
				printf(
					'<div class="notice notice-info is-dismissible cdnhub-adopt-notice"><p><strong>CDNHub:</strong> images in your Media Library are not on GitHub yet. Already pushed them to the repository yourself? <a href="%s">Let CDNHub adopt them by content hash</a> — no re-upload. Otherwise use <a href="%s">bulk upload</a>.</p></div>',
					esc_url( admin_url( 'tools.php?page=cdnhub#tools' ) ),
					esc_url( admin_url( 'tools.php?page=cdnhub#tools' ) )
				);
			}
		}
	}

	/* --------------------------------------------------------- media library */

	private function badge( $id ) {
		switch ( CDNHub_Store::status( $id ) ) {
			case 'excluded':
				return '<span class="cdnhub-badge is-local" title="Excluded from CDN — served from your server only">&#9874; Excluded</span>';
			case 'synced':
				return '<span class="cdnhub-badge is-ok">&#10003; On CDN</span>';
			case 'failed':
				$err = (string) get_post_meta( $id, '_cdnhub_error', true );
				return '<span class="cdnhub-badge is-bad" title="' . esc_attr( $err ) . '">&#10007; Failed</span>';
			case 'pending':
				return '<span class="cdnhub-badge is-wait">&#8987; Queued</span>';
		}
		return '<span class="cdnhub-badge">Local only</span>';
	}

	public function media_column( $cols ) {
		$cols['cdnhub'] = 'CDNHub';
		return $cols;
	}

	public function media_column_content( $col, $id ) {
		if ( 'cdnhub' !== $col ) {
			return;
		}
		if ( ! $this->uploader->is_supported( $id ) ) {
			echo '&mdash;';
			return;
		}
		echo $this->badge( $id ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function row_actions( $actions, $post ) {
		$cap = $this->settings->is_active() && current_user_can( 'edit_post', $post->ID ) && $this->uploader->is_supported( $post->ID );
		if ( $cap && CDNHub_Store::is_excluded( $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cdnhub_row_local&id=' . (int) $post->ID . '&state=0' ), 'cdnhub_row_' . $post->ID );
			$actions['cdnhub_local'] = '<a href="' . esc_url( $url ) . '" title="Remove exclusion and serve from CDN">Serve from CDN</a>';
		} elseif ( $cap ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cdnhub_row_sync&id=' . (int) $post->ID ), 'cdnhub_row_' . $post->ID );
			$actions['cdnhub'] = '<a href="' . esc_url( $url ) . '">' . ( CDNHub_Store::is_synced( $post->ID ) ? 'Re-sync to CDN' : 'Send to CDN' ) . '</a>';
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cdnhub_row_local&id=' . (int) $post->ID . '&state=1' ), 'cdnhub_row_' . $post->ID );
			$actions['cdnhub_local'] = '<a href="' . esc_url( $url ) . '" title="Exclude from CDN and keep on this server only">Exclude from CDN</a>';
		}
		return $actions;
	}

	public function handle_row_sync() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'cdnhub_row_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$r = $this->uploader->sync( $id, null, true );
		$this->flash( $r['ok'] ? 'success' : 'error', $r['ok'] ? 'Image synced to the CDN.' : 'CDNHub: ' . $r['message'] );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	/** Row action / bulk toggle: keep an image on this server (or hand it back to the CDN). */
	public function handle_row_local() {
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$exclude = ! isset( $_GET['state'] ) || (bool) absint( $_GET['state'] );
		check_admin_referer( 'cdnhub_row_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$r = $exclude ? $this->uploader->keep_local( $id ) : $this->uploader->use_cdn( $id );
		$this->flash( $r['ok'] ? 'success' : 'error', 'CDNHub: ' . $r['message'] );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	public function bulk_actions( $actions ) {
		$actions['cdnhub_sync']    = 'Send to CDN';
		$actions['cdnhub_exclude'] = 'Exclude from CDN (serve locally)';
		$actions['cdnhub_include'] = 'Serve from CDN (remove exclusion)';
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'cdnhub_sync', 'cdnhub_exclude', 'cdnhub_include' ), true ) ) {
			return $redirect;
		}
		if ( ! $this->settings->is_active() ) {
			$this->flash( 'error', 'CDNHub is not active. Finish the setup first.' );
			return $redirect;
		}
		$ok = 0;
		$no = 0;
		foreach ( array_slice( array_map( 'absint', (array) $ids ), 0, 25 ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) || ! $this->uploader->is_supported( $id ) ) {
				continue;
			}
			if ( 'cdnhub_exclude' === $action ) {
				$r = $this->uploader->keep_local( $id );
			} elseif ( 'cdnhub_include' === $action ) {
				if ( ! CDNHub_Store::is_excluded( $id ) ) {
					continue; // Nothing to remove.
				}
				$r = $this->uploader->use_cdn( $id );
			} elseif ( CDNHub_Store::is_excluded( $id ) ) {
				continue; // Use "Serve from CDN" for excluded images.
			} else {
				$r = $this->uploader->sync( $id );
			}
			$r['ok'] ? $ok++ : $no++;
			if ( ! empty( $r['rate_limited'] ) ) {
				break;
			}
		}
		$extra = count( (array) $ids ) > 25 ? ' Only the first 25 were processed — select the next batch or use Tools → CDNHub → Tools.' : '';
		$what  = 'cdnhub_exclude' === $action ? 'excluded from CDN' : ( 'cdnhub_include' === $action ? 'handed back to the CDN' : 'synced' );
		$this->flash( $no ? 'warning' : 'success', sprintf( 'CDNHub: %d image(s) %s, %d failed.%s', $ok, $what, $no, $extra ) );
		return $redirect;
	}

	/** Status + buttons shown when you click an image in the media library / editor. */
	public function attachment_fields( $fields, $post ) {
		if ( ! $this->settings->is_ready() || ! $this->uploader->is_supported( $post->ID ) ) {
			return $fields;
		}
		$fields['cdnhub'] = array(
			'label' => 'CDNHub',
			'input' => 'html',
			'html'  => '<div class="cdnhub-field" data-id="' . (int) $post->ID . '">' . $this->field_html( (int) $post->ID ) . '</div>',
		);
		return $fields;
	}

	/** Inner HTML of the CDNHub field (also returned by AJAX so the buttons can swap). */
	private function field_html( $id ) {
		$excluded = CDNHub_Store::is_excluded( $id );
		if ( $excluded ) {
			$html  = '<div class="cdnhub-status">' . $this->badge( $id ) . '</div>';
			$html .= '<p class="description">Excluded from the CDN — served from your own server only. CDNHub will not upload it or rewrite its URLs. Ideal for logos and theme images.</p>';
			$html .= '<button type="button" class="button cdnhub-include" data-id="' . $id . '">Serve from CDN</button>';
			$html .= '<p class="cdnhub-error" style="display:none"></p>';
			return $html;
		}

		$url = CDNHub_Store::is_synced( $id )
			? $this->urls->for_file( CDNHub_Store::dir( $id ), wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) )
			: '';
		$err = (string) get_post_meta( $id, '_cdnhub_error', true );

		$html  = '<div class="cdnhub-status">' . $this->badge( $id ) . '</div>';
		$html .= '<input type="text" class="widefat cdnhub-url" readonly value="' . esc_attr( $url ) . '"' . ( $url ? '' : ' style="display:none"' ) . ' onfocus="this.select()">';
		$html .= '<p class="cdnhub-error"' . ( 'failed' === CDNHub_Store::status( $id ) && $err ? '' : ' style="display:none"' ) . '>' . esc_html( $err ) . '</p>';
		$html .= '<button type="button" class="button cdnhub-resync" data-id="' . $id . '">' . ( $url ? 'Re-sync to CDN' : 'Send to CDN' ) . '</button>';
		$html .= ' <button type="button" class="button cdnhub-exclude" data-id="' . $id . '" title="Exclude from CDN and serve only from your server (e.g. logos)">Exclude from CDN</button>';
		return $html;
	}

	public function render_upload_ui() {
		if ( ! $this->settings->is_active() ) {
			return;
		}
		$default_on = (bool) $this->settings->get( 'upload_to_cdn' );
		?>
		<div class="cdnhub-upload-option-wrap hide-if-no-js">
			<label class="cdnhub-upload-option-label" for="cdnhub-upload-toggle">
				<span class="cdnhub-toggle-switch">
					<input type="checkbox" id="cdnhub-upload-toggle" class="cdnhub-upload-toggle" name="cdnhub_offload" value="1" <?php checked( $default_on ); ?>>
					<span class="cdnhub-toggle-slider"></span>
				</span>
				<span class="cdnhub-toggle-info">
					<strong class="cdnhub-toggle-title">
						<svg class="cdnhub-svg-icon" width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
						Upload to GitHub via CDNHub
					</strong>
					<span class="cdnhub-toggle-desc">Images will be stored on GitHub and served from CDN</span>
				</span>
			</label>
		</div>
		<p class="hide-if-js cdnhub-upload-option-fallback">
			<label><input type="checkbox" name="cdnhub_offload" value="1" <?php checked( $default_on ); ?>> Upload to GitHub via CDNHub (serve from CDN)</label>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------- ajax */

	public function ajax_test() {
		$this->guard_ajax();
		$token = isset( $_POST['token'] ) ? preg_replace( '/\s+/', '', wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore
		if ( '' === $token ) {
			$token = (string) $this->settings->get( 'token' );
		}
		$owner  = preg_replace( '/[^A-Za-z0-9._-]/', '', isset( $_POST['owner'] ) ? wp_unslash( $_POST['owner'] ) : '' ); // phpcs:ignore
		$repo   = preg_replace( '/[^A-Za-z0-9._-]/', '', isset( $_POST['repo'] ) ? wp_unslash( $_POST['repo'] ) : '' ); // phpcs:ignore
		$branch = preg_replace( '/[^A-Za-z0-9._\/-]/', '', isset( $_POST['branch'] ) ? wp_unslash( $_POST['branch'] ) : '' ); // phpcs:ignore

		$provider = new CDNHub_GitHub( $token, $owner, $repo, '' !== $branch ? $branch : 'main' );
		if ( ! $provider->is_configured() ) {
			wp_send_json_error( array( 'message' => 'Please fill in the token, owner, repository and branch first.' ) );
		}
		$r = $provider->test();
		if ( is_wp_error( $r ) ) {
			update_option( 'cdnhub_last_test', array( 'ok' => false, 'time' => time() ), false );
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		update_option( 'cdnhub_last_test', array( 'ok' => true, 'time' => time() ), false );
		$msg = $r['message'];
		if ( $r['private'] ) {
			$msg .= ' Note: the repository is private, so jsDelivr / raw links will not be publicly reachable. Make it public.';
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/** Bulk tool: sync one not-yet-synced image per request. */
	public function ajax_sync() {
		$this->guard_ajax();
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => 'CDNHub is disabled or not fully configured. Save your settings first.' ) );
		}
		global $wpdb;
		$last = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) : 0;
		$q    = $this->uploader->unsynced_sql( 'p.ID', $last );
		if ( ! $q ) {
			wp_send_json_success( array( 'done' => true ) );
		}
		$id = (int) $wpdb->get_var( $q . ' ORDER BY p.ID ASC LIMIT 1' ); // phpcs:ignore
		if ( ! $id ) {
			$counts = $this->uploader->counts();
			$notice = '';
			if ( $counts['local'] > 0 ) {
				$notice = 'Note: ' . $counts['local'] . ' image(s) are currently marked as Excluded from CDN ("kept on this server"). Use "Send excluded images to CDN" above to offload them.';
			}
			wp_send_json_success( array( 'done' => true, 'notice' => $notice ) );
		}
		$remaining = (int) $wpdb->get_var( $this->uploader->unsynced_sql( 'COUNT(*)', $last ) ); // phpcs:ignore
		$r         = $this->uploader->sync( $id );

		wp_send_json_success(
			array(
				'done'      => false,
				'id'        => $id,
				'ok'        => $r['ok'],
				'message'   => $r['message'],
				'stop'      => ! empty( $r['rate_limited'] ),
				'remaining' => $remaining,
			)
		);
	}

	/** Adopt tool, step 1: read the whole remote tree once and count what needs adopting. */
	public function ajax_adopt_start() {
		$this->guard_ajax();
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => 'CDNHub is disabled or not fully configured. Save your settings first.' ) );
		}
		global $wpdb;
		$this->uploader->drop_index(); // Always start from a fresh snapshot.
		$index = $this->uploader->load_index();
		if ( is_wp_error( $index ) ) {
			wp_send_json_error( array( 'message' => $index->get_error_message() ) );
		}
		$q      = $this->uploader->unsynced_sql( 'COUNT(*)', 0 );
		$remain = $q ? (int) $wpdb->get_var( $q ) : 0; // phpcs:ignore
		$counts = $this->uploader->counts();
		$notice = '';
		if ( 0 === $remain && $counts['local'] > 0 ) {
			$notice = 'All standard images are already synced. Note: ' . $counts['local'] . ' image(s) are currently marked as Excluded from CDN ("kept on this server") and were skipped. Use "Send excluded images to CDN" above to offload them.';
		}
		wp_send_json_success(
			array(
				'files'  => count( $index['paths'] ),
				'remain' => $remain,
				'notice' => $notice,
			)
		);
	}

	/** Adopt tool, step 2: adopt un-synced images in batches (no upload for matching files). */
	public function ajax_adopt() {
		$this->guard_ajax();
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => 'CDNHub is disabled or not fully configured. Save your settings first.' ) );
		}
		$index = $this->uploader->load_index();
		if ( is_wp_error( $index ) ) {
			wp_send_json_error( array( 'message' => $index->get_error_message() ) );
		}
		$last = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) : 0;
		wp_send_json_success( $this->uploader->adopt_batch( $index, $last ) );
	}

	public function ajax_adopt_dismiss() {
		$this->guard_ajax();
		update_option( 'cdnhub_adopt_notice', 1, false );
		wp_send_json_success();
	}

	/** Free up server space: delete local copies of images verified to be on the CDN. */
	public function ajax_cleanup() {
		$this->guard_ajax();
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => 'CDNHub is disabled or not fully configured. Save your settings first.' ) );
		}
		$last = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) : 0;
		wp_send_json_success( $this->uploader->cleanup_batch( $last ) );
	}

	/** Re-include tool: re-include excluded images and sync them to CDN in batches. */
	public function ajax_reinclude() {
		$this->guard_ajax();
		if ( ! $this->settings->is_active() ) {
			wp_send_json_error( array( 'message' => 'CDNHub is disabled or not fully configured. Save your settings first.' ) );
		}
		$last = isset( $_POST['last_id'] ) ? absint( $_POST['last_id'] ) : 0;
		wp_send_json_success( $this->uploader->reinclude_batch( $last ) );
	}

	/** Single attachment (button in the media modal / attachment screen). */
	public function ajax_resync() {
		$this->guard_ajax( 'upload_files' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		if ( ! $this->settings->is_active() || ! $this->uploader->is_supported( $id ) ) {
			wp_send_json_error( array( 'message' => 'CDNHub is not active, or this file type is not enabled.' ) );
		}
		$r  = $this->uploader->sync( $id, null, true );
		$ok = $r['ok'];
		wp_send_json_success(
			array(
				'ok'      => $ok,
				'message' => $r['message'],
				'badge'   => $this->badge( $id ),
				'url'     => $ok && ! empty( $r['url'] ) ? $r['url'] : '',
			)
		);
	}

	/** Media modal toggle: keep this image on the server (exclude=1) or hand it back to the CDN (exclude=0). */
	public function ajax_local() {
		$this->guard_ajax( 'upload_files' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		if ( ! $this->uploader->is_supported( $id ) ) {
			wp_send_json_error( array( 'message' => 'This file type is not enabled for upload.' ) );
		}
		$exclude = ! empty( $_POST['exclude'] );
		$r = $exclude ? $this->uploader->keep_local( $id ) : $this->uploader->use_cdn( $id );
		wp_send_json_success(
			array(
				'ok'      => ! empty( $r['ok'] ),
				'message' => $r['message'],
				'badge'   => $this->badge( $id ),
				'field'   => $this->field_html( $id ),
			)
		);
	}

	public function handle_clear_log() {
		check_admin_referer( 'cdnhub_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$this->logger->clear();
		$this->flash( 'success', 'CDNHub log cleared.' );
		wp_safe_redirect( admin_url( 'tools.php?page=cdnhub#log' ) );
		exit;
	}

	/* ------------------------------------------------------------ settings UI */

	private function field_name( $key ) {
		return esc_attr( CDNHub_Settings::OPT . '[' . $key . ']' );
	}

	private function check( $key, $label, $desc = '' ) {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> %s</label>%s',
			$this->field_name( $key ), // phpcs:ignore
			checked( (bool) $this->settings->get( $key ), true, false ), // phpcs:ignore
			esc_html( $label ),
			$desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : '' // phpcs:ignore
		);
	}

	private function select( $key, $options, $id = '' ) {
		$cur = (string) $this->settings->get( $key );
		printf( '<select name="%s" id="%s">', $this->field_name( $key ), esc_attr( $id ) ); // phpcs:ignore
		foreach ( $options as $val => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $cur, (string) $val, false ), esc_html( $label ) ); // phpcs:ignore
		}
		echo '</select>';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = $this->settings;
		$counts = $this->uploader->counts();
		$test   = get_option( 'cdnhub_last_test', array() );
		$ready  = $s->is_ready();

		if ( ! $ready ) {
			$pill = array( 'Setup needed', 'is-wait' );
		} elseif ( ! $s->get( 'enabled' ) ) {
			$pill = array( 'Paused', 'is-off' );
		} elseif ( is_array( $test ) && isset( $test['ok'] ) && ! $test['ok'] ) {
			$pill = array( 'Check connection', 'is-bad' );
		} else {
			$pill = array( 'Active', 'is-ok' );
		}
		$steps = array(
			array( 'Create a public GitHub repository', $ready || '' !== $s->get( 'repo' ) ),
			array( 'Create an access token and paste it below', '' !== $s->get( 'token' ) ),
			array( 'Click “Test connection”', is_array( $test ) && ! empty( $test['ok'] ) ),
			array( 'Upload an image in any post — that’s it', $counts['synced'] > 0 ),
		);
		?>
		<div class="wrap cdnhub">
			<h1>CDNHub <span class="cdnhub-badge <?php echo esc_attr( $pill[1] ); ?>"><?php echo esc_html( $pill[0] ); ?></span></h1>
			<?php settings_errors(); ?>

			<div class="cdnhub-cards">
				<div class="cdnhub-card"><strong><?php echo (int) $counts['synced']; ?></strong><span>images on CDN</span></div>
				<div class="cdnhub-card"><strong><?php echo (int) $counts['pending']; ?></strong><span>queued</span></div>
				<div class="cdnhub-card <?php echo $counts['failed'] ? 'is-bad' : ''; ?>"><strong><?php echo (int) $counts['failed']; ?></strong><span>failed</span></div>
				<div class="cdnhub-card"><strong><?php echo (int) $counts['local']; ?></strong><span title="Served locally from your server (excluded from CDN)">excluded from CDN</span></div>
			</div>

			<?php if ( ! $ready || ! $counts['synced'] ) : ?>
				<div class="cdnhub-setup">
					<h2>Quick setup</h2>
					<ol>
						<?php foreach ( $steps as $i => $st ) : ?>
							<li class="<?php echo $st[1] ? 'done' : ''; ?>"><?php echo esc_html( $st[0] ); ?></li>
						<?php endforeach; ?>
					</ol>
					<p>
						<a class="button" href="https://github.com/new" target="_blank" rel="noopener noreferrer">Create repository &#8599;</a>
						<a class="button" href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">Create token &#8599;</a>
					</p>
					<p class="description">Token tip: choose “Only select repositories” → your media repo, then under Permissions set <strong>Contents: Read and write</strong>.</p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper cdnhub-tabs">
				<a href="#connection" class="nav-tab" data-tab="connection">Connection</a>
				<a href="#storage" class="nav-tab" data-tab="storage">Storage &amp; URLs</a>
				<a href="#rules" class="nav-tab" data-tab="rules">Upload rules</a>
				<a href="#tools" class="nav-tab" data-tab="tools">Tools</a>
				<a href="#log" class="nav-tab" data-tab="log">Activity log</a>
			</h2>

			<form method="post" action="options.php" id="cdnhub-form">
				<?php settings_fields( 'cdnhub_group' ); ?>

				<!-- Connection -->
				<div class="cdnhub-panel" data-panel="connection">
					<table class="form-table" role="presentation">
						<tr><th scope="row">Automatic upload</th>
							<td><?php $this->check( 'enabled', 'Upload new images to GitHub and use the CDN link in my posts' ); ?></td></tr>
						<tr><th scope="row"><label for="cdnhub-token">Access token</label></th>
							<td>
								<?php if ( $s->token_from_constant() ) : ?>
									<em>Defined in <code>wp-config.php</code> (CDNHUB_GITHUB_TOKEN).</em>
								<?php else : ?>
									<input type="password" id="cdnhub-token" class="regular-text" name="<?php echo $this->field_name( 'token' ); // phpcs:ignore ?>" value="" autocomplete="new-password"
										placeholder="<?php echo '' !== $s->get( 'token' ) ? '•••••••• saved — leave blank to keep' : 'github_pat_…'; ?>">
									<p class="description">Stored encrypted and never shown again. Alternatively add <code>define( 'CDNHUB_GITHUB_TOKEN', '…' );</code> to wp-config.php.</p>
								<?php endif; ?>
							</td></tr>
						<tr><th scope="row"><label for="cdnhub-owner">GitHub username / organization</label></th>
							<td><input type="text" id="cdnhub-owner" class="regular-text" name="<?php echo $this->field_name( 'owner' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'owner' ) ); ?>" placeholder="your-username"></td></tr>
						<tr><th scope="row"><label for="cdnhub-repo">Repository</label></th>
							<td><input type="text" id="cdnhub-repo" class="regular-text" name="<?php echo $this->field_name( 'repo' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'repo' ) ); ?>" placeholder="blog-media">
								<p class="description">Must be <strong>public</strong> so visitors can load the images.</p></td></tr>
						<tr><th scope="row"><label for="cdnhub-branch">Branch</label></th>
							<td><input type="text" id="cdnhub-branch" class="regular-text" name="<?php echo $this->field_name( 'branch' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'branch' ) ); ?>"></td></tr>
						<tr><th scope="row">Check</th>
							<td><button type="button" class="button button-secondary" id="cdnhub-test">Test connection</button>
								<span id="cdnhub-test-out" class="cdnhub-out"></span>
								<p class="description">Tests the values above — you don’t need to save first.</p></td></tr>
					</table>
				</div>

				<!-- Storage -->
				<div class="cdnhub-panel" data-panel="storage">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="cdnhub-folder">Media folder in repository</label></th>
							<td><input type="text" id="cdnhub-folder" class="regular-text" name="<?php echo $this->field_name( 'folder' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'folder' ) ); ?>" placeholder="media">
								<p class="description">Leave empty to use the repository root.</p></td></tr>
						<tr><th scope="row"><label for="cdnhub-structure">Folder structure</label></th>
							<td>
								<?php
								$this->select(
									'structure',
									array(
										'year_month' => 'Year / Month  (media/2026/10/)',
										'year'       => 'Year only  (media/2026/)',
										'post_id'    => 'Post ID  (media/123/)',
										'flat'       => 'One folder  (media/)',
										'custom'     => 'Custom pattern…',
									),
									'cdnhub-structure'
								);
								?>
								<div id="cdnhub-custom-pattern-wrap">
									<input type="text" id="cdnhub-custom-pattern" class="regular-text" name="<?php echo $this->field_name( 'custom_pattern' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'custom_pattern' ) ); ?>">
									<p class="description">Tokens: <code>{year}</code> <code>{month}</code> <code>{day}</code> <code>{post_id}</code></p>
								</div>
							</td></tr>
						<tr><th scope="row"><label for="cdnhub-urlmode">Public URL format</label></th>
							<td>
								<?php
								$this->select(
									'url_mode',
									array(
										'jsdelivr' => 'jsDelivr CDN (recommended)',
										'raw'      => 'GitHub raw (raw.githubusercontent.com)',
										'pages'    => 'GitHub Pages (owner.github.io)',
										'custom'   => 'Custom domain / proxy…',
									),
									'cdnhub-urlmode'
								);
								?>
								<div id="cdnhub-custom-template-wrap">
									<input type="text" id="cdnhub-custom-template" class="large-text" name="<?php echo $this->field_name( 'custom_template' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'custom_template' ) ); ?>" placeholder="https://cdn.example.com/{path}">
									<p class="description">Tokens: <code>{path}</code> (required), <code>{owner}</code> <code>{repo}</code> <code>{branch}</code></p>
								</div>
								<p class="description" id="cdnhub-urlmode-help"></p>
								<p><strong>Example link:</strong> <code id="cdnhub-preview"></code></p>
							</td></tr>
						<tr><th scope="row"><label for="cdnhub-conflict">If a file name already exists</label></th>
							<td>
								<?php
								$this->select(
									'conflict',
									array(
										'unique'    => 'Keep both — store the new image in a unique sub-folder (safe)',
										'fail'      => 'Don’t upload — keep the local image',
										'overwrite' => 'Overwrite the existing file',
									),
									'cdnhub-conflict'
								);
								?>
								<p class="description">Identical files are never uploaded twice.</p></td></tr>
					</table>
				</div>

				<!-- Rules -->
				<div class="cdnhub-panel" data-panel="rules">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="cdnhub-mode">When to upload</label></th>
							<td>
								<?php
								$this->select(
									'upload_mode',
									array(
										'instant'    => 'Immediately — the CDN link is inserted into the post right away',
										'background' => 'In the background — fastest editor; links switch to the CDN once uploaded',
									),
									'cdnhub-mode'
								);
								?>
							</td></tr>
						<tr><th scope="row">Media uploader</th>
							<td>
								<?php $this->check( 'upload_to_cdn', 'Enable "Upload to GitHub" option by default', 'When uploading images in WordPress, the option will be checked by default. You can always toggle it individually per upload.' ); ?>
							</td></tr>
						<tr><th scope="row">File types</th>
							<td>
								<?php foreach ( CDNHub_Settings::all_types() as $key => $t ) : ?>
									<label class="cdnhub-inline"><input type="checkbox" name="<?php echo $this->field_name( 'types' ); // phpcs:ignore ?>[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s->get( 'types' ), true ) ); ?>> <?php echo esc_html( $t['label'] ); ?></label>
								<?php endforeach; ?>
								<p class="description">WordPress itself must allow a type before you can upload it (SVG needs a separate SVG-support plugin).</p>
							</td></tr>
						<tr><th scope="row"><label for="cdnhub-max">Maximum file size</label></th>
							<td><input type="number" id="cdnhub-max" min="1" max="90" step="1" class="small-text" name="<?php echo $this->field_name( 'max_size_mb' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'max_size_mb' ) ); ?>"> MB
								<p class="description">Larger images stay on your server.</p></td></tr>
						<tr><th scope="row">If GitHub is unavailable</th>
							<td>
								<p class="description" style="margin-top:0">The editor is never blocked: the image simply stays local and works normally.</p>
								<?php $this->check( 'auto_retry', 'Retry failed uploads automatically' ); ?>
								<p>Give up after <input type="number" min="1" max="20" class="small-text" name="<?php echo $this->field_name( 'max_attempts' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s->get( 'max_attempts' ) ); ?>"> attempts</p>
							</td></tr>
						<tr><th scope="row">Existing posts</th>
							<td>
								<?php $this->check( 'rewrite_content', 'Show CDN links for images already used in posts (when those images are on the CDN)' ); ?>
								<br><br>
								<?php $this->check( 'save_cdn_urls', 'Also save CDN links permanently when a post is saved', 'Off by default. Turn on only if you want the database to contain CDN URLs.' ); ?>
							</td></tr>
						<tr><th scope="row">Large originals</th>
							<td><?php $this->check( 'upload_original', 'Also upload the original, un-scaled version of very large photos', 'Uses more repository space. Normally not needed.' ); ?></td></tr>
						<tr><th scope="row">Cleanup</th>
							<td>
								<?php $this->check( 'delete_remote', 'When I delete an image from the Media Library, also delete it from GitHub' ); ?>
								<br><br>
								<?php $this->check( 'delete_local', 'Delete the local copy after a successful upload', '<strong>Not recommended.</strong> Off by default. Without local copies, image editing, thumbnail regeneration and backups rely on GitHub.' ); ?>
							</td></tr>
					</table>
				</div>

				<p class="submit cdnhub-savebar"><?php submit_button( 'Save changes', 'primary', 'submit', false ); ?></p>
			</form>

			<!-- Tools -->
			<div class="cdnhub-panel" data-panel="tools">
				<?php if ( $counts['local'] > 0 ) : ?>
					<div class="notice notice-warning inline cdnhub-excluded-box" style="margin:12px 0 20px;padding:12px 16px">
						<p style="margin:0 0 8px"><strong>Notice:</strong> You have <strong><?php echo (int) $counts['local']; ?></strong> image(s) currently marked as <strong>Excluded from CDN</strong> (served locally only). Standard sync and adopt tools skip these.</p>
						<p style="margin:0">
							<button type="button" class="button button-primary" id="cdnhub-reinclude-all">Send excluded images to CDN</button>
							<progress id="cdnhub-reinclude-bar" value="0" max="1" style="display:none"></progress>
							<span id="cdnhub-reinclude-out" class="cdnhub-out"></span>
						</p>
					</div>
				<?php endif; ?>
				<h2>Already uploaded your media to GitHub?</h2>
				<p>If you pushed your existing images to the repository yourself, CDNHub can match them by content hash and mark them as synced — <strong>without re-uploading anything</strong>. Files are recognized anywhere inside your media folder, in any folder layout; sizes that are missing remotely are uploaded to complete the set.</p>
				<p>
					<button type="button" class="button button-primary" id="cdnhub-adopt">Adopt existing remote files</button>
					<progress id="cdnhub-adopt-bar" value="0" max="1" style="display:none"></progress>
				</p>
				<p id="cdnhub-adopt-out" class="cdnhub-out"></p>
				<ul id="cdnhub-adopt-log"></ul>
				<h2>Upload existing images</h2>
				<p>Sends images that are already in your Media Library (and any failed uploads) to GitHub, one at a time. Keep this tab open until it finishes — you can stop and continue later.</p>
				<p>
					<button type="button" class="button button-primary" id="cdnhub-sync">Send existing images to CDN</button>
					<progress id="cdnhub-bar" value="0" max="1" style="display:none"></progress>
				</p>
				<p id="cdnhub-sync-out" class="cdnhub-out"></p>
				<ul id="cdnhub-sync-log"></ul>
				<h2>Free up server space</h2>
				<p>Deletes the local copies of images that are safely on the CDN — each file is verified against its stored content hash first, so anything edited or missing remotely is skipped. Works for images synced at any time, including adopted ones; no API calls are made.</p>
				<p>
					<button type="button" class="button" id="cdnhub-cleanup">Delete local copies of synced images</button>
					<progress id="cdnhub-cleanup-bar" value="0" max="1" style="display:none"></progress>
				</p>
				<p id="cdnhub-cleanup-out" class="cdnhub-out"></p>
				<h2>In the Media Library</h2>
				<p>Use the <strong>CDNHub</strong> column to see each image’s status. Hover an image in list view for “Send to CDN / Re-sync”, or select several and pick <em>Bulk actions → Send to CDN</em>. Click any image to see its CDN link.</p>
				<p>Want some images — a logo, for example — to always come from your own server? Use <strong>“Keep on this server”</strong> (row action, media-modal button or bulk action). CDNHub then never uploads them and always serves them locally; if the local copy was already deleted, it is downloaded back from GitHub first. “Serve from CDN” undoes it.</p>
			</div>

			<!-- Log -->
			<div class="cdnhub-panel" data-panel="log">
				<h2>Activity log <small>(last <?php echo (int) CDNHub_Logger::MAX; ?> events — credentials are never logged)</small></h2>
				<?php $log = $this->logger->all(); ?>
				<?php if ( ! $log ) : ?>
					<p>Nothing yet. Upload an image to see activity here.</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cdnhub_clear_log">
						<?php wp_nonce_field( 'cdnhub_clear_log' ); ?>
						<?php submit_button( 'Clear log', 'secondary small', 'submit', false ); ?>
					</form>
					<table class="widefat striped cdnhub-log">
						<thead><tr><th style="width:150px">Time</th><th style="width:70px">Level</th><th>Message</th><th style="width:90px">Image</th></tr></thead>
						<tbody>
						<?php foreach ( $log as $row ) : ?>
							<tr class="<?php echo 'error' === $row['l'] ? 'is-error' : ''; ?>">
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['t'] ) ); ?></td>
								<td><?php echo esc_html( 'error' === $row['l'] ? 'Error' : 'Info' ); ?></td>
								<td><?php echo esc_html( $row['m'] ); ?></td>
								<td><?php
									if ( ! empty( $row['id'] ) && get_post( $row['id'] ) ) {
										printf( '<a href="%s">#%d</a>', esc_url( get_edit_post_link( $row['id'] ) ), (int) $row['id'] );
									}
								?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
