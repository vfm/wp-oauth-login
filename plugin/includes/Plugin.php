<?php
/**
 * Main plugin class
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin;

use WPOAuthLogin\Admin\SettingsPage;
use WPOAuthLogin\Admin\DashboardWidget;
use WPOAuthLogin\Admin\Assets;
use WPOAuthLogin\Admin\UserProfile;
use WPOAuthLogin\Frontend\LoginButton;
use WPOAuthLogin\Frontend\LogoutHandler;

/**
 * Main plugin orchestrator using Singleton pattern
 */
final class Plugin {
    private static ?self $instance = null;

    private readonly OAuthClient $oauthClient;
    private readonly UserHandler $userHandler;
    private readonly RestApi $restApi;
    private readonly SettingsPage $settingsPage;
    private readonly DashboardWidget $dashboardWidget;
    private readonly Assets $assets;
    private readonly UserProfile $userProfile;
    private readonly LoginButton $loginButton;
    private readonly LogoutHandler $logoutHandler;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton
     */
    private function __construct() {
        $this->userHandler = new UserHandler();
        $this->oauthClient = new OAuthClient($this->userHandler);
        $this->restApi = new RestApi($this->oauthClient);
        $this->settingsPage = new SettingsPage();
        $this->dashboardWidget = new DashboardWidget();
        $this->assets = new Assets();
        $this->userProfile = new UserProfile();
        $this->loginButton = new LoginButton();
        $this->logoutHandler = new LogoutHandler();

        $this->initHooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function initHooks(): void {
        // Load text domain
        add_action('init', $this->loadTextdomain(...));

        // Handle OAuth flow initiation
        add_action('init', $this->handleOAuthStart(...), 5);

        // Handle OAuth callback (non-REST alternative)
        add_action('init', $this->handleOAuthCallback(...));
    }

    /**
     * Load plugin text domain
     */
    private function loadTextdomain(): void {
        load_plugin_textdomain(
            'wp-oauth-login',
            false,
            dirname(WP_OAUTH_LOGIN_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Handle OAuth flow start via query parameter
     */
    private function handleOAuthStart(): void {
        if (!isset($_GET['wp-oauth-login-start'])) {
            return;
        }

        $isTest = isset($_GET['test']) && $_GET['test'] === '1';

        // Test requires admin permissions
        if ($isTest && !current_user_can('manage_options')) {
            wp_die(
                esc_html__('Keine Berechtigung für den Test.', 'wp-oauth-login'),
                esc_html__('Zugriff verweigert', 'wp-oauth-login'),
                ['response' => 403]
            );
        }

        $redirectTo = $isTest 
            ? admin_url('admin.php?page=sso-settings')
            : (isset($_GET['redirect_to']) ? sanitize_url($_GET['redirect_to']) : admin_url());

        $this->oauthClient->startOAuthFlow($isTest, $redirectTo);
    }

    /**
     * Handle OAuth callback via query parameter (alternative to REST API)
     */
    private function handleOAuthCallback(): void {
        if (!isset($_GET['wp-oauth-login-callback'])) {
            return;
        }

        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

        if ($error) {
            Options::setLoginError($error);
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $result = $this->oauthClient->processCallback($code, $state);

        if (is_wp_error($result)) {
            Options::setLoginError($result->get_error_message());
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $sessionData = $this->oauthClient->getSessionData($state);
        $redirectTo = $sessionData['redirect_to'] ?? admin_url();
        wp_safe_redirect($redirectTo);
        exit;
    }

    /**
     * Plugin activation
     */
    public static function activate(): void {
        Options::initDefaults();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Get OAuth client instance
     */
    public function getOAuthClient(): OAuthClient {
        return $this->oauthClient;
    }

    /**
     * Get user handler instance
     */
    public function getUserHandler(): UserHandler {
        return $this->userHandler;
    }
}
