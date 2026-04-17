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

        // Sync WooCommerce Cart -> HCM when items are modified on the frontend
        add_action('woocommerce_add_to_cart',              [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_cart_item_removed',        [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_cart_item_restored',       [self::class, 'sync_wc_to_hcm'], 20);
        add_action('woocommerce_cart_item_set_quantity',   [self::class, 'sync_wc_to_hcm'], 20);
    }

    /**
     * Pull data from HCM table and push to WooCommerce native cart.
     * This ensures that items added via Headless API appear on the checkout page.
     */
    public static function sync_hcm_to_wc(): void {
        if (self::$is_syncing || !is_user_logged_in() || is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
            return;
        }

        self::$is_syncing = true;

        $user_id   = get_current_user_id();
        $cart_ctrl = new HCM_Cart();
        $hcm_cart  = $cart_ctrl->get_by_key("user_{$user_id}");

        if ($hcm_cart && !empty($hcm_cart['items'])) {
            $wc_cart = WC()->cart;
            
            // Loop through HCM items and ensure they are in WC cart
            foreach ($hcm_cart['items'] as $item) {
                $product_id   = (int) $item['product_id'];
                $variation_id = (int) ($item['variation_id'] ?? 0);
                $quantity     = (int) $item['quantity'];
                $variation    = (array) ($item['variation'] ?? []);

                $cart_id = $wc_cart->generate_cart_id($product_id, $variation_id, $variation);
                $found   = $wc_cart->find_product_in_cart($cart_id);

                if ($found) {
                    // Update quantity if different
                    if ($wc_cart->get_cart()[$found]['quantity'] !== $quantity) {
                        $wc_cart->set_quantity($found, $quantity, false);
                    }
                } else {
                    // Add to cart if missing
                    $wc_cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
                }
            }
        }

        self::$is_syncing = false;
    }

    /**
     * Push data from WooCommerce native cart to HCM table.
     * This ensures that items added via the website frontend appear in the Headless API.
     */
    public static function sync_wc_to_hcm(): void {
        if (self::$is_syncing || !is_user_logged_in() || is_admin()) {
            return;
        }

        self::$is_syncing = true;

        $user_id   = get_current_user_id();
        $wc_cart   = WC()->cart;
        $cart_ctrl = new HCM_Cart();
        
        if (!$wc_cart) {
            self::$is_syncing = false;
            return;
        }

        $new_items = [];
        foreach ($wc_cart->get_cart() as $cart_item_key => $values) {
            $product_id   = $values['product_id'];
            $variation_id = $values['variation_id'];
            $variation    = $values['variation'];
            
            // Generate same key format as HCM_Cart::add_item
            $key = md5("{$product_id}_{$variation_id}_" . serialize($variation));
            
            $new_items[$key] = [
                'key'          => $key,
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $values['quantity'],
                'variation'    => $variation,
            ];
        }

        // Load existing HCM cart to preserve other data (coupons, address, etc.)
        $hcm_cart = $cart_ctrl->get_by_key("user_{$user_id}") ?? $cart_ctrl->empty_cart();
        $hcm_cart['items'] = $new_items;
        
        // Save to DB
        $cart_ctrl->upsert("user_{$user_id}", $user_id, $hcm_cart);

        self::$is_syncing = false;
    }
}
