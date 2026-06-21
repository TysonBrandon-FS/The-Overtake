<?php
/**
 * Plugin Name:       Elementor GitHub Sync
 * Plugin URI:        https://example.com/elementor-github-sync
 * Description:       Automatically export Elementor content (via WP-CLI + Elementor CLI) and push it to a GitHub repository whenever an Elementor page/template is saved.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elementor-github-sync
 *
 * @package ElementorGitHubSync
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'EGS_VERSION' ) ) {
	return;
}

define( 'EGS_VERSION', '1.0.0' );
define( 'EGS_PLUGIN_FILE', __FILE__ );
define( 'EGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EGS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EGS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum PHP guard. Avoids fatal errors on very old hosts.
 */
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Elementor GitHub Sync requires PHP 8.0 or higher.', 'elementor-github-sync' );
			echo '</p></div>';
		}
	);
	return;
}

require_once EGS_PLUGIN_DIR . 'includes/class-logger.php';
require_once EGS_PLUGIN_DIR . 'includes/class-settings.php';
require_once EGS_PLUGIN_DIR . 'includes/class-exporter.php';
require_once EGS_PLUGIN_DIR . 'includes/class-github-api.php';
require_once EGS_PLUGIN_DIR . 'includes/class-local-git.php';
require_once EGS_PLUGIN_DIR . 'includes/class-plugin.php';
require_once EGS_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Boot the plugin.
 *
 * @return Elementor_GitHub_Sync_Plugin
 */
function egs_plugin() {
	return Elementor_GitHub_Sync_Plugin::instance();
}

add_action( 'plugins_loaded', 'egs_plugin' );

register_activation_hook( __FILE__, array( 'Elementor_GitHub_Sync_Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'Elementor_GitHub_Sync_Plugin', 'on_deactivation' ) );
