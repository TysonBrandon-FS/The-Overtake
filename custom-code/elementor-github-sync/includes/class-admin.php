<?php
/**
 * Admin UI, settings form handling and AJAX actions.
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_Admin
 */
class Elementor_GitHub_Sync_Admin {

	const MENU_SLUG  = 'elementor-github-sync';
	const CAPABILITY = 'manage_options';
	const NONCE_AJAX = 'egs_ajax_nonce';

	/**
	 * Plugin instance.
	 *
	 * @var Elementor_GitHub_Sync_Plugin
	 */
	protected $plugin;

	/**
	 * Settings.
	 *
	 * @var Elementor_GitHub_Sync_Settings
	 */
	protected $settings;

	/**
	 * Logger.
	 *
	 * @var Elementor_GitHub_Sync_Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param Elementor_GitHub_Sync_Plugin $plugin Plugin instance.
	 */
	public function __construct( Elementor_GitHub_Sync_Plugin $plugin ) {
		$this->plugin   = $plugin;
		$this->settings = $plugin->settings;
		$this->logger   = $plugin->logger;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submit' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . EGS_PLUGIN_BASENAME, array( $this, 'action_links' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_egs_test_wpcli', array( $this, 'ajax_test_wpcli' ) );
		add_action( 'wp_ajax_egs_test_elementor', array( $this, 'ajax_test_elementor' ) );
		add_action( 'wp_ajax_egs_test_github', array( $this, 'ajax_test_github' ) );
		add_action( 'wp_ajax_egs_test_localgit', array( $this, 'ajax_test_localgit' ) );
		add_action( 'wp_ajax_egs_manual_sync', array( $this, 'ajax_manual_sync' ) );
		add_action( 'wp_ajax_egs_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_egs_clear_logs', array( $this, 'ajax_clear_logs' ) );
	}

	/**
	 * Add the admin menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Elementor GitHub Sync', 'elementor-github-sync' ),
			__( 'Elementor GitHub Sync', 'elementor-github-sync' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-update',
			81
		);
	}

	/**
	 * Add a settings shortcut on the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'elementor-github-sync' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Enqueue CSS/JS on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'egs-admin', EGS_PLUGIN_URL . 'assets/admin.css', array(), EGS_VERSION );
		wp_enqueue_script( 'egs-admin', EGS_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), EGS_VERSION, true );

		wp_localize_script(
			'egs-admin',
			'EGS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'i18n'    => array(
					'running'    => __( 'Running...', 'elementor-github-sync' ),
					'confirmClr' => __( 'Clear all logs?', 'elementor-github-sync' ),
				),
			)
		);
	}

	/* ----------------------------------------------------------------------
	 * Form submission (settings + token actions).
	 * -------------------------------------------------------------------- */

	/**
	 * Handle non-AJAX POSTs (settings save, token clear).
	 *
	 * @return void
	 */
	public function handle_form_submit() {
		if ( empty( $_POST['egs_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['egs_action'] ) );

		if ( 'save_settings' === $action ) {
			check_admin_referer( 'egs_save_settings' );
			$this->process_save_settings();
		} elseif ( 'clear_token' === $action ) {
			check_admin_referer( 'egs_clear_token' );
			$this->settings->clear_token();
			$this->logger->info( 'GitHub token cleared by admin.' );
			$this->redirect_with_notice( 'token_cleared' );
		}
	}

	/**
	 * Save settings and (optionally) a new token.
	 *
	 * @return void
	 */
	protected function process_save_settings() {
		// Note: $_POST is sanitized inside Settings::sanitize().
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$this->settings->save( $raw );

		// Handle token field separately so it's never stored in the settings array.
		if ( isset( $raw['github_token'] ) ) {
			$token = trim( (string) $raw['github_token'] );
			// Ignore the masked placeholder so saving the form doesn't overwrite it.
			if ( '' !== $token && false === strpos( $token, '*' ) ) {
				$this->settings->save_token( $token );
				$this->logger->info( 'GitHub token updated by admin.' );
			}
		}

		$this->logger->info( 'Settings saved.' );
		$this->redirect_with_notice( 'saved' );
	}

	/**
	 * Redirect back to the page with a notice flag.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	protected function redirect_with_notice( $notice ) {
		$url = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'egs_notice'  => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/* ----------------------------------------------------------------------
	 * AJAX handlers.
	 * -------------------------------------------------------------------- */

	/**
	 * Shared AJAX guard.
	 *
	 * @return void
	 */
	protected function verify_ajax() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'elementor-github-sync' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
	}

	/**
	 * AJAX: test WP-CLI.
	 *
	 * @return void
	 */
	public function ajax_test_wpcli() {
		$this->verify_ajax();
		$result = $this->plugin->exporter->test_wp_cli();
		$this->send_test_result( $result );
	}

	/**
	 * AJAX: test Elementor CLI.
	 *
	 * @return void
	 */
	public function ajax_test_elementor() {
		$this->verify_ajax();
		$result = $this->plugin->exporter->test_elementor_cli();
		$this->send_test_result( $result );
	}

	/**
	 * AJAX: test GitHub API connection.
	 *
	 * @return void
	 */
	public function ajax_test_github() {
		$this->verify_ajax();
		$result = $this->plugin->github->test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * AJAX: test local git.
	 *
	 * @return void
	 */
	public function ajax_test_localgit() {
		$this->verify_ajax();
		$result = $this->plugin->local_git->test();
		$this->send_test_result( $result );
	}

	/**
	 * AJAX: run a manual sync now.
	 *
	 * @return void
	 */
	public function ajax_manual_sync() {
		$this->verify_ajax();

		// Increase limits where allowed; long-running export.
		@set_time_limit( 300 );

		$result = $this->plugin->run_sync( 0, 'manual' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$summary = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
			__( 'Sync complete: %1$d created, %2$d updated, %3$d skipped, %4$d failed.', 'elementor-github-sync' ),
			isset( $result['created'] ) ? $result['created'] : 0,
			isset( $result['updated'] ) ? $result['updated'] : 0,
			isset( $result['skipped'] ) ? $result['skipped'] : 0,
			isset( $result['failed'] ) ? $result['failed'] : 0
		);

		wp_send_json_success(
			array(
				'message' => $summary,
				'stats'   => $result,
			)
		);
	}

	/**
	 * AJAX: fetch recent logs as HTML rows.
	 *
	 * @return void
	 */
	public function ajax_get_logs() {
		$this->verify_ajax();
		$logs = $this->plugin->logger->get_recent_logs( 100 );
		wp_send_json_success( array( 'html' => $this->render_log_rows( $logs ) ) );
	}

	/**
	 * AJAX: clear logs.
	 *
	 * @return void
	 */
	public function ajax_clear_logs() {
		$this->verify_ajax();
		$this->plugin->logger->clear();
		wp_send_json_success( array( 'message' => __( 'Logs cleared.', 'elementor-github-sync' ) ) );
	}

	/**
	 * Normalize and send a test result via JSON.
	 *
	 * @param array $result { ok, message, output }.
	 * @return void
	 */
	protected function send_test_result( $result ) {
		$payload = array(
			'message' => isset( $result['message'] ) ? $result['message'] : '',
			'output'  => isset( $result['output'] ) ? $this->trim_output( $result['output'] ) : '',
		);
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $payload );
		}
		wp_send_json_error( $payload );
	}

	/**
	 * Trim long output for display.
	 *
	 * @param string $output Output.
	 * @return string
	 */
	protected function trim_output( $output ) {
		$output = (string) $output;
		if ( strlen( $output ) > 2000 ) {
			$output = substr( $output, 0, 2000 ) . '... [truncated]';
		}
		return $output;
	}

	/* ----------------------------------------------------------------------
	 * Page rendering.
	 * -------------------------------------------------------------------- */

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'elementor-github-sync' ) );
		}

		$s      = $this->settings->all();
		$status = $this->settings->get_status();

		$this->render_notices();
		?>
		<div class="wrap egs-wrap">
			<h1><?php esc_html_e( 'Elementor GitHub Sync', 'elementor-github-sync' ); ?></h1>
			<p class="egs-subtitle"><?php esc_html_e( 'Export Elementor content with Elementor CLI and push it to GitHub automatically.', 'elementor-github-sync' ); ?></p>

			<?php $this->render_status_cards( $status ); ?>

			<div class="egs-grid">
				<div class="egs-main">
					<form method="post" action="">
						<?php wp_nonce_field( 'egs_save_settings' ); ?>
						<input type="hidden" name="egs_action" value="save_settings" />

						<?php
						$this->render_general_section( $s );
						$this->render_github_section( $s );
						$this->render_local_git_section( $s );
						$this->render_export_section( $s );
						$this->render_advanced_section( $s );
						$this->render_logging_section( $s );
						?>

						<p class="submit">
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Settings', 'elementor-github-sync' ); ?></button>
						</p>
					</form>
				</div>

				<div class="egs-side">
					<?php $this->render_actions_box(); ?>
					<?php $this->render_help_box(); ?>
				</div>
			</div>

			<?php $this->render_logs_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render admin notices based on the query flag.
	 *
	 * @return void
	 */
	protected function render_notices() {
		if ( empty( $_GET['egs_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$notice  = sanitize_key( wp_unslash( $_GET['egs_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$map     = array(
			'saved'         => array( 'success', __( 'Settings saved.', 'elementor-github-sync' ) ),
			'token_cleared' => array( 'success', __( 'GitHub token cleared.', 'elementor-github-sync' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		list( $type, $text ) = $map[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * Render the system status cards (Step 1).
	 *
	 * @param array $status Runtime status.
	 * @return void
	 */
	protected function render_status_cards( $status ) {
		$can_exec    = $this->plugin->exporter->can_exec();
		$exec_fn     = $this->plugin->exporter->available_exec_function();
		$elementor   = $this->plugin->is_elementor_active();
		$has_token   = $this->settings->has_token();
		$method      = $this->settings->get( 'connection_method', 'api' );

		$cards = array(
			array(
				'label' => __( 'WP-CLI', 'elementor-github-sync' ),
				'state' => $can_exec ? 'unknown' : 'bad',
				'value' => $can_exec ? __( 'Click "Test WP-CLI"', 'elementor-github-sync' ) : __( 'Shell exec disabled', 'elementor-github-sync' ),
				'id'    => 'egs-card-wpcli',
			),
			array(
				'label' => __( 'Elementor Plugin', 'elementor-github-sync' ),
				'state' => $elementor ? 'good' : 'bad',
				'value' => $elementor ? __( 'Active', 'elementor-github-sync' ) : __( 'Inactive', 'elementor-github-sync' ),
				'id'    => 'egs-card-elementor',
			),
			array(
				'label' => __( 'Elementor CLI', 'elementor-github-sync' ),
				'state' => 'unknown',
				'value' => __( 'Click "Test Elementor CLI"', 'elementor-github-sync' ),
				'id'    => 'egs-card-elcli',
			),
			array(
				'label' => __( 'PHP Shell Exec', 'elementor-github-sync' ),
				'state' => $can_exec ? 'good' : 'bad',
				'value' => $can_exec ? sprintf( /* translators: %s function name */ __( 'Enabled (%s)', 'elementor-github-sync' ), $exec_fn ) : __( 'Disabled', 'elementor-github-sync' ),
				'id'    => 'egs-card-exec',
			),
			array(
				'label' => 'local' === $method ? __( 'Local Git', 'elementor-github-sync' ) : __( 'GitHub Connection', 'elementor-github-sync' ),
				'state' => ( 'local' === $method ) ? 'unknown' : ( $has_token ? 'unknown' : 'bad' ),
				'value' => ( 'local' === $method ) ? __( 'Click "Test Local Git"', 'elementor-github-sync' ) : ( $has_token ? __( 'Click "Test GitHub"', 'elementor-github-sync' ) : __( 'No token set', 'elementor-github-sync' ) ),
				'id'    => 'egs-card-github',
			),
		);

		echo '<div class="egs-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="egs-card egs-state-%1$s" id="%2$s"><span class="egs-card-label">%3$s</span><span class="egs-card-value">%4$s</span></div>',
				esc_attr( $card['state'] ),
				esc_attr( $card['id'] ),
				esc_html( $card['label'] ),
				esc_html( $card['value'] )
			);
		}
		echo '</div>';

		// Last sync info row.
		$last_status = isset( $status['last_status'] ) ? $status['last_status'] : 'never';
		$last_time   = isset( $status['last_time'] ) && '' !== $status['last_time'] ? $status['last_time'] : __( 'Never', 'elementor-github-sync' );
		$last_error  = isset( $status['last_error'] ) ? $status['last_error'] : '';

		echo '<div class="egs-laststatus">';
		printf( '<span><strong>%s</strong> %s</span>', esc_html__( 'Last sync status:', 'elementor-github-sync' ), esc_html( ucfirst( $last_status ) ) );
		printf( '<span><strong>%s</strong> %s</span>', esc_html__( 'Last sync time:', 'elementor-github-sync' ), esc_html( $last_time ) );
		if ( '' !== $last_error ) {
			printf( '<span class="egs-error-text"><strong>%s</strong> %s</span>', esc_html__( 'Last error:', 'elementor-github-sync' ), esc_html( $last_error ) );
		}
		echo '</div>';
	}

	/**
	 * General section (Step 5: enable auto sync).
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	protected function render_general_section( $s ) {
		?>
		<div class="egs-box">
			<h2><?php esc_html_e( 'General', 'elementor-github-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable auto sync', 'elementor-github-sync' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_auto_sync" value="1" <?php checked( $s['enable_auto_sync'], 1 ); ?> />
							<?php esc_html_e( 'Automatically sync to GitHub when an Elementor page/template is saved.', 'elementor-github-sync' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection method', 'elementor-github-sync' ); ?></th>
					<td>
						<label class="egs-radio">
							<input type="radio" name="connection_method" value="api" <?php checked( $s['connection_method'], 'api' ); ?> />
							<?php esc_html_e( 'GitHub API token mode (recommended, no server git required)', 'elementor-github-sync' ); ?>
						</label>
						<label class="egs-radio">
							<input type="radio" name="connection_method" value="local" <?php checked( $s['connection_method'], 'local' ); ?> />
							<?php esc_html_e( 'Local Git / SSH mode (advanced)', 'elementor-github-sync' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * GitHub API section (Step 2).
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	protected function render_github_section( $s ) {
		$masked = $this->settings->masked_token();
		?>
		<div class="egs-box egs-method egs-method-api">
			<h2><?php esc_html_e( 'GitHub Repository', 'elementor-github-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="github_owner"><?php esc_html_e( 'Owner / Org', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="github_owner" name="github_owner" class="regular-text" value="<?php echo esc_attr( $s['github_owner'] ); ?>" placeholder="my-org" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="github_repo"><?php esc_html_e( 'Repository name', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="github_repo" name="github_repo" class="regular-text" value="<?php echo esc_attr( $s['github_repo'] ); ?>" placeholder="my-repo" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="github_branch"><?php esc_html_e( 'Branch', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="github_branch" name="github_branch" class="regular-text" value="<?php echo esc_attr( $s['github_branch'] ); ?>" placeholder="main" /></td>
				</tr>
				<tr class="egs-api-only">
					<th scope="row"><label for="github_token"><?php esc_html_e( 'Personal Access Token', 'elementor-github-sync' ); ?></label></th>
					<td>
						<input type="password" id="github_token" name="github_token" class="regular-text" autocomplete="new-password"
							value="<?php echo esc_attr( $masked ); ?>"
							placeholder="<?php echo esc_attr( $masked ? $masked : 'ghp_xxx / github_pat_xxx' ); ?>" />
						<?php if ( $this->settings->has_token() ) : ?>
							<span class="egs-token-status egs-state-good"><?php esc_html_e( 'Token stored', 'elementor-github-sync' ); ?></span>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Stored securely in the database. The field shows a masked value; leave it unchanged to keep the existing token.', 'elementor-github-sync' ); ?></p>
						<?php if ( $this->settings->has_token() ) : ?>
							<button type="submit" form="egs-clear-token-form" class="button button-secondary"><?php esc_html_e( 'Clear token', 'elementor-github-sync' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( $this->settings->has_token() ) : ?>
			<form id="egs-clear-token-form" method="post" action="">
				<?php wp_nonce_field( 'egs_clear_token' ); ?>
				<input type="hidden" name="egs_action" value="clear_token" />
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Local git section.
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	protected function render_local_git_section( $s ) {
		?>
		<div class="egs-box egs-method egs-method-local">
			<h2><?php esc_html_e( 'Local Git / SSH', 'elementor-github-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="local_repo_path"><?php esc_html_e( 'Local repo path', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="local_repo_path" name="local_repo_path" class="large-text code" value="<?php echo esc_attr( $s['local_repo_path'] ); ?>" placeholder="/var/www/my-repo" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="git_path"><?php esc_html_e( 'Git binary path', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="git_path" name="git_path" class="regular-text code" value="<?php echo esc_attr( $s['git_path'] ); ?>" placeholder="git" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="git_remote"><?php esc_html_e( 'Remote name', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="git_remote" name="git_remote" class="regular-text code" value="<?php echo esc_attr( $s['git_remote'] ); ?>" placeholder="origin" /></td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Branch is shared with the GitHub section above. The web server user must have git access (and SSH keys) to the remote.', 'elementor-github-sync' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Export options section (Step 3).
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	protected function render_export_section( $s ) {
		?>
		<div class="egs-box">
			<h2><?php esc_html_e( 'Export Options', 'elementor-github-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Export includes', 'elementor-github-sync' ); ?></th>
					<td>
						<label><input type="checkbox" name="include_content" value="1" <?php checked( $s['include_content'], 1 ); ?> /> <?php esc_html_e( 'content', 'elementor-github-sync' ); ?></label><br />
						<label><input type="checkbox" name="include_templates" value="1" <?php checked( $s['include_templates'], 1 ); ?> /> <?php esc_html_e( 'templates', 'elementor-github-sync' ); ?></label><br />
						<label><input type="checkbox" name="include_site_settings" value="1" <?php checked( $s['include_site_settings'], 1 ); ?> /> <?php esc_html_e( 'site-settings', 'elementor-github-sync' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="repo_path_prefix"><?php esc_html_e( 'Repository path / prefix', 'elementor-github-sync' ); ?></label></th>
					<td>
						<input type="text" id="repo_path_prefix" name="repo_path_prefix" class="regular-text code" value="<?php echo esc_attr( $s['repo_path_prefix'] ); ?>" placeholder="elementor-kit" />
						<p class="description"><?php esc_html_e( 'Folder inside the repository where the exported kit is stored.', 'elementor-github-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="commit_message_tpl"><?php esc_html_e( 'Commit message template', 'elementor-github-sync' ); ?></label></th>
					<td>
						<input type="text" id="commit_message_tpl" name="commit_message_tpl" class="large-text code" value="<?php echo esc_attr( $s['commit_message_tpl'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Placeholders: {post_id}, {datetime}, {date}, {time}, {site}.', 'elementor-github-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Advanced section (paths, debounce).
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	protected function render_advanced_section( $s ) {
		?>
		<div class="egs-box">
			<h2><?php esc_html_e( 'Advanced', 'elementor-github-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wp_root"><?php esc_html_e( 'WP root path', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="wp_root" name="wp_root" class="large-text code" value="<?php echo esc_attr( $s['wp_root'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wp_cli_path"><?php esc_html_e( 'WP-CLI binary path', 'elementor-github-sync' ); ?></label></th>
					<td><input type="text" id="wp_cli_path" name="wp_cli_path" class="regular-text code" value="<?php echo esc_attr( $s['wp_cli_path'] ); ?>" placeholder="wp" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="debounce_delay"><?php esc_html_e( 'Debounce delay (seconds)', 'elementor-github-sync' ); ?></label></th>
					<td>
						<input type="number" id="debounce_delay" name="debounce_delay" min="0" max="3600" class="small-text" value="<?php echo esc_attr( $s['debounce_delay'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Multiple rapid saves within this window trigger only one sync.', 'elementor-github-sync' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Logging section.
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	protected function render_logging_section( $s ) {
		?>
		<div class="egs-box">
			<h2><?php esc_html_e( 'Logging', 'elementor-github-sync' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable logging', 'elementor-github-sync' ); ?></th>
					<td>
						<label><input type="checkbox" name="enable_logging" value="1" <?php checked( $s['enable_logging'], 1 ); ?> /> <?php esc_html_e( 'Record sync activity to an internal log.', 'elementor-github-sync' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="log_retention"><?php esc_html_e( 'Log retention count', 'elementor-github-sync' ); ?></label></th>
					<td><input type="number" id="log_retention" name="log_retention" min="1" max="10000" class="small-text" value="<?php echo esc_attr( $s['log_retention'] ); ?>" /></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Actions box (Step 4: tests + manual sync).
	 *
	 * @return void
	 */
	protected function render_actions_box() {
		?>
		<div class="egs-box egs-actions">
			<h2><?php esc_html_e( 'Tests & Actions', 'elementor-github-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Save your settings first, then run the checks below.', 'elementor-github-sync' ); ?></p>
			<div class="egs-buttons">
				<button type="button" class="button" data-egs-action="egs_test_wpcli" data-target="egs-card-wpcli"><?php esc_html_e( 'Test WP-CLI', 'elementor-github-sync' ); ?></button>
				<button type="button" class="button" data-egs-action="egs_test_elementor" data-target="egs-card-elcli"><?php esc_html_e( 'Test Elementor CLI', 'elementor-github-sync' ); ?></button>
				<button type="button" class="button" data-egs-action="egs_test_github" data-target="egs-card-github"><?php esc_html_e( 'Test GitHub connection', 'elementor-github-sync' ); ?></button>
				<button type="button" class="button" data-egs-action="egs_test_localgit" data-target="egs-card-github"><?php esc_html_e( 'Test Local Git', 'elementor-github-sync' ); ?></button>
				<button type="button" class="button button-primary" data-egs-action="egs_manual_sync"><?php esc_html_e( 'Run manual sync now', 'elementor-github-sync' ); ?></button>
			</div>
			<div class="egs-result" id="egs-result" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Help box with GitHub token instructions (Step 11).
	 *
	 * @return void
	 */
	protected function render_help_box() {
		?>
		<div class="egs-box egs-help">
			<h2><?php esc_html_e( 'GitHub Token Setup', 'elementor-github-sync' ); ?></h2>
			<p><?php esc_html_e( 'This plugin only needs access to your repository\'s contents.', 'elementor-github-sync' ); ?></p>
			<p><strong><?php esc_html_e( 'Fine-grained token (recommended):', 'elementor-github-sync' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'GitHub → Settings → Developer settings → Fine-grained tokens.', 'elementor-github-sync' ); ?></li>
				<li><?php esc_html_e( 'Select the target repository only.', 'elementor-github-sync' ); ?></li>
				<li><?php esc_html_e( 'Repository permissions → Contents: Read and write.', 'elementor-github-sync' ); ?></li>
				<li><?php esc_html_e( 'Repository permissions → Metadata: Read (auto-selected).', 'elementor-github-sync' ); ?></li>
				<li><?php esc_html_e( 'Generate the token and paste it above.', 'elementor-github-sync' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'Classic tokens also work if granted the "repo" scope, but fine-grained tokens are safer.', 'elementor-github-sync' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Logs section.
	 *
	 * @return void
	 */
	protected function render_logs_section() {
		$logs = $this->plugin->logger->get_recent_logs( 100 );
		?>
		<div class="egs-box egs-logs-box">
			<div class="egs-logs-head">
				<h2><?php esc_html_e( 'Recent Logs', 'elementor-github-sync' ); ?></h2>
				<div>
					<button type="button" class="button" id="egs-refresh-logs"><?php esc_html_e( 'View latest logs', 'elementor-github-sync' ); ?></button>
					<button type="button" class="button button-link-delete" id="egs-clear-logs"><?php esc_html_e( 'Clear logs', 'elementor-github-sync' ); ?></button>
				</div>
			</div>
			<table class="widefat striped egs-logs-table">
				<thead>
					<tr>
						<th class="egs-col-time"><?php esc_html_e( 'Time', 'elementor-github-sync' ); ?></th>
						<th class="egs-col-level"><?php esc_html_e( 'Level', 'elementor-github-sync' ); ?></th>
						<th><?php esc_html_e( 'Message', 'elementor-github-sync' ); ?></th>
					</tr>
				</thead>
				<tbody id="egs-logs-body">
					<?php echo $this->render_log_rows( $logs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render log rows HTML (escaped).
	 *
	 * @param array $logs Logs.
	 * @return string
	 */
	protected function render_log_rows( $logs ) {
		if ( empty( $logs ) ) {
			return '<tr><td colspan="3">' . esc_html__( 'No log entries yet.', 'elementor-github-sync' ) . '</td></tr>';
		}

		$html = '';
		foreach ( $logs as $entry ) {
			$level = isset( $entry['level'] ) ? $entry['level'] : 'info';
			$html .= sprintf(
				'<tr><td class="egs-col-time">%1$s</td><td><span class="egs-level egs-level-%2$s">%3$s</span></td><td>%4$s</td></tr>',
				esc_html( isset( $entry['datetime'] ) ? $entry['datetime'] : '' ),
				esc_attr( $level ),
				esc_html( ucfirst( $level ) ),
				esc_html( isset( $entry['message'] ) ? $entry['message'] : '' )
			);
		}
		return $html;
	}
}
