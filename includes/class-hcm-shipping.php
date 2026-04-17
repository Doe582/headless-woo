<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Shipping
 *
 * Calculates available shipping rates using WooCommerce Shipping Zones.
 * No WC session required — uses WC_Shipping_Zones directly.
 *
 * Endpoints:
 *   GET  /hcm/v1/cart/shipping        — get available methods for address
 *   POST /hcm/v1/cart/shipping/select — choose a method & save to cart
 *   GET  /hcm/v1/cart/shipping/zones  — list all shipping zones (admin)
 */
class HCM_Shipping {

    public function register_routes(): void {
        $ns = HCM_NS;

        register_rest_route($ns, '/cart/shipping', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_rates'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/cart/shipping/select', [
            'methods'             => 'POST',
            'callback'            => [$this, 'select'],
            'permission_callback' => '__return_true',
            'args' => [
                'method_id' => ['required' => true, 'type' => 'string'],
                'address'   => ['required' => false, 'type' => 'object'],
            ],
        ]);

        register_rest_route($ns, '/cart/shipping/zones', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_zones'],
            'permission_callback' => [HCM_JWT::class, 'require_admin'],
        ]);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function get_rates(\WP_REST_Request $req) {
        $address = $this->parse_address($req);
        $cart    = (new HCM_Cart())->load_session($req);
        $rates   = $this->calculate($address, $cart);

        // Check free shipping from coupons
        $has_free = false;
        foreach ($cart['coupons'] as $coupon) {
            if (!empty($coupon['free_shipping'])) { $has_free = true; break; }
        }

        return rest_ensure_response([
            'address'           => $address,
            'rates'             => $rates,
            'free_shipping_via_coupon' => $has_free,
            'currently_selected'=> $cart['selected_shipping'],
        ]);
    }

    public function select(\WP_REST_Request $req) {
        $method_id = sanitize_text_field($req->get_param('method_id'));
        $address   = $this->parse_address($req);
        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);
        $rates     = $this->calculate($address, $cart);

        // Find the requested rate
        $selected = null;
        foreach ($rates as $rate) {
            if ($rate['id'] === $method_id) { $selected = $rate; break; }
        }

        if (!$selected) {
            return new \WP_Error(
                'hcm_shipping_unavailable',
                __('Selected shipping method is not available for this address.', 'hcm'),
                ['status' => 400]
            );
        }

        $cart['selected_shipping'] = $selected;
        $cart['shipping_address']  = $address;
        $cart_ctrl->save_session($req, $cart);

        return rest_ensure_response([
            'message'  => __('Shipping method selected.', 'hcm'),
            'shipping' => $selected,
            'cart'     => $cart_ctrl->format_cart($cart),
        ]);
    }

    public function get_zones(\WP_REST_Request $req) {
        $zones = \WC_Shipping_Zones::get_zones();
        $out   = [];

        foreach ($zones as $zone_data) {
            $zone    = new \WC_Shipping_Zone($zone_data['zone_id']);
            $methods = [];
            foreach ($zone->get_shipping_methods(true) as $method) {
                $methods[] = [
                    'id'      => $method->id,
                    'title'   => $method->get_title(),
                    'enabled' => $method->is_enabled(),
                ];
            }
            $out[] = [
                'id'       => $zone_data['zone_id'],
                'name'     => $zone_data['zone_name'],
                'regions'  => $zone_data['zone_locations'],
                'methods'  => $methods,
            ];
        }

        return rest_ensure_response($out);
    }

    // ── Calculation Engine ────────────────────────────────────────────────────

    /**
     * Calculate shipping rates for a given address and cart.
     * Uses WC Shipping Zones exactly as WooCommerce does on the frontend.
     */
    public function calculate(array $address, array $cart): array {
        // Build a WC shipping package
        $package = $this->build_package($address, $cart);

        // Find matching zone
        $zone    = \WC_Shipping_Zones::get_zone_matching_package($package);
        $methods = $zone->get_shipping_methods(true);
        $rates   = [];

        foreach ($methods as $method) {
            if (!$method->is_enabled()) continue;

            // Feed the package to the method
            $method->calculate_shipping($package);

            foreach ($method->rates as $rate) {
                $cost     = (float) $rate->get_cost();
                $taxes    = $rate->get_taxes();
                $tax_cost = !empty($taxes) ? array_sum($taxes) : 0;

                $rates[] = [
                    'id'            => $rate->get_id(),
                    'method_id'     => $rate->get_method_id(),
                    'label'         => $rate->get_label(),
                    'cost'          => wc_format_decimal($cost, 2),
                    'cost_with_tax' => wc_format_decimal($cost + $tax_cost, 2),
                    'taxes'         => $taxes,
                    'meta_data'     => $rate->get_meta_data(),
                ];
            }
        }

        // Fallback: "Rest of the world" zone
        if (empty($rates)) {
            $rates = $this->get_rest_of_world_rates($package);
        }

        // Sort by cost ascending
        usort($rates, static fn($a, $b) => (float) $a['cost'] <=> (float) $b['cost']);

        return $rates;
    }

    private function build_package(array $address, array $cart): array {
        $contents = [];
        $cost     = 0.0;

        foreach ($cart['items'] as $key => $item) {
            $product = wc_get_product($item['variation_id'] ?: $item['product_id']);
            if (!$product) continue;

            $line_cost = (float) $product->get_price() * (int) $item['quantity'];
            $cost     += $line_cost;

            $contents[$key] = [
                'product_id'   => $item['product_id'],
                'variation_id' => $item['variation_id'],
                'quantity'     => $item['quantity'],
                'data'         => $product,
                'line_total'   => $line_cost,
            ];
        }

        return [
            'contents'        => $contents,
            'contents_cost'   => $cost,
            'applied_coupons' => array_keys($cart['coupons']),
            'user'            => ['ID' => get_current_user_id()],
            'destination'     => [
                'country'   => $address['country'],
                'state'     => $address['state'],
                'postcode'  => $address['postcode'],
                'city'      => $address['city'],
                'address'   => $address['address_1'] ?? '',
                'address_2' => $address['address_2'] ?? '',
            ],
        ];
    }

    private function get_rest_of_world_rates(array $package): array {
        $zone    = new \WC_Shipping_Zone(0); // Zone 0 = Rest of world
        $methods = $zone->get_shipping_methods(true);
        $rates   = [];

        foreach ($methods as $method) {
            if (!$method->is_enabled()) continue;
            $method->calculate_shipping($package);
            foreach ($method->rates as $rate) {
                $rates[] = [
                    'id'            => $rate->get_id(),
                    'method_id'     => $rate->get_method_id(),
                    'label'         => $rate->get_label(),
                    'cost'          => wc_format_decimal((float) $rate->get_cost(), 2),
                    'cost_with_tax' => wc_format_decimal((float) $rate->get_cost(), 2),
                    'taxes'         => [],
                    'meta_data'     => [],
                ];
            }
        }
        return $rates;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parse_address(\WP_REST_Request $req): array {
        $raw = $req->get_param('address') ?? [];

        // Fallback to cart's saved address if not provided
        if (empty($raw)) {
            $cart = (new HCM_Cart())->load_session($req);
            $raw  = $cart['shipping_address'] ?? [];
        }

        $default_country = explode(':', get_option('woocommerce_default_country', 'IN:GJ'));

        return [
            'country'   => sanitize_text_field($raw['country']   ?? ($default_country[0] ?? 'IN')),
            'state'     => sanitize_text_field($raw['state']     ?? ($default_country[1] ?? '')),
            'postcode'  => sanitize_text_field($raw['postcode']  ?? ''),
            'city'      => sanitize_text_field($raw['city']      ?? ''),
            'address_1' => sanitize_text_field($raw['address_1'] ?? ''),
            'address_2' => sanitize_text_field($raw['address_2'] ?? ''),
        ];
    }
}
