# Headless Cart Manager — Full Documentation

> Complete headless WooCommerce cart plugin with JWT auth, persistent user carts, coupons, shipping, cart fees, batch API & checkout.

---

## Table of Contents

1. [Installation](#installation)
2. [Authentication](#authentication)
3. [Cart API](#cart-api)
4. [Coupon API](#coupon-api)
5. [Shipping API](#shipping-api)
6. [Fees API](#fees-api)
7. [Checkout API](#checkout-api)
8. [Batch API](#batch-api)
9. [Guest Cart Flow](#guest-cart-flow)
10. [Frontend Integration Examples](#frontend-integration-examples)
11. [WordPress Filters & Hooks](#wordpress-filters--hooks)
12. [Troubleshooting](#troubleshooting)

---

## Installation

### Requirements
- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

### Steps
1. Upload `headless-cart-manager/` folder to `/wp-content/plugins/`
2. Activate from **Plugins → Installed Plugins**
3. The plugin creates table `wp_hcm_carts` on activation
4. Ensure WordPress permalinks are set to **Post name** (`/wp-admin/options-permalink.php`)

### WordPress Configuration (`wp-config.php`)
```php
// Make sure AUTH_KEY is set to a strong random value
// This is used as JWT HMAC secret
define('AUTH_KEY', 'your-very-long-random-string-here-at-least-32-chars');
```

---

## Authentication

All endpoints use **JWT Bearer tokens**. Send token in every request:
```
Authorization: Bearer <your_token_here>
```

Guest users use a **cart key** instead:
```
X-Cart-Key: guest_550e8400-e29b-41d4-a716-446655440000
```

---

### POST /hcm/v1/auth/login

**Login and get JWT token.**

```json
// Request
{
  "username": "customer@example.com",
  "password": "mypassword"
}

// Response 200
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_at": "2026-05-17T10:00:00+00:00",
  "user": {
    "id": 42,
    "username": "john",
    "email": "customer@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "roles": ["customer"]
  }
}
```

---

### POST /hcm/v1/auth/register

**Register new customer account.**

```json
// Request
{
  "username": "newuser",
  "email": "new@example.com",
  "password": "SecurePass123!",
  "first_name": "Rahul",
  "last_name": "Shah"
}

// Response 200 — same as login response
```

---

### GET /hcm/v1/auth/me
*Requires: JWT Token*

**Get current user info.**

```json
// Response
{
  "id": 42,
  "username": "john",
  "email": "john@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "display_name": "John Doe",
  "roles": ["customer"],
  "avatar": "https://yoursite.com/wp-content/..."
}
```

---

### POST /hcm/v1/auth/guest

**Get a guest cart key (for non-logged-in users).**

```json
// Response
{
  "cart_key": "guest_550e8400-e29b-41d4-a716-446655440000",
  "note": "Send this as X-Cart-Key header with every cart request."
}
```

---

### POST /hcm/v1/auth/refresh
*Requires: JWT Token*

**Refresh an expiring token. Returns fresh token with new expiry.**

---

## Cart API

Cart is **automatically persisted** per user across all devices.
- Logged-in → identified by `user_{id}` (send JWT)
- Guest → identified by `X-Cart-Key` header

---

### GET /hcm/v1/cart

**Get full cart with totals.**

```bash
# Logged-in user
curl -H "Authorization: Bearer <token>" https://yoursite.com/wp-json/hcm/v1/cart

# Guest
curl -H "X-Cart-Key: guest_uuid" https://yoursite.com/wp-json/hcm/v1/cart
```

```json
// Response
{
  "items": [
    {
      "key": "a1b2c3d4e5f6...",
      "product_id": 123,
      "variation_id": 0,
      "name": "Blue Cotton T-Shirt",
      "sku": "SHIRT-BLU-M",
      "quantity": 2,
      "price": "599.00",
      "regular_price": "799.00",
      "sale_price": "599.00",
      "line_total": "1198.00",
      "image": "https://yoursite.com/wp-content/...",
      "permalink": "https://yoursite.com/product/blue-shirt/",
      "stock_status": "instock",
      "stock_quantity": 15,
      "variation": { "attribute_pa_color": "blue" }
    }
  ],
  "item_count": 2,
  "unique_products": 1,
  "coupons": [],
  "fees": [],
  "shipping": null,
  "shipping_address": {},
  "totals": {
    "subtotal": "1198.00",
    "discount": "0.00",
    "fees": "0.00",
    "shipping": "0.00",
    "total": "1198.00",
    "currency": "INR",
    "currency_symbol": "₹",
    "currency_pos": "left",
    "decimals": 2
  }
}
```

---

### POST /hcm/v1/cart/add

**Add product to cart.**

```json
// Request
{
  "product_id": 123,
  "quantity": 2,
  "variation_id": 456,       // optional, for variable products
  "variation": {             // optional
    "attribute_pa_color": "blue",
    "attribute_pa_size": "M"
  }
}

// Response — full cart (same as GET /cart)
```

---

### PUT /hcm/v1/cart/item/{key}

**Update item quantity. Set quantity to 0 to remove.**

```json
// Request
{ "quantity": 3 }
```

---

### DELETE /hcm/v1/cart/item/{key}

**Remove item from cart.**

```bash
curl -X DELETE -H "Authorization: Bearer <token>" \
  https://yoursite.com/wp-json/hcm/v1/cart/item/a1b2c3d4e5f6...
```

---

### DELETE /hcm/v1/cart/clear

**Remove all items, coupons, fees from cart.**

---

### POST /hcm/v1/cart/transfer
*Requires: JWT Token*

**Merge guest cart into logged-in user's cart (call this after login).**

```json
// Request
{ "cart_key": "guest_550e8400-e29b-41d4-a716-446655440000" }

// Response
{
  "message": "Cart transferred successfully.",
  "cart": { /* merged cart */ }
}
```

---

## Coupon API

---

### POST /hcm/v1/cart/coupon

**Apply a coupon to cart.**

Validates: existence, expiry, usage limits, per-user limits, min/max spend, product/category restrictions, individual use.

```json
// Request
{ "code": "SAVE10" }

// Response 200
{
  "message": "Coupon applied successfully.",
  "coupon": {
    "code": "save10",
    "discount_type": "percent",
    "coupon_amount": 10,
    "discount": "119.80",
    "free_shipping": false,
    "description": "10% off on all orders"
  },
  "cart": { /* updated cart */ }
}

// Error responses:
// 400 hcm_coupon_invalid    — code not found
// 400 hcm_coupon_expired    — past expiry date
// 400 hcm_coupon_exhausted  — global usage limit reached
// 400 hcm_coupon_user_limit — you've used it already
// 400 hcm_coupon_min_spend  — below minimum order amount
// 400 hcm_coupon_duplicate  — already applied
// 400 hcm_coupon_no_eligible_products
```

---

### DELETE /hcm/v1/cart/coupon/{code}

**Remove an applied coupon.**

```bash
curl -X DELETE -H "X-Cart-Key: guest_uuid" \
  https://yoursite.com/wp-json/hcm/v1/cart/coupon/SAVE10
```

---

### GET /hcm/v1/cart/coupons

**List all coupons applied to current cart.**

---

## Shipping API

---

### GET /hcm/v1/cart/shipping

**Get available shipping methods for an address.**

Uses WooCommerce Shipping Zones exactly as the storefront does.

```bash
curl "https://yoursite.com/wp-json/hcm/v1/cart/shipping?address[country]=IN&address[state]=GJ&address[postcode]=395001" \
  -H "X-Cart-Key: guest_uuid"
```

```json
// Response
{
  "address": {
    "country": "IN",
    "state": "GJ",
    "postcode": "395001",
    "city": "Surat"
  },
  "rates": [
    {
      "id": "flat_rate:1",
      "method_id": "flat_rate",
      "label": "Standard Delivery",
      "cost": "99.00",
      "cost_with_tax": "99.00",
      "taxes": [],
      "meta_data": {}
    },
    {
      "id": "free_shipping:1",
      "method_id": "free_shipping",
      "label": "Free Shipping (orders above ₹999)",
      "cost": "0.00",
      "cost_with_tax": "0.00",
      "taxes": [],
      "meta_data": {}
    }
  ],
  "free_shipping_via_coupon": false,
  "currently_selected": null
}
```

---

### POST /hcm/v1/cart/shipping/select

**Select a shipping method and save to cart.**

```json
// Request
{
  "method_id": "flat_rate:1",
  "address": {
    "country": "IN",
    "state": "GJ",
    "city": "Surat",
    "postcode": "395001",
    "address_1": "123 Ring Road"
  }
}

// Response — updated cart with shipping total
```

---

### GET /hcm/v1/cart/shipping/zones
*Requires: Admin JWT*

**List all WooCommerce shipping zones (admin debug tool).**

---

## Fees API

Add custom fees like handling charges, COD fees, rush charges, etc.

By default, fee write endpoints require authentication. Change with filter:
```php
add_filter('hcm_fee_write_permission', '__return_true'); // allow anyone
```

---

### GET /hcm/v1/cart/fees

**List fees on current cart.**

---

### POST /hcm/v1/cart/fee
*Requires: Auth (configurable)*

**Add a fee to cart.**

```json
// Request
{
  "name": "COD Handling Fee",
  "amount": 50,
  "taxable": false
}

// Response
{
  "message": "Fee added.",
  "fee": {
    "id": "cod-handling-fee-abc123",
    "name": "COD Handling Fee",
    "amount": 50,
    "taxable": false
  },
  "cart": { /* updated cart */ }
}
```

---

### PUT /hcm/v1/cart/fee/{id}

**Update an existing fee.**

```json
{ "amount": 75 }
```

---

### DELETE /hcm/v1/cart/fee/{id}

**Remove a fee.**

---

## Checkout API

---

### GET /hcm/v1/checkout/payment-methods

**Get all available WooCommerce payment gateways.**

```json
// Response
[
  {
    "id": "razorpay",
    "title": "Razorpay",
    "description": "Pay via Razorpay using UPI, Cards, Net Banking.",
    "icon": "",
    "supports": ["products", "refunds"]
  },
  {
    "id": "cod",
    "title": "Cash on Delivery",
    "description": "Pay when your order arrives.",
    "icon": "",
    "supports": ["products"]
  }
]
```

---

### POST /hcm/v1/checkout

**Place order. Converts cart → WC Order.**

```json
// Request
{
  "billing": {
    "first_name": "Rahul",
    "last_name": "Shah",
    "email": "rahul@example.com",
    "phone": "9876543210",
    "address_1": "123 Ring Road",
    "address_2": "Near City Mall",
    "city": "Surat",
    "state": "GJ",
    "postcode": "395001",
    "country": "IN"
  },
  "shipping": {               // optional — defaults to billing
    "first_name": "Rahul",
    "last_name": "Shah",
    "address_1": "123 Ring Road",
    "city": "Surat",
    "state": "GJ",
    "postcode": "395001",
    "country": "IN"
  },
  "payment_method": "razorpay",
  "order_note": "Please pack carefully.",
  "meta_data": [              // optional custom fields
    { "key": "source", "value": "mobile_app" }
  ]
}

// Response 200
{
  "order_id": 1001,
  "order_key": "wc_order_abc123xyz",
  "order_number": "#1001",
  "status": "pending",
  "status_label": "Pending payment",
  "total": "1347.00",
  "total_formatted": "₹1,347.00",
  "currency": "INR",
  "payment_method": "razorpay",
  "pay_url": "https://yoursite.com/checkout/order-pay/1001/?key=wc_order_abc123xyz",
  "thank_you_url": "https://yoursite.com/checkout/order-received/1001/?key=wc_order_abc123xyz",
  "created_at": "2026-04-17T12:30:00+00:00"
}
```

> **After checkout:** Redirect user to `pay_url` for payment gateway, or handle payment in your own UI using the gateway's JS SDK with the `order_id`.

---

### GET /hcm/v1/order/{id}
*Requires: JWT Token (order owner or admin)*

**Get a single order's details.**

---

### GET /hcm/v1/orders
*Requires: JWT Token*

**Get current user's order history.**

```bash
curl "https://yoursite.com/wp-json/hcm/v1/orders?page=1&per_page=10&status=completed" \
  -H "Authorization: Bearer <token>"
```

---

## Batch API

Process multiple operations in **one HTTP request**. Ideal for mobile apps to reduce latency.

### POST /hcm/v1/batch

```json
// Request — add 3 products + apply coupon in one call
{
  "requests": [
    {
      "method": "POST",
      "path": "/hcm/v1/cart/add",
      "body": { "product_id": 101, "quantity": 2 }
    },
    {
      "method": "POST",
      "path": "/hcm/v1/cart/add",
      "body": { "product_id": 202, "quantity": 1 }
    },
    {
      "method": "POST",
      "path": "/hcm/v1/cart/coupon",
      "body": { "code": "SAVE10" }
    },
    {
      "method": "GET",
      "path": "/hcm/v1/cart"
    }
  ]
}

// Response
{
  "responses": [
    { "index": 0, "status": 200, "body": { /* cart */ } },
    { "index": 1, "status": 200, "body": { /* cart */ } },
    { "index": 2, "status": 200, "body": { "message": "Coupon applied", ... } },
    { "index": 3, "status": 200, "body": { /* final cart */ } }
  ],
  "count": 4,
  "success": 4,
  "failed": 0
}
```

**Limits:** Max 25 requests per batch. Only `/hcm/v1/` paths allowed.

---

## Guest Cart Flow

```
1. App start (no login)
   POST /hcm/v1/auth/guest
   → save cart_key in AsyncStorage / localStorage

2. Browse & add to cart
   POST /hcm/v1/cart/add
   Header: X-Cart-Key: guest_uuid
   
3. User registers/logs in
   POST /hcm/v1/auth/login  → get JWT token
   
4. Transfer guest cart to user
   POST /hcm/v1/cart/transfer
   Header: Authorization: Bearer <token>
   Body: { "cart_key": "guest_uuid" }
   
5. All subsequent requests use JWT only
   Header: Authorization: Bearer <token>
   (cart persists across mobile, desktop, tablet)
```

---

## Frontend Integration Examples

### React/Next.js Service

```javascript
// lib/cart.js
const API_BASE = process.env.NEXT_PUBLIC_WP_URL + '/wp-json/hcm/v1';

function getHeaders() {
  const token   = localStorage.getItem('hcm_token');
  const cartKey = localStorage.getItem('hcm_cart_key');
  const headers = { 'Content-Type': 'application/json' };
  
  if (token)   headers['Authorization'] = `Bearer ${token}`;
  if (cartKey && !token) headers['X-Cart-Key'] = cartKey;
  
  return headers;
}

export const CartAPI = {
  
  async initGuest() {
    if (localStorage.getItem('hcm_cart_key') || localStorage.getItem('hcm_token')) return;
    const res  = await fetch(`${API_BASE}/auth/guest`, { method: 'POST' });
    const data = await res.json();
    localStorage.setItem('hcm_cart_key', data.cart_key);
  },

  async login(username, password) {
    const res  = await fetch(`${API_BASE}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message);
    
    // Save token
    localStorage.setItem('hcm_token', data.token);
    
    // Transfer guest cart
    const guestKey = localStorage.getItem('hcm_cart_key');
    if (guestKey) {
      await this.transfer(guestKey);
      localStorage.removeItem('hcm_cart_key');
    }
    return data;
  },

  async transfer(cartKey) {
    return fetch(`${API_BASE}/cart/transfer`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ cart_key: cartKey }),
    }).then(r => r.json());
  },

  async getCart() {
    const res = await fetch(`${API_BASE}/cart`, { headers: getHeaders() });
    return res.json();
  },

  async addToCart(productId, quantity = 1, variationId = 0, variation = {}) {
    const res = await fetch(`${API_BASE}/cart/add`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ product_id: productId, quantity, variation_id: variationId, variation }),
    });
    return res.json();
  },

  async updateItem(key, quantity) {
    const res = await fetch(`${API_BASE}/cart/item/${key}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify({ quantity }),
    });
    return res.json();
  },

  async removeItem(key) {
    return fetch(`${API_BASE}/cart/item/${key}`, {
      method: 'DELETE',
      headers: getHeaders(),
    }).then(r => r.json());
  },

  async applyCoupon(code) {
    const res = await fetch(`${API_BASE}/cart/coupon`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ code }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message);
    return data;
  },

  async removeCoupon(code) {
    return fetch(`${API_BASE}/cart/coupon/${code}`, {
      method: 'DELETE',
      headers: getHeaders(),
    }).then(r => r.json());
  },

  async getShipping(address) {
    const params = new URLSearchParams({
      'address[country]':  address.country,
      'address[state]':    address.state,
      'address[postcode]': address.postcode,
      'address[city]':     address.city,
    });
    const res = await fetch(`${API_BASE}/cart/shipping?${params}`, { headers: getHeaders() });
    return res.json();
  },

  async selectShipping(methodId, address) {
    const res = await fetch(`${API_BASE}/cart/shipping/select`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ method_id: methodId, address }),
    });
    return res.json();
  },

  async checkout(payload) {
    const res = await fetch(`${API_BASE}/checkout`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message);
    return data;
  },

  async batch(requests) {
    const res = await fetch(`${API_BASE}/batch`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify({ requests }),
    });
    return res.json();
  },
};
```

### React Native (Expo) Usage

```javascript
import AsyncStorage from '@react-native-async-storage/async-storage';

async function getHeaders() {
  const token   = await AsyncStorage.getItem('hcm_token');
  const cartKey = await AsyncStorage.getItem('hcm_cart_key');
  const headers = { 'Content-Type': 'application/json' };
  if (token)   headers['Authorization'] = `Bearer ${token}`;
  if (cartKey && !token) headers['X-Cart-Key'] = cartKey;
  return headers;
}

// Usage: same API calls as above, just await getHeaders()
```

---

## WordPress Filters & Hooks

### Filters

```php
// JWT token lifetime (default: 30 days)
add_filter('hcm_token_ttl', fn() => 7 * DAY_IN_SECONDS);

// Cart session lifetime (default: 30 days)
add_filter('hcm_cart_ttl', fn() => 14 * DAY_IN_SECONDS);

// Rate limit: max requests (default: 100)
add_filter('hcm_rate_limit', fn() => 200);

// Rate limit: window in seconds (default: 60)
add_filter('hcm_rate_window', fn() => 120);

// Disable rate limiting completely
add_filter('hcm_rate_limit_enabled', '__return_false');

// Whitelist user IDs from rate limiting
add_filter('hcm_rate_limit_whitelist', fn() => [1, 2, 3]);

// Allow everyone to add fees (default: auth required)
add_filter('hcm_fee_write_permission', '__return_true');

// Initial order status on checkout (default: pending)
add_filter('hcm_initial_order_status', fn() => 'processing');

// CORS allowed origins
add_filter('hcm_allowed_origins', fn() => [
  'https://myapp.com',
  'http://localhost:3000',
]);
```

### Actions

```php
// After a user logs in via JWT
add_action('hcm_user_logged_in', function(int $user_id) {
    // e.g., log login event
});

// After an order is created via checkout
add_action('hcm_order_created', function(\WC_Order $order, array $cart) {
    // e.g., send custom notification, update CRM
}, 10, 2);
```

---

## Rate Limiting

All write endpoints are rate-limited by default:
- **100 requests / 60 seconds** per IP (guests) or user ID (logged-in)
- Exceeding the limit returns HTTP 429 with `Retry-After` header
- Customize via filters (see above)

---

## Troubleshooting

### JWT auth not working
1. Check `Authorization: Bearer <token>` header is being sent
2. Some servers strip the Authorization header. Add to `.htaccess`:
   ```apache
   RewriteEngine On
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
3. Or add to `wp-config.php`:
   ```php
   $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
   ```

### Cart not persisting
1. Ensure `wp_hcm_carts` table exists (`wp-admin → Tools → Database` or check activation)
2. Check that the same `X-Cart-Key` or `Authorization` header is sent across requests
3. Guest carts expire after 30 days (configurable)

### Shipping returns no rates
1. Set up Shipping Zones in **WooCommerce → Settings → Shipping**
2. Ensure the zone has at least one enabled shipping method
3. Pass `address[country]` in the request matching your zone's regions

### CORS errors in browser
Add your frontend domain to allowed origins:
```php
add_filter('hcm_allowed_origins', fn() => ['https://yourfrontend.com', 'http://localhost:3000']);
```

### 404 on all API endpoints
- Flush permalinks: **Settings → Permalinks → Save Changes**
- Ensure `WP_SITEURL` and `WP_HOME` are set correctly

---

## API Reference Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/hcm/v1/auth/login` | — | Login, get JWT |
| POST | `/hcm/v1/auth/register` | — | Register customer |
| GET | `/hcm/v1/auth/me` | JWT | Current user |
| POST | `/hcm/v1/auth/refresh` | JWT | Refresh token |
| POST | `/hcm/v1/auth/guest` | — | Get guest cart key |
| GET | `/hcm/v1/cart` | JWT/Key | Get cart |
| POST | `/hcm/v1/cart/add` | JWT/Key | Add item |
| PUT | `/hcm/v1/cart/item/{key}` | JWT/Key | Update quantity |
| DELETE | `/hcm/v1/cart/item/{key}` | JWT/Key | Remove item |
| DELETE | `/hcm/v1/cart/clear` | JWT/Key | Clear cart |
| POST | `/hcm/v1/cart/transfer` | JWT | Merge guest→user |
| POST | `/hcm/v1/cart/coupon` | JWT/Key | Apply coupon |
| DELETE | `/hcm/v1/cart/coupon/{code}` | JWT/Key | Remove coupon |
| GET | `/hcm/v1/cart/coupons` | JWT/Key | List coupons |
| GET | `/hcm/v1/cart/shipping` | JWT/Key | Get rates |
| POST | `/hcm/v1/cart/shipping/select` | JWT/Key | Select method |
| GET | `/hcm/v1/cart/shipping/zones` | Admin JWT | List zones |
| GET | `/hcm/v1/cart/fees` | JWT/Key | List fees |
| POST | `/hcm/v1/cart/fee` | JWT | Add fee |
| PUT | `/hcm/v1/cart/fee/{id}` | JWT | Update fee |
| DELETE | `/hcm/v1/cart/fee/{id}` | JWT | Remove fee |
| GET | `/hcm/v1/checkout/payment-methods` | — | Payment gateways |
| POST | `/hcm/v1/checkout` | JWT/Key | Place order |
| GET | `/hcm/v1/order/{id}` | JWT | Get order |
| GET | `/hcm/v1/orders` | JWT | Order history |
| POST | `/hcm/v1/batch` | JWT/Key | Batch requests |
