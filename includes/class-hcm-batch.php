<?php
defined('ABSPATH') || exit;

/**
 * Class HCM_Batch
 *
 * Process up to 25 cart API operations in a single HTTP request.
 * Dramatically reduces round-trips for operations like:
 *  - Add multiple products at once
 *  - Apply coupon + get updated cart
 *  - Update quantities + recalculate shipping
 *
 * Endpoint:
 *   POST /hcm/v1/batch
 *
 * Request body:
 * {
 *   "requests": [
 *     { "method": "POST", "path": "/hcm/v1/cart/add", "body": { "product_id": 123, "quantity": 2 } },
 *     { "method": "POST", "path": "/hcm/v1/cart/coupon", "body": { "code": "SAVE10" } },
 *     { "method": "GET",  "path": "/hcm/v1/cart" }
 *   ]
 * }
 *
 * Response:
 * {
 *   "responses": [
 *     { "index": 0, "status": 200, "body": { ... } },
 *     ...
 *   ]
 * }
 */
class HCM_Batch {

    const MAX_REQUESTS = 25;

    public function register_routes(): void {
        register_rest_route(HCM_NS, '/batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'process'],
            'permission_callback' => '__return_true',
            'args' => [
                'requests' => [
                    'required' => true,
                    'type'     => 'array',
                    'items'    => ['type' => 'object'],
                    'maxItems' => self::MAX_REQUESTS,
                ],
            ],
        ]);
    }

    public function process(\WP_REST_Request $req) {
        HCM_RateLimit::check($req, 'batch');

        $requests = $req->get_param('requests');

        if (!is_array($requests) || empty($requests)) {
            return new \WP_Error('hcm_batch_empty', __('Requests array is required and must not be empty.', 'hcm'), ['status' => 400]);
        }

        if (count($requests) > self::MAX_REQUESTS) {
            return new \WP_Error(
                'hcm_batch_too_large',
                sprintf(__('Maximum %d requests per batch allowed.', 'hcm'), self::MAX_REQUESTS),
                ['status' => 400]
            );
        }

        // Inherit auth headers from parent request
        $auth_header = $req->get_header('authorization') ?? '';
        $cart_key    = $req->get_header('x_cart_key')    ?? '';

        $responses = [];
        $server    = rest_get_server();

        foreach ($requests as $index => $request_data) {
            $responses[] = $this->dispatch_single(
                $index,
                $request_data,
                $server,
                $auth_header,
                $cart_key
            );
        }

        return rest_ensure_response([
            'responses' => $responses,
            'count'     => count($responses),
            'success'   => count(array_filter($responses, static fn($r) => $r['status'] < 400)),
            'failed'    => count(array_filter($responses, static fn($r) => $r['status'] >= 400)),
        ]);
    }

    private function dispatch_single(
        int $index,
        array $request_data,
        \WP_REST_Server $server,
        string $auth_header,
        string $cart_key
    ): array {
        $method  = strtoupper(sanitize_text_field($request_data['method'] ?? 'GET'));
        $path    = sanitize_text_field($request_data['path']   ?? '');
        $body    = (array) ($request_data['body']    ?? []);
        $headers = (array) ($request_data['headers'] ?? []);

        if (!$path) {
            return [
                'index'  => $index,
                'status' => 400,
                'body'   => ['code' => 'hcm_missing_path', 'message' => 'Path is required.'],
            ];
        }

        // Only allow HCM namespace for security
        if (!str_starts_with($path, '/' . HCM_NS . '/') && !str_starts_with($path, '/hcm/')) {
            return [
                'index'  => $index,
                'status' => 403,
                'body'   => ['code' => 'hcm_batch_scope', 'message' => 'Batch requests are limited to HCM endpoints.'],
            ];
        }

        // Build the sub-request
        $sub = new \WP_REST_Request($method, $path);
        $sub->set_body_params($body);
        $sub->set_query_params($request_data['query'] ?? []);

        // Forward auth & cart-key headers
        if ($auth_header) $sub->set_header('authorization', $auth_header);
        if ($cart_key)    $sub->set_header('x-cart-key', $cart_key);

        // Allow per-request header overrides
        foreach ($headers as $key => $val) {
            $sub->set_header(sanitize_key($key), sanitize_text_field($val));
        }

        // Dispatch internally
        try {
            $response = rest_do_request($sub);
            $data     = $server->response_to_data($response, false);
            $status   = $response->get_status();
        } catch (\Throwable $e) {
            $data   = ['code' => 'hcm_batch_exception', 'message' => $e->getMessage()];
            $status = 500;
        }

        return [
            'index'  => $index,
            'status' => $status,
            'body'   => $data,
        ];
    }
}
