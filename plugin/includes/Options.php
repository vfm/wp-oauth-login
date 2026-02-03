<?php
/**
 * Options management for the plugin
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin;

/**
 * Handles all plugin options with typed access
 */
final class Options {
    private const OPTION_NAME = 'wp_oauth_login_options';

    /**
     * Default option values
     */
    private static array $defaults = [
        'display_name'              => '',
        'discovery_url'             => '',
        'client_id'                 => '',
        'client_secret'             => '',
        'scope'                     => 'openid profile email urn:zitadel:iam:user:metadata',
        'authorize_endpoint'        => '',
        'token_endpoint'            => '',
        'userinfo_endpoint'         => '',
        'end_session_endpoint'      => '',
        'credentials_in_header'     => 1,
        'credentials_in_body'       => 1,
        'send_state'                => 1,
        'send_nonce'                => 0,
        'send_scope_in_body'        => 1,
        'grant_type'                => 'authorization_code',
        'show_on_login_page'        => 0,
        'button_text'               => 'Mit SSO anmelden',
        'force_sso_redirect'        => 0,
        // Attribute Mapping
        'attr_username'             => 'sub',
        'attr_email'                => 'email',
        'attr_first_name'           => 'given_name',
        'attr_last_name'            => 'family_name',
        'attr_display_name_format'  => 'firstname_lastname',
        // Role Mapping
        'enable_role_mapping'       => 0,
        'role_mapping_attribute'    => '',
        'role_mapping_rules'        => [],
        'default_role'              => 'subscriber',
        'keep_existing_roles'       => 1,
        'deny_unmapped_roles'       => 0,
        // Custom Attribute Mapping (wp_meta_key => oauth_claim)
        'custom_attribute_mapping'  => [],
    ];

    /**
     * Cached options
     */
    private static ?array $options = null;

    /**
     * Get all options
     */
    public static function getAll(): array {
        if (self::$options === null) {
            $stored = get_option(self::OPTION_NAME, []);
            self::$options = array_merge(self::$defaults, is_array($stored) ? $stored : []);
        }
        return self::$options;
    }

    /**
     * Get a single option value
     */
    public static function get(string $key, mixed $default = null): mixed {
        $options = self::getAll();
        return $options[$key] ?? $default ?? (self::$defaults[$key] ?? null);
    }

    /**
     * Set an option value
     */
    public static function set(string $key, mixed $value): bool {
        $options = self::getAll();
        $options[$key] = $value;
        self::$options = $options;
        return update_option(self::OPTION_NAME, $options);
    }

    /**
     * Update multiple options at once
     */
    public static function update(array $values): bool {
        $options = self::getAll();
        $options = array_merge($options, $values);
        self::$options = $options;
        return update_option(self::OPTION_NAME, $options);
    }

    /**
     * Get default options
     */
    public static function getDefaults(): array {
        return self::$defaults;
    }

    /**
     * Initialize default options (on plugin activation)
     */
    public static function initDefaults(): void {
        if (false === get_option(self::OPTION_NAME)) {
            add_option(self::OPTION_NAME, self::$defaults);
        }
    }

    /**
     * Clear cached options (useful after updates)
     */
    public static function clearCache(): void {
        self::$options = null;
    }

    /**
     * Sanitize options input from settings form
     */
    public static function sanitize(array $input): array {
        $sanitized = [];

        // Text fields
        $textFields = ['display_name', 'client_id', 'scope', 'button_text'];
        foreach ($textFields as $field) {
            $sanitized[$field] = isset($input[$field]) ? sanitize_text_field($input[$field]) : '';
        }

        // Secret field (no sanitization to preserve special chars)
        $sanitized['client_secret'] = $input['client_secret'] ?? '';

        // URL fields
        $urlFields = ['discovery_url', 'authorize_endpoint', 'token_endpoint', 'userinfo_endpoint', 'end_session_endpoint'];
        foreach ($urlFields as $field) {
            $sanitized[$field] = isset($input[$field]) ? esc_url_raw($input[$field]) : '';
        }

        // Checkbox fields (boolean as int)
        $checkboxFields = [
            'credentials_in_header', 'credentials_in_body', 'send_state', 
            'send_nonce', 'send_scope_in_body', 'show_on_login_page',
            'enable_role_mapping', 'keep_existing_roles', 'deny_unmapped_roles',
            'force_sso_redirect'
        ];
        foreach ($checkboxFields as $field) {
            $sanitized[$field] = isset($input[$field]) ? 1 : 0;
        }

        // Select/enum fields
        $sanitized['grant_type'] = isset($input['grant_type']) 
            ? sanitize_text_field($input['grant_type']) 
            : 'authorization_code';

        // Attribute mapping fields
        $attrFields = ['attr_username', 'attr_email', 'attr_first_name', 'attr_last_name', 'attr_display_name_format'];
        foreach ($attrFields as $field) {
            $sanitized[$field] = isset($input[$field]) ? sanitize_text_field($input[$field]) : '';
        }

        // Role mapping
        $sanitized['default_role'] = isset($input['default_role']) 
            ? sanitize_text_field($input['default_role']) 
            : 'subscriber';
        
        $sanitized['role_mapping_attribute'] = isset($input['role_mapping_attribute']) 
            ? sanitize_text_field($input['role_mapping_attribute']) 
            : '';

        // Role mapping rules (array of claim_value => wp_role)
        $sanitized['role_mapping_rules'] = [];
        if (isset($input['role_mapping_rules']) && is_array($input['role_mapping_rules'])) {
            foreach ($input['role_mapping_rules'] as $rule) {
                if (is_array($rule) && !empty($rule['claim_value']) && !empty($rule['wp_role'])) {
                    $sanitized['role_mapping_rules'][] = [
                        'claim_value' => sanitize_text_field($rule['claim_value']),
                        'wp_role'     => sanitize_text_field($rule['wp_role']),
                    ];
                }
            }
        }

        // Custom attribute mapping rules (array of wp_meta_key => oauth_claim)
        $sanitized['custom_attribute_mapping'] = [];
        if (isset($input['custom_attribute_mapping']) && is_array($input['custom_attribute_mapping'])) {
            foreach ($input['custom_attribute_mapping'] as $mapping) {
                if (is_array($mapping) && !empty($mapping['wp_meta_key']) && !empty($mapping['oauth_claim'])) {
                    $sanitized['custom_attribute_mapping'][] = [
                        'wp_meta_key'  => sanitize_key($mapping['wp_meta_key']),
                        'oauth_claim'  => sanitize_text_field($mapping['oauth_claim']),
                    ];
                }
            }
        }

        return $sanitized;
    }

    /**
     * Get callback URL for OAuth
     */
    public static function getCallbackUrl(): string {
        return home_url('/wp-json/wp-oauth-login/v1/callback');
    }

    /**
     * Get login URL to start OAuth flow
     */
    public static function getLoginUrl(string $redirectTo = ''): string {
        $url = home_url('?wp-oauth-login-start=1');
        if (!empty($redirectTo)) {
            $url .= '&redirect_to=' . urlencode($redirectTo);
        }
        return $url;
    }

    /**
     * Set a login error message (stored as transient)
     */
    public static function setLoginError(string $message): void {
        // Use session ID or IP as identifier for anonymous users
        $key = self::getErrorTransientKey();
        set_transient($key, $message, 60); // 60 seconds
    }

    /**
     * Get and clear login error message
     */
    public static function getLoginError(): ?string {
        $key = self::getErrorTransientKey();
        $error = get_transient($key);
        if ($error !== false) {
            delete_transient($key);
            return $error;
        }
        return null;
    }

    /**
     * Get transient key for login errors
     */
    private static function getErrorTransientKey(): string {
        // Use a hash of IP + User Agent for anonymous identification
        $identifier = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '') . 
                      sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        return 'wp_oauth_login_error_' . substr(md5($identifier), 0, 12);
    }
}
