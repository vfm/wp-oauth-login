<?php
/**
 * Login Button for WordPress login page
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin\Frontend;

use WPOAuthLogin\Options;
use WP_Error;

/**
 * Renders SSO login button on WordPress login page
 */
final class LoginButton {
    /**
     * GET parameter to bypass SSO redirect
     */
    public const BYPASS_PARAM = 'wp_login';

    public function __construct() {
        add_action('login_init', $this->maybeRedirectToSSO(...), 5);
        add_action('login_footer', $this->renderButton(...));
        add_action('login_enqueue_scripts', $this->enqueueStyles(...));
        add_filter('wp_login_errors', $this->addOAuthError(...), 10, 2);
    }

    /**
     * Redirect to SSO if force_sso_redirect is enabled
     */
    private function maybeRedirectToSSO(): void {
        // Only on login action (not logout, register, etc.)
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'login';
        if (!in_array($action, ['login', ''], true)) {
            return;
        }

        // Don't redirect if already logged in
        if (is_user_logged_in()) {
            return;
        }

        // Don't redirect if force_sso_redirect is disabled
        if (!Options::get('force_sso_redirect')) {
            return;
        }

        // Don't redirect if bypass parameter is set
        if (isset($_GET[self::BYPASS_PARAM]) && $_GET[self::BYPASS_PARAM] === '1') {
            return;
        }

        // Don't redirect if there's an OAuth error (show the error on login page)
        if (Options::getLoginError() !== null) {
            // Put it back so it gets displayed
            return;
        }

        // Don't redirect on POST requests (form submissions)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return;
        }

        // Get redirect_to parameter
        $redirectTo = isset($_GET['redirect_to']) 
            ? sanitize_url($_GET['redirect_to']) 
            : admin_url();

        // Redirect to SSO
        $loginUrl = Options::getLoginUrl($redirectTo);
        wp_safe_redirect($loginUrl);
        exit;
    }

    /**
     * Add OAuth error to WordPress login errors
     */
    private function addOAuthError(WP_Error $errors, string $redirect_to): WP_Error {
        $oauthError = Options::getLoginError();
        if ($oauthError !== null) {
            $errors->add(
                'oauth_error',
                '<strong>' . esc_html__('SSO Fehler:', 'wp-oauth-login') . '</strong> ' . esc_html($oauthError),
                'error'
            );
        }
        return $errors;
    }

    /**
     * Render login button on WordPress login form
     */
    private function renderButton(): void {
        if (!Options::get('show_on_login_page')) {
            return;
        }

        $buttonText = Options::get('button_text');
        if (empty($buttonText)) {
            $buttonText = __('Mit SSO anmelden', 'wp-oauth-login');
        }

        $redirectTo = isset($_GET['redirect_to']) 
            ? sanitize_url($_GET['redirect_to']) 
            : admin_url();
        
        $loginUrl = Options::getLoginUrl($redirectTo);

        ?>
        <div id="wp-oauth-login-container">
            <div class="wp-oauth-login-separator">
                <span><?php esc_html_e('oder', 'wp-oauth-login'); ?></span>
            </div>
            <a href="<?php echo esc_url($loginUrl); ?>" class="button button-primary button-large wp-oauth-login-btn">
                <?php echo esc_html($buttonText); ?>
            </a>
        </div>
        <script>
            (function() {
                var container = document.getElementById('wp-oauth-login-container');
                var loginForm = document.getElementById('loginform');
                if (container && loginForm) {
                    loginForm.parentNode.insertBefore(container, loginForm.nextSibling);
                }
            })();
        </script>
        <?php
    }

    /**
     * Enqueue login page styles
     */
    private function enqueueStyles(): void {
        if (!Options::get('show_on_login_page')) {
            return;
        }

        wp_add_inline_style('login', '
            #wp-oauth-login-container {
                width: 320px;
                margin: 20px auto 0;
                padding: 0;
            }
            @media screen and (max-width: 782px) {
                #wp-oauth-login-container {
                    width: 100%;
                    max-width: 320px;
                }
            }
            .wp-oauth-login-separator {
                display: flex;
                align-items: center;
                text-align: center;
                margin: 0 0 20px;
                color: #50575e;
                font-size: 14px;
            }
            .wp-oauth-login-separator::before,
            .wp-oauth-login-separator::after {
                content: "";
                flex: 1;
                border-bottom: 1px solid #c3c4c7;
            }
            .wp-oauth-login-separator span {
                padding: 0 16px;
            }
            .wp-oauth-login-btn {
                width: 100%;
                height: auto;
                padding: 0 12px;
                text-align: center;
                display: block;
                box-sizing: border-box;
                font-size: 14px;
                line-height: 2.15384615;
                min-height: 36px;
            }
        ');
    }
}
