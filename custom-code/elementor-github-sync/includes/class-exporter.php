<?php
/**
 * Elementor kit exporter (WP-CLI + Elementor CLI driven).
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_Exporter
 */
class Elementor_GitHub_Sync_Exporter {

	const TEMP_SUBDIR = 'egs-temp';

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
	 * @param Elementor_GitHub_Sync_Settings $settings Settings.
	 * @param Elementor_GitHub_Sync_Logger   $logger   Logger.
	 */
	public function __construct( Elementor_GitHub_Sync_Settings $settings, Elementor_GitHub_Sync_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/* ----------------------------------------------------------------------
	 * Environment detection.
	 * -------------------------------------------------------------------- */

	/**
	 * Determine which command execution function is available.
	 *
	 * @return string|false 'proc_open', 'exec', 'shell_exec' or false.
	 */
	public function available_exec_function() {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		foreach ( array( 'proc_open', 'exec', 'shell_exec' ) as $fn ) {
			if ( function_exists( $fn ) && ! in_array( $fn, $disabled, true ) ) {
				return $fn;
			}
		}
		return false;
	}

	/**
	 * Whether any exec function is available.
	 *
	 * @return bool
	 */
	public function can_exec() {
		return false !== $this->available_exec_function();
	}

	/**
	 * Run a shell command safely and capture output + exit code.
	 *
	 * Arguments are escaped by the caller building the command. This method
	 * prefers proc_open, falling back to exec.
	 *
	 * @param string $command Fully built, escaped command string.
	 * @return array { 'output' => string, 'code' => int, 'ok' => bool }
	 */
	public function run( $command ) {
		$fn = $this->available_exec_function();

		if ( false === $fn ) {
			return array(
				'output' => 'No exec function available (proc_open/exec/shell_exec disabled).',
				'code'   => -1,
				'ok'     => false,
			);
		}

		// Redirect stderr to stdout so we capture errors.
		$full = $command . ' 2>&1';

		if ( 'proc_open' === $fn ) {
			return $this->run_proc_open( $full );
		}

		if ( 'exec' === $fn ) {
			$out  = array();
			$code = 0;
			exec( $full, $out, $code );
			return array(
				'output' => implode( "\n", $out ),
				'code'   => (int) $code,
				'ok'     => 0 === (int) $code,
			);
		}

		// shell_exec (no exit code available).
		$out = shell_exec( $full );
		return array(
			'output' => null === $out ? '' : (string) $out,
			'code'   => null === $out ? -1 : 0,
			'ok'     => null !== $out,
		);
	}

	/**
	 * Run a command using proc_open.
	 *
	 * @param string $command Full command.
	 * @return array
	 */
	protected function run_proc_open( $command ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$pipes   = array();
		$process = proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return array(
				'output' => 'Failed to start process.',
				'code'   => -1,
				'ok'     => false,
			);
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$code   = proc_close( $process );
		$output = trim( (string) $stdout . "\n" . (string) $stderr );

		return array(
			'output' => $output,
			'code'   => (int) $code,
			'ok'     => 0 === (int) $code,
		);
	}

	/**
	 * Build the base WP-CLI command with the configured binary and path.
	 *
	 * @param string $args Subcommand and arguments (already escaped).
	 * @return string
	 */
	protected function wp_cli_base( $args ) {
		$bin  = $this->settings->get( 'wp_cli_path', 'wp' );
		$root = $this->settings->get( 'wp_root' );

		$command = escapeshellarg_passthrough( $bin );
		if ( '' !== $root ) {
			$command .= ' --path=' . escapeshellarg( $root );
		}
		$command .= ' ' . $args;
		return $command;
	}

	/* ----------------------------------------------------------------------
	 * Tests.
	 * -------------------------------------------------------------------- */

	/**
	 * Test that WP-CLI is callable.
	 *
	 * @return array { ok, message, output }
	 */
	public function test_wp_cli() {
		if ( ! $this->can_exec() ) {
			return array(
				'ok'      => false,
				'message' => 'PHP cannot execute shell commands (exec/proc_open/shell_exec disabled).',
				'output'  => '',
			);
		}

		$cmd    = $this->wp_cli_base( 'cli version' );
		$result = $this->run( $cmd );

		return array(
			'ok'      => $result['ok'],
			'message' => $result['ok'] ? 'WP-CLI is available.' : 'WP-CLI is not available or returned an error.',
			'output'  => $result['output'],
		);
	}

	/**
	 * Test that the Elementor CLI command is registered.
	 *
	 * @return array { ok, message, output }
	 */
	public function test_elementor_cli() {
		if ( ! $this->can_exec() ) {
			return array(
				'ok'      => false,
				'message' => 'PHP cannot execute shell commands.',
				'output'  => '',
			);
		}

		// `wp help elementor` returns 0 when the command exists.
		$cmd    = $this->wp_cli_base( 'elementor --help' );
		$result = $this->run( $cmd );

		$ok = $result['ok'] && false === stripos( $result['output'], "'elementor' is not a registered" );

		return array(
			'ok'      => $ok,
			'message' => $ok ? 'Elementor CLI command is available.' : 'Elementor CLI command not found. Ensure Elementor is active and WP-CLI loads it.',
			'output'  => $result['output'],
		);
	}

	/* ----------------------------------------------------------------------
	 * Export.
	 * -------------------------------------------------------------------- */

	/**
	 * Create a unique temp working directory under uploads.
	 *
	 * @return string|WP_Error Absolute path or error.
	 */
	public function create_temp_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'egs_uploads', 'Could not resolve uploads directory: ' . $uploads['error'] );
		}

		$base = trailingslashit( $uploads['basedir'] ) . self::TEMP_SUBDIR;
		if ( ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'egs_mkdir', 'Could not create base temp directory.' );
		}

		// Protect the directory from public listing/access.
		$this->protect_dir( $base );

		$unique = trailingslashit( $base ) . 'run-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $unique ) ) {
			return new WP_Error( 'egs_mkdir', 'Could not create unique temp directory.' );
		}

		return untrailingslashit( $unique );
	}

	/**
	 * Drop protective files in a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	protected function protect_dir( $dir ) {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\n" );
		}
	}

	/**
	 * Run the full export: produce a directory of extracted kit files.
	 *
	 * @return array|WP_Error {
	 *     'temp_dir'    => string base temp dir (to be cleaned up later),
	 *     'extract_dir' => string directory containing extracted files,
	 *     'zip_path'    => string path to the exported zip,
	 * }
	 */
	public function export() {
		if ( ! $this->can_exec() ) {
			return new WP_Error( 'egs_noexec', 'Cannot export: PHP shell execution is disabled on this server.' );
		}

		$temp = $this->create_temp_dir();
		if ( is_wp_error( $temp ) ) {
			$this->logger->error( 'Temp dir failure: ' . $temp->get_error_message() );
			return $temp;
		}

		$zip_path = trailingslashit( $temp ) . 'elementor-kit.zip';
		$includes = implode( ',', $this->settings->include_list() );

		$args = 'elementor kit export ' . escapeshellarg( $zip_path ) . ' --include=' . escapeshellarg( $includes );
		$cmd  = $this->wp_cli_base( $args );

		$this->logger->info( 'Running Elementor export (includes: ' . $includes . ').' );
		$result = $this->run( $cmd );

		if ( ! $result['ok'] ) {
			$this->cleanup( $temp );
			$msg = 'Elementor export failed (exit ' . $result['code'] . '): ' . $this->truncate( $result['output'] );
			$this->logger->error( $msg );
			return new WP_Error( 'egs_export', $msg );
		}

		// Elementor may append a suffix or write to a slightly different name; locate the zip.
		$zip_path = $this->locate_zip( $temp, $zip_path );
		if ( is_wp_error( $zip_path ) ) {
			$this->cleanup( $temp );
			$this->logger->error( $zip_path->get_error_message() );
			return $zip_path;
		}

		$extract_dir = trailingslashit( $temp ) . 'extracted';
		if ( ! wp_mkdir_p( $extract_dir ) ) {
			$this->cleanup( $temp );
			return new WP_Error( 'egs_mkdir', 'Could not create extraction directory.' );
		}

		$unzip = $this->unzip( $zip_path, $extract_dir );
		if ( is_wp_error( $unzip ) ) {
			$this->cleanup( $temp );
			$this->logger->error( 'Unzip failed: ' . $unzip->get_error_message() );
			return $unzip;
		}

		$this->logger->success( 'Elementor kit exported and extracted successfully.' );

		return array(
			'temp_dir'    => $temp,
			'extract_dir' => untrailingslashit( $extract_dir ),
			'zip_path'    => $zip_path,
		);
	}

	/**
	 * Locate the exported zip file (handles name variations).
	 *
	 * @param string $temp     Temp dir.
	 * @param string $expected Expected zip path.
	 * @return string|WP_Error
	 */
	protected function locate_zip( $temp, $expected ) {
		if ( file_exists( $expected ) && filesize( $expected ) > 0 ) {
			return $expected;
		}

		$candidates = glob( trailingslashit( $temp ) . '*.zip' );
		if ( ! empty( $candidates ) ) {
			// Pick the largest / most recent.
			usort(
				$candidates,
				static function ( $a, $b ) {
					return filemtime( $b ) <=> filemtime( $a );
				}
			);
			if ( filesize( $candidates[0] ) > 0 ) {
				return $candidates[0];
			}
		}

		return new WP_Error( 'egs_zip_missing', 'Export completed but the kit zip file was not found.' );
	}

	/**
	 * Unzip an archive using WordPress filesystem helpers.
	 *
	 * @param string $zip_path Zip path.
	 * @param string $dest     Destination directory.
	 * @return true|WP_Error
	 */
	protected function unzip( $zip_path, $dest ) {
		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		$result = unzip_file( $zip_path, $dest );
		if ( is_wp_error( $result ) ) {
			// Fallback to ZipArchive if available.
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				if ( true === $zip->open( $zip_path ) ) {
					$zip->extractTo( $dest );
					$zip->close();
					return true;
				}
			}
			return $result;
		}
		return true;
	}

	/**
	 * Recursively list all files in a directory as relative paths.
	 *
	 * @param string $dir Base directory.
	 * @return array<string,string> Map of relativePath => absolutePath.
	 */
	public function list_files( $dir ) {
		$dir   = untrailingslashit( $dir );
		$files = array();

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs = $file->getPathname();
			$rel = ltrim( str_replace( '\\', '/', substr( $abs, strlen( $dir ) ) ), '/' );

			// Skip our protective files.
			if ( in_array( $rel, array( 'index.php', '.htaccess' ), true ) ) {
				continue;
			}
			$files[ $rel ] = $abs;
		}

		ksort( $files );
		return $files;
	}

	/**
	 * Recursively delete a temp directory (only inside our temp base).
	 *
	 * @param string $dir Directory to remove.
	 * @return bool
	 */
	public function cleanup( $dir ) {
		$dir = untrailingslashit( (string) $dir );
		if ( '' === $dir ) {
			return false;
		}

		// Safety: only allow deletion within our temp base.
		$uploads = wp_upload_dir();
		$base    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::TEMP_SUBDIR );
		$target  = wp_normalize_path( $dir );

		if ( strpos( $target, $base ) !== 0 ) {
			$this->logger->warning( 'Refused to clean up directory outside temp base.' );
			return false;
		}

		return $this->rrmdir( $dir );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	protected function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		return @rmdir( $dir );
	}

	/**
	 * Truncate long command output for logging.
	 *
	 * @param string $text Output.
	 * @param int    $max  Max length.
	 * @return string
	 */
	protected function truncate( $text, $max = 1000 ) {
		$text = trim( (string) $text );
		if ( strlen( $text ) > $max ) {
			return substr( $text, 0, $max ) . '... [truncated]';
		}
		return $text;
	}
}

/**
 * Wrapper around escapeshellarg that keeps a bare binary name unquoted when
 * it contains no spaces or shell metacharacters, while quoting full paths.
 *
 * @param string $bin Binary name or path.
 * @return string
 */
function escapeshellarg_passthrough( $bin ) {
	$bin = (string) $bin;
	if ( preg_match( '/^[A-Za-z0-9_\-]+$/', $bin ) ) {
		return $bin;
	}
	return escapeshellarg( $bin );
}
