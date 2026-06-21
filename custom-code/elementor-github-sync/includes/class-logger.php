<?php
/**
 * Simple option-backed logger.
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_Logger
 *
 * Stores a rolling list of log entries in a single wp_option.
 */
class Elementor_GitHub_Sync_Logger {

	const OPTION_KEY = 'egs_logs';

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';
	const LEVEL_SUCCESS = 'success';

	/**
	 * Settings handler.
	 *
	 * @var Elementor_GitHub_Sync_Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Elementor_GitHub_Sync_Settings $settings Settings handler.
	 */
	public function __construct( Elementor_GitHub_Sync_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $message Log message (secrets must be pre-redacted by caller).
	 * @param string $level   One of the LEVEL_* constants.
	 * @return void
	 */
	public function log( $message, $level = self::LEVEL_INFO ) {
		if ( ! $this->settings->get( 'enable_logging' ) ) {
			return;
		}

		$allowed = array( self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_SUCCESS );
		if ( ! in_array( $level, $allowed, true ) ) {
			$level = self::LEVEL_INFO;
		}

		$logs = $this->get_logs();

		$logs[] = array(
			'datetime' => current_time( 'mysql' ),
			'level'    => $level,
			'message'  => $this->redact( (string) $message ),
		);

		$retention = (int) $this->settings->get( 'log_retention' );
		if ( $retention < 1 ) {
			$retention = 100;
		}

		if ( count( $logs ) > $retention ) {
			$logs = array_slice( $logs, -$retention );
		}

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * Convenience helpers.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function info( $message ) {
		$this->log( $message, self::LEVEL_INFO );
	}

	/**
	 * Warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( $message ) {
		$this->log( $message, self::LEVEL_WARNING );
	}

	/**
	 * Error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( $message ) {
		$this->log( $message, self::LEVEL_ERROR );
	}

	/**
	 * Success.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function success( $message ) {
		$this->log( $message, self::LEVEL_SUCCESS );
	}

	/**
	 * Return all stored logs (oldest first).
	 *
	 * @return array
	 */
	public function get_logs() {
		$logs = get_option( self::OPTION_KEY, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Return logs newest first, optionally limited.
	 *
	 * @param int $limit Max entries (0 = all).
	 * @return array
	 */
	public function get_recent_logs( $limit = 0 ) {
		$logs = array_reverse( $this->get_logs() );
		if ( $limit > 0 ) {
			$logs = array_slice( $logs, 0, $limit );
		}
		return $logs;
	}

	/**
	 * Clear all stored logs.
	 *
	 * @return void
	 */
	public function clear() {
		update_option( self::OPTION_KEY, array(), false );
	}

	/**
	 * Best-effort redaction of secrets that may slip into log messages.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	protected function redact( $message ) {
		$token = (string) $this->settings->get_token();
		if ( '' !== $token ) {
			$message = str_replace( $token, '***REDACTED***', $message );
		}

		// Generic GitHub token patterns (ghp_, github_pat_, gho_, ghs_, ghu_, ghr_).
		$message = preg_replace( '/\b(gh[pousr]_[A-Za-z0-9]{20,255})\b/', '***REDACTED***', $message );
		$message = preg_replace( '/\bgithub_pat_[A-Za-z0-9_]{20,255}\b/', '***REDACTED***', $message );

		return $message;
	}
}
