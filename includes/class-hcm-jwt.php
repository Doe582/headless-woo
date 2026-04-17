<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_JWT
 *
 * Minimal HS256 JWT implementation — zero external dependencies.
 * Uses WordPress AUTH_KEY as the HMAC secret.
 *
 * Endpoints:
 *   POST /hcm/v1/auth/login    — returns JWT token
 *   POST /hcm/v1/auth/register — create account + token
 *   GET  /hcm/v1/auth/me       — current user info
 *   POST /hcm/v1/auth/guest    — get guest cart_key
 *   POST /hcm/v1/auth/refresh  — refresh expiring token
 */
class HCM_JWT {

    // ── Low-level JWT ──────────────────────────────────────────────────────────

    private static function b64_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64_decode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private static function secret(): string {
        return defined('AUTH_KEY') && strlen(AUTH_KEY) > 12
            ? AUTH_KEY
            : 'hcm-insecure-default-please-set-auth-key-in-wp-config';
    }

    /** Encode a payload array into a signed JWT string */
    public static function encode(array $payload): string {
        $header  = self::b64_encode(wp_json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = self::b64_encode(wp_json_encode($payload));
        $sig     = self::b64_encode(hash_hmac('sha256', "$header.$payload", self::secret(), true));
        return "$header.$payload.$sig";
    }

    /** Decode and verify a JWT. Returns payload array or null on failure. */
    public static function decode(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $sig] = $parts;
        $expected = self::b64_encode(hash_hmac('sha256', "$header.$payload", self::secret(), true));

        // Constant-time comparison prevents timing attacks
        if (!hash_equals($expected, $sig)) return null;

        $data = json_decode(self::b64_decode($payload), true);
        if (!is_array($data)) return null;

        // Check expiry
        if (isset($data['exp']) && $data['exp'] < time()) return null;

        return $data;
    }

    /** Generate a full token response for a WP user */
    public static function generate(int $user_id): array {
        $user = get_user_by('id', $user_id);
        $ttl  = (int) apply_filters('hcm_token_ttl', 30 * DAY_IN_SECONDS);
        $exp  = time() + $ttl;

        $token = self::encode([
            'iss'  => get_bloginfo('url'),
            'iat'  => time(),
            'exp'  => $exp,
            'sub'  => $user_id,
            'data' => [
                'user_id'    => $user_id,
                'user_email' => $user->user_email,
                'roles'      => $user->roles,
            ],
        ]);

        return [
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_at' => gmdate('c', $exp),
            'user'       => [
                'id'         => $user_id,
                'username'   => $user->user_login,
                'email'      => $user->user_email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'roles'      => $user->roles,
            ],
        ];
    }

    // ── WordPress Auth Integration ─────────────────────────────────────────────

    /**
     * @filter determine_current_user (priority 20)
     * Reads Authorization: Bearer <token> header and sets the WP current user.
     */
    public static function authenticate($user_id) {
        $token = self::get_bearer_token();
        if (!$token) return $user_id; // No token → let WP handle normally

        $payload = self::decode($token);
        if (!$payload || empty($payload['sub'])) {
            return false; // Invalid token → auth fails
        }

        return (int) $payload['sub'];
    }

    /**
     * @filter rest_authentication_errors
     * Clears errors for valid JWT so other auth methods don't interfere.
     */
    public static function auth_errors($result) {
        if (!empty($result)) return $result;
        if (!self::get_bearer_token()) return $result;
        return null;
    }

    /** Extract Bearer token from Authorization header */
    public static function get_bearer_token(): ?string {
        $auth = $_SERVER['HTTP_AUTHORIZATION']
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
             ?? '';

        if ($auth && preg_match('/Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    // ── REST Route Registration ────────────────────────────────────────────────

    public static function register_routes(): void {
        $ns = HCM_NS;

        register_rest_route($ns, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'login'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_user'],
                'password' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route($ns, '/auth/register', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'register'],
            'permission_callback' => '__return_true',
            'args' => [
                'username'   => ['required' => true,  'type' => 'string'],
                'email'      => ['required' => true,  'type' => 'string', 'format' => 'email'],
                'password'   => ['required' => true,  'type' => 'string'],
                'first_name' => ['required' => false, 'type' => 'string', 'default' => ''],
                'last_name'  => ['required' => false, 'type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route($ns, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'me'],
            'permission_callback' => [self::class, 'require_auth'],
        ]);

        register_rest_route($ns, '/auth/refresh', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'refresh'],
            'permission_callback' => [self::class, 'require_auth'],
        ]);

        register_rest_route($ns, '/auth/guest', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'guest_key'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ── Endpoint Handlers ─────────────────────────────────────────────────────

    public static function login(\WP_REST_Request $req) {
        $user = wp_authenticate(
            $req->get_param('username'),
            $req->get_param('password')
        );

        if (is_wp_error($user)) {
            return new \WP_Error(
                'hcm_invalid_credentials',
                __('Invalid username or password.', 'hcm'),
                ['status' => 401]
            );
        }

        do_action('hcm_user_logged_in', $user->ID);
        return rest_ensure_response(self::generate($user->ID));
    }

    public static function register(\WP_REST_Request $req) {
        if (!get_option('users_can_register')) {
            return new \WP_Error(
                'hcm_registration_disabled',
                __('User registration is disabled.', 'hcm'),
                ['status' => 403]
            );
        }

        $email    = sanitize_email($req->get_param('email'));
        $username = sanitize_user($req->get_param('username'));
        $password = $req->get_param('password');

        // WooCommerce customer creation (fires all WC hooks)
        $user_id = wc_create_new_customer($email, $username, $password, [
            'first_name' => sanitize_text_field($req->get_param('first_name')),
            'last_name'  => sanitize_text_field($req->get_param('last_name')),
        ]);

        if (is_wp_error($user_id)) {
            $user_id->add_data(['status' => 400]);
            return $user_id;
        }

        return rest_ensure_response(self::generate($user_id));
    }

    public static function me(\WP_REST_Request $req) {
        $user = wp_get_current_user();
        return rest_ensure_response([
            'id'           => $user->ID,
            'username'     => $user->user_login,
            'email'        => $user->user_email,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'display_name' => $user->display_name,
            'roles'        => $user->roles,
            'avatar'       => get_avatar_url($user->ID),
        ]);
    }

    public static function refresh(\WP_REST_Request $req) {
        return rest_ensure_response(self::generate(get_current_user_id()));
    }

    public static function guest_key(\WP_REST_Request $req) {
        return rest_ensure_response([
            'cart_key' => 'guest_' . wp_generate_uuid4(),
            'note'     => 'Send this as X-Cart-Key header with every cart request.',
        ]);
    }

    // ── Permission Helpers ────────────────────────────────────────────────────

    public static function require_auth() {
        if (is_user_logged_in()) return true;
        return new \WP_Error(
            'hcm_unauthorized',
            __('Authentication required. Send Bearer token in Authorization header.', 'hcm'),
            ['status' => 401]
        );
    }

    public static function require_admin() {
        if (current_user_can('manage_woocommerce')) return true;
        return new \WP_Error(
            'hcm_forbidden',
            __('Admin access required.', 'hcm'),
            ['status' => 403]
        );
    }
}
