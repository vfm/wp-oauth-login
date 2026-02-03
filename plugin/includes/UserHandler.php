<?php
/**
 * User Handler for WordPress user creation and management
 *
 * @package WP_OAuth_Login
 */

declare(strict_types=1);

namespace WPOAuthLogin;

use WP_Error;
use WP_User;

/**
 * Handles user creation, mapping, and role assignment from OAuth claims
 */
final class UserHandler {
    /**
     * Display name format options
     */
    private const DISPLAY_NAME_FORMATS = [
        'firstname_lastname',
        'lastname_firstname',
        'firstname',
        'username',
        'email',
        'name_claim',
    ];

    /**
     * Find existing user or create new one based on OAuth claims
     *
     * @param array $userinfo Claims from OAuth provider
     * @return WP_User|WP_Error User object or error
     */
    public function findOrCreateUser(array $userinfo): WP_User|WP_Error {
        $sub = $userinfo['sub'] ?? '';
        $email = $userinfo['email'] ?? '';

        // Try to find user by sub (stored as user meta)
        $user = $this->findUserBySub($sub);

        // Try to find user by email
        if (!$user && !empty($email)) {
            $user = get_user_by('email', $email);
        }

        // Try to find user by sub as username
        if (!$user && !empty($sub)) {
            $user = get_user_by('login', $sub);
        }

        if ($user instanceof WP_User) {
            // Check if login should be denied for existing user
            if (Options::get('enable_role_mapping') && Options::get('deny_unmapped_roles')) {
                $role = $this->determineUserRole($userinfo, isExistingUser: false);
                if ($role === 'deny_login') {
                    return new WP_Error(
                        'role_not_mapped',
                        __('Anmeldung verweigert: Keine passende Rolle gefunden.', 'wp-oauth-login')
                    );
                }
            }

            // Update existing user
            $this->updateUserFromClaims($user->ID, $userinfo);
            return $user;
        }

        // Create new user
        return $this->createUserFromClaims($userinfo);
    }

    /**
     * Find user by OAuth sub claim
     */
    private function findUserBySub(string $sub): ?WP_User {
        if (empty($sub)) {
            return null;
        }

        $users = get_users([
            'meta_key'   => 'wp_oauth_login_sub',
            'meta_value' => $sub,
            'number'     => 1,
        ]);

        return !empty($users) ? $users[0] : null;
    }

    /**
     * Create a new WordPress user from OAuth claims
     *
     * @param array $userinfo Claims from OAuth provider
     * @return WP_User|WP_Error Created user or error
     */
    private function createUserFromClaims(array $userinfo): WP_User|WP_Error {
        // Get attribute mappings
        $attrUsername = Options::get('attr_username') ?: 'sub';
        $attrEmail = Options::get('attr_email') ?: 'email';
        $attrFirstName = Options::get('attr_first_name') ?: 'given_name';
        $attrLastName = Options::get('attr_last_name') ?: 'family_name';
        $displayNameFormat = Options::get('attr_display_name_format') ?: 'firstname_lastname';

        // Extract values using configured mappings
        $sub = isset($userinfo['sub']) ? sanitize_user($userinfo['sub']) : '';
        $email = $this->getClaimValue($userinfo, $attrEmail);
        $email = !empty($email) ? sanitize_email($email) : '';
        $givenName = sanitize_text_field($this->getClaimValue($userinfo, $attrFirstName));
        $familyName = sanitize_text_field($this->getClaimValue($userinfo, $attrLastName));

        // Determine username
        $username = $this->determineUsername($userinfo, $attrUsername, $email, $sub);
        if (empty($username)) {
            return new WP_Error('no_username', __('Kein Benutzername konnte ermittelt werden.', 'wp-oauth-login'));
        }

        // Make username unique if it exists
        $username = $this->makeUsernameUnique($username);

        // Generate email if not provided
        if (empty($email)) {
            $email = $username . '@oauth.local';
        }

        // Determine role
        $role = $this->determineUserRole($userinfo, isExistingUser: false);
        if ($role === 'deny_login') {
            return new WP_Error(
                'role_not_mapped',
                __('Anmeldung verweigert: Keine passende Rolle gefunden.', 'wp-oauth-login')
            );
        }

        // Build display name
        $displayName = $this->buildDisplayName($userinfo, $displayNameFormat, $givenName, $familyName, $username);

        $userData = [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(24, true, true),
            'display_name' => $displayName,
            'first_name'   => $givenName,
            'last_name'    => $familyName,
            'role'         => $role,
        ];

        $userId = wp_insert_user($userData);

        if (is_wp_error($userId)) {
            return $userId;
        }

        // Store OAuth metadata
        if (!empty($sub)) {
            update_user_meta($userId, 'wp_oauth_login_sub', $sub);
        }
        update_user_meta($userId, 'wp_oauth_login_claims', $userinfo);
        update_user_meta($userId, 'wp_oauth_login_last_login', current_time('mysql'));

        // Apply custom attribute mappings
        $this->applyCustomAttributeMapping($userId, $userinfo);

        $user = get_user_by('ID', $userId);
        return $user instanceof WP_User ? $user : new WP_Error('user_not_found', __('Benutzer konnte nicht erstellt werden.', 'wp-oauth-login'));
    }

    /**
     * Update existing user from OAuth claims
     */
    private function updateUserFromClaims(int $userId, array $userinfo): void {
        $attrEmail = Options::get('attr_email') ?: 'email';
        $attrFirstName = Options::get('attr_first_name') ?: 'given_name';
        $attrLastName = Options::get('attr_last_name') ?: 'family_name';
        $displayNameFormat = Options::get('attr_display_name_format') ?: 'firstname_lastname';

        $sub = $userinfo['sub'] ?? '';
        $email = sanitize_email($this->getClaimValue($userinfo, $attrEmail));
        $givenName = sanitize_text_field($this->getClaimValue($userinfo, $attrFirstName));
        $familyName = sanitize_text_field($this->getClaimValue($userinfo, $attrLastName));

        $user = get_user_by('ID', $userId);
        $username = $user?->user_login ?? '';

        $userData = ['ID' => $userId];

        if (!empty($email)) {
            $userData['user_email'] = $email;
        }

        $displayName = $this->buildDisplayName($userinfo, $displayNameFormat, $givenName, $familyName, $username);
        if (!empty($displayName)) {
            $userData['display_name'] = $displayName;
        }

        if (!empty($givenName)) {
            $userData['first_name'] = $givenName;
        }

        if (!empty($familyName)) {
            $userData['last_name'] = $familyName;
        }

        wp_update_user($userData);

        // Update role if role mapping is enabled
        if (Options::get('enable_role_mapping')) {
            $newRole = $this->determineUserRole($userinfo, isExistingUser: true);
            if ($newRole !== null && $newRole !== 'deny_login' && $user instanceof WP_User) {
                if (!in_array($newRole, $user->roles, true)) {
                    $user->set_role($newRole);
                }
            }
        }

        // Update metadata
        if (!empty($sub)) {
            update_user_meta($userId, 'wp_oauth_login_sub', $sub);
        }
        update_user_meta($userId, 'wp_oauth_login_claims', $userinfo);
        update_user_meta($userId, 'wp_oauth_login_last_login', current_time('mysql'));

        // Apply custom attribute mappings
        $this->applyCustomAttributeMapping($userId, $userinfo);
    }

    /**
     * Get a claim value from userinfo, supporting dot notation for nested claims
     */
    public function getClaimValue(array $userinfo, string $claimPath): string {
        if (empty($claimPath)) {
            return '';
        }

        // Support dot notation for nested claims (e.g., "address.locality")
        $parts = explode('.', $claimPath);
        $value = $userinfo;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return '';
            }
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Determine username from claims
     */
    private function determineUsername(array $userinfo, string $attrUsername, string $email, string $sub): string {
        $usernameValue = $this->getClaimValue($userinfo, $attrUsername);
        $username = !empty($usernameValue) ? sanitize_user($usernameValue) : '';

        if (empty($username) && !empty($email)) {
            $username = strstr($email, '@', true) ?: '';
        }

        if (empty($username) && !empty($sub)) {
            $username = sanitize_user($sub);
        }

        return $username;
    }

    /**
     * Make username unique by appending counter if needed
     */
    private function makeUsernameUnique(string $username): string {
        $baseUsername = $username;
        $counter = 1;

        while (username_exists($username)) {
            $username = $baseUsername . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Build display name based on format setting
     */
    private function buildDisplayName(
        array $userinfo,
        string $format,
        string $givenName,
        string $familyName,
        string $username
    ): string {
        $displayName = match ($format) {
            'firstname_lastname' => trim($givenName . ' ' . $familyName),
            'lastname_firstname' => trim($familyName . ' ' . $givenName),
            'firstname' => $givenName,
            'username' => $username,
            'email' => $this->getClaimValue($userinfo, Options::get('attr_email') ?: 'email'),
            'name_claim' => sanitize_text_field($userinfo['name'] ?? ''),
            default => trim($givenName . ' ' . $familyName),
        };

        // Fallback chain
        if (empty($displayName) && (!empty($givenName) || !empty($familyName))) {
            $displayName = trim($givenName . ' ' . $familyName);
        }

        return !empty($displayName) ? $displayName : $username;
    }

    /**
     * Determine user role based on mapping rules
     *
     * @return string|null Role name, 'deny_login', or null (keep existing)
     */
    private function determineUserRole(array $userinfo, bool $isExistingUser): ?string {
        $defaultRole = Options::get('default_role') ?: 'subscriber';
        $denyUnmapped = (bool) Options::get('deny_unmapped_roles');

        if (!Options::get('enable_role_mapping')) {
            return $defaultRole;
        }

        // Keep existing roles for existing users if option is set
        if ($isExistingUser && Options::get('keep_existing_roles')) {
            return null;
        }

        $roleAttributes = Options::get('role_mapping_attribute');
        $roleRules = Options::get('role_mapping_rules');

        // If no attributes or rules configured, deny or use default
        if (empty($roleAttributes) || empty($roleRules)) {
            return $denyUnmapped ? 'deny_login' : $defaultRole;
        }

        // Support multiple attributes separated by semicolon
        $attributes = array_map('trim', explode(';', $roleAttributes));

        // Collect all role values from all specified attributes
        $allRoleValues = $this->collectRoleValues($userinfo, $attributes);

        // If no role values found in claims, deny or use default
        if (empty($allRoleValues)) {
            return $denyUnmapped ? 'deny_login' : $defaultRole;
        }

        // Check rules
        if (is_array($roleRules)) {
            foreach ($roleRules as $rule) {
                if (!is_array($rule) || empty($rule['claim_value']) || empty($rule['wp_role'])) {
                    continue;
                }

                $claimVal = trim($rule['claim_value']);
                $wpRole = trim($rule['wp_role']);

                if (in_array($claimVal, $allRoleValues, true)) {
                    return $wpRole;
                }
            }
        }

        // No matching rule found
        return $denyUnmapped ? 'deny_login' : $defaultRole;
    }

    /**
     * Collect all role values from specified attributes
     */
    private function collectRoleValues(array $userinfo, array $attributes): array {
        $allRoleValues = [];

        foreach ($attributes as $attr) {
            if (empty($attr)) {
                continue;
            }

            $roleValue = $this->getClaimValue($userinfo, $attr);
            if (!empty($roleValue)) {
                $allRoleValues[] = $roleValue;
            }

            // Also check for array values (e.g., roles array)
            if (isset($userinfo[$attr])) {
                $roleData = $userinfo[$attr];
                if (is_array($roleData)) {
                    // For Zitadel format: {"role_name": {"project_id": "org_id"}}
                    $allRoleValues = array_merge($allRoleValues, array_keys($roleData));
                } elseif (is_string($roleData) && !in_array($roleData, $allRoleValues, true)) {
                    $allRoleValues[] = $roleData;
                }
            }
        }

        return $allRoleValues;
    }

    /**
     * Apply custom attribute mappings to user meta
     *
     * @param int   $userId   WordPress user ID
     * @param array $userinfo Claims from OAuth provider
     */
    private function applyCustomAttributeMapping(int $userId, array $userinfo): void {
        $customMappings = Options::get('custom_attribute_mapping');
        
        if (!is_array($customMappings) || empty($customMappings)) {
            return;
        }

        foreach ($customMappings as $mapping) {
            if (!is_array($mapping) || empty($mapping['wp_meta_key']) || empty($mapping['oauth_claim'])) {
                continue;
            }

            $wpMetaKey = sanitize_key($mapping['wp_meta_key']);
            $oauthClaim = $mapping['oauth_claim'];

            // Try to get the value from the claim
            $value = $this->getCustomClaimValue($userinfo, $oauthClaim);

            if ($value !== null && $value !== '') {
                update_user_meta($userId, $wpMetaKey, sanitize_text_field($value));
            }
        }
    }

    /**
     * Get a custom claim value, checking both root claims and Zitadel metadata
     *
     * @param array  $userinfo   Claims from OAuth provider
     * @param string $claimName  Name of the claim to retrieve
     * @return string|null The claim value or null if not found
     */
    private function getCustomClaimValue(array $userinfo, string $claimName): ?string {
        // First, try to get from root level claims using dot notation
        $rootValue = $this->getClaimValue($userinfo, $claimName);
        if (!empty($rootValue)) {
            return $rootValue;
        }

        // Then check Zitadel metadata (urn:zitadel:iam:user:metadata)
        $zitadelMetadata = $userinfo['urn:zitadel:iam:user:metadata'] ?? null;
        
        if (is_array($zitadelMetadata) && isset($zitadelMetadata[$claimName])) {
            $metadataValue = $zitadelMetadata[$claimName];
            
            // Zitadel metadata values are Base64 encoded
            if (is_string($metadataValue)) {
                $decoded = base64_decode($metadataValue, strict: true);
                if ($decoded !== false) {
                    return $decoded;
                }
                // If not valid Base64, return raw value
                return $metadataValue;
            }
        }

        return null;
    }
}
