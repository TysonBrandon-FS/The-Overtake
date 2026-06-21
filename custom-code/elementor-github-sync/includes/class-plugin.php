<?php
/**
 * Main plugin orchestrator: hooks, cron, debounce and the sync pipeline.
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_Plugin
 */
class Elementor_GitHub_Sync_Plugin {

	/**
	 * Cron hook fired to run a debounced sync.
	 */
	const CRON_HOOK = 'egs_run_sync_event';

	/**
	 * Transient used as a debounce lock so multiple saves schedule one job.
	 */
	const DEBOUNCE_LOCK = 'egs_sync_pending';

	/**
	 * Lock to prevent overlapping sync runs.
	 */
	const RUN_LOCK = 'egs_sync_running';

	/**
	 * Singleton instance.
	 *
	 * @var Elementor_GitHub_Sync_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Settings.
	 *
	 * @var Elementor_GitHub_Sync_Settings
	 */
	public $settings;

	/**
	 * Logger.
	 *
	 * @var Elementor_GitHub_Sync_Logger
	 */
	public $logger;

	/**
	 * Exporter.
	 *
	 * @var Elementor_GitHub_Sync_Exporter
	 */
	public $exporter;

	/**
	 * GitHub API.
	 *
	 * @var Elementor_GitHub_Sync_GitHub_API
	 */
	public $github;

	/**
	 * Local git.
	 *
	 * @var Elementor_GitHub_Sync_Local_Git
	 */
	public $local_git;

	/**
	 * Admin handler.
	 *
	 * @var Elementor_GitHub_Sync_Admin
	 */
	public $admin;

	/**
	 * Get singleton.
	 *
	 * @return Elementor_GitHub_Sync_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->settings  = new Elementor_GitHub_Sync_Settings();
		$this->logger    = new Elementor_GitHub_Sync_Logger( $this->settings );
		$this->exporter  = new Elementor_GitHub_Sync_Exporter( $this->settings, $this->logger );
		$this->github    = new Elementor_GitHub_Sync_GitHub_API( $this->settings, $this->logger );
		$this->local_git = new Elementor_GitHub_Sync_Local_Git( $this->settings, $this->logger, $this->exporter );

		$this->init_hooks();

		if ( is_admin() ) {
			$this->admin = new Elementor_GitHub_Sync_Admin( $this );
			$this->admin->init();
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	protected function init_hooks() {
		// Hook Elementor save.
		add_action( 'elementor/editor/after_save', array( $this, 'on_elementor_save' ), 10, 2 );

		// Cron callback.
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_sync' ), 10, 1 );
	}

	/* ----------------------------------------------------------------------
	 * Save hook + debounce scheduling.
	 * -------------------------------------------------------------------- */

	/**
	 * Handle the Elementor "after save" event.
	 *
	 * @param int   $post_id Edited post ID.
	 * @param array $data    Editor data (unused).
	 * @return void
	 */
	public function on_elementor_save( $post_id, $data = array() ) {
		unset( $data );

		if ( ! $this->settings->get( 'enable_auto_sync' ) ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$this->schedule_sync( $post_id );
	}

	/**
	 * Schedule a single debounced sync event.
	 *
	 * @param int $post_id Post ID to sync.
	 * @return void
	 */
	public function schedule_sync( $post_id ) {
		$delay = (int) $this->settings->get( 'debounce_delay', 30 );
		if ( $delay < 0 ) {
			$delay = 0;
		}

		// Debounce: if an event is already pending, reschedule it forward.
		$pending = get_transient( self::DEBOUNCE_LOCK );

		if ( $pending ) {
			// Clear the previously scheduled event so we don't double-run.
			$existing = wp_next_scheduled( self::CRON_HOOK, array( (int) $pending ) );
			if ( $existing ) {
				wp_unschedule_event( $existing, self::CRON_HOOK, array( (int) $pending ) );
			}
		}

		// Remember the latest post id and refresh the debounce window.
		set_transient( self::DEBOUNCE_LOCK, $post_id, max( 60, $delay + 60 ) );

		// Avoid scheduling duplicates for the same args.
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $post_id ) );
			$this->logger->info( 'Scheduled sync for post ' . $post_id . ' in ' . $delay . 's.' );
		}
	}

	/**
	 * Cron callback wrapper.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function run_scheduled_sync( $post_id = 0 ) {
		delete_transient( self::DEBOUNCE_LOCK );
		$this->run_sync( (int) $post_id, 'auto' );
	}

	/* ----------------------------------------------------------------------
	 * Sync pipeline (shared by cron + manual).
	 * -------------------------------------------------------------------- */

	/**
	 * Run the full sync pipeline.
	 *
	 * @param int    $post_id Post ID (0 for manual).
	 * @param string $trigger 'auto' or 'manual'.
	 * @return array|WP_Error Result stats or error.
	 */
	public function run_sync( $post_id = 0, $trigger = 'manual' ) {
		// Prevent overlapping runs.
		if ( get_transient( self::RUN_LOCK ) ) {
			$msg = 'A sync is already running; skipping this trigger.';
			$this->logger->warning( $msg );
			return new WP_Error( 'egs_locked', $msg );
		}
		set_transient( self::RUN_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$this->logger->info( ucfirst( $trigger ) . ' sync started' . ( $post_id ? ' for post ' . $post_id : '' ) . '.' );

		$result = $this->do_sync( $post_id );

		delete_transient( self::RUN_LOCK );

		if ( is_wp_error( $result ) ) {
			$this->settings->set_status(
				array(
					'last_status' => 'error',
					'last_time'   => current_time( 'mysql' ),
					'last_error'  => $result->get_error_message(),
				)
			);
			$this->logger->error( 'Sync failed: ' . $result->get_error_message() );
			return $result;
		}

		$summary = sprintf(
			'%d created, %d updated, %d skipped, %d failed.',
			isset( $result['created'] ) ? $result['created'] : 0,
			isset( $result['updated'] ) ? $result['updated'] : 0,
			isset( $result['skipped'] ) ? $result['skipped'] : 0,
			isset( $result['failed'] ) ? $result['failed'] : 0
		);

		$this->settings->set_status(
			array(
				'last_status' => 'success',
				'last_time'   => current_time( 'mysql' ),
				'last_error'  => '',
			)
		);
		$this->logger->success( 'Sync completed: ' . $summary );

		return $result;
	}

	/**
	 * Core export + push, without locking/status bookkeeping.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	protected function do_sync( $post_id ) {
		// Pre-flight checks.
		if ( ! $this->is_elementor_active() ) {
			return new WP_Error( 'egs_no_elementor', 'Elementor is not active.' );
		}
		if ( ! $this->exporter->can_exec() ) {
			return new WP_Error( 'egs_noexec', 'Server cannot execute shell commands (exec/proc_open/shell_exec disabled).' );
		}

		// 1. Export.
		$export = $this->exporter->export();
		if ( is_wp_error( $export ) ) {
			return $export;
		}

		$temp_dir    = $export['temp_dir'];
		$extract_dir = $export['extract_dir'];

		// 2. Collect files.
		$files = $this->exporter->list_files( $extract_dir );
		if ( empty( $files ) ) {
			$this->exporter->cleanup( $temp_dir );
			return new WP_Error( 'egs_nofiles', 'No files found in the exported kit.' );
		}

		// 3. Push using the selected method.
		$method = $this->settings->get( 'connection_method', 'api' );

		if ( 'local' === $method ) {
			$result = $this->local_git->push_files( $extract_dir, $files, $post_id );
		} else {
			$result = $this->github->push_files( $extract_dir, $files, $post_id );
		}

		// 4. Cleanup temp files regardless of result.
		$this->exporter->cleanup( $temp_dir );

		return $result;
	}

	/* ----------------------------------------------------------------------
	 * Status helpers.
	 * -------------------------------------------------------------------- */

	/**
	 * Whether Elementor is active.
	 *
	 * @return bool
	 */
	public function is_elementor_active() {
		return did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
	}

	/* ----------------------------------------------------------------------
	 * Activation / deactivation.
	 * -------------------------------------------------------------------- */

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function on_activation() {
		// Nothing persistent to schedule; single events are created on demand.
	}

	/**
	 * Deactivation hook: clear scheduled events.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::DEBOUNCE_LOCK );
		delete_transient( self::RUN_LOCK );
	}
}
