<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Checkout
 *
 * Converts the headless cart into a real WooCommerce order.
 * Supports all WC payment gateways, billing/shipping addresses,
 * order notes, coupons, fees, and shipping costs.
 *
 * Endpoints:
 *   GET  /hcm/v1/checkout/payment-methods — available payment gateways
 *   POST /hcm/v1/checkout                  — place order, returns order + pay URL
 *   GET  /hcm/v1/order/{id}               — order details (authenticated owner)
 *   GET  /hcm/v1/orders                   — order history (authenticated)
 */
class HCM_Checkout {

    public function register_routes(): void {
        $ns = HCM_NS;

        register_rest_route($ns, '/checkout/payment-methods', [
            'methods'             => 'GET',
            'callback'            => [$this, 'payment_methods'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/checkout', [
            'methods'             => 'POST',
            'callback'            => [$this, 'place_order'],
            'permission_callback' => '__return_true',
            'args'                => $this->checkout_args(),
        ]);

        register_rest_route($ns, '/order/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_order'],
            'permission_callback' => [HCM_JWT::class, 'require_auth'],
        ]);

        register_rest_route($ns, '/orders', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_orders'],
            'permission_callback' => [HCM_JWT::class, 'require_auth'],
            'args' => [
                'page'     => ['type' => 'integer', 'default' => 1],
                'per_page' => ['type' => 'integer', 'default' => 10],
                'status'   => ['type' => 'string',  'default' => 'any'],
            ],
        ]);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function payment_methods(\WP_REST_Request $req) {
        // Ensure payment gateways are loaded
        WC()->payment_gateways()->init();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $out      = [];

        foreach ($gateways as $id => $gateway) {
            $out[] = [
                'id'          => $id,
                'title'       => $gateway->get_title(),
                'description' => wp_strip_all_tags($gateway->get_description() ?? ''),
                'icon'        => $gateway->get_icon() ? wp_strip_all_tags($gateway->get_icon()) : '',
                'supports'    => $gateway->supports,
            ];
        }

        return rest_ensure_response($out);
    }

    public function place_order(\WP_REST_Request $req) {
        HCM_RateLimit::check($req, 'checkout');

        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);

        // Validate cart is not empty
        if (empty($cart['items'])) {
            return new \WP_Error('hcm_empty_cart', __('Your cart is empty.', 'hcm'), ['status' => 400]);
        }

        $billing        = (array) ($req->get_param('billing')  ?? []);
        $shipping       = (array) ($req->get_param('shipping') ?? $billing);
        $payment_method = sanitize_text_field($req->get_param('payment_method') ?? '');
        $order_note     = sanitize_textarea_field($req->get_param('order_note') ?? '');
        $meta_data      = (array) ($req->get_param('meta_data') ?? []);

        // Validate required billing fields
        $error = $this->validate_billing($billing);
        if (is_wp_error($error)) return $error;

        // Validate payment method
        WC()->payment_gateways()->init();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if (!isset($gateways[$payment_method])) {
            return new \WP_Error(
                'hcm_invalid_payment',
                __('Selected payment method is not available.', 'hcm'),
                ['status' => 400]
            );
        }

        // ── Create the order ─────────────────────────────────────────────────
        $order = wc_create_order([
            'customer_id' => is_user_logged_in() ? get_current_user_id() : 0,
            'status'      => apply_filters('hcm_initial_order_status', 'pending'),
        ]);

        if (is_wp_error($order)) {
            $order->add_data(['status' => 500]);
            return $order;
        }

        // Add line items
        foreach ($cart['items'] as $item) {
            $product = wc_get_product($item['variation_id'] ?: $item['product_id']);
            if (!$product) continue;

            $order->add_product($product, (int) $item['quantity'], [
                'variation' => $item['variation'] ?? [],
            ]);
        }

        // Set addresses
        $order->set_address($this->sanitize_address($billing),  'billing');
        $order->set_address($this->sanitize_address($shipping), 'shipping');

        // Apply coupons
        foreach (array_keys($cart['coupons']) as $code) {
            $order->apply_coupon(wc_sanitize_coupon_code($code));
        }

        // Add fees
        foreach ($cart['fees'] as $fee) {
            $fee_item = new \WC_Order_Item_Fee();
            $fee_item->set_name($fee['name']);
            $fee_item->set_amount((float) $fee['amount']);
            $fee_item->set_total((float) $fee['amount']);
            $fee_item->set_tax_status($fee['taxable'] ? 'taxable' : 'none');
            $order->add_item($fee_item);
        }

        // Add shipping line
        if (!empty($cart['selected_shipping'])) {
            $s     = $cart['selected_shipping'];
            $s_rate = new \WC_Shipping_Rate(
                $s['id'],
                $s['label'],
                (float) $s['cost'],
                [],
                $s['method_id']
            );
            $shipping_item = new \WC_Order_Item_Shipping();
            $shipping_item->set_shipping_rate($s_rate);
            $order->add_item($shipping_item);
        }

        // Payment & note
        $order->set_payment_method($payment_method);
        $order->set_payment_method_title($gateways[$payment_method]->get_title());
        $order->set_customer_note($order_note);

        // Custom meta
        foreach ($meta_data as $meta) {
            if (!empty($meta['key'])) {
                $order->update_meta_data(
                    sanitize_key($meta['key']),
                    sanitize_text_field($meta['value'] ?? '')
                );
            }
        }

        // Calculate totals & save
        $order->calculate_totals();
        $order->save();

        do_action('hcm_order_created', $order, $cart);

        // Clear cart
        $cart_ctrl->save_session($req, $cart_ctrl->empty_cart());

        // Payment URL (redirect customer here)
        $pay_url = $order->get_checkout_payment_url();

        // For COD / manual payment, mark as processing
        if ($payment_method === 'cod') {
            $order->payment_complete();
        }

        return rest_ensure_response([
            'order_id'        => $order->get_id(),
            'order_key'       => $order->get_order_key(),
            'order_number'    => $order->get_order_number(),
            'status'          => $order->get_status(),
            'status_label'    => wc_get_order_status_name($order->get_status()),
            'total'           => $order->get_total(),
            'total_formatted' => wp_strip_all_tags($order->get_formatted_order_total()),
            'currency'        => $order->get_currency(),
            'payment_method'  => $payment_method,
            'pay_url'         => $pay_url,
            'thank_you_url'   => $order->get_checkout_order_received_url(),
            'created_at'      => $order->get_date_created()?->format('c'),
        ]);
    }

    public function get_order(\WP_REST_Request $req) {
        $order_id = (int) $req->get_param('id');
        $order    = wc_get_order($order_id);

        if (!$order) {
            return new \WP_Error('hcm_order_not_found', __('Order not found.', 'hcm'), ['status' => 404]);
        }

        // Ensure user owns this order
        if ($order->get_customer_id() !== get_current_user_id() && !current_user_can('manage_woocommerce')) {
            return new \WP_Error('hcm_order_forbidden', __('You cannot access this order.', 'hcm'), ['status' => 403]);
        }

        return rest_ensure_response($this->format_order($order));
    }

    public function get_orders(\WP_REST_Request $req) {
        $user_id  = get_current_user_id();
        $page     = max(1, (int) $req->get_param('page'));
        $per_page = min(50, max(1, (int) $req->get_param('per_page')));
        $status   = sanitize_text_field($req->get_param('status'));

        $args = [
            'customer_id' => $user_id,
            'limit'       => $per_page,
            'paged'       => $page,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ];
        if ($status !== 'any') $args['status'] = $status;

        $orders = wc_get_orders($args);

        return rest_ensure_response([
            'orders' => array_map([$this, 'format_order'], $orders),
            'page'   => $page,
        ]);
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    private function format_order(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'image'        => $product ? get_the_post_thumbnail_url($item->get_product_id(), 'thumbnail') : '',
            ];
        }

        return [
            'id'             => $order->get_id(),
            'number'         => $order->get_order_number(),
            'status'         => $order->get_status(),
            'status_label'   => wc_get_order_status_name($order->get_status()),
            'date_created'   => $order->get_date_created()?->format('c'),
            'date_modified'  => $order->get_date_modified()?->format('c'),
            'total'          => $order->get_total(),
            'subtotal'       => $order->get_subtotal(),
            'discount_total' => $order->get_discount_total(),
            'shipping_total' => $order->get_shipping_total(),
            'currency'       => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'billing'        => $order->get_address('billing'),
            'shipping'       => $order->get_address('shipping'),
            'items'          => $items,
            'pay_url'        => $order->get_checkout_payment_url(),
            'thank_you_url'  => $order->get_checkout_order_received_url(),
        ];
    }

    // ── Validation & Sanitization ─────────────────────────────────────────────

    private function validate_billing(array $billing) {
        $required = [
            'first_name' => 'First name',
            'last_name'  => 'Last name',
            'email'      => 'Email',
            'address_1'  => 'Address',
            'city'       => 'City',
            'country'    => 'Country',
        ];

        foreach ($required as $field => $label) {
            if (empty($billing[$field])) {
                return new \WP_Error(
                    'hcm_missing_billing_' . $field,
                    sprintf(__('Billing %s is required.', 'hcm'), $label),
                    ['status' => 400]
                );
            }
        }

        if (!is_email($billing['email'])) {
            return new \WP_Error('hcm_invalid_email', __('Invalid billing email address.', 'hcm'), ['status' => 400]);
        }

        return true;
    }

    private function sanitize_address(array $addr): array {
        return [
            'first_name' => sanitize_text_field($addr['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($addr['last_name']  ?? ''),
            'company'    => sanitize_text_field($addr['company']    ?? ''),
            'address_1'  => sanitize_text_field($addr['address_1']  ?? ''),
            'address_2'  => sanitize_text_field($addr['address_2']  ?? ''),
            'city'       => sanitize_text_field($addr['city']       ?? ''),
            'state'      => sanitize_text_field($addr['state']      ?? ''),
            'postcode'   => sanitize_text_field($addr['postcode']   ?? ''),
            'country'    => sanitize_text_field($addr['country']    ?? ''),
            'email'      => sanitize_email($addr['email']           ?? ''),
            'phone'      => sanitize_text_field($addr['phone']      ?? ''),
        ];
    }

    private function checkout_args(): array {
        return [
            'billing'        => ['required' => true,  'type' => 'object'],
            'shipping'       => ['required' => false, 'type' => 'object'],
            'payment_method' => ['required' => true,  'type' => 'string'],
            'order_note'     => ['required' => false, 'type' => 'string', 'default' => ''],
            'meta_data'      => ['required' => false, 'type' => 'array',  'default' => []],
        ];
    }
}
