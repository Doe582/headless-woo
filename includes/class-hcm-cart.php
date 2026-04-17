<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Cart
 *
 * Core cart engine. Stores cart as JSON in wp_hcm_carts.
 * Resolves session by:
 *   - Logged-in user → cart_key = "user_{id}"
 *   - Guest          → cart_key from X-Cart-Key header or ?cart_key param
 *
 * Endpoints:
 *   GET    /hcm/v1/cart                 — get cart
 *   POST   /hcm/v1/cart/add            — add item
 *   PUT    /hcm/v1/cart/item/{key}     — update quantity
 *   DELETE /hcm/v1/cart/item/{key}     — remove item
 *   DELETE /hcm/v1/cart/clear          — clear all
 *   POST   /hcm/v1/cart/transfer       — guest → user merge
 */
class HCM_Cart {

    // ── Route Registration ────────────────────────────────────────────────────

    public function register_routes(): void {
        $ns = HCM_NS;

        register_rest_route($ns, '/cart', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_cart'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/cart/add', [
            'methods'             => 'POST',
            'callback'            => [$this, 'add_item'],
            'permission_callback' => '__return_true',
            'args'                => $this->item_args(),
        ]);

        register_rest_route($ns, '/cart/item/(?P<key>[a-f0-9]{32})', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => '__return_true',
                'args' => [
                    'quantity' => ['required' => true, 'type' => 'integer', 'minimum' => 0],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'remove_item'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route($ns, '/cart/clear', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'clear_cart'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/cart/transfer', [
            'methods'             => 'POST',
            'callback'            => [$this, 'transfer_cart'],
            'permission_callback' => [HCM_JWT::class, 'require_auth'],
            'args' => [
                'cart_key' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function get_cart(\WP_REST_Request $req) {
        $cart = $this->load_session($req);
        return rest_ensure_response($this->format_cart($cart));
    }

    public function add_item(\WP_REST_Request $req) {
        HCM_RateLimit::check($req, 'add_item');

        $product_id   = (int) $req->get_param('product_id');
        $variation_id = (int) ($req->get_param('variation_id') ?? 0);
        $quantity     = max(1, (int) ($req->get_param('quantity') ?? 1));
        $variation    = (array) ($req->get_param('variation') ?? []);

        // Validate product
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return new \WP_Error('hcm_product_not_found', __('Product not found.', 'hcm'), ['status' => 404]);
        }
        if (!$product->is_purchasable()) {
            return new \WP_Error('hcm_not_purchasable', __('This product cannot be purchased.', 'hcm'), ['status' => 400]);
        }
        if (!$product->is_in_stock()) {
            return new \WP_Error('hcm_out_of_stock', __('Product is out of stock.', 'hcm'), ['status' => 400]);
        }

        // Load cart & build item key
        $cart = $this->load_session($req);
        $key  = md5("{$product_id}_{$variation_id}_" . serialize($variation));

        if (isset($cart['items'][$key])) {
            $cart['items'][$key]['quantity'] += $quantity;
        } else {
            $cart['items'][$key] = compact('key', 'product_id', 'variation_id', 'quantity', 'variation');
        }

        // Enforce stock / max purchase
        $max = $product->get_max_purchase_quantity();
        if ($max > 0 && $cart['items'][$key]['quantity'] > $max) {
            $cart['items'][$key]['quantity'] = $max;
        }

        $this->save_session($req, $cart);
        return rest_ensure_response($this->format_cart($cart));
    }

    public function update_item(\WP_REST_Request $req) {
        $key      = $req->get_param('key');
        $quantity = (int) $req->get_param('quantity');
        $cart     = $this->load_session($req);

        if (!isset($cart['items'][$key])) {
            return new \WP_Error('hcm_item_not_found', __('Item not found in cart.', 'hcm'), ['status' => 404]);
        }

        if ($quantity <= 0) {
            unset($cart['items'][$key]);
        } else {
            $cart['items'][$key]['quantity'] = $quantity;
        }

        $this->save_session($req, $cart);
        return rest_ensure_response($this->format_cart($cart));
    }

    public function remove_item(\WP_REST_Request $req) {
        $key  = $req->get_param('key');
        $cart = $this->load_session($req);

        if (!isset($cart['items'][$key])) {
            return new \WP_Error('hcm_item_not_found', __('Item not found in cart.', 'hcm'), ['status' => 404]);
        }

        unset($cart['items'][$key]);
        $this->save_session($req, $cart);
        return rest_ensure_response($this->format_cart($cart));
    }

    public function clear_cart(\WP_REST_Request $req) {
        $empty = $this->empty_cart();
        $this->save_session($req, $empty);
        return rest_ensure_response(['message' => __('Cart cleared.', 'hcm'), 'cart' => $this->format_cart($empty)]);
    }

    /**
     * Transfer guest cart → logged-in user cart.
     * Items are merged (quantities added for duplicate products).
     */
    public function transfer_cart(\WP_REST_Request $req) {
        global $wpdb;
        $table     = $wpdb->prefix . 'hcm_carts';
        $guest_key = sanitize_text_field($req->get_param('cart_key'));
        $user_id   = get_current_user_id();
        $user_key  = "user_{$user_id}";

        $guest_row = $wpdb->get_row(
            $wpdb->prepare("SELECT cart_data FROM $table WHERE cart_key = %s", $guest_key)
        );

        if (!$guest_row) {
            return new \WP_Error('hcm_cart_not_found', __('Guest cart not found.', 'hcm'), ['status' => 404]);
        }

        $guest_cart = json_decode($guest_row->cart_data, true) ?: $this->empty_cart();
        $user_cart  = $this->get_by_key($user_key) ?? $this->empty_cart();

        // Merge items
        foreach ($guest_cart['items'] as $key => $item) {
            if (isset($user_cart['items'][$key])) {
                $user_cart['items'][$key]['quantity'] += $item['quantity'];
            } else {
                $user_cart['items'][$key] = $item;
            }
        }

        // Merge coupons (guest coupons added if not already present)
        foreach ($guest_cart['coupons'] as $code => $data) {
            if (!isset($user_cart['coupons'][$code])) {
                $user_cart['coupons'][$code] = $data;
            }
        }

        $this->upsert($user_key, $user_id, $user_cart);
        $wpdb->delete($table, ['cart_key' => $guest_key]); // clean up guest cart

        return rest_ensure_response([
            'message' => __('Cart transferred successfully.', 'hcm'),
            'cart'    => $this->format_cart($user_cart),
        ]);
    }

    // ── Session Helpers (public so other classes can use them) ────────────────

    /** Determine cart_key from request context */
    public function resolve_key(\WP_REST_Request $req): string {
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }

        $key = $req->get_header('x_cart_key')
            ?? $req->get_param('cart_key')
            ?? '';

        return sanitize_text_field($key);
    }

    /** Load cart data for this request */
    public function load_session(\WP_REST_Request $req): array {
        $key = $this->resolve_key($req);
        if (!$key) return $this->empty_cart();
        return $this->get_by_key($key) ?? $this->empty_cart();
    }

    /** Save cart data for this request */
    public function save_session(\WP_REST_Request $req, array $cart): void {
        $key = $this->resolve_key($req);
        if (!$key) return;
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $this->upsert($key, $user_id, $cart);
    }

    // ── DB Helpers ────────────────────────────────────────────────────────────

    public function get_by_key(string $key): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cart_data FROM {$wpdb->prefix}hcm_carts WHERE cart_key = %s AND expires_at > NOW()",
            $key
        ));
        if (!$row) return null;
        $data = json_decode($row->cart_data, true);
        return is_array($data) ? $data : null;
    }

    public function upsert(string $key, int $user_id, array $cart): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'hcm_carts';
        $ttl     = (int) apply_filters('hcm_cart_ttl', 30 * DAY_IN_SECONDS);
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
        $json    = wp_json_encode($cart);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (cart_key, user_id, cart_data, expires_at)
             VALUES (%s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
               user_id    = VALUES(user_id),
               cart_data  = VALUES(cart_data),
               expires_at = VALUES(expires_at)",
            $key, $user_id, $json, $expires
        ));
    }

    /** Default empty cart structure */
    public function empty_cart(): array {
        return [
            'items'            => [],
            'coupons'          => [],
            'fees'             => [],
            'selected_shipping'=> null,
            'shipping_address' => [],
        ];
    }

    // ── Response Formatting ───────────────────────────────────────────────────

    public function format_cart(array $cart): array {
        $formatted_items = [];
        $subtotal        = 0.0;
        $discount        = 0.0;

        // Format items
        foreach ($cart['items'] as $key => $item) {
            $pid     = (int) $item['product_id'];
            $vid     = (int) $item['variation_id'];
            $product = wc_get_product($vid ?: $pid);
            if (!$product) continue;

            $price      = (float) $product->get_price();
            $line       = $price * (int) $item['quantity'];
            $subtotal  += $line;

            $image_id       = $product->get_image_id();
            $formatted_items[] = [
                'key'              => $key,
                'product_id'       => $pid,
                'variation_id'     => $vid,
                'name'             => $product->get_name(),
                'sku'              => $product->get_sku(),
                'quantity'         => (int) $item['quantity'],
                'price'            => wc_format_decimal($price, 2),
                'regular_price'    => wc_format_decimal((float) $product->get_regular_price(), 2),
                'sale_price'       => wc_format_decimal((float) $product->get_sale_price(), 2),
                'line_total'       => wc_format_decimal($line, 2),
                'image'            => $image_id
                    ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail')
                    : wc_placeholder_img_src(),
                'permalink'        => get_permalink($pid),
                'stock_status'     => $product->get_stock_status(),
                'stock_quantity'   => $product->get_stock_quantity(),
                'variation'        => $item['variation'],
                'variation_data'   => $vid ? $this->get_variation_data($vid) : [],
            ];
        }

        // Sum coupon discounts
        foreach ($cart['coupons'] as $data) {
            $discount += (float) ($data['discount'] ?? 0);
        }

        // Sum fees
        $fees_total = array_sum(array_column($cart['fees'], 'amount'));

        // Shipping
        $shipping_cost = (float) ($cart['selected_shipping']['cost'] ?? 0);
        $free_ship     = $this->has_free_shipping_coupon($cart);
        if ($free_ship) $shipping_cost = 0;

        $total = max(0.0, $subtotal - $discount + $fees_total + $shipping_cost);

        return [
            'items'           => $formatted_items,
            'item_count'      => array_sum(array_column($formatted_items, 'quantity')),
            'unique_products' => count($formatted_items),
            'coupons'         => array_values($cart['coupons']),
            'fees'            => array_values($cart['fees']),
            'shipping'        => $cart['selected_shipping'],
            'shipping_address'=> $cart['shipping_address'],
            'totals'          => [
                'subtotal'        => wc_format_decimal($subtotal, 2),
                'discount'        => wc_format_decimal($discount, 2),
                'fees'            => wc_format_decimal($fees_total, 2),
                'shipping'        => wc_format_decimal($shipping_cost, 2),
                'total'           => wc_format_decimal($total, 2),
                'currency'        => get_woocommerce_currency(),
                'currency_symbol' => html_entity_decode(get_woocommerce_currency_symbol()),
                'currency_pos'    => get_option('woocommerce_currency_pos'),
                'decimals'        => wc_get_price_decimals(),
            ],
        ];
    }

    private function get_variation_data(int $variation_id): array {
        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) return [];
        return $variation->get_variation_attributes();
    }

    private function has_free_shipping_coupon(array $cart): bool {
        foreach ($cart['coupons'] as $data) {
            if (!empty($data['free_shipping'])) return true;
        }
        return false;
    }

    // ── Arg Definitions ───────────────────────────────────────────────────────

    private function item_args(): array {
        return [
            'product_id'   => ['required' => true,  'type' => 'integer', 'minimum' => 1],
            'variation_id' => ['required' => false, 'type' => 'integer', 'default' => 0],
            'quantity'     => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1],
            'variation'    => ['required' => false, 'type' => 'object',  'default' => []],
        ];
    }
}
