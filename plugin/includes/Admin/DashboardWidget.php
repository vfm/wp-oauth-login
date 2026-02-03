<?php
/**
 * Dashboard Widget for displaying OAuth claims
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin\Admin;

use WPOAuthLogin\Plugin;

/**
 * Dashboard widget to show user's SSO claims
 */
final class DashboardWidget {
    public function __construct() {
        add_action('wp_dashboard_setup', $this->addWidget(...));
        add_action('wp_ajax_wp_oauth_login_refresh_claims', $this->ajaxRefreshClaims(...));
    }

    /**
     * Add dashboard widget if user has OAuth data
     */
    private function addWidget(): void {
        $userId = get_current_user_id();
        $sub = get_user_meta($userId, 'wp_oauth_login_sub', true);
        $claims = get_user_meta($userId, 'wp_oauth_login_claims', true);

        // Only show widget if user has OAuth data
        if (!empty($sub) || !empty($claims)) {
            wp_add_dashboard_widget(
                'wp_oauth_login_claims_widget',
                __('Meine SSO Claims', 'wp-oauth-login'),
                $this->renderWidget(...)
            );
        }
    }

    /**
     * AJAX handler for refreshing user claims
     */
    private function ajaxRefreshClaims(): void {
        check_ajax_referer('wp_oauth_login_refresh_claims', 'nonce');

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(__('Nicht angemeldet.', 'wp-oauth-login'));
        }

        // Check if user has OAuth connection
        $sub = get_user_meta($userId, 'wp_oauth_login_sub', true);
        $storedClaims = get_user_meta($userId, 'wp_oauth_login_claims', true);

        if (empty($sub) && empty($storedClaims)) {
            wp_send_json_error(__('Kein OAuth-Login für diesen Benutzer vorhanden.', 'wp-oauth-login'));
        }

        // Return stored claims (fresh fetch would require stored refresh token)
        if (!empty($storedClaims)) {
            $lastLogin = get_user_meta($userId, 'wp_oauth_login_last_login', true);
            wp_send_json_success([
                'claims'       => $storedClaims,
                'last_updated' => $lastLogin,
                'message'      => __('Claims vom letzten Login geladen.', 'wp-oauth-login'),
            ]);
        }

        wp_send_json_error(__('Keine Claims gespeichert.', 'wp-oauth-login'));
    }

    /**
     * Render dashboard widget
     */
    private function renderWidget(): void {
        $userId = get_current_user_id();
        $claims = get_user_meta($userId, 'wp_oauth_login_claims', true);
        $lastLogin = get_user_meta($userId, 'wp_oauth_login_last_login', true);
        $nonce = wp_create_nonce('wp_oauth_login_refresh_claims');

        $this->renderWidgetStyles();
        ?>
        <div id="oauth-claims-content">
            <?php if (!empty($claims) && is_array($claims)) : ?>
                <?php $this->renderClaimsTable($claims, $lastLogin, $nonce); ?>
            <?php else : ?>
                <?php $this->renderEmptyState($nonce); ?>
            <?php endif; ?>
        </div>
        <?php
        $this->renderWidgetScript();
    }

    /**
     * Render claims table
     */
    private function renderClaimsTable(array $claims, string $lastLogin, string $nonce): void {
        ?>
        <div class="oauth-claims-container">
            <table class="oauth-claims-table">
                <tbody>
                    <?php foreach ($claims as $key => $value) : ?>
                        <?php if ($key === 'urn:zitadel:iam:user:metadata' && (is_array($value) || is_object($value))) : ?>
                            <?php $this->renderZitadelMetadata($value); ?>
                        <?php else : ?>
                            <tr>
                                <th><?php echo esc_html($key); ?></th>
                                <td>
                                    <?php 
                                    if (is_array($value) || is_object($value)) {
                                        echo esc_html(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                    } else {
                                        echo esc_html((string)$value);
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="oauth-claims-footer">
            <?php if ($lastLogin) : ?>
                <div class="last-updated">
                    <?php printf(esc_html__('Zuletzt aktualisiert: %s', 'wp-oauth-login'), esc_html($lastLogin)); ?>
                </div>
            <?php endif; ?>
            <button type="button" class="button button-secondary" id="refresh-oauth-claims" data-nonce="<?php echo esc_attr($nonce); ?>">
                <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 3px;"></span>
                <?php esc_html_e('Claims aktualisieren', 'wp-oauth-login'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render Zitadel metadata section
     */
    private function renderZitadelMetadata(array|object $value): void {
        $metadata = is_object($value) ? (array)$value : $value;
        ?>
        <tr>
            <th colspan="2" style="background: #e7f3ff; color: #0073aa; text-align: center; padding: 8px;">
                <span class="dashicons dashicons-admin-generic" style="vertical-align: middle;"></span>
                <?php esc_html_e('Zitadel Metadata', 'wp-oauth-login'); ?>
            </th>
        </tr>
        <?php foreach ($metadata as $metaKey => $metaValue) : ?>
            <tr>
                <th style="padding-left: 20px;"><?php echo esc_html($metaKey); ?></th>
                <td>
                    <?php 
                    // Decode Base64 value
                    $decoded = base64_decode((string)$metaValue, true);
                    if ($decoded !== false) {
                        echo esc_html($decoded);
                        echo ' <small style="color:#999;">(decoded)</small>';
                    } else {
                        echo esc_html((string)$metaValue);
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Render empty state
     */
    private function renderEmptyState(string $nonce): void {
        ?>
        <div class="oauth-claims-info">
            <p><?php esc_html_e('Keine Claims gespeichert.', 'wp-oauth-login'); ?></p>
            <button type="button" class="button button-secondary" id="refresh-oauth-claims" data-nonce="<?php echo esc_attr($nonce); ?>">
                <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 3px;"></span>
                <?php esc_html_e('Claims laden', 'wp-oauth-login'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render widget styles
     */
    private function renderWidgetStyles(): void {
        ?>
        <style>
            #wp_oauth_login_claims_widget .inside {
                margin: 0;
                padding: 0;
            }
            .oauth-claims-container {
                max-height: 400px;
                overflow-y: auto;
            }
            .oauth-claims-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            .oauth-claims-table th,
            .oauth-claims-table td {
                padding: 8px;
                border-bottom: 1px solid #eee;
                text-align: left;
                vertical-align: top;
            }
            .oauth-claims-table th {
                background: #f9f9f9;
                font-weight: 600;
                width: 35%;
                word-break: break-word;
            }
            .oauth-claims-table td {
                word-break: break-all;
                font-family: monospace;
                font-size: 11px;
            }
            .oauth-claims-table tr:hover {
                background: #f5f5f5;
            }
            .oauth-claims-footer {
                padding: 12px;
                background: #f9f9f9;
                border-top: 1px solid #ddd;
            }
            .oauth-claims-footer .last-updated {
                color: #666;
                font-size: 11px;
                margin-bottom: 8px;
            }
            .oauth-claims-loading {
                text-align: center;
                padding: 20px;
                color: #666;
            }
            .oauth-claims-error {
                color: #dc3232;
                padding: 10px;
                background: #fbeaea;
                margin: 10px;
                border-radius: 3px;
            }
            .oauth-claims-info {
                padding: 15px;
                text-align: center;
                color: #666;
            }
        </style>
        <?php
    }

    /**
     * Render widget JavaScript
     */
    private function renderWidgetScript(): void {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-oauth-claims').on('click', function() {
                var $btn = $(this);
                var nonce = $btn.data('nonce');
                var $content = $('#oauth-claims-content');
                
                $btn.prop('disabled', true);
                $content.html('<div class="oauth-claims-loading"><span class="spinner is-active" style="float:none;"></span> Lade Claims...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_oauth_login_refresh_claims',
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.claims) {
                            var html = '<div class="oauth-claims-container"><table class="oauth-claims-table"><tbody>';
                            var claims = response.data.claims;
                            for (var key in claims) {
                                if (claims.hasOwnProperty(key)) {
                                    var value = claims[key];
                                    if (key === 'urn:zitadel:iam:user:metadata' && typeof value === 'object') {
                                        html += '<tr><th colspan="2" style="background: #e7f3ff; color: #0073aa; text-align: center; padding: 8px;">';
                                        html += '<span class="dashicons dashicons-admin-generic" style="vertical-align: middle;"></span> Zitadel Metadata</th></tr>';
                                        for (var metaKey in value) {
                                            if (value.hasOwnProperty(metaKey)) {
                                                var metaValue = value[metaKey];
                                                var decoded = '';
                                                try {
                                                    decoded = atob(metaValue);
                                                } catch(e) {
                                                    decoded = metaValue;
                                                }
                                                html += '<tr><th style="padding-left: 20px;">' + escapeHtml(metaKey) + '</th>';
                                                html += '<td>' + escapeHtml(decoded) + ' <small style="color:#999;">(decoded)</small></td></tr>';
                                            }
                                        }
                                    } else if (typeof value === 'object') {
                                        html += '<tr><th>' + escapeHtml(key) + '</th><td>' + escapeHtml(JSON.stringify(value, null, 2)) + '</td></tr>';
                                    } else {
                                        html += '<tr><th>' + escapeHtml(key) + '</th><td>' + escapeHtml(String(value)) + '</td></tr>';
                                    }
                                }
                            }
                            html += '</tbody></table></div>';
                            html += '<div class="oauth-claims-footer">';
                            if (response.data.last_updated) {
                                html += '<div class="last-updated">Zuletzt aktualisiert: ' + escapeHtml(response.data.last_updated) + '</div>';
                            }
                            if (response.data.message) {
                                html += '<div class="last-updated">' + escapeHtml(response.data.message) + '</div>';
                            }
                            html += '<button type="button" class="button button-secondary" id="refresh-oauth-claims" data-nonce="' + nonce + '">';
                            html += '<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 3px;"></span> Claims aktualisieren</button>';
                            html += '</div>';
                            $content.html(html);
                        } else {
                            $content.html('<div class="oauth-claims-error">' + (response.data || 'Fehler beim Laden der Claims') + '</div>');
                        }
                    },
                    error: function() {
                        $content.html('<div class="oauth-claims-error">Verbindungsfehler</div>');
                    }
                });
            });
            
            function escapeHtml(text) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(text));
                return div.innerHTML;
            }
        });
        </script>
        <?php
    }
}
