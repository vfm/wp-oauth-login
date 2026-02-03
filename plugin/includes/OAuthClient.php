<?php
/**
 * OAuth Client for handling OAuth/OIDC flows
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin;

use WP_Error;
use WP_User;

/**
 * Handles OAuth 2.0 / OIDC authentication flows
 */
final class OAuthClient {
    private const STATE_TRANSIENT_PREFIX = 'wp_oauth_login_state_';
    private const STATE_EXPIRY = 600; // 10 minutes

    public function __construct(
        private readonly UserHandler $userHandler
    ) {}

    /**
     * Start OAuth authorization flow
     */
    public function startOAuthFlow(bool $isTest = false, string $redirectTo = ''): never {
        $authorizeEndpoint = Options::get('authorize_endpoint');
        $clientId = Options::get('client_id');
        $scope = Options::get('scope');

        if (empty($authorizeEndpoint) || empty($clientId)) {
            wp_die(
                esc_html__('OAuth nicht konfiguriert. Bitte konfiguriere zuerst die SSO Einstellungen.', 'wp-oauth-login'),
                esc_html__('Konfigurationsfehler', 'wp-oauth-login'),
                ['response' => 500]
            );
        }

        $params = [
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => Options::getCallbackUrl(),
            'scope'         => $scope,
        ];

        // Add state parameter for CSRF protection
        if (Options::get('send_state')) {
            $state = wp_generate_password(32, false);
            $params['state'] = $state;

            $this->storeSessionData($state, [
                'is_test'     => $isTest,
                'redirect_to' => $redirectTo,
                'created_at'  => time(),
            ]);
        }

        // Add nonce if configured
        if (Options::get('send_nonce')) {
            $params['nonce'] = wp_generate_password(32, false);
        }

        $authUrl = $authorizeEndpoint . '?' . http_build_query($params);

        wp_redirect($authUrl);
        exit;
    }

    /**
     * Process OAuth callback - exchange code for token and handle user
     *
     * @return array{userinfo: array, user?: WP_User, token?: array}|WP_Error
     */
    public function processCallback(string $code, string $state): array|WP_Error {
        if (empty($code)) {
            return new WP_Error('no_code', __('Kein Authorization Code erhalten.', 'wp-oauth-login'));
        }

        // Verify state parameter
        $sessionData = $this->getSessionData($state);
        if (!$sessionData && Options::get('send_state')) {
            return new WP_Error('invalid_state', __('Ungültiger State Parameter.', 'wp-oauth-login'));
        }

        // Exchange code for tokens
        $tokenResponse = $this->exchangeCodeForToken($code);
        if (is_wp_error($tokenResponse)) {
            return $tokenResponse;
        }

        $accessToken = $tokenResponse['access_token'] ?? '';
        if (empty($accessToken)) {
            return new WP_Error('no_access_token', __('Kein Access Token erhalten.', 'wp-oauth-login'));
        }

        // Get user info from OIDC provider
        $userinfo = $this->getUserInfo($accessToken);
        if (is_wp_error($userinfo)) {
            return $userinfo;
        }

        // If this is a test, return userinfo without logging in
        if ($sessionData && ($sessionData['is_test'] ?? false)) {
            // Store claims for test display
            set_transient('wp_oauth_login_test_claims_' . get_current_user_id(), $userinfo, 60);
            // Store available claims for attribute mapping dropdowns
            set_transient('wp_oauth_login_available_claims', $userinfo, 86400);

            wp_safe_redirect(admin_url('admin.php?page=sso-settings&test_complete=1'));
            exit;
        }

        // Find or create WordPress user
        $user = $this->userHandler->findOrCreateUser($userinfo);
        if (is_wp_error($user)) {
            return $user;
        }

        // Log the user in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        return [
            'userinfo' => $userinfo,
            'user'     => $user,
            'token'    => $tokenResponse,
        ];
    }

    /**
     * Exchange authorization code for access token
     *
     * @return array|WP_Error Token response or error
     */
    private function exchangeCodeForToken(string $code): array|WP_Error {
        $tokenEndpoint = Options::get('token_endpoint');
        $clientId = Options::get('client_id');
        $clientSecret = Options::get('client_secret');

        $body = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => Options::getCallbackUrl(),
        ];

        // Add credentials to body if configured
        if (Options::get('credentials_in_body')) {
            $body['client_id'] = $clientId;
            $body['client_secret'] = $clientSecret;
        }

        // Add scope to body if configured
        if (Options::get('send_scope_in_body')) {
            $body['scope'] = Options::get('scope');
        }

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        // Add credentials to header if configured (Basic Auth)
        if (Options::get('credentials_in_header')) {
            $headers['Authorization'] = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
        }

        $response = wp_remote_post($tokenEndpoint, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'token_request_failed',
                __('Token-Anfrage fehlgeschlagen: ', 'wp-oauth-login') . $response->get_error_message()
            );
        }

        $responseBody = json_decode(wp_remote_retrieve_body($response), true);
        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode !== 200 || isset($responseBody['error'])) {
            $errorMsg = $responseBody['error_description'] 
                ?? $responseBody['error'] 
                ?? __('Unbekannter Fehler', 'wp-oauth-login');
            return new WP_Error('token_error', __('Token-Fehler: ', 'wp-oauth-login') . $errorMsg);
        }

        return $responseBody;
    }

    /**
     * Get user info from userinfo endpoint
     *
     * @return array|WP_Error User info claims or error
     */
    private function getUserInfo(string $accessToken): array|WP_Error {
        $userinfoEndpoint = Options::get('userinfo_endpoint');

        $response = wp_remote_get($userinfoEndpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'userinfo_request_failed',
                __('UserInfo-Anfrage fehlgeschlagen: ', 'wp-oauth-login') . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return new WP_Error(
                'userinfo_error',
                __('UserInfo-Fehler: HTTP ', 'wp-oauth-login') . $statusCode
            );
        }

        $userinfo = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($userinfo)) {
            return new WP_Error('userinfo_parse_error', __('Ungültige UserInfo-Antwort.', 'wp-oauth-login'));
        }

        return $userinfo;
    }

    /**
     * Discover OIDC endpoints from well-known configuration
     *
     * @return array|WP_Error Discovery endpoints or error
     */
    public function discoverEndpoints(string $discoveryUrl): array|WP_Error {
        // Ensure URL ends with well-known path
        if (!str_contains($discoveryUrl, '/.well-known/openid-configuration')) {
            $discoveryUrl = rtrim($discoveryUrl, '/') . '/.well-known/openid-configuration';
        }

        $response = wp_remote_get($discoveryUrl, [
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'discovery_failed',
                __('Fehler beim Abrufen: ', 'wp-oauth-login') . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return new WP_Error('discovery_http_error', __('HTTP Fehler: ', 'wp-oauth-login') . $statusCode);
        }

        $config = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
            return new WP_Error('discovery_parse_error', __('Ungültiges JSON in der Antwort.', 'wp-oauth-login'));
        }

        return [
            'issuer'                                 => $config['issuer'] ?? '',
            'authorization_endpoint'                 => $config['authorization_endpoint'] ?? '',
            'token_endpoint'                         => $config['token_endpoint'] ?? '',
            'userinfo_endpoint'                      => $config['userinfo_endpoint'] ?? '',
            'end_session_endpoint'                   => $config['end_session_endpoint'] ?? '',
            'jwks_uri'                               => $config['jwks_uri'] ?? '',
            'scopes_supported'                       => $config['scopes_supported'] ?? [],
            'response_types_supported'               => $config['response_types_supported'] ?? [],
            'grant_types_supported'                  => $config['grant_types_supported'] ?? [],
            'token_endpoint_auth_methods_supported'  => $config['token_endpoint_auth_methods_supported'] ?? [],
            'claims_supported'                       => $config['claims_supported'] ?? [],
        ];
    }

    /**
     * Store session data for OAuth flow
     */
    private function storeSessionData(string $state, array $data): void {
        set_transient(self::STATE_TRANSIENT_PREFIX . $state, $data, self::STATE_EXPIRY);
    }

    /**
     * Get and delete session data for OAuth flow
     */
    public function getSessionData(string $state): ?array {
        $key = self::STATE_TRANSIENT_PREFIX . $state;
        $data = get_transient($key);
        delete_transient($key);
        return is_array($data) ? $data : null;
    }

    /**
     * Get stored test claims for current user
     */
    public function getTestClaims(): ?array {
        $userId = get_current_user_id();
        if (!$userId) {
            return null;
        }

        $claims = get_transient('wp_oauth_login_test_claims_' . $userId);
        delete_transient('wp_oauth_login_test_claims_' . $userId);

        return is_array($claims) ? $claims : null;
    }

    /**
     * Get stored claims for a user
     */
    public function getStoredClaims(int $userId): ?array {
        $claims = get_user_meta($userId, 'wp_oauth_login_claims', true);
        return is_array($claims) ? $claims : null;
    }
}
