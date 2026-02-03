<?php
/**
 * Admin Assets - CSS and JavaScript for admin pages
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin\Admin;

/**
 * Handles loading of admin CSS and JavaScript
 */
final class Assets {
    public function __construct() {
        add_action('admin_enqueue_scripts', $this->enqueueAssets(...));
    }

    /**
     * Enqueue admin scripts and styles
     */
    private function enqueueAssets(string $hook): void {
        // Dashboard scripts are inline in widget
        if ($hook === 'index.php') {
            return;
        }

        // Only load on our settings page
        if (!str_contains($hook, 'sso-settings')) {
            return;
        }

        // Enqueue external CSS file
        wp_enqueue_style(
            'wp-oauth-login-admin',
            WP_OAUTH_LOGIN_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_OAUTH_LOGIN_VERSION
        );

        // Enqueue external JS file
        wp_enqueue_script(
            'wp-oauth-login-admin',
            WP_OAUTH_LOGIN_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WP_OAUTH_LOGIN_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('wp-oauth-login-admin', 'wpOAuthLogin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'test'     => wp_create_nonce('wp_oauth_login_test'),
                'discover' => wp_create_nonce('wp_oauth_login_discover'),
            ],
            'i18n'    => [
                'loading'         => __('Lade...', 'wp-oauth-login'),
                'loadClaims'      => __('Lade Claims...', 'wp-oauth-login'),
                'connectionError' => __('Verbindungsfehler', 'wp-oauth-login'),
                'noClaimsFound'   => __('Keine Claims gefunden oder Fehler aufgetreten.', 'wp-oauth-login'),
                'errorFetching'   => __('Fehler beim Abrufen der Claims.', 'wp-oauth-login'),
                'enterDiscovery'  => __('Bitte gib eine Discovery URL ein.', 'wp-oauth-login'),
                'load'            => __('Laden', 'wp-oauth-login'),
            ],
        ]);
    }
}
