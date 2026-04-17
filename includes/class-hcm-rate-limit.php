<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_RateLimit
 *
 * Token-bucket style rate limiting using WP transients.
 * Defaults: 100 requests / 60 seconds per IP or user.
 *
 * Filters:
 *   hcm_rate_limit_enabled  (bool)   — disable completely
 *   hcm_rate_limit          (int)    — max requests per window
 *   hcm_rate_window         (int)    — window in seconds
 *   hcm_rate_limit_whitelist (array) — user IDs exempt from limiting
 */
class HCM_RateLimit {

    const DEFAULT_LIMIT  = 100;
    const DEFAULT_WINDOW = 60;

    /**
     * Call this at the start of write endpoints (add, checkout etc).
     * Sends a 429 and dies if limit is exceeded.
     */
    public static function check(\WP_REST_Request $req, string $action = 'default'): void {
        if (!apply_filters('hcm_rate_limit_enabled', true)) return;

        $user_id    = get_current_user_id();
        $whitelist  = (array) apply_filters('hcm_rate_limit_whitelist', []);

        if ($user_id && in_array($user_id, $whitelist, true)) return;

        $identifier = $user_id ? "user_{$user_id}" : 'ip_' . self::get_ip();
        $key        = 'hcm_rl_' . md5($identifier . '_' . $action);
        $limit      = (int) apply_filters('hcm_rate_limit', self::DEFAULT_LIMIT);
        $window     = (int) apply_filters('hcm_rate_window', self::DEFAULT_WINDOW);

        $current = get_transient($key);

        if ($current === false) {
            set_transient($key, 1, $window);
            return;
        }

        if ((int) $current >= $limit) {
            header('Retry-After: ' . $window);
            wp_send_json(
                [
                    'code'    => 'hcm_rate_limited',
                    'message' => "Rate limit exceeded. Max {$limit} requests per {$window}s. Try again later.",
                    'data'    => ['status' => 429, 'limit' => $limit, 'window' => $window],
                ],
                429
            );
            exit;
        }

        set_transient($key, (int) $current + 1, $window);
    }

    /** Returns remaining requests for the current client */
    public static function status(string $action = 'default'): array {
        $user_id    = get_current_user_id();
        $identifier = $user_id ? "user_{$user_id}" : 'ip_' . self::get_ip();
        $key        = 'hcm_rl_' . md5($identifier . '_' . $action);
        $limit      = (int) apply_filters('hcm_rate_limit', self::DEFAULT_LIMIT);
        $current    = (int) (get_transient($key) ?: 0);

        return [
            'limit'     => $limit,
            'remaining' => max(0, $limit - $current),
            'used'      => $current,
        ];
    }

    /** Best-effort real IP detection (supports Cloudflare, proxies) */
    private static function get_ip(): string {
        foreach ([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // Accept private IPs too (dev/local)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
