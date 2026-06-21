<?php
/**
 * Settings storage, defaults and sanitization.
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_Settings
 */
class Elementor_GitHub_Sync_Settings {

	/**
	 * Main settings option key.
	 */
	const OPTION_KEY = 'egs_settings';

	/**
	 * Token is stored separately so it is never echoed back in the settings array.
	 */
	const TOKEN_OPTION_KEY = 'egs_github_token';

	/**
	 * Runtime status option (last sync state, etc.).
	 */
	const STATUS_OPTION_KEY = 'egs_status';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	protected $cache = null;

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'enable_auto_sync'       => 0,
			'connection_method'      => 'api', // 'api' or 'local'.
			'github_owner'           => '',
			'github_repo'            => '',
			'github_branch'          => 'main',
			'repo_path_prefix'       => 'elementor-kit',
			'wp_root'                => $this->detect_wp_root(),
			'wp_cli_path'            => 'wp',
			'debounce_delay'         => 30,
			'include_content'        => 1,
			'include_templates'      => 1,
			'include_site_settings'  => 1,
			'commit_message_tpl'     => 'Elementor sync: {post_id} - {datetime}',
			'enable_logging'         => 1,
			'log_retention'          => 100,
			// Local git mode.
			'local_repo_path'        => '',
			'git_path'               => 'git',
			'git_remote'             => 'origin',
		);
	}

	/**
	 * Auto-detect the WordPress root path.
	 *
	 * @return string
	 */
	public function detect_wp_root() {
		return defined( 'ABSPATH' ) ? untrailingslashit( ABSPATH ) : '';
	}

	/**
	 * Return all settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = wp_parse_args( $stored, $this->defaults() );
		return $this->cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persist sanitized settings.
	 *
	 * @param array $raw Raw input (typically $_POST).
	 * @return array Sanitized values that were saved.
	 */
	public function save( array $raw ) {
		$clean = $this->sanitize( $raw );
		update_option( self::OPTION_KEY, $clean, false );
		$this->cache = null;
		return $clean;
	}

	/**
	 * Sanitize a raw settings array.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	public function sanitize( array $raw ) {
		$defaults = $this->defaults();
		$current  = $this->all();
		$out      = array();

		$out['enable_auto_sync'] = empty( $raw['enable_auto_sync'] ) ? 0 : 1;

		$method                    = isset( $raw['connection_method'] ) ? sanitize_key( $raw['connection_method'] ) : 'api';
		$out['connection_method']  = in_array( $method, array( 'api', 'local' ), true ) ? $method : 'api';

		$out['github_owner'] = isset( $raw['github_owner'] ) ? sanitize_text_field( wp_unslash( $raw['github_owner'] ) ) : '';
		$out['github_repo']  = isset( $raw['github_repo'] ) ? sanitize_text_field( wp_unslash( $raw['github_repo'] ) ) : '';

		$branch                = isset( $raw['github_branch'] ) ? sanitize_text_field( wp_unslash( $raw['github_branch'] ) ) : 'main';
		$out['github_branch']  = '' !== $branch ? $branch : 'main';

		$out['repo_path_prefix'] = $this->sanitize_repo_path( isset( $raw['repo_path_prefix'] ) ? wp_unslash( $raw['repo_path_prefix'] ) : $defaults['repo_path_prefix'] );

		$out['wp_root']     = isset( $raw['wp_root'] ) ? $this->sanitize_path( wp_unslash( $raw['wp_root'] ) ) : $defaults['wp_root'];
		$out['wp_cli_path'] = isset( $raw['wp_cli_path'] ) ? sanitize_text_field( wp_unslash( $raw['wp_cli_path'] ) ) : 'wp';
		if ( '' === $out['wp_cli_path'] ) {
			$out['wp_cli_path'] = 'wp';
		}

		$debounce               = isset( $raw['debounce_delay'] ) ? (int) $raw['debounce_delay'] : 30;
		$out['debounce_delay']  = max( 0, min( 3600, $debounce ) );

		$out['include_content']       = empty( $raw['include_content'] ) ? 0 : 1;
		$out['include_templates']     = empty( $raw['include_templates'] ) ? 0 : 1;
		$out['include_site_settings'] = empty( $raw['include_site_settings'] ) ? 0 : 1;

		// Always have at least one include selected.
		if ( ! $out['include_content'] && ! $out['include_templates'] && ! $out['include_site_settings'] ) {
			$out['include_content'] = 1;
		}

		$tpl                        = isset( $raw['commit_message_tpl'] ) ? sanitize_text_field( wp_unslash( $raw['commit_message_tpl'] ) ) : $defaults['commit_message_tpl'];
		$out['commit_message_tpl']  = '' !== $tpl ? $tpl : $defaults['commit_message_tpl'];

		$out['enable_logging'] = empty( $raw['enable_logging'] ) ? 0 : 1;

		$retention            = isset( $raw['log_retention'] ) ? (int) $raw['log_retention'] : 100;
		$out['log_retention'] = max( 1, min( 10000, $retention ) );

		// Local git mode.
		$out['local_repo_path'] = isset( $raw['local_repo_path'] ) ? $this->sanitize_path( wp_unslash( $raw['local_repo_path'] ) ) : '';
		$git                    = isset( $raw['git_path'] ) ? sanitize_text_field( wp_unslash( $raw['git_path'] ) ) : 'git';
		$out['git_path']        = '' !== $git ? $git : 'git';
		$remote                 = isset( $raw['git_remote'] ) ? sanitize_text_field( wp_unslash( $raw['git_remote'] ) ) : 'origin';
		$out['git_remote']      = '' !== $remote ? $remote : 'origin';

		// Preserve any keys not represented in the form.
		return wp_parse_args( $out, $current );
	}

	/**
	 * Sanitize a filesystem path (no traversal cleanup beyond normalization).
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public function sanitize_path( $path ) {
		$path = trim( (string) $path );
		// Normalize backslashes to forward slashes for consistency.
		$path = str_replace( '\\', '/', $path );
		// Strip null bytes.
		$path = str_replace( "\0", '', $path );
		return untrailingslashit( $path );
	}

	/**
	 * Sanitize the repo path/prefix and prevent directory traversal.
	 *
	 * @param string $path Raw relative path inside the repo.
	 * @return string
	 */
	public function sanitize_repo_path( $path ) {
		$path = trim( (string) $path );
		$path = str_replace( '\\', '/', $path );
		$path = str_replace( "\0", '', $path );
		// Remove leading slashes.
		$path = ltrim( $path, '/' );
		// Split and rebuild, dropping any traversal or empty segments.
		$parts = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				continue;
			}
			// Allow safe path characters only.
			$segment = preg_replace( '/[^A-Za-z0-9._\- ]/', '', $segment );
			if ( '' !== $segment ) {
				$parts[] = $segment;
			}
		}
		$clean = implode( '/', $parts );
		return '' !== $clean ? $clean : 'elementor-kit';
	}

	/* ----------------------------------------------------------------------
	 * Token handling (kept out of the main settings array).
	 * -------------------------------------------------------------------- */

	/**
	 * Save the GitHub token.
	 *
	 * @param string $token Raw token.
	 * @return void
	 */
	public function save_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return;
		}
		update_option( self::TOKEN_OPTION_KEY, $token, false );
	}

	/**
	 * Retrieve the raw token (server-side use only, never echo).
	 *
	 * @return string
	 */
	public function get_token() {
		return (string) get_option( self::TOKEN_OPTION_KEY, '' );
	}

	/**
	 * Whether a token is stored.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->get_token();
	}

	/**
	 * Delete the stored token.
	 *
	 * @return void
	 */
	public function clear_token() {
		delete_option( self::TOKEN_OPTION_KEY );
	}

	/**
	 * Return a masked representation of the token for display.
	 *
	 * @return string
	 */
	public function masked_token() {
		$token = $this->get_token();
		if ( '' === $token ) {
			return '';
		}
		$len = strlen( $token );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $token, 0, 4 ) . str_repeat( '*', 12 ) . substr( $token, -4 );
	}

	/* ----------------------------------------------------------------------
	 * Runtime status.
	 * -------------------------------------------------------------------- */

	/**
	 * Get the runtime status array.
	 *
	 * @return array
	 */
	public function get_status() {
		$status = get_option(
			self::STATUS_OPTION_KEY,
			array(
				'last_status' => 'never',
				'last_time'   => '',
				'last_error'  => '',
			)
		);
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Update runtime status fields.
	 *
	 * @param array $data Partial status data.
	 * @return void
	 */
	public function set_status( array $data ) {
		$status = wp_parse_args( $data, $this->get_status() );
		update_option( self::STATUS_OPTION_KEY, $status, false );
	}

	/**
	 * Build the Elementor CLI include list from checkbox settings.
	 *
	 * @return array
	 */
	public function include_list() {
		$list = array();
		if ( $this->get( 'include_content' ) ) {
			$list[] = 'content';
		}
		if ( $this->get( 'include_templates' ) ) {
			$list[] = 'templates';
		}
		if ( $this->get( 'include_site_settings' ) ) {
			$list[] = 'site-settings';
		}
		if ( empty( $list ) ) {
			$list[] = 'content';
		}
		return $list;
	}

	/**
	 * Delete all plugin options (used on uninstall scenarios).
	 *
	 * @return void
	 */
	public function delete_all() {
		delete_option( self::OPTION_KEY );
		delete_option( self::TOKEN_OPTION_KEY );
		delete_option( self::STATUS_OPTION_KEY );
		delete_option( Elementor_GitHub_Sync_Logger::OPTION_KEY );
	}
}
