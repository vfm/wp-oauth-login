<?php
/**
 * REST API endpoints for OAuth callback
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Registers and handles REST API endpoints
 */
final class RestApi {
    private const NAMESPACE = 'wp-oauth-login/v1';

    public function __construct(
        private readonly OAuthClient $oauthClient
    ) {
        add_action('rest_api_init', $this->registerRoutes(...));
    }

    /**
     * Register REST API routes
     */
    private function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/callback', [
            'methods'             => 'GET',
            'callback'            => $this->handleCallback(...),
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle OAuth callback via REST API
     */
    private function handleCallback(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $code = $request->get_param('code');
        $state = $request->get_param('state');
        $error = $request->get_param('error');

        // Handle OAuth error
        if ($error) {
            Options::setLoginError($error);
            wp_safe_redirect(wp_login_url());
            exit;
        }

        // Process the callback
        $result = $this->oauthClient->processCallback(
            $code ? sanitize_text_field($code) : '',
            $state ? sanitize_text_field($state) : ''
        );

        if (is_wp_error($result)) {
            Options::setLoginError($result->get_error_message());
            wp_safe_redirect(wp_login_url());
            exit;
        }

        // Get session data for redirect
        $sessionData = $this->oauthClient->getSessionData($state ?? '');

        // Check if this was a test login
        if ($sessionData && ($sessionData['is_test'] ?? false)) {
            // Store claims in transient for test display
            set_transient('wp_oauth_login_test_claims_' . get_current_user_id(), $result['userinfo'], 60);
            set_transient('wp_oauth_login_available_claims', $result['userinfo'], 86400);
            wp_safe_redirect(admin_url('admin.php?page=sso-settings&test_complete=1'));
            exit;
        }

        // Regular login - redirect to admin or specified URL
        $redirectTo = $sessionData['redirect_to'] ?? admin_url();
        wp_safe_redirect($redirectTo);
        exit;
    }
}
