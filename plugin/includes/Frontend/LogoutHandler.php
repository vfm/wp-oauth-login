<?php
/**
 * Logout Handler for OIDC Single Logout
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin\Frontend;

use WPOAuthLogin\Options;

/**
 * Handles OIDC logout flow when user logs out from WordPress
 */
final class LogoutHandler {
    /**
     * Transient key prefix for storing logout flag
     */
    private const LOGOUT_TRANSIENT_PREFIX = 'wp_oauth_logout_';

    public function __construct() {
        // Hook into WordPress logout - store flag before session is destroyed
        add_action('clear_auth_cookie', $this->beforeLogout(...));
        
        // Hook into wp_redirect to intercept the logout redirect
        add_filter('wp_redirect', $this->interceptLogoutRedirect(...), 10, 2);
    }

    /**
     * Before logout: store flag that user was SSO authenticated
     */
    private function beforeLogout(): void {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }

        // Check if user logged in via OAuth
        $oauthSub = get_user_meta($userId, 'wp_oauth_login_sub', true);
        if (empty($oauthSub)) {
            return;
        }

        $endSessionEndpoint = Options::get('end_session_endpoint');
        if (empty($endSessionEndpoint)) {
            return;
        }

        // Store flag in transient (keyed by IP to identify after logout)
        $key = $this->getTransientKey();
        set_transient($key, [
            'do_oidc_logout' => true,
            'end_session_endpoint' => $endSessionEndpoint,
            'client_id' => Options::get('client_id'),
        ], 60);
    }

    /**
     * Intercept wp_redirect and redirect to OIDC logout if needed
     */
    private function interceptLogoutRedirect(string $location, int $status): string {
        // Only intercept redirects to wp-login.php (logout redirects)
        if (strpos($location, 'wp-login.php') === false) {
            return $location;
        }

        $key = $this->getTransientKey();
        $logoutData = get_transient($key);
        
        // Clean up transient
        delete_transient($key);

        if (!is_array($logoutData) || empty($logoutData['do_oidc_logout'])) {
            return $location;
        }

        // Build OIDC logout URL
        $endSessionEndpoint = $logoutData['end_session_endpoint'];
        $params = [];

        // Add client_id
        if (!empty($logoutData['client_id'])) {
            $params['client_id'] = $logoutData['client_id'];
        }

        // Post-logout redirect back to WordPress home (must be registered in OIDC provider)
        $params['post_logout_redirect_uri'] = home_url('/');

        $oidcLogoutUrl = add_query_arg($params, $endSessionEndpoint);

        // Perform direct redirect to external OIDC URL (bypassing wp_safe_redirect)
        // We use header() directly because wp_safe_redirect blocks external URLs
        header('Location: ' . $oidcLogoutUrl, true, 302);
        exit;
    }

    /**
     * Get transient key based on client identifier
     */
    private function getTransientKey(): string {
        $identifier = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '') . 
                      sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        return self::LOGOUT_TRANSIENT_PREFIX . substr(md5($identifier), 0, 12);
    }
}
