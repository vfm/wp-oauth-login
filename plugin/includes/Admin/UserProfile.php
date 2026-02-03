<?php
/**
 * User Profile integration for displaying custom attribute mappings
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin\Admin;

use WPOAuthLogin\Options;
use WP_User;

/**
 * Displays custom OAuth attribute mappings on the user profile page
 */
final class UserProfile {
    /**
     * Initialize hooks
     */
    public function __construct() {
        add_action('show_user_profile', $this->renderCustomAttributes(...));
        add_action('edit_user_profile', $this->renderCustomAttributes(...));
    }

    /**
     * Render custom attributes section on user profile
     */
    public function renderCustomAttributes(WP_User $user): void {
        $customMappings = Options::get('custom_attribute_mapping');
        
        if (!is_array($customMappings) || empty($customMappings)) {
            return;
        }

        // Check if user has any OAuth data
        $oauthSub = get_user_meta($user->ID, 'wp_oauth_login_sub', true);
        if (empty($oauthSub)) {
            return; // Not an OAuth user, don't show section
        }

        // Collect mapped values
        $mappedValues = [];
        foreach ($customMappings as $mapping) {
            if (!is_array($mapping) || empty($mapping['wp_meta_key']) || empty($mapping['oauth_claim'])) {
                continue;
            }

            $metaKey = $mapping['wp_meta_key'];
            $oauthClaim = $mapping['oauth_claim'];
            $value = get_user_meta($user->ID, $metaKey, true);

            $mappedValues[] = [
                'wp_meta_key'  => $metaKey,
                'oauth_claim'  => $oauthClaim,
                'value'        => $value,
            ];
        }

        if (empty($mappedValues)) {
            return;
        }

        $lastLogin = get_user_meta($user->ID, 'wp_oauth_login_last_login', true);
        ?>
        <h2><?php esc_html_e('SSO Custom Attributes', 'wp-oauth-login'); ?></h2>
        <p class="description">
            <?php esc_html_e('Diese Werte wurden vom OAuth Provider übertragen und als User Meta gespeichert.', 'wp-oauth-login'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('OAuth Subject (sub)', 'wp-oauth-login'); ?></th>
                <td><code><?php echo esc_html($oauthSub); ?></code></td>
            </tr>
            <?php if (!empty($lastLogin)) : ?>
            <tr>
                <th scope="row"><?php esc_html_e('Letzter SSO Login', 'wp-oauth-login'); ?></th>
                <td><?php echo esc_html($lastLogin); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <h3><?php esc_html_e('Gemappte Attribute', 'wp-oauth-login'); ?></h3>
        <table class="widefat striped" style="max-width: 800px;">
            <thead>
                <tr>
                    <th style="width: 30%;"><?php esc_html_e('WordPress Meta Key', 'wp-oauth-login'); ?></th>
                    <th style="width: 30%;"><?php esc_html_e('OAuth Claim', 'wp-oauth-login'); ?></th>
                    <th style="width: 40%;"><?php esc_html_e('Aktueller Wert', 'wp-oauth-login'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mappedValues as $item) : ?>
                <tr>
                    <td><code><?php echo esc_html($item['wp_meta_key']); ?></code></td>
                    <td><code><?php echo esc_html($item['oauth_claim']); ?></code></td>
                    <td>
                        <?php if ($item['value'] !== '' && $item['value'] !== false) : ?>
                            <?php echo esc_html($item['value']); ?>
                        <?php else : ?>
                            <em style="color: #999;"><?php esc_html_e('(nicht gesetzt)', 'wp-oauth-login'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <?php
    }
}
