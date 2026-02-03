/**
 * WP OAuth Login - Admin JavaScript
 *
 * @package WP_OAuth_Login
 */

(function($) {
    'use strict';

    /**
     * Initialize admin functionality
     */
    function init() {
        handleTestComplete();
        bindTestButton();
        bindModalClose();
        bindRoleMappingToggle();
        bindRoleMappingRules();
        bindCustomAttributeMapping();
        bindDiscovery();
    }

    /**
     * Check if returning from test OAuth flow
     */
    function handleTestComplete() {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('test_complete') === '1') {
            // Remove the parameter from URL
            window.history.replaceState({}, document.title, window.location.pathname + '?page=sso-settings');
            // Fetch the claims
            fetchTestClaims();
        }
    }

    /**
     * Bind test button click handler
     */
    function bindTestButton() {
        $('#wp-oauth-test-btn').on('click', function(e) {
            e.preventDefault();
            var testUrl = $(this).attr('href');
            window.location.href = testUrl;
        });
    }

    /**
     * Fetch test claims via AJAX
     */
    function fetchTestClaims() {
        showModal('<div class="loading">' + escapeHtml(wpOAuthLogin.i18n.loadClaims) + '</div>');
        
        $.ajax({
            url: wpOAuthLogin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wp_oauth_login_test',
                nonce: wpOAuthLogin.nonces.test
            },
            success: function(response) {
                if (response.success && response.data.claims) {
                    displayClaims(response.data.claims);
                } else {
                    showModal('<p>' + escapeHtml(wpOAuthLogin.i18n.noClaimsFound) + '</p>');
                }
            },
            error: function() {
                showModal('<p>' + escapeHtml(wpOAuthLogin.i18n.errorFetching) + '</p>');
            }
        });
    }

    /**
     * Show modal dialog
     */
    function showModal(content) {
        var modal = $('#wp-oauth-claims-modal');
        if (modal.length === 0) {
            $('body').append(
                '<div id="wp-oauth-claims-modal">' +
                    '<div class="modal-content">' +
                        '<span class="modal-close">&times;</span>' +
                        '<h2>OAuth Claims / UserInfo</h2>' +
                        '<div id="modal-body"></div>' +
                    '</div>' +
                '</div>'
            );
            modal = $('#wp-oauth-claims-modal');
        }
        $('#modal-body').html(content);
        modal.show();
    }

    /**
     * Display claims in modal
     */
    function displayClaims(claims) {
        var html = '<table><thead><tr><th>Claim</th><th>Wert</th></tr></thead><tbody>';
        
        for (var key in claims) {
            if (claims.hasOwnProperty(key)) {
                var value = claims[key];
                
                // Special handling for Zitadel metadata
                if (key === 'urn:zitadel:iam:user:metadata' && typeof value === 'object') {
                    html += '<tr><td colspan="2" style="background: #e7f3ff; color: #0073aa; text-align: center;">';
                    html += '<strong><span class="dashicons dashicons-admin-generic" style="vertical-align: middle;"></span> Zitadel Metadata</strong></td></tr>';
                    
                    for (var metaKey in value) {
                        if (value.hasOwnProperty(metaKey)) {
                            var metaValue = value[metaKey];
                            var decoded = '';
                            try {
                                decoded = atob(metaValue);
                            } catch(e) {
                                decoded = metaValue;
                            }
                            html += '<tr><td style="padding-left: 20px;"><strong>' + escapeHtml(metaKey) + '</strong></td>';
                            html += '<td>' + escapeHtml(decoded) + ' <small style="color:#999;">(decoded)</small></td></tr>';
                        }
                    }
                } else if (typeof value === 'object') {
                    html += '<tr><td><strong>' + escapeHtml(key) + '</strong></td>';
                    html += '<td>' + escapeHtml(JSON.stringify(value, null, 2)) + '</td></tr>';
                } else {
                    html += '<tr><td><strong>' + escapeHtml(key) + '</strong></td>';
                    html += '<td>' + escapeHtml(String(value)) + '</td></tr>';
                }
            }
        }
        
        html += '</tbody></table>';
        showModal(html);
    }

    /**
     * Bind modal close handler
     */
    function bindModalClose() {
        $(document).on('click', '.modal-close, #wp-oauth-claims-modal', function(e) {
            if (e.target === this) {
                $('#wp-oauth-claims-modal').hide();
            }
        });
    }

    /**
     * Toggle role mapping fields visibility
     */
    function bindRoleMappingToggle() {
        $('#enable_role_mapping').on('change', function() {
            if ($(this).is(':checked')) {
                $('.role-mapping-row').show();
            } else {
                $('.role-mapping-row').hide();
            }
        });
    }

    /**
     * Bind role mapping rule add/remove handlers
     */
    function bindRoleMappingRules() {
        // Add new rule
        $(document).on('click', '.add-role-rule', function() {
            var container = $('#role-mapping-rules-container');
            var template = $('#role-rule-template').html();
            var newIndex = container.find('.role-mapping-rule').length;
            var newRule = template.replace(/{{INDEX}}/g, newIndex);
            container.append(newRule);
        });

        // Remove rule
        $(document).on('click', '.remove-role-rule', function() {
            var container = $('#role-mapping-rules-container');
            if (container.find('.role-mapping-rule').length > 1) {
                $(this).closest('.role-mapping-rule').remove();
                // Re-index the rules
                container.find('.role-mapping-rule').each(function(index) {
                    $(this).find('input, select').each(function() {
                        var name = $(this).attr('name');
                        if (name) {
                            name = name.replace(/\[role_mapping_rules\]\[\d+\]/, '[role_mapping_rules][' + index + ']');
                            $(this).attr('name', name);
                        }
                    });
                });
            } else {
                // Clear the only remaining row instead of removing
                $(this).closest('.role-mapping-rule').find('input[type="text"]').val('');
            }
        });
    }

    /**
     * Bind custom attribute mapping add/remove handlers
     */
    function bindCustomAttributeMapping() {
        // Add new mapping
        $(document).on('click', '.add-custom-mapping', function() {
            var container = $('#custom-attribute-mapping-container');
            var template = $('#custom-mapping-template').html();
            var newIndex = container.find('.custom-mapping-rule').length;
            var newMapping = template.replace(/{{INDEX}}/g, newIndex);
            container.append(newMapping);
        });

        // Remove mapping
        $(document).on('click', '.remove-custom-mapping', function() {
            var container = $('#custom-attribute-mapping-container');
            if (container.find('.custom-mapping-rule').length > 1) {
                $(this).closest('.custom-mapping-rule').remove();
                // Re-index the mappings
                container.find('.custom-mapping-rule').each(function(index) {
                    $(this).find('input').each(function() {
                        var name = $(this).attr('name');
                        if (name) {
                            name = name.replace(/\[custom_attribute_mapping\]\[\d+\]/, '[custom_attribute_mapping][' + index + ']');
                            $(this).attr('name', name);
                        }
                    });
                });
            } else {
                // Clear the only remaining row instead of removing
                $(this).closest('.custom-mapping-rule').find('input[type="text"]').val('');
            }
        });
    }

    /**
     * Bind OIDC discovery functionality
     */
    function bindDiscovery() {
        $('#discover-endpoints-btn').on('click', function() {
            var $btn = $(this);
            var nonce = $btn.data('nonce');
            var discoveryUrl = $('#discovery_url').val();
            var $result = $('#discovery-result');

            if (!discoveryUrl) {
                $result.html('<div class="discovery-error">' + escapeHtml(wpOAuthLogin.i18n.enterDiscovery) + '</div>');
                return;
            }

            $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:left; margin-right:5px;"></span> ' + escapeHtml(wpOAuthLogin.i18n.loading));
            $result.html('');

            $.ajax({
                url: wpOAuthLogin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wp_oauth_login_discover',
                    nonce: nonce,
                    discovery_url: discoveryUrl
                },
                success: function(response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download" style="margin-top: 4px;"></span> ' + escapeHtml(wpOAuthLogin.i18n.load));
                    
                    if (response.success && response.data.endpoints) {
                        handleDiscoverySuccess(response.data, $result);
                    } else {
                        $result.html('<div class="discovery-error">' + escapeHtml(response.data || 'Unbekannter Fehler') + '</div>');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download" style="margin-top: 4px;"></span> ' + escapeHtml(wpOAuthLogin.i18n.load));
                    $result.html('<div class="discovery-error">' + escapeHtml(wpOAuthLogin.i18n.connectionError) + '</div>');
                }
            });
        });
    }

    /**
     * Handle successful OIDC discovery
     */
    function handleDiscoverySuccess(data, $result) {
        var endpoints = data.endpoints;
        
        // Fill in the endpoint fields
        var fields = [
            { id: 'authorize_endpoint', key: 'authorization_endpoint' },
            { id: 'token_endpoint', key: 'token_endpoint' },
            { id: 'userinfo_endpoint', key: 'userinfo_endpoint' },
            { id: 'end_session_endpoint', key: 'end_session_endpoint' }
        ];

        fields.forEach(function(field) {
            if (endpoints[field.key]) {
                $('#' + field.id).val(endpoints[field.key]).addClass('field-updated');
            }
        });
        
        // Build success message
        var html = '<div class="discovery-success">';
        html += '<strong>✓ ' + escapeHtml(data.message) + '</strong></div>';
        
        // Show discovered metadata
        html += '<div class="discovery-metadata">';
        html += '<strong>Issuer:</strong> ' + escapeHtml(endpoints.issuer || '-') + '<br>';
        
        if (endpoints.scopes_supported && endpoints.scopes_supported.length > 0) {
            html += '<strong>Unterstützte Scopes:</strong> <code style="font-size: 11px;">' + 
                    escapeHtml(endpoints.scopes_supported.join(', ')) + '</code><br>';
        }
        
        if (endpoints.claims_supported && endpoints.claims_supported.length > 0) {
            var claimsDisplay = endpoints.claims_supported.slice(0, 15).join(', ');
            html += '<strong>Unterstützte Claims:</strong> <code style="font-size: 11px;">' + escapeHtml(claimsDisplay);
            if (endpoints.claims_supported.length > 15) {
                html += ' ... (+' + (endpoints.claims_supported.length - 15) + ' weitere)';
            }
            html += '</code><br>';
        }
        
        if (endpoints.token_endpoint_auth_methods_supported && endpoints.token_endpoint_auth_methods_supported.length > 0) {
            html += '<strong>Auth Methoden:</strong> ' + escapeHtml(endpoints.token_endpoint_auth_methods_supported.join(', '));
        }
        
        html += '</div>';
        html += '<p style="margin-top: 10px; color: #666;"><em>Die Endpoint-Felder wurden ausgefüllt. Bitte speichere die Einstellungen.</em></p>';
        
        $result.html(html);
        
        // Reset field highlighting after a few seconds
        setTimeout(function() {
            $('.field-updated').removeClass('field-updated');
        }, 3000);
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(text)));
        return div.innerHTML;
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
