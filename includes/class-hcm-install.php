<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Install
 * Creates and upgrades the plugin database table.
 *
 * Table: wp_hcm_carts
 *   cart_key  — 'user_{id}' for logged-in users, 'guest_{uuid}' for guests
 *   user_id   — 0 for guests
 *   cart_data — full cart JSON (items, coupons, fees, shipping)
 *   expires_at — auto-cleanup support
 */
class HCM_Install {

    const DB_VER_KEY = 'hcm_db_version';
    const DB_VER     = '1.0';

    public static function activate(): void {
        self::create_tables();
        update_option(self::DB_VER_KEY, self::DB_VER);
        self::schedule_cleanup();
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hcm_carts (
            id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            cart_key   VARCHAR(64)      NOT NULL COMMENT 'user_{id} or guest_{uuid}',
            user_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            cart_data  LONGTEXT         NOT NULL COMMENT 'JSON payload',
            expires_at DATETIME         NOT NULL,
            created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY  uq_cart_key  (cart_key),
            KEY         idx_user_id  (user_id),
            KEY         idx_expires  (expires_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /** Remove expired carts daily */
    private static function schedule_cleanup(): void {
        if (!wp_next_scheduled('hcm_cleanup_carts')) {
            wp_schedule_event(time(), 'daily', 'hcm_cleanup_carts');
        }
    }

    public static function setup_cleanup_hook(): void {
        add_action('hcm_cleanup_carts', static function () {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->prefix}hcm_carts WHERE expires_at < NOW()");
        });
    }
}

// Register cleanup hook
HCM_Install::setup_cleanup_hook();
