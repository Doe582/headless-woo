<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Coupon
 *
 * Full coupon management using WooCommerce's own coupon validation engine.
 * Supports: percent, fixed_cart, fixed_product discount types.
 * Validates: expiry, usage limit, per-user limit, minimum/maximum spend,
 *            product restrictions, category restrictions, individual-use.
 *
 * Endpoints:
 *   POST   /hcm/v1/cart/coupon        — apply coupon
 *   DELETE /hcm/v1/cart/coupon/{code} — remove coupon
 *   GET    /hcm/v1/cart/coupons       — list applied coupons
 */
class HCM_Coupon {

    public function register_routes(): void {
        $ns = HCM_NS;

        register_rest_route($ns, '/cart/coupon', [
            'methods'             => 'POST',
            'callback'            => [$this, 'apply'],
            'permission_callback' => '__return_true',
            'args' => [
                'code' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => static fn($v) => strtolower(sanitize_text_field($v)),
                ],
            ],
        ]);

        register_rest_route($ns, '/cart/coupon/(?P<code>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'remove'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/cart/coupons', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_coupons'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function apply(\WP_REST_Request $req) {
        $code      = $req->get_param('code');
        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);

        // Already applied?
        if (isset($cart['coupons'][$code])) {
            return new \WP_Error('hcm_coupon_duplicate', __('Coupon already applied.', 'hcm'), ['status' => 400]);
        }

        // Validate coupon via WC
        $coupon = new \WC_Coupon($code);
        $error  = $this->validate($coupon, $cart, get_current_user_id());
        if (is_wp_error($error)) return $error;

        // Individual use — remove others
        if ($coupon->get_individual_use()) {
            $cart['coupons'] = [];
        }

        // Calculate discount
        $discount = $this->calculate_discount($coupon, $cart);

        $cart['coupons'][$code] = [
            'code'          => $code,
            'discount_type' => $coupon->get_discount_type(),
            'coupon_amount' => (float) $coupon->get_amount(),
            'discount'      => $discount,
            'free_shipping' => $coupon->get_free_shipping(),
            'description'   => wp_strip_all_tags($coupon->get_description()),
        ];

        $cart_ctrl->save_session($req, $cart);

        return rest_ensure_response([
            'message'  => __('Coupon applied successfully.', 'hcm'),
            'coupon'   => $cart['coupons'][$code],
            'cart'     => $cart_ctrl->format_cart($cart),
        ]);
    }

    public function remove(\WP_REST_Request $req) {
        $code      = strtolower(sanitize_text_field($req->get_param('code')));
        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);

        if (!isset($cart['coupons'][$code])) {
            return new \WP_Error('hcm_coupon_not_applied', __('Coupon is not applied to this cart.', 'hcm'), ['status' => 404]);
        }

        unset($cart['coupons'][$code]);
        $cart_ctrl->save_session($req, $cart);

        return rest_ensure_response([
            'message' => __('Coupon removed.', 'hcm'),
            'cart'    => $cart_ctrl->format_cart($cart),
        ]);
    }

    public function list_coupons(\WP_REST_Request $req) {
        $cart = (new HCM_Cart())->load_session($req);
        return rest_ensure_response(array_values($cart['coupons']));
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Full WooCommerce-compatible coupon validation.
     * Returns WP_Error on failure, true on success.
     */
    public function validate(\WC_Coupon $coupon, array $cart, int $user_id = 0) {
        // Coupon exists?
        if (!$coupon->get_id()) {
            return new \WP_Error('hcm_coupon_invalid', __('Coupon code not found.', 'hcm'), ['status' => 400]);
        }

        // Expired?
        $expiry = $coupon->get_date_expires();
        if ($expiry && $expiry->getTimestamp() < time()) {
            return new \WP_Error('hcm_coupon_expired', __('This coupon has expired.', 'hcm'), ['status' => 400]);
        }

        // Global usage limit
        $usage_limit = $coupon->get_usage_limit();
        if ($usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit) {
            return new \WP_Error('hcm_coupon_exhausted', __('This coupon has reached its usage limit.', 'hcm'), ['status' => 400]);
        }

        // Per-user limit
        if ($user_id) {
            $per_user = $coupon->get_usage_limit_per_user();
            if ($per_user > 0) {
                $used_by = $coupon->get_used_by();
                $count   = count(array_keys($used_by, (string) $user_id, true));
                if ($count >= $per_user) {
                    return new \WP_Error('hcm_coupon_user_limit', __('You have already used this coupon.', 'hcm'), ['status' => 400]);
                }
            }
        }

        // Minimum / maximum spend
        $subtotal = $this->get_cart_subtotal($cart);
        $min      = (float) $coupon->get_minimum_amount();
        $max      = (float) $coupon->get_maximum_amount();

        if ($min > 0 && $subtotal < $min) {
            return new \WP_Error(
                'hcm_coupon_min_spend',
                sprintf(__('Minimum spend of %s required for this coupon.', 'hcm'), wc_price($min)),
                ['status' => 400]
            );
        }
        if ($max > 0 && $subtotal > $max) {
            return new \WP_Error(
                'hcm_coupon_max_spend',
                sprintf(__('Maximum spend of %s allowed for this coupon.', 'hcm'), wc_price($max)),
                ['status' => 400]
            );
        }

        // Product/category restrictions
        if (!$this->cart_has_eligible_products($coupon, $cart)) {
            return new \WP_Error('hcm_coupon_no_eligible_products', __('No products in your cart are eligible for this coupon.', 'hcm'), ['status' => 400]);
        }

        // Excluded products
        if ($this->cart_has_excluded_products($coupon, $cart)) {
            return new \WP_Error('hcm_coupon_excluded_products', __('Your cart contains products excluded from this coupon.', 'hcm'), ['status' => 400]);
        }

        return true;
    }

    // ── Discount Calculation ──────────────────────────────────────────────────

    public function calculate_discount(\WC_Coupon $coupon, array $cart): float {
        $type   = $coupon->get_discount_type();
        $amount = (float) $coupon->get_amount();

        $eligible_subtotal = $this->get_eligible_subtotal($coupon, $cart);
        $total_qty         = array_sum(array_column($cart['items'], 'quantity'));

        $discount = match ($type) {
            'percent'       => round($eligible_subtotal * $amount / 100, wc_get_price_decimals()),
            'fixed_cart'    => min($amount, $eligible_subtotal),
            'fixed_product' => min($amount * $total_qty, $eligible_subtotal),
            default         => 0.0,
        };

        return max(0.0, $discount);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function get_cart_subtotal(array $cart): float {
        $subtotal = 0.0;
        foreach ($cart['items'] as $item) {
            $p = wc_get_product($item['variation_id'] ?: $item['product_id']);
            if ($p) $subtotal += (float) $p->get_price() * (int) $item['quantity'];
        }
        return $subtotal;
    }

    private function get_eligible_subtotal(\WC_Coupon $coupon, array $cart): float {
        $subtotal    = 0.0;
        $product_ids = $coupon->get_product_ids();
        $cat_ids     = $coupon->get_product_categories();

        foreach ($cart['items'] as $item) {
            $p = wc_get_product($item['variation_id'] ?: $item['product_id']);
            if (!$p) continue;
            if ($this->is_product_eligible($coupon, $item['product_id'])) {
                $subtotal += (float) $p->get_price() * (int) $item['quantity'];
            }
        }
        return $subtotal;
    }

    private function is_product_eligible(\WC_Coupon $coupon, int $product_id): bool {
        $allowed_ids = $coupon->get_product_ids();
        $cat_ids     = $coupon->get_product_categories();

        // No restrictions → all products eligible
        if (empty($allowed_ids) && empty($cat_ids)) return true;

        if ($allowed_ids && in_array($product_id, $allowed_ids, true)) return true;

        if ($cat_ids) {
            $product_cats = wc_get_product_term_ids($product_id, 'product_cat');
            if (array_intersect($cat_ids, $product_cats)) return true;
        }

        return false;
    }

    private function cart_has_eligible_products(\WC_Coupon $coupon, array $cart): bool {
        $allowed_ids = $coupon->get_product_ids();
        $cat_ids     = $coupon->get_product_categories();

        if (empty($allowed_ids) && empty($cat_ids)) return !empty($cart['items']);

        foreach ($cart['items'] as $item) {
            if ($this->is_product_eligible($coupon, $item['product_id'])) return true;
        }
        return false;
    }

    private function cart_has_excluded_products(\WC_Coupon $coupon, array $cart): bool {
        $excluded     = $coupon->get_excluded_product_ids();
        $excluded_cats = $coupon->get_excluded_product_categories();

        if (empty($excluded) && empty($excluded_cats)) return false;

        foreach ($cart['items'] as $item) {
            if ($excluded && in_array($item['product_id'], $excluded, true)) return true;

            if ($excluded_cats) {
                $cats = wc_get_product_term_ids($item['product_id'], 'product_cat');
                if (array_intersect($excluded_cats, $cats)) return true;
            }
        }
        return false;
    }
}
