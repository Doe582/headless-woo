<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Fees
 *
 * Add/remove custom cart fees (handling, rush, COD charge etc.).
 * Admin-only write endpoints by default (configurable via filter).
 *
 * Endpoints:
 *   GET    /hcm/v1/cart/fees        — list current fees
 *   POST   /hcm/v1/cart/fee        — add a fee
 *   PUT    /hcm/v1/cart/fee/{id}   — update a fee
 *   DELETE /hcm/v1/cart/fee/{id}   — remove a fee
 */
class HCM_Fees {

    public function register_routes(): void {
        $ns        = HCM_NS;
        $write_perm = apply_filters('hcm_fee_write_permission', [HCM_JWT::class, 'require_auth']);

        register_rest_route($ns, '/cart/fees', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_fees'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/cart/fee', [
            'methods'             => 'POST',
            'callback'            => [$this, 'add'],
            'permission_callback' => $write_perm,
            'args'                => $this->fee_args(),
        ]);

        register_rest_route($ns, '/cart/fee/(?P<id>[a-z0-9\-_]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update'],
                'permission_callback' => $write_perm,
                'args'                => $this->fee_args(false),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'remove'],
                'permission_callback' => $write_perm,
            ],
        ]);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function list_fees(\WP_REST_Request $req) {
        $cart = (new HCM_Cart())->load_session($req);
        return rest_ensure_response(array_values($cart['fees']));
    }

    public function add(\WP_REST_Request $req) {
        $name    = sanitize_text_field($req->get_param('name'));
        $amount  = (float) $req->get_param('amount');
        $taxable = (bool) ($req->get_param('taxable') ?? false);
        $id      = sanitize_title($name . '-' . uniqid());

        if ($amount == 0) {
            return new \WP_Error('hcm_fee_zero', __('Fee amount cannot be zero.', 'hcm'), ['status' => 400]);
        }

        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);

        // Prevent duplicate name
        foreach ($cart['fees'] as $fee) {
            if (strtolower($fee['name']) === strtolower($name)) {
                return new \WP_Error('hcm_fee_duplicate', __('A fee with this name already exists.', 'hcm'), ['status' => 400]);
            }
        }

        $cart['fees'][$id] = [
            'id'      => $id,
            'name'    => $name,
            'amount'  => $amount,
            'taxable' => $taxable,
        ];

        $cart_ctrl->save_session($req, $cart);

        return rest_ensure_response([
            'message' => __('Fee added.', 'hcm'),
            'fee'     => $cart['fees'][$id],
            'cart'    => $cart_ctrl->format_cart($cart),
        ]);
    }

    public function update(\WP_REST_Request $req) {
        $id        = sanitize_text_field($req->get_param('id'));
        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);

        if (!isset($cart['fees'][$id])) {
            return new \WP_Error('hcm_fee_not_found', __('Fee not found.', 'hcm'), ['status' => 404]);
        }

        if ($req->get_param('name') !== null) {
            $cart['fees'][$id]['name'] = sanitize_text_field($req->get_param('name'));
        }
        if ($req->get_param('amount') !== null) {
            $cart['fees'][$id]['amount'] = (float) $req->get_param('amount');
        }
        if ($req->get_param('taxable') !== null) {
            $cart['fees'][$id]['taxable'] = (bool) $req->get_param('taxable');
        }

        $cart_ctrl->save_session($req, $cart);

        return rest_ensure_response([
            'message' => __('Fee updated.', 'hcm'),
            'fee'     => $cart['fees'][$id],
            'cart'    => $cart_ctrl->format_cart($cart),
        ]);
    }

    public function remove(\WP_REST_Request $req) {
        $id        = sanitize_text_field($req->get_param('id'));
        $cart_ctrl = new HCM_Cart();
        $cart      = $cart_ctrl->load_session($req);

        if (!isset($cart['fees'][$id])) {
            return new \WP_Error('hcm_fee_not_found', __('Fee not found.', 'hcm'), ['status' => 404]);
        }

        $removed = $cart['fees'][$id];
        unset($cart['fees'][$id]);
        $cart_ctrl->save_session($req, $cart);

        return rest_ensure_response([
            'message' => __('Fee removed.', 'hcm'),
            'removed' => $removed,
            'cart'    => $cart_ctrl->format_cart($cart),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fee_args(bool $require_name_amount = true): array {
        return [
            'name'    => ['required' => $require_name_amount, 'type' => 'string'],
            'amount'  => [
                'required' => $require_name_amount,
                'type'     => 'number',
                // Allow negative fees (discounts) if needed
            ],
            'taxable' => ['required' => false, 'type' => 'boolean', 'default' => false],
        ];
    }
}
