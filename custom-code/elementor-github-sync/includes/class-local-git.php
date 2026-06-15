<?php
/**
 * Local Git/SSH push implementation for advanced users.
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_Local_Git
 */
class Elementor_GitHub_Sync_Local_Git {

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
	 * Exporter (re-used for its command runner).
	 *
	 * @var Elementor_GitHub_Sync_Exporter
	 */
	protected $exporter;

	/**
	 * Constructor.
	 *
	 * @param Elementor_GitHub_Sync_Settings $settings Settings.
	 * @param Elementor_GitHub_Sync_Logger   $logger   Logger.
	 * @param Elementor_GitHub_Sync_Exporter $exporter Exporter.
	 */
	public function __construct( Elementor_GitHub_Sync_Settings $settings, Elementor_GitHub_Sync_Logger $logger, Elementor_GitHub_Sync_Exporter $exporter ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->exporter = $exporter;
	}

	/**
	 * Validate the local repo path.
	 *
	 * @return true|WP_Error
	 */
	public function validate_repo() {
		$repo = $this->settings->get( 'local_repo_path' );
		if ( '' === $repo ) {
			return new WP_Error( 'egs_norepo', 'Local repository path is not configured.' );
		}
		if ( ! is_dir( $repo ) ) {
			return new WP_Error( 'egs_norepo', 'Local repository path does not exist: ' . $repo );
		}
		if ( ! is_dir( trailingslashit( $repo ) . '.git' ) ) {
			return new WP_Error( 'egs_nogit', 'The configured path is not a git repository (no .git directory).' );
		}
		return true;
	}

	/**
	 * Run a git command in the repo directory.
	 *
	 * @param string $args Git subcommand + args (already escaped).
	 * @return array { ok, code, output }
	 */
	protected function git( $args ) {
		$git_bin = $this->settings->get( 'git_path', 'git' );
		$repo    = $this->settings->get( 'local_repo_path' );

		$bin = escapeshellarg_passthrough( $git_bin );
		$cmd = $bin . ' -C ' . escapeshellarg( $repo ) . ' ' . $args;

		return $this->exporter->run( $cmd );
	}

	/**
	 * Copy extracted files into the local repo at the prefix path.
	 *
	 * @param array $files Map of relativePath => absolutePath.
	 * @return true|WP_Error
	 */
	protected function copy_into_repo( array $files ) {
		$repo   = $this->settings->get( 'local_repo_path' );
		$prefix = $this->settings->get( 'repo_path_prefix', 'elementor-kit' );
		$dest   = untrailingslashit( trailingslashit( $repo ) . $prefix );

		if ( ! wp_mkdir_p( $dest ) ) {
			return new WP_Error( 'egs_mkdir', 'Could not create destination path inside repo: ' . $dest );
		}

		foreach ( $files as $rel => $abs ) {
			$target = $dest . '/' . ltrim( str_replace( '\\', '/', $rel ), '/' );
			$dir    = dirname( $target );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'egs_mkdir', 'Could not create directory: ' . $dir );
			}
			if ( ! @copy( $abs, $target ) ) {
				return new WP_Error( 'egs_copy', 'Could not copy file to repo: ' . $rel );
			}
		}

		return true;
	}

	/**
	 * Perform export -> copy -> commit -> push in local git mode.
	 *
	 * @param string $extract_dir Directory with extracted kit files.
	 * @param array  $files       Map of relativePath => absolutePath.
	 * @param int    $post_id     Post ID for commit message.
	 * @return array|WP_Error Stats array or error.
	 */
	public function push_files( $extract_dir, array $files, $post_id = 0 ) {
		if ( ! $this->exporter->can_exec() ) {
			return new WP_Error( 'egs_noexec', 'Cannot run git: PHP shell execution is disabled.' );
		}

		$valid = $this->validate_repo();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$branch = $this->settings->get( 'github_branch', 'main' );
		$remote = $this->settings->get( 'git_remote', 'origin' );

		// Pull latest to reduce conflicts (fast-forward only).
		$pull = $this->git( 'pull --ff-only ' . escapeshellarg( $remote ) . ' ' . escapeshellarg( $branch ) );
		if ( ! $pull['ok'] ) {
			// Not always fatal (e.g. no upstream yet), but log a warning.
			$this->logger->warning( 'git pull --ff-only reported: ' . $this->safe_output( $pull['output'] ) );
		}

		// Copy files in.
		$copy = $this->copy_into_repo( $files );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		// Stage changes.
		$add = $this->git( 'add -A' );
		if ( ! $add['ok'] ) {
			return new WP_Error( 'egs_gitadd', 'git add failed: ' . $this->safe_output( $add['output'] ) );
		}

		// Check if there is anything to commit.
		$status = $this->git( 'status --porcelain' );
		if ( '' === trim( $status['output'] ) ) {
			$this->logger->info( 'Local git: no changes to commit.' );
			return array(
				'created'    => 0,
				'updated'    => 0,
				'skipped'    => count( $files ),
				'failed'     => 0,
				'total'      => count( $files ),
				'no_changes' => true,
			);
		}

		// Commit.
		$message = $this->build_commit_message( $post_id );
		$commit  = $this->git( 'commit -m ' . escapeshellarg( $message ) );
		if ( ! $commit['ok'] ) {
			return new WP_Error( 'egs_gitcommit', 'git commit failed: ' . $this->safe_output( $commit['output'] ) );
		}

		// Push.
		$push = $this->git( 'push ' . escapeshellarg( $remote ) . ' ' . escapeshellarg( $branch ) );
		if ( ! $push['ok'] ) {
			$out = $this->safe_output( $push['output'] );
			if ( false !== stripos( $out, 'conflict' ) || false !== stripos( $out, 'rejected' ) || false !== stripos( $out, 'non-fast-forward' ) ) {
				return new WP_Error( 'egs_gitconflict', 'git push rejected (possible conflict / non-fast-forward). Resolve manually. Details: ' . $out );
			}
			return new WP_Error( 'egs_gitpush', 'git push failed: ' . $out );
		}

		$this->logger->success( 'Local git push complete to ' . $remote . '/' . $branch . '.' );

		return array(
			'created'    => count( $files ),
			'updated'    => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'total'      => count( $files ),
			'no_changes' => false,
		);
	}

	/**
	 * Build commit message via template.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function build_commit_message( $post_id ) {
		$tpl = $this->settings->get( 'commit_message_tpl', 'Elementor sync: {post_id} - {datetime}' );

		$replacements = array(
			'{post_id}'  => $post_id ? (string) $post_id : 'manual',
			'{datetime}' => current_time( 'mysql' ),
			'{date}'     => current_time( 'Y-m-d' ),
			'{time}'     => current_time( 'H:i:s' ),
			'{site}'     => wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		return strtr( $tpl, $replacements );
	}

	/**
	 * Test local git availability and repo validity.
	 *
	 * @return array { ok, message, output }
	 */
	public function test() {
		if ( ! $this->exporter->can_exec() ) {
			return array(
				'ok'      => false,
				'message' => 'PHP shell execution is disabled.',
				'output'  => '',
			);
		}

		$version = $this->git_version();
		if ( ! $version['ok'] ) {
			return array(
				'ok'      => false,
				'message' => 'Git binary not found or not executable.',
				'output'  => $this->safe_output( $version['output'] ),
			);
		}

		$valid = $this->validate_repo();
		if ( is_wp_error( $valid ) ) {
			return array(
				'ok'      => false,
				'message' => $valid->get_error_message(),
				'output'  => $version['output'],
			);
		}

		return array(
			'ok'      => true,
			'message' => 'Git is available and the repository is valid.',
			'output'  => $this->safe_output( $version['output'] ),
		);
	}

	/**
	 * Get the git version.
	 *
	 * @return array
	 */
	protected function git_version() {
		$git_bin = $this->settings->get( 'git_path', 'git' );
		$bin     = escapeshellarg_passthrough( $git_bin );
		return $this->exporter->run( $bin . ' --version' );
	}

	/**
	 * Strip any secrets from command output before logging/displaying.
	 *
	 * @param string $output Raw output.
	 * @return string
	 */
	protected function safe_output( $output ) {
		$output = (string) $output;
		// Redact anything that looks like credentials in a remote URL.
		$output = preg_replace( '#https?://[^/@\s]+:[^/@\s]+@#', 'https://***:***@', $output );
		$token  = $this->settings->get_token();
		if ( '' !== $token ) {
			$output = str_replace( $token, '***', $output );
		}
		if ( strlen( $output ) > 1500 ) {
			$output = substr( $output, 0, 1500 ) . '... [truncated]';
		}
		return trim( $output );
	}
}
