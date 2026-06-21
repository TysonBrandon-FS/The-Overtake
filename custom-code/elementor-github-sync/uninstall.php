<?php
/**
 * Uninstall cleanup for Elementor GitHub Sync.
 *
 * @package ElementorGitHubSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all plugin options, including the stored token and logs.
$egs_options = array(
	'egs_settings',
	'egs_github_token',
	'egs_status',
	'egs_logs',
);

foreach ( $egs_options as $egs_option ) {
	delete_option( $egs_option );
}

// Clear any scheduled sync events.
wp_clear_scheduled_hook( 'egs_run_sync_event' );
delete_transient( 'egs_sync_pending' );
delete_transient( 'egs_sync_running' );
