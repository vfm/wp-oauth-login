<?php
/**
 * Admin Settings Page
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin\Admin;

use WPOAuthLogin\Options;
use WPOAuthLogin\OAuthClient;
use WPOAuthLogin\UserHandler;
use WPOAuthLogin\Plugin;

/**
 * Handles the admin settings page for SSO configuration
 */
final class SettingsPage {
    private const MENU_SLUG = 'sso-settings';
    private const OPTION_GROUP = 'wp_oauth_login_settings';

    public function __construct() {
        add_action('admin_menu', $this->addMenu(...));
        add_action('admin_init', $this->registerSettings(...));
        add_action('wp_ajax_wp_oauth_login_test', $this->ajaxTestLogin(...));
        add_action('wp_ajax_wp_oauth_login_discover', $this->ajaxDiscoverEndpoints(...));
    }

    /**
     * Add admin menu
     */
    private function addMenu(): void {
        add_menu_page(
            __('SSO Einstellungen', 'wp-oauth-login'),
            __('SSO', 'wp-oauth-login'),
            'manage_options',
            self::MENU_SLUG,
            $this->renderPage(...),
            'dashicons-shield-alt',
            80
        );
    }

    /**
     * Register settings
     */
    private function registerSettings(): void {
        register_setting(
            self::OPTION_GROUP,
            'wp_oauth_login_options',
            [
                'sanitize_callback' => Options::sanitize(...),
            ]
        );
    }

    /**
     * AJAX handler for test login
     */
    private function ajaxTestLogin(): void {
        check_ajax_referer('wp_oauth_login_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Keine Berechtigung.', 'wp-oauth-login'));
        }

        // Check if we're returning from OAuth with test claims
        $oauthClient = Plugin::getInstance()->getOAuthClient();
        $claims = $oauthClient->getTestClaims();

        if ($claims) {
            wp_send_json_success(['claims' => $claims]);
        }

        // Start OAuth flow for test
        $oauthClient->startOAuthFlow(isTest: true, redirectTo: admin_url('admin.php?page=sso-settings'));
    }

    /**
     * AJAX handler for OIDC discovery
     */
    private function ajaxDiscoverEndpoints(): void {
        check_ajax_referer('wp_oauth_login_discover', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Keine Berechtigung.', 'wp-oauth-login'));
        }

        $discoveryUrl = isset($_POST['discovery_url']) ? esc_url_raw($_POST['discovery_url']) : '';

        if (empty($discoveryUrl)) {
            wp_send_json_error(__('Bitte gib eine Discovery URL ein.', 'wp-oauth-login'));
        }

        $oauthClient = Plugin::getInstance()->getOAuthClient();
        $endpoints = $oauthClient->discoverEndpoints($discoveryUrl);

        if (is_wp_error($endpoints)) {
            wp_send_json_error($endpoints->get_error_message());
        }

        wp_send_json_success([
            'endpoints' => $endpoints,
            'message'   => __('Konfiguration erfolgreich geladen!', 'wp-oauth-login'),
        ]);
    }

    /**
     * Render settings page
     */
    private function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'wp_oauth_login_messages',
                'wp_oauth_login_message',
                __('Einstellungen gespeichert.', 'wp-oauth-login'),
                'updated'
            );
        }

        $this->renderTemplate();
    }

    /**
     * Render the settings page template
     */
    private function renderTemplate(): void {
        $callbackUrl = Options::getCallbackUrl();
        $testUrl = add_query_arg([
            'wp-oauth-login-start' => '1',
            'test' => '1',
        ], home_url());

        // Get available claims from last test
        $availableClaims = get_transient('wp_oauth_login_available_claims');
        $claimOptions = ['sub', 'email', 'name', 'given_name', 'family_name', 'preferred_username', 'nickname'];
        if (!empty($availableClaims) && is_array($availableClaims)) {
            $claimOptions = array_unique(array_merge($claimOptions, array_keys($availableClaims)));
        }

        // Get all WordPress roles
        $wpRoles = wp_roles()->get_names();

        // Get role mapping rules
        $roleRules = Options::get('role_mapping_rules');
        $rulesArray = is_array($roleRules) ? $roleRules : [];
        if (empty($rulesArray)) {
            $rulesArray[] = ['claim_value' => '', 'wp_role' => 'subscriber'];
        }

        ?>
        <div class="wrap wp-oauth-login-settings">
            <h1><?php echo esc_html__('SSO Konfiguration', 'wp-oauth-login'); ?></h1>
            
            <?php settings_errors('wp_oauth_login_messages'); ?>

            <div class="callback-url-box">
                <strong><?php esc_html_e('Callback / Redirect URL', 'wp-oauth-login'); ?></strong>
                <p><?php esc_html_e('Verwende diese URL als Redirect URI bei deinem OAuth Provider:', 'wp-oauth-login'); ?></p>
                <code><?php echo esc_html($callbackUrl); ?></code>
                <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($callbackUrl); ?>'); this.textContent='Kopiert!'; setTimeout(() => this.textContent='Kopieren', 2000);">
                    <?php esc_html_e('Kopieren', 'wp-oauth-login'); ?>
                </button>
            </div>

            <form action="options.php" method="post">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <?php $this->renderApplicationSection(); ?>
                <?php $this->renderCredentialsSection(); ?>
                <?php $this->renderDiscoverySection(); ?>
                <?php $this->renderEndpointsSection(); ?>
                <?php $this->renderAdvancedSection(); ?>
                <?php $this->renderAttributeMappingSection($claimOptions); ?>
                <?php $this->renderRoleMappingSection($wpRoles, $rulesArray); ?>
                <?php $this->renderCustomAttributeMappingSection(); ?>
                <?php $this->renderLoginPageSection(); ?>

                <?php submit_button(__('Einstellungen speichern', 'wp-oauth-login')); ?>
            </form>

            <div class="test-button-wrapper">
                <h2><?php esc_html_e('Verbindung testen', 'wp-oauth-login'); ?></h2>
                <p><?php esc_html_e('Teste die OAuth-Anmeldung und zeige alle Claims vom UserInfo-Endpoint an.', 'wp-oauth-login'); ?></p>
                <a href="<?php echo esc_url($testUrl); ?>" class="button button-primary button-large" id="wp-oauth-test-btn">
                    <?php esc_html_e('Teste Anmeldung', 'wp-oauth-login'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render application section
     */
    private function renderApplicationSection(): void {
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('Anwendung', 'wp-oauth-login'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="display_name"><?php esc_html_e('Anzeigename', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="display_name" name="wp_oauth_login_options[display_name]" 
                               value="<?php echo esc_attr(Options::get('display_name')); ?>" class="regular-text"
                               placeholder="z.B. Firmen-SSO">
                        <p class="description"><?php esc_html_e('Name der Anwendung für die Anzeige auf der Login-Seite.', 'wp-oauth-login'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render credentials section
     */
    private function renderCredentialsSection(): void {
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('OAuth Zugangsdaten', 'wp-oauth-login'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="client_id" class="required-field"><?php esc_html_e('Client ID', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="client_id" name="wp_oauth_login_options[client_id]" 
                               value="<?php echo esc_attr(Options::get('client_id')); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="client_secret" class="required-field"><?php esc_html_e('Client Secret', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="password" id="client_secret" name="wp_oauth_login_options[client_secret]" 
                               value="<?php echo esc_attr(Options::get('client_secret')); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="scope"><?php esc_html_e('Scope', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="scope" name="wp_oauth_login_options[scope]" 
                               value="<?php echo esc_attr(Options::get('scope')); ?>" class="regular-text"
                               placeholder="openid profile email">
                        <p class="description"><?php esc_html_e('Durch Leerzeichen getrennte Liste der angeforderten Scopes.', 'wp-oauth-login'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render OIDC discovery section
     */
    private function renderDiscoverySection(): void {
        ?>
        <div class="section-card" style="border-left: 4px solid #2271b1;">
            <h2><?php esc_html_e('OIDC Discovery', 'wp-oauth-login'); ?></h2>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('Lade die Endpoint-Konfiguration automatisch vom OIDC Discovery Endpoint.', 'wp-oauth-login'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="discovery_url"><?php esc_html_e('Discovery URL', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <div style="display: flex; gap: 10px; align-items: flex-start;">
                            <input type="url" id="discovery_url" name="wp_oauth_login_options[discovery_url]" 
                                   value="<?php echo esc_attr(Options::get('discovery_url')); ?>" class="regular-text"
                                   placeholder="https://identity.example.com/.well-known/openid-configuration">
                            <button type="button" class="button button-primary" id="discover-endpoints-btn" 
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('wp_oauth_login_discover')); ?>">
                                <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                                <?php esc_html_e('Laden', 'wp-oauth-login'); ?>
                            </button>
                        </div>
                        <p class="description"><?php esc_html_e('z.B. https://identity.example.com/.well-known/openid-configuration', 'wp-oauth-login'); ?></p>
                        <div id="discovery-result" style="margin-top: 10px;"></div>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render endpoints section
     */
    private function renderEndpointsSection(): void {
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('OAuth Endpoints', 'wp-oauth-login'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="authorize_endpoint" class="required-field"><?php esc_html_e('Authorize Endpoint', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="authorize_endpoint" name="wp_oauth_login_options[authorize_endpoint]" 
                               value="<?php echo esc_attr(Options::get('authorize_endpoint')); ?>" class="regular-text" required
                               placeholder="https://auth.example.com/oauth/v2/authorize">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="token_endpoint" class="required-field"><?php esc_html_e('Token Endpoint', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="token_endpoint" name="wp_oauth_login_options[token_endpoint]" 
                               value="<?php echo esc_attr(Options::get('token_endpoint')); ?>" class="regular-text" required
                               placeholder="https://auth.example.com/oauth/v2/token">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="userinfo_endpoint" class="required-field"><?php esc_html_e('UserInfo Endpoint', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="userinfo_endpoint" name="wp_oauth_login_options[userinfo_endpoint]" 
                               value="<?php echo esc_attr(Options::get('userinfo_endpoint')); ?>" class="regular-text" required
                               placeholder="https://auth.example.com/oidc/v1/userinfo">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="end_session_endpoint"><?php esc_html_e('End Session Endpoint', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="end_session_endpoint" name="wp_oauth_login_options[end_session_endpoint]" 
                               value="<?php echo esc_attr(Options::get('end_session_endpoint')); ?>" class="regular-text"
                               placeholder="https://auth.example.com/oidc/v1/end_session">
                        <p class="description"><?php esc_html_e('Optional: Für Single Logout (SLO) beim OAuth Provider.', 'wp-oauth-login'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render advanced settings section
     */
    private function renderAdvancedSection(): void {
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('Erweiterte Einstellungen', 'wp-oauth-login'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Client Credentials', 'wp-oauth-login'); ?></th>
                    <td>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="wp_oauth_login_options[credentials_in_header]" value="1" 
                                       <?php checked(Options::get('credentials_in_header'), 1); ?>>
                                <?php esc_html_e('Im Header senden (Basic Auth)', 'wp-oauth-login'); ?>
                            </label>
                            <label>
                                <input type="checkbox" name="wp_oauth_login_options[credentials_in_body]" value="1" 
                                       <?php checked(Options::get('credentials_in_body'), 1); ?>>
                                <?php esc_html_e('Im Body senden', 'wp-oauth-login'); ?>
                            </label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('State & Nonce', 'wp-oauth-login'); ?></th>
                    <td>
                        <div class="checkbox-group">
                            <label>
                                <input type="checkbox" name="wp_oauth_login_options[send_state]" value="1" 
                                       <?php checked(Options::get('send_state'), 1); ?>>
                                <?php esc_html_e('State Parameter senden', 'wp-oauth-login'); ?>
                            </label>
                            <label>
                                <input type="checkbox" name="wp_oauth_login_options[send_nonce]" value="1" 
                                       <?php checked(Options::get('send_nonce'), 1); ?>>
                                <?php esc_html_e('Nonce senden', 'wp-oauth-login'); ?>
                            </label>
                            <label>
                                <input type="checkbox" name="wp_oauth_login_options[send_scope_in_body]" value="1" 
                                       <?php checked(Options::get('send_scope_in_body'), 1); ?>>
                                <?php esc_html_e('Scope im Body senden', 'wp-oauth-login'); ?>
                            </label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="grant_type"><?php esc_html_e('Grant Type', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <select id="grant_type" name="wp_oauth_login_options[grant_type]">
                            <option value="authorization_code" <?php selected(Options::get('grant_type'), 'authorization_code'); ?>>
                                <?php esc_html_e('Authorization Code Grant', 'wp-oauth-login'); ?>
                            </option>
                            <option value="implicit" <?php selected(Options::get('grant_type'), 'implicit'); ?>>
                                <?php esc_html_e('Implicit Grant', 'wp-oauth-login'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render attribute mapping section
     */
    private function renderAttributeMappingSection(array $claimOptions): void {
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('Attribute Mapping', 'wp-oauth-login'); ?></h2>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('Führe erst eine Test-Konfiguration oben durch, um verfügbare Claims zu sehen.', 'wp-oauth-login'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="attr_username" class="required-field"><?php esc_html_e('Username Attribute', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <select id="attr_username" name="wp_oauth_login_options[attr_username]" class="regular-text">
                            <?php foreach ($claimOptions as $claim) : ?>
                                <option value="<?php echo esc_attr($claim); ?>" <?php selected(Options::get('attr_username'), $claim); ?>>
                                    <?php echo esc_html($claim); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Claim für den WordPress-Benutzernamen.', 'wp-oauth-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="attr_first_name"><?php esc_html_e('First Name Attribute', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="attr_first_name" name="wp_oauth_login_options[attr_first_name]" 
                               value="<?php echo esc_attr(Options::get('attr_first_name')); ?>" class="regular-text"
                               placeholder="given_name">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="attr_last_name"><?php esc_html_e('Last Name Attribute', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="attr_last_name" name="wp_oauth_login_options[attr_last_name]" 
                               value="<?php echo esc_attr(Options::get('attr_last_name')); ?>" class="regular-text"
                               placeholder="family_name">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="attr_email"><?php esc_html_e('Email Attribute', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="attr_email" name="wp_oauth_login_options[attr_email]" 
                               value="<?php echo esc_attr(Options::get('attr_email')); ?>" class="regular-text"
                               placeholder="email">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="attr_display_name_format"><?php esc_html_e('Display Name', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <select id="attr_display_name_format" name="wp_oauth_login_options[attr_display_name_format]">
                            <option value="firstname_lastname" <?php selected(Options::get('attr_display_name_format'), 'firstname_lastname'); ?>>
                                <?php esc_html_e('FirstName LastName', 'wp-oauth-login'); ?>
                            </option>
                            <option value="lastname_firstname" <?php selected(Options::get('attr_display_name_format'), 'lastname_firstname'); ?>>
                                <?php esc_html_e('LastName FirstName', 'wp-oauth-login'); ?>
                            </option>
                            <option value="firstname" <?php selected(Options::get('attr_display_name_format'), 'firstname'); ?>>
                                <?php esc_html_e('FirstName', 'wp-oauth-login'); ?>
                            </option>
                            <option value="username" <?php selected(Options::get('attr_display_name_format'), 'username'); ?>>
                                <?php esc_html_e('Username', 'wp-oauth-login'); ?>
                            </option>
                            <option value="email" <?php selected(Options::get('attr_display_name_format'), 'email'); ?>>
                                <?php esc_html_e('Email', 'wp-oauth-login'); ?>
                            </option>
                            <option value="name_claim" <?php selected(Options::get('attr_display_name_format'), 'name_claim'); ?>>
                                <?php esc_html_e('name Claim (direkt)', 'wp-oauth-login'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render role mapping section
     */
    private function renderRoleMappingSection(array $wpRoles, array $rulesArray): void {
        $enableRoleMapping = Options::get('enable_role_mapping');
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('Role Mapping', 'wp-oauth-login'); ?></h2>
            <p class="description" style="margin-bottom: 10px;">
                <strong><?php esc_html_e('Hinweis:', 'wp-oauth-login'); ?></strong> 
                <?php esc_html_e('Rollen werden nur für Nicht-Administratoren zugewiesen.', 'wp-oauth-login'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Role Mapping aktivieren', 'wp-oauth-login'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_oauth_login_options[enable_role_mapping]" value="1" 
                                   <?php checked($enableRoleMapping, 1); ?> id="enable_role_mapping">
                            <?php esc_html_e('Rollen basierend auf Claims zuweisen', 'wp-oauth-login'); ?>
                        </label>
                    </td>
                </tr>
                <tr class="role-mapping-row" style="<?php echo $enableRoleMapping ? '' : 'display:none;'; ?>">
                    <th scope="row"><?php esc_html_e('Optionen', 'wp-oauth-login'); ?></th>
                    <td>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wp_oauth_login_options[keep_existing_roles]" value="1" 
                                   <?php checked(Options::get('keep_existing_roles'), 1); ?>>
                            <?php esc_html_e('Bestehende Benutzerrollen beibehalten', 'wp-oauth-login'); ?>
                            <span class="description" style="display: block; margin-left: 24px; color: #666;">
                                <?php esc_html_e('Role Mapping gilt nicht für bestehende WordPress-Benutzer.', 'wp-oauth-login'); ?>
                            </span>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="wp_oauth_login_options[deny_unmapped_roles]" value="1" 
                                   <?php checked(Options::get('deny_unmapped_roles'), 1); ?>>
                            <?php esc_html_e('Login verweigern wenn Rolle nicht gemappt', 'wp-oauth-login'); ?>
                            <span class="description" style="display: block; margin-left: 24px; color: #666;">
                                <?php esc_html_e('Benutzer ohne passende Rolle können sich nicht anmelden.', 'wp-oauth-login'); ?>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr class="role-mapping-row" style="<?php echo $enableRoleMapping ? '' : 'display:none;'; ?>">
                    <th scope="row">
                        <label for="default_role"><?php esc_html_e('Standard Rolle', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <select id="default_role" name="wp_oauth_login_options[default_role]">
                            <?php wp_dropdown_roles(Options::get('default_role') ?: 'subscriber'); ?>
                        </select>
                        <p class="description"><?php esc_html_e('Rolle wenn keine Mapping-Regel greift.', 'wp-oauth-login'); ?></p>
                    </td>
                </tr>
                <tr class="role-mapping-row" style="<?php echo $enableRoleMapping ? '' : 'display:none;'; ?>">
                    <th scope="row">
                        <label for="role_mapping_attribute"><?php esc_html_e('Role Attribute', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="role_mapping_attribute" name="wp_oauth_login_options[role_mapping_attribute]" 
                               value="<?php echo esc_attr(Options::get('role_mapping_attribute')); ?>" class="regular-text"
                               placeholder="roles oder urn:zitadel:iam:org:project:roles">
                        <p class="description"><?php esc_html_e('Claim der die Rolle enthält. Mehrere mit Semikolon trennen.', 'wp-oauth-login'); ?></p>
                    </td>
                </tr>
                <tr class="role-mapping-row" style="<?php echo $enableRoleMapping ? '' : 'display:none;'; ?>">
                    <th scope="row">
                        <?php esc_html_e('Mapping Regeln', 'wp-oauth-login'); ?>
                    </th>
                    <td>
                        <div id="role-mapping-rules-container">
                            <div class="role-mapping-header" style="display: flex; gap: 10px; margin-bottom: 8px; font-weight: 600;">
                                <div style="flex: 1;"><?php esc_html_e('Claim Wert', 'wp-oauth-login'); ?></div>
                                <div style="flex: 1;"><?php esc_html_e('WordPress Rolle', 'wp-oauth-login'); ?></div>
                                <div style="width: 70px;"></div>
                            </div>
                            <?php foreach ($rulesArray as $index => $rule) : ?>
                            <div class="role-mapping-rule" style="display: flex; gap: 10px; margin-bottom: 8px; align-items: center;">
                                <input type="text" 
                                       name="wp_oauth_login_options[role_mapping_rules][<?php echo $index; ?>][claim_value]" 
                                       value="<?php echo esc_attr($rule['claim_value'] ?? ''); ?>" 
                                       class="regular-text" style="flex: 1;"
                                       placeholder="z.B. admin, editor, Service_keinStatus">
                                <select name="wp_oauth_login_options[role_mapping_rules][<?php echo $index; ?>][wp_role]" style="flex: 1;">
                                    <?php foreach ($wpRoles as $roleSlug => $roleName) : ?>
                                        <option value="<?php echo esc_attr($roleSlug); ?>" <?php selected($rule['wp_role'] ?? '', $roleSlug); ?>>
                                            <?php echo esc_html($roleName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button remove-role-rule" title="<?php esc_attr_e('Entfernen', 'wp-oauth-login'); ?>" style="background: #dc3232; border-color: #dc3232; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-minus" style="margin-top: 3px;"></span>
                                </button>
                                <button type="button" class="button add-role-rule" title="<?php esc_attr_e('Hinzufügen', 'wp-oauth-login'); ?>" style="background: #2271b1; border-color: #2271b1; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-plus" style="margin-top: 3px;"></span>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <script type="text/template" id="role-rule-template">
                            <div class="role-mapping-rule" style="display: flex; gap: 10px; margin-bottom: 8px; align-items: center;">
                                <input type="text" 
                                       name="wp_oauth_login_options[role_mapping_rules][{{INDEX}}][claim_value]" 
                                       value="" 
                                       class="regular-text" style="flex: 1;"
                                       placeholder="z.B. admin, editor, Service_keinStatus">
                                <select name="wp_oauth_login_options[role_mapping_rules][{{INDEX}}][wp_role]" style="flex: 1;">
                                    <?php foreach ($wpRoles as $roleSlug => $roleName) : ?>
                                        <option value="<?php echo esc_attr($roleSlug); ?>">
                                            <?php echo esc_html($roleName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button remove-role-rule" title="<?php esc_attr_e('Entfernen', 'wp-oauth-login'); ?>" style="background: #dc3232; border-color: #dc3232; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-minus" style="margin-top: 3px;"></span>
                                </button>
                                <button type="button" class="button add-role-rule" title="<?php esc_attr_e('Hinzufügen', 'wp-oauth-login'); ?>" style="background: #2271b1; border-color: #2271b1; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-plus" style="margin-top: 3px;"></span>
                                </button>
                            </div>
                        </script>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render custom attribute mapping section
     */
    private function renderCustomAttributeMappingSection(): void {
        $customMappings = Options::get('custom_attribute_mapping');
        $mappingsArray = is_array($customMappings) ? $customMappings : [];
        if (empty($mappingsArray)) {
            $mappingsArray[] = ['wp_meta_key' => '', 'oauth_claim' => ''];
        }
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('Custom Attribute Mapping', 'wp-oauth-login'); ?></h2>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('Ordne OAuth Claims zusätzlichen WordPress User Meta Feldern zu. Claims werden zuerst im Root-Objekt gesucht, dann in Zitadel Metadata (Base64 decodiert).', 'wp-oauth-login'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Mapping Regeln', 'wp-oauth-login'); ?>
                    </th>
                    <td>
                        <div id="custom-attribute-mapping-container">
                            <div class="custom-mapping-header" style="display: flex; gap: 10px; margin-bottom: 8px; font-weight: 600;">
                                <div style="flex: 1;"><?php esc_html_e('WordPress Meta Key', 'wp-oauth-login'); ?></div>
                                <div style="flex: 1;"><?php esc_html_e('OAuth Claim', 'wp-oauth-login'); ?></div>
                                <div style="width: 70px;"></div>
                            </div>
                            <?php foreach ($mappingsArray as $index => $mapping) : ?>
                            <div class="custom-mapping-rule" style="display: flex; gap: 10px; margin-bottom: 8px; align-items: center;">
                                <input type="text" 
                                       name="wp_oauth_login_options[custom_attribute_mapping][<?php echo $index; ?>][wp_meta_key]" 
                                       value="<?php echo esc_attr($mapping['wp_meta_key'] ?? ''); ?>" 
                                       class="regular-text" style="flex: 1;"
                                       placeholder="z.B. billing_phone, billing_company">
                                <input type="text" 
                                       name="wp_oauth_login_options[custom_attribute_mapping][<?php echo $index; ?>][oauth_claim]" 
                                       value="<?php echo esc_attr($mapping['oauth_claim'] ?? ''); ?>" 
                                       class="regular-text" style="flex: 1;"
                                       placeholder="z.B. telefon, phone_number">
                                <button type="button" class="button remove-custom-mapping" title="<?php esc_attr_e('Entfernen', 'wp-oauth-login'); ?>" style="background: #dc3232; border-color: #dc3232; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-minus" style="margin-top: 3px;"></span>
                                </button>
                                <button type="button" class="button add-custom-mapping" title="<?php esc_attr_e('Hinzufügen', 'wp-oauth-login'); ?>" style="background: #2271b1; border-color: #2271b1; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-plus" style="margin-top: 3px;"></span>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <script type="text/template" id="custom-mapping-template">
                            <div class="custom-mapping-rule" style="display: flex; gap: 10px; margin-bottom: 8px; align-items: center;">
                                <input type="text" 
                                       name="wp_oauth_login_options[custom_attribute_mapping][{{INDEX}}][wp_meta_key]" 
                                       value="" 
                                       class="regular-text" style="flex: 1;"
                                       placeholder="z.B. billing_phone, billing_company">
                                <input type="text" 
                                       name="wp_oauth_login_options[custom_attribute_mapping][{{INDEX}}][oauth_claim]" 
                                       value="" 
                                       class="regular-text" style="flex: 1;"
                                       placeholder="z.B. telefon, phone_number">
                                <button type="button" class="button remove-custom-mapping" title="<?php esc_attr_e('Entfernen', 'wp-oauth-login'); ?>" style="background: #dc3232; border-color: #dc3232; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-minus" style="margin-top: 3px;"></span>
                                </button>
                                <button type="button" class="button add-custom-mapping" title="<?php esc_attr_e('Hinzufügen', 'wp-oauth-login'); ?>" style="background: #2271b1; border-color: #2271b1; color: #fff; width: 32px; padding: 0;">
                                    <span class="dashicons dashicons-plus" style="margin-top: 3px;"></span>
                                </button>
                            </div>
                        </script>
                        <p class="description" style="margin-top: 10px;">
                            <?php esc_html_e('Beispiele: billing_phone → telefon, billing_debitor → debitornumber, billing_company → company', 'wp-oauth-login'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render login page section
     */
    private function renderLoginPageSection(): void {
        ?>
        <div class="section-card">
            <h2><?php esc_html_e('Login-Seite', 'wp-oauth-login'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Login Button', 'wp-oauth-login'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_oauth_login_options[show_on_login_page]" value="1" 
                                   <?php checked(Options::get('show_on_login_page'), 1); ?>>
                            <?php esc_html_e('SSO Button auf der Login-Seite anzeigen', 'wp-oauth-login'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="button_text"><?php esc_html_e('Button Text', 'wp-oauth-login'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="button_text" name="wp_oauth_login_options[button_text]" 
                               value="<?php echo esc_attr(Options::get('button_text')); ?>" class="regular-text"
                               placeholder="Mit SSO anmelden">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Auto-Redirect', 'wp-oauth-login'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wp_oauth_login_options[force_sso_redirect]" value="1" 
                                   <?php checked(Options::get('force_sso_redirect'), 1); ?>>
                            <?php esc_html_e('Automatisch zu SSO weiterleiten (Login-Seite überspringen)', 'wp-oauth-login'); ?>
                        </label>
                        <p class="description">
                            <?php 
                            printf(
                                /* translators: %s: URL parameter */
                                esc_html__('Um die Weiterleitung zu umgehen und das normale Login-Formular anzuzeigen, füge %s zur URL hinzu.', 'wp-oauth-login'),
                                '<code>?wp_login=1</code>'
                            ); 
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
}
