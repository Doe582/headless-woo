<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Sync
 * 
 * Synchronizes the custom headless cart table (wp_hcm_carts) 
 * with the native WooCommerce cart session.
 */
class HCM_Sync {

    /** @var bool Guard for recursion */
    private static $is_syncing = false;

    /**
     * Initialize synchronization hooks.
     */
    public static function init(): void {
        // Sync HCM -> WooCommerce Cart when a normal page loads
        add_action('woocommerce_cart_loaded_from_session', [self::class, 'sync_hcm_to_wc'], 20);

        // Sync WooCommerce Cart -> HCM when anything changes on the frontend
        add_action('woocommerce_add_to_cart',              [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_cart_item_removed',        [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_cart_item_restored',       [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_cart_item_set_quantity',   [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_applied_coupon',           [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_removed_coupon',           [self::class, 'sync_wc_to_hcm'], 20);
    }

    /**
     * Pull data from HCM table and push to WooCommerce native cart.
     */
    public static function sync_hcm_to_wc(): void {
        if (self::$is_syncing || !is_user_logged_in() || is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
            return;
        }

        self::$is_syncing = true;

        $user_id   = get_current_user_id();
        $cart_ctrl = new HCM_Cart();
        $hcm_cart  = $cart_ctrl->get_by_key("user_{$user_id}");

        if ($hcm_cart) {
            $wc_cart = WC()->cart;
            
            // 1. Sync Items (Add/Update and Remove missing)
            $hcm_item_ids = []; // Store WC cart item keys that should exist
            
            if (!empty($hcm_cart['items'])) {
                foreach ($hcm_cart['items'] as $item) {
                    $product_id   = (int) $item['product_id'];
                    $variation_id = (int) ($item['variation_id'] ?? 0);
                    $quantity     = (int) $item['quantity'];
                    $variation    = (array) ($item['variation'] ?? []);

                    // Fallback for variations missing their attribute data in the HCM table
                    if ($variation_id > 0 && empty($variation)) {
                        $v_obj = wc_get_product($variation_id);
                        if ($v_obj && $v_obj->is_type('variation')) {
                            $variation = $v_obj->get_variation_attributes();
                        }
                    }

                    $cart_id = $wc_cart->generate_cart_id($product_id, $variation_id, $variation);
                    $found   = $wc_cart->find_product_in_cart($cart_id);
                    $hcm_item_ids[] = $found ?: $cart_id;

                    if ($found) {
                        if ($wc_cart->get_cart()[$found]['quantity'] !== $quantity) {
                            $wc_cart->set_quantity($found, $quantity, false);
                        }
                    } else {
                        $wc_cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
                    }
                }
            }

            // Remove items from WC that are not in HCM
            foreach ($wc_cart->get_cart() as $cart_item_key => $values) {
                if (!in_array($cart_item_key, $hcm_item_ids, true)) {
                    $wc_cart->remove_cart_item($cart_item_key);
                }
            }

            // 2. Sync Coupons (Add and Remove missing)
            $hcm_coupon_codes = array_keys($hcm_cart['coupons'] ?? []);
            foreach ($hcm_coupon_codes as $code) {
                if (!$wc_cart->has_discount($code)) {
                    $wc_cart->apply_coupon($code);
                }
            }

            // Remove coupons that are not in HCM
            foreach ($wc_cart->get_applied_coupons() as $code) {
                if (!in_array($code, $hcm_coupon_codes, true)) {
                    $wc_cart->remove_coupon($code);
                }
            }
        }

        self::$is_syncing = false;
    }

    /**
     * Push data from WooCommerce native cart to HCM table.
     */
    public static function sync_wc_to_hcm(): void {
        if (self::$is_syncing || !is_user_logged_in() || is_admin()) {
            return;
        }

        self::$is_syncing = true;

        $user_id   = get_current_user_id();
        $wc_cart   = WC()->cart;
        
        if (!$wc_cart) {
            self::$is_syncing = false;
            return;
        }

        // Ensure totals are calculated before extracting discount amounts
        $wc_cart->calculate_totals();

        $cart_ctrl = new HCM_Cart();

        // --- Sync Items ---
        $new_items = [];
        foreach ($wc_cart->get_cart() as $cart_item_key => $values) {
            $product_id   = $values['product_id'];
            $variation_id = $values['variation_id'];
            $variation    = $values['variation'];
            $key = md5("{$product_id}_{$variation_id}_" . serialize($variation));
            
            $new_items[$key] = [
                'key'          => $key,
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $values['quantity'],
                'variation'    => $variation,
            ];
        }

        // --- Sync Coupons ---
        $new_coupons = [];
        foreach ($wc_cart->get_applied_coupons() as $code) {
            $coupon = new \WC_Coupon($code);
            $new_coupons[$code] = [
                'code'          => $code,
                'discount_type' => $coupon->get_discount_type(),
                'coupon_amount' => (float) $coupon->get_amount(),
                'discount'      => (float) $wc_cart->get_coupon_discount_amount($code),
                'free_shipping' => $coupon->get_free_shipping(),
                'description'   => wp_strip_all_tags($coupon->get_description()),
            ];
        }

        // Load existing HCM cart and update items/coupons
        $hcm_cart = $cart_ctrl->get_by_key("user_{$user_id}") ?? $cart_ctrl->empty_cart();
        $hcm_cart['items']   = $new_items;
        $hcm_cart['coupons'] = $new_coupons;
        
        $cart_ctrl->upsert("user_{$user_id}", $user_id, $hcm_cart);

        self::$is_syncing = false;
    }
}
