<?php
/**
 * Plugin Name: WP OAuth Login
 * Plugin URI: https://github.com/vfm/wp-oauth-login
 * Description: OAuth/OIDC Login Integration für WordPress
 * Version: 1.0.0
 * Author: Jens Havelberg / vfm
 * Author URI: https://www.vfm.de
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-oauth-login
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.3
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_OAUTH_LOGIN_VERSION', '1.0.0');
define('WP_OAUTH_LOGIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_OAUTH_LOGIN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_OAUTH_LOGIN_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WP_OAUTH_LOGIN_MIN_PHP_VERSION', '8.3');

/**
 * Check PHP version before loading plugin
 */
if (version_compare(PHP_VERSION, WP_OAUTH_LOGIN_MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', static function (): void {
        $message = sprintf(
            /* translators: 1: Required PHP version, 2: Current PHP version */
            __('WP OAuth Login benötigt PHP %1$s oder höher. Du verwendest PHP %2$s.', 'wp-oauth-login'),
            WP_OAUTH_LOGIN_MIN_PHP_VERSION,
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
    return;
}

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(static function (string $class): void {
    // Only handle our namespace
    $prefix = 'WPOAuthLogin\\';
    $base_dir = WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/';

    // Check if class uses our namespace
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get relative class name
    $relative_class = substr($class, $len);

    // Map namespace to directory structure
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Load required files
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/Plugin.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/Options.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/OAuthClient.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/UserHandler.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/RestApi.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/Admin/SettingsPage.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/Admin/DashboardWidget.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/Admin/Assets.php';
require_once WP_OAUTH_LOGIN_PLUGIN_DIR . 'includes/Frontend/LoginButton.php';

/**
 * Initialize the plugin
 */
function init(): Plugin {
    return Plugin::getInstance();
}

// Activation hook
register_activation_hook(__FILE__, static function (): void {
    Plugin::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, static function (): void {
    Plugin::deactivate();
});

// Initialize plugin
add_action('plugins_loaded', __NAMESPACE__ . '\\init');
