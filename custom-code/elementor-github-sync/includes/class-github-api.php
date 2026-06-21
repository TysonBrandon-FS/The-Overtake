<?php
/**
 * GitHub REST API push implementation (no server git required).
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Elementor_GitHub_Sync_GitHub_API
 */
class Elementor_GitHub_Sync_GitHub_API {

	const API_BASE = 'https://api.github.com';

	/**
	 * GitHub max blob size for the contents API (~100 MB hard, but base64 + JSON
	 * makes large files impractical; we guard around the documented 100MB limit).
	 */
	const MAX_FILE_BYTES = 50000000; // 50 MB safety threshold.

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

	/**
	 * Build common request headers.
	 *
	 * @return array
	 */
	protected function headers() {
		return array(
			'Authorization'        => 'Bearer ' . $this->settings->get_token(),
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'Elementor-GitHub-Sync/' . EGS_VERSION,
		);
	}

	/**
	 * Owner/repo slug.
	 *
	 * @return string
	 */
	protected function repo_slug() {
		return rawurlencode( $this->settings->get( 'github_owner' ) ) . '/' . rawurlencode( $this->settings->get( 'github_repo' ) );
	}

	/**
	 * Perform a request to the GitHub API.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Endpoint path (starting with /).
	 * @param array|null $body     Body data (will be JSON-encoded).
	 * @return array { ok, code, data, message }
	 */
	protected function request( $method, $endpoint, $body = null ) {
		$url = self::API_BASE . $endpoint;

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => $this->headers(),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'data'    => null,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// Rate limit detection.
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		if ( 403 === $code && '0' === (string) $remaining ) {
			return array(
				'ok'      => false,
				'code'    => $code,
				'data'    => $data,
				'message' => 'GitHub API rate limit exceeded. Please try again later.',
			);
		}

		$ok      = $code >= 200 && $code < 300;
		$message = '';
		if ( ! $ok ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : ( 'HTTP ' . $code );
		}

		return array(
			'ok'      => $ok,
			'code'    => $code,
			'data'    => $data,
			'message' => $message,
		);
	}

	/* ----------------------------------------------------------------------
	 * Connection validation.
	 * -------------------------------------------------------------------- */

	/**
	 * Validate the token + repo by hitting the repo endpoint.
	 *
	 * @return array { ok, message }
	 */
	public function test_connection() {
		if ( ! $this->settings->has_token() ) {
			return array(
				'ok'      => false,
				'message' => 'No GitHub token configured.',
			);
		}
		if ( '' === $this->settings->get( 'github_owner' ) || '' === $this->settings->get( 'github_repo' ) ) {
			return array(
				'ok'      => false,
				'message' => 'GitHub owner and repository must be set.',
			);
		}

		$res = $this->request( 'GET', '/repos/' . $this->repo_slug() );

		if ( ! $res['ok'] ) {
			if ( 404 === $res['code'] ) {
				return array(
					'ok'      => false,
					'message' => 'Repository not found (404). Check owner/repo and token permissions.',
				);
			}
			if ( 401 === $res['code'] ) {
				return array(
					'ok'      => false,
					'message' => 'Authentication failed (401). The token is invalid or expired.',
				);
			}
			return array(
				'ok'      => false,
				'message' => 'GitHub connection failed: ' . $res['message'],
			);
		}

		// Confirm branch existence (warn but not fatal).
		$branch    = $this->settings->get( 'github_branch', 'main' );
		$has_branch = $this->branch_exists( $branch );

		$msg = 'Connected to ' . $this->settings->get( 'github_owner' ) . '/' . $this->settings->get( 'github_repo' ) . '.';
		if ( ! $has_branch ) {
			$msg .= ' Note: branch "' . $branch . '" does not exist yet; it will be created on first sync.';
		}

		return array(
			'ok'      => true,
			'message' => $msg,
		);
	}

	/**
	 * Check whether a branch exists.
	 *
	 * @param string $branch Branch name.
	 * @return bool
	 */
	public function branch_exists( $branch ) {
		$res = $this->request( 'GET', '/repos/' . $this->repo_slug() . '/branches/' . rawurlencode( $branch ) );
		return $res['ok'];
	}

	/**
	 * Get the repository's default branch.
	 *
	 * @return string|WP_Error
	 */
	protected function default_branch() {
		$res = $this->request( 'GET', '/repos/' . $this->repo_slug() );
		if ( ! $res['ok'] ) {
			return new WP_Error( 'egs_repo', $res['message'] );
		}
		return isset( $res['data']['default_branch'] ) ? $res['data']['default_branch'] : 'main';
	}

	/**
	 * Ensure the target branch exists, creating it from the default branch if needed.
	 *
	 * @param string $branch Branch name.
	 * @return true|WP_Error
	 */
	public function ensure_branch( $branch ) {
		if ( $this->branch_exists( $branch ) ) {
			return true;
		}

		$default = $this->default_branch();
		if ( is_wp_error( $default ) ) {
			return $default;
		}

		// Get the SHA of the default branch tip.
		$ref = $this->request( 'GET', '/repos/' . $this->repo_slug() . '/git/ref/heads/' . rawurlencode( $default ) );
		if ( ! $ref['ok'] || empty( $ref['data']['object']['sha'] ) ) {
			return new WP_Error( 'egs_ref', 'Could not resolve default branch ref: ' . $ref['message'] );
		}

		$sha = $ref['data']['object']['sha'];

		$create = $this->request(
			'POST',
			'/repos/' . $this->repo_slug() . '/git/refs',
			array(
				'ref' => 'refs/heads/' . $branch,
				'sha' => $sha,
			)
		);

		if ( ! $create['ok'] ) {
			return new WP_Error( 'egs_branch', 'Could not create branch "' . $branch . '": ' . $create['message'] );
		}

		$this->logger->info( 'Created branch "' . $branch . '" from "' . $default . '".' );
		return true;
	}

	/* ----------------------------------------------------------------------
	 * File push.
	 * -------------------------------------------------------------------- */

	/**
	 * Get an existing file's SHA and content (if present) on the branch.
	 *
	 * @param string $repo_path Path within repo.
	 * @param string $branch    Branch.
	 * @return array|null { sha, content_base64 } or null if not found.
	 */
	protected function get_remote_file( $repo_path, $branch ) {
		$endpoint = '/repos/' . $this->repo_slug() . '/contents/' . $this->encode_path( $repo_path ) . '?ref=' . rawurlencode( $branch );
		$res      = $this->request( 'GET', $endpoint );

		if ( ! $res['ok'] ) {
			return null;
		}
		if ( ! isset( $res['data']['sha'] ) ) {
			return null; // Likely a directory listing.
		}

		return array(
			'sha'     => $res['data']['sha'],
			'content' => isset( $res['data']['content'] ) ? preg_replace( '/\s+/', '', $res['data']['content'] ) : '',
		);
	}

	/**
	 * Upload/update a single file using the contents API.
	 *
	 * @param string $repo_path      Path within repo.
	 * @param string $local_abs_path Absolute local file path.
	 * @param string $branch         Branch.
	 * @param string $commit_message Commit message.
	 * @return string One of: 'created', 'updated', 'skipped', 'failed'.
	 */
	protected function put_file( $repo_path, $local_abs_path, $branch, $commit_message ) {
		$size = filesize( $local_abs_path );
		if ( false === $size ) {
			$this->logger->error( 'Could not read file: ' . $repo_path );
			return 'failed';
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			$this->logger->error( 'File too large for GitHub API (' . size_format( $size ) . '): ' . $repo_path );
			return 'failed';
		}

		$contents = file_get_contents( $local_abs_path );
		if ( false === $contents ) {
			$this->logger->error( 'Could not read file contents: ' . $repo_path );
			return 'failed';
		}

		$encoded = base64_encode( $contents );
		$remote  = $this->get_remote_file( $repo_path, $branch );

		// Skip if unchanged.
		if ( null !== $remote && $remote['content'] === $encoded ) {
			return 'skipped';
		}

		$body = array(
			'message' => $commit_message,
			'content' => $encoded,
			'branch'  => $branch,
		);

		$is_update = false;
		if ( null !== $remote ) {
			$body['sha'] = $remote['sha'];
			$is_update   = true;
		}

		$endpoint = '/repos/' . $this->repo_slug() . '/contents/' . $this->encode_path( $repo_path );
		$res      = $this->request( 'PUT', $endpoint, $body );

		if ( ! $res['ok'] ) {
			// Handle 409 conflict (SHA changed mid-flight): retry once.
			if ( 409 === $res['code'] || 422 === $res['code'] ) {
				$remote = $this->get_remote_file( $repo_path, $branch );
				if ( null !== $remote ) {
					$body['sha'] = $remote['sha'];
					$res         = $this->request( 'PUT', $endpoint, $body );
				}
			}
		}

		if ( ! $res['ok'] ) {
			$this->logger->error( 'Failed to push ' . $repo_path . ': ' . $res['message'] );
			return 'failed';
		}

		return $is_update ? 'updated' : 'created';
	}

	/**
	 * Push an entire directory of files into the repo path prefix.
	 *
	 * @param string $extract_dir Local directory with files.
	 * @param array  $files       Map of relativePath => absolutePath.
	 * @param int    $post_id     Post ID for the commit template.
	 * @return array|WP_Error Stats: created/updated/skipped/failed counts.
	 */
	public function push_files( $extract_dir, array $files, $post_id = 0 ) {
		if ( ! $this->settings->has_token() ) {
			return new WP_Error( 'egs_notoken', 'No GitHub token configured.' );
		}

		$validate = $this->test_connection();
		if ( ! $validate['ok'] ) {
			return new WP_Error( 'egs_conn', $validate['message'] );
		}

		$branch = $this->settings->get( 'github_branch', 'main' );
		$ensure = $this->ensure_branch( $branch );
		if ( is_wp_error( $ensure ) ) {
			return $ensure;
		}

		$prefix  = $this->settings->get( 'repo_path_prefix', 'elementor-kit' );
		$message = $this->build_commit_message( $post_id );

		$stats = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'total'   => count( $files ),
		);

		foreach ( $files as $rel => $abs ) {
			$repo_path = $this->join_repo_path( $prefix, $rel );
			$outcome   = $this->put_file( $repo_path, $abs, $branch, $message );
			$stats[ $outcome ]++;
		}

		$this->logger->info(
			sprintf(
				'GitHub push complete: %d created, %d updated, %d skipped, %d failed (of %d).',
				$stats['created'],
				$stats['updated'],
				$stats['skipped'],
				$stats['failed'],
				$stats['total']
			)
		);

		return $stats;
	}

	/**
	 * Build the commit message from the template.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function build_commit_message( $post_id ) {
		$tpl = $this->settings->get( 'commit_message_tpl', 'Elementor sync: {post_id} - {datetime}' );

		$replacements = array(
			'{post_id}'   => $post_id ? (string) $post_id : 'manual',
			'{datetime}'  => current_time( 'mysql' ),
			'{date}'      => current_time( 'Y-m-d' ),
			'{time}'      => current_time( 'H:i:s' ),
			'{site}'      => wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		return strtr( $tpl, $replacements );
	}

	/**
	 * Join the prefix and a relative path safely.
	 *
	 * @param string $prefix Repo prefix.
	 * @param string $rel    Relative path.
	 * @return string
	 */
	protected function join_repo_path( $prefix, $rel ) {
		$prefix = trim( $prefix, '/' );
		$rel    = ltrim( str_replace( '\\', '/', $rel ), '/' );
		return '' !== $prefix ? $prefix . '/' . $rel : $rel;
	}

	/**
	 * URL-encode a path while preserving slashes.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function encode_path( $path ) {
		$segments = array_map( 'rawurlencode', explode( '/', $path ) );
		return implode( '/', $segments );
	}
}
