# Client-Side E-Commerce Tracking Plan

**Created**: 2026-02-25
**Status**: Draft
**Estimated Effort**: ~5–7 working days across 4 phases
**Last Updated**: 2026-02-25

## Changelog

| Date | Changes |
|------|---------|
| 2026-02-25 | Initial plan created. Hybrid architecture (PHP data injection + SDK event firing). All 7 WooCommerce events + form tracking in scope. |

---

## Executive Summary

The Segmentflow Connect WordPress plugin currently injects the CDN SDK and fires `identify()` for logged-in users, but **fires zero e-commerce track events**. The SDK's `WooCommercePlugin` enriches events with context (currency, cart hash, customer ID) but does not track any user actions.

This plan adds client-side tracking for all critical WooCommerce e-commerce events, plus form submission tracking for Contact Form 7 and Elementor Pro. The architecture uses a **hybrid approach**: PHP injects server-side data into `window.__sf_wc`, and the SDK reads it to fire events.

### Events In Scope

| Event Name | Trigger | Phase |
|---|---|---|
| `product_viewed` | Single product page load | Phase 2 |
| `add_to_cart` | Add to cart (AJAX + form submit) | Phase 2 |
| `remove_from_cart` | Remove from cart (AJAX) | Phase 2 |
| `cart_viewed` | Cart page load | Phase 2 |
| `checkout_started` | Checkout page load | Phase 2 |
| `checkout_email_captured` | Email field blur on checkout | Phase 2 |
| `order_completed` | Thank-you page load | Phase 2 |
| `form_submitted` | CF7 / Elementor Pro form submit | Phase 3 |

**Note**: `order_completed` fires from both client-side (thank-you page) and server-side (WooCommerce webhook). This is intentional — the client event captures browser context (UTM, referrer, device) while the webhook captures authoritative order data. Downstream deduplication uses `order_id` property.

---

## Architecture

### Hybrid: PHP Data Injection + SDK Event Firing

```
┌─────────────────────────────────────────────────┐
│  WordPress / WooCommerce (PHP)                  │
│                                                 │
│  class-segmentflow-wc-tracking.php              │
│  ┌───────────────────────────────────────────┐  │
│  │ wp_head hook (priority 0, before SDK)     │  │
│  │                                           │  │
│  │ Renders: <script>                         │  │
│  │   window.__sf_wc = {                      │  │
│  │     page: "product" | "cart" | ...        │  │
│  │     product: { id, name, price, ... }     │  │
│  │     cart: { items: [...], total: "..." }  │  │
│  │     order: { id, total, items: [...] }    │  │
│  │     currency: "USD"                       │  │
│  │   }                                       │  │
│  │ </script>                                 │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
│  class-segmentflow-tracking.php (priority 1)    │
│  ┌───────────────────────────────────────────┐  │
│  │ Injects CDN SDK + init() + identify()     │  │
│  └───────────────────────────────────────────┘  │
└─────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────┐
│  Browser (SDK + WooCommercePlugin)              │
│                                                 │
│  woocommerce.plugin.ts (in sdk-web)             │
│  ┌───────────────────────────────────────────┐  │
│  │ load(analytics):                          │  │
│  │   reads window.__sf_wc                    │  │
│  │   fires track() based on page type:       │  │
│  │     "product" → product_viewed            │  │
│  │     "cart"    → cart_viewed               │  │
│  │     "checkout"→ checkout_started          │  │
│  │     "thankyou"→ order_completed           │  │
│  │                                           │  │
│  │   binds DOM listeners:                    │  │
│  │     jQuery added_to_cart → add_to_cart    │  │
│  │     jQuery removed_from_cart → remove     │  │
│  │     #billing_email blur → email capture   │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
│  storefront.ts (in segmentflow-connect)         │
│  ┌───────────────────────────────────────────┐  │
│  │ CF7 wpcf7mailsent listener               │  │
│  │ Elementor Pro jQuery.ajaxComplete hook    │  │
│  │ → window.segmentflow.track(form_submitted)│  │
│  └───────────────────────────────────────────┘  │
└─────────────────────────────────────────────────┘
```

### Why Hybrid?

| Approach | Pros | Cons |
|---|---|---|
| **Pure PHP (server-rendered inline events)** | Simple, no DOM scraping | Cannot capture dynamic interactions (AJAX add-to-cart, email blur) |
| **Pure JS (DOM scraping)** | No PHP changes needed | Fragile (theme-dependent selectors), misses server-only data |
| **Hybrid (chosen)** | Server injects authoritative data, JS fires events + captures interactions | Two codebases to maintain (PHP + JS), but clean separation |

The hybrid approach gives us:
- **Authoritative product/cart/order data** from WooCommerce PHP APIs (no DOM scraping)
- **Dynamic interaction tracking** via jQuery events (add-to-cart, remove-from-cart)
- **Checkout email capture** via DOM listeners
- **Form submission tracking** via dedicated storefront JS

---

## Phase 1: PHP Data Injection (`window.__sf_wc`)

**Goal**: Inject structured WooCommerce page data into `window.__sf_wc` so the SDK can read it.

**Effort**: ~1 day

### Files Modified

| File | Change |
|---|---|
| `integrations/woocommerce/class-segmentflow-wc-tracking.php` | Add `inject_page_data()` method, hook to `wp_head` at priority 0 |
| `integrations/woocommerce/class-segmentflow-wc-helper.php` | Add helper methods for product/cart/order data extraction |

### Data Shape: `window.__sf_wc`

```typescript
interface SfWcPageData {
  /** Current page type for the SDK to determine which event to fire */
  page: "product" | "cart" | "checkout" | "thankyou" | "shop" | "category" | "other";

  /** Currency code (ISO 4217) */
  currency: string;

  /** Product data (only on single product pages) */
  product?: {
    id: number;
    name: string;
    price: string;        // Formatted price string
    sku: string;
    categories: string[]; // Category names
    image_url: string;    // Featured image URL
    url: string;          // Permalink
    type: string;         // "simple" | "variable" | "grouped" | etc.
  };

  /** Cart data (on cart + checkout pages) */
  cart?: {
    items: Array<{
      product_id: number;
      variation_id: number;
      name: string;
      quantity: number;
      price: string;
      sku: string;
      image_url: string;
      url: string;
    }>;
    total: string;
    subtotal: string;
    item_count: number;
    cart_hash: string;
  };

  /** Order data (only on thank-you page) */
  order?: {
    id: number;
    number: string;
    total: string;
    subtotal: string;
    tax: string;
    shipping: string;
    discount: string;
    payment_method: string;
    currency: string;
    items: Array<{
      product_id: number;
      name: string;
      quantity: number;
      price: string;
      sku: string;
    }>;
    coupon_codes: string[];
    already_tracked: boolean;
  };
}
```

### Page Detection Logic (PHP)

```php
private function get_page_type(): string {
    if ( is_product() ) return 'product';
    if ( is_cart() ) return 'cart';
    if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) return 'checkout';
    if ( is_wc_endpoint_url( 'order-received' ) ) return 'thankyou';
    if ( is_shop() ) return 'shop';
    if ( is_product_category() || is_product_tag() ) return 'category';
    return 'other';
}
```

### Product Data Extraction (PHP)

On single product pages, extract from the global `$product` object:

```php
private function get_product_data(): ?array {
    global $product;
    if ( ! $product instanceof WC_Product ) return null;

    return [
        'id'         => $product->get_id(),
        'name'       => $product->get_name(),
        'price'      => $product->get_price(),
        'sku'        => $product->get_sku(),
        'categories' => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
        'image_url'  => wp_get_attachment_url( $product->get_image_id() ) ?: '',
        'url'        => $product->get_permalink(),
        'type'       => $product->get_type(),
    ];
}
```

### Cart Data Extraction (PHP)

On cart and checkout pages, extract from `WC()->cart`:

```php
private function get_cart_data(): ?array {
    $cart = WC()->cart;
    if ( ! $cart || $cart->is_empty() ) return null;

    $items = [];
    foreach ( $cart->get_cart() as $item ) {
        $product = $item['data'];
        $items[] = [
            'product_id'   => $item['product_id'],
            'variation_id' => $item['variation_id'] ?? 0,
            'name'         => $product->get_name(),
            'quantity'     => $item['quantity'],
            'price'        => $product->get_price(),
            'sku'          => $product->get_sku(),
            'image_url'    => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            'url'          => $product->get_permalink(),
        ];
    }

    return [
        'items'      => $items,
        'total'      => $cart->get_total( 'raw' ),
        'subtotal'   => $cart->get_subtotal(),
        'item_count' => $cart->get_cart_contents_count(),
        'cart_hash'  => $cart->get_cart_hash(),
    ];
}
```

### Order Data Extraction (PHP)

On the thank-you page, extract from the order object:

```php
private function get_order_data(): ?array {
    global $wp;
    $order_id = absint( $wp->query_vars['order-received'] ?? 0 );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) return null;

    // Deduplication: mark as tracked to prevent duplicate events on page refresh.
    $already_tracked = (bool) $order->get_meta( '_sf_order_tracked' );
    if ( ! $already_tracked ) {
        $order->update_meta_data( '_sf_order_tracked', '1' );
        $order->save();
    }

    $items = [];
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        $items[] = [
            'product_id' => $item->get_product_id(),
            'name'       => $item->get_name(),
            'quantity'   => $item->get_quantity(),
            'price'      => $order->get_item_total( $item, false, true ),
            'sku'        => $product ? $product->get_sku() : '',
        ];
    }

    return [
        'id'              => $order->get_id(),
        'number'          => $order->get_order_number(),
        'total'           => $order->get_total(),
        'subtotal'        => $order->get_subtotal(),
        'tax'             => $order->get_total_tax(),
        'shipping'        => $order->get_shipping_total(),
        'discount'        => $order->get_total_discount(),
        'payment_method'  => $order->get_payment_method_title(),
        'currency'        => $order->get_currency(),
        'items'           => $items,
        'coupon_codes'    => $order->get_coupon_codes(),
        'already_tracked' => $already_tracked,
    ];
}
```

### Rendering (PHP)

The `inject_page_data()` method renders a `<script>` tag at priority 0 in `wp_head`, before the SDK injection at priority 1:

```php
public function inject_page_data(): void {
    $page_type = $this->get_page_type();

    $data = [
        'page'     => $page_type,
        'currency' => Segmentflow_WC_Helper::get_currency(),
    ];

    if ( 'product' === $page_type ) {
        $product_data = $this->get_product_data();
        if ( $product_data ) {
            $data['product'] = $product_data;
        }
    }

    if ( in_array( $page_type, [ 'cart', 'checkout' ], true ) ) {
        $cart_data = $this->get_cart_data();
        if ( $cart_data ) {
            $data['cart'] = $cart_data;
        }
    }

    if ( 'thankyou' === $page_type ) {
        $order_data = $this->get_order_data();
        if ( $order_data ) {
            $data['order'] = $order_data;
        }
    }

    printf(
        '<script>window.__sf_wc = %s;</script>' . "\n",
        wp_json_encode( $data )
    );
}
```

---

## Phase 2: SDK Event Firing (WooCommercePlugin Enhancement)

**Goal**: Enhance the SDK's `WooCommercePlugin` to read `window.__sf_wc` and fire track events.

**Effort**: ~2 days

### Files Modified

| File | Change |
|---|---|
| `packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts` (in segmentflow-ai) | Add page-load event firing + DOM event listeners in `load()` |

### Page-Load Events

In the `load(analytics)` method, after detecting WooCommerce, read `window.__sf_wc` and fire the appropriate event:

```typescript
load(analytics: SegmentflowWeb): void {
  this.analytics = analytics;

  if (!this.detectWooCommerce()) return;
  this.captureContext();

  const data = (window as any).__sf_wc as SfWcPageData | undefined;
  if (!data) return;

  // Page-load events
  switch (data.page) {
    case "product":
      if (data.product) {
        analytics.track({
          event: "product_viewed",
          properties: {
            product_id: data.product.id,
            name: data.product.name,
            price: data.product.price,
            sku: data.product.sku,
            categories: data.product.categories,
            image_url: data.product.image_url,
            url: data.product.url,
            currency: data.currency,
          },
        });
      }
      break;

    case "cart":
      if (data.cart) {
        analytics.track({
          event: "cart_viewed",
          properties: {
            items: data.cart.items,
            total: data.cart.total,
            item_count: data.cart.item_count,
            currency: data.currency,
          },
        });
      }
      break;

    case "checkout":
      if (data.cart) {
        analytics.track({
          event: "checkout_started",
          properties: {
            items: data.cart.items,
            total: data.cart.total,
            item_count: data.cart.item_count,
            currency: data.currency,
          },
        });
      }
      break;

    case "thankyou":
      if (data.order && !data.order.already_tracked) {
        analytics.track({
          event: "order_completed",
          properties: {
            order_id: data.order.id,
            order_number: data.order.number,
            total: data.order.total,
            subtotal: data.order.subtotal,
            tax: data.order.tax,
            shipping: data.order.shipping,
            discount: data.order.discount,
            payment_method: data.order.payment_method,
            currency: data.order.currency,
            items: data.order.items,
            coupon_codes: data.order.coupon_codes,
          },
        });
      }
      break;
  }

  // Interaction event listeners
  this.bindCartListeners(analytics, data.currency);
  this.bindCheckoutEmailListener(analytics);
}
```

### Interaction Events: Add to Cart / Remove from Cart

WooCommerce uses jQuery events on `document.body` for AJAX cart operations:

```typescript
private bindCartListeners(
  analytics: SegmentflowWeb,
  currency: string,
): void {
  const $ = (window as any).jQuery;
  if (!$) return;

  // AJAX add-to-cart (archive/shop pages)
  $(document.body).on(
    "added_to_cart",
    (_event: any, fragments: any, _cartHash: string, $button: any) => {
      const productId = $button?.data("product_id");
      const productName = $button?.data("product_name") || $button?.attr("aria-label") || "";
      const quantity = $button?.data("quantity") || 1;

      analytics.track({
        event: "add_to_cart",
        properties: {
          product_id: productId,
          name: productName,
          quantity: Number(quantity),
          currency,
          source: "ajax",
        },
      });
    },
  );

  // Single product page form submit (non-AJAX add-to-cart)
  const sfWcData = (window as any).__sf_wc as SfWcPageData | undefined;
  if (sfWcData?.page === "product" && sfWcData.product) {
    const form = document.querySelector("form.cart");
    if (form) {
      form.addEventListener("submit", () => {
        const qtyInput = form.querySelector<HTMLInputElement>(
          'input[name="quantity"]',
        );
        const quantity = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

        analytics.track({
          event: "add_to_cart",
          properties: {
            product_id: sfWcData.product!.id,
            name: sfWcData.product!.name,
            price: sfWcData.product!.price,
            sku: sfWcData.product!.sku,
            quantity,
            currency,
            source: "single_product",
          },
        });

        // Flush immediately since the page is about to navigate away
        analytics.flush();
      });
    }
  }

  // Remove from cart (AJAX)
  $(document.body).on(
    "removed_from_cart",
    (_event: any, fragments: any, _cartHash: string, $button: any) => {
      const productId = $button?.data("product_id");

      analytics.track({
        event: "remove_from_cart",
        properties: {
          product_id: productId,
          currency,
          source: "ajax",
        },
      });
    },
  );
}
```

### Interaction Events: Checkout Email Capture

When a user enters their email on the checkout page, fire both `identify()` and `track()`:

```typescript
private bindCheckoutEmailListener(analytics: SegmentflowWeb): void {
  const sfWcData = (window as any).__sf_wc as SfWcPageData | undefined;
  if (sfWcData?.page !== "checkout") return;

  const selectors = [
    "#billing_email",                                     // Classic checkout
    'input[id*="email"][autocomplete="email"]',           // Blocks checkout
  ];

  let emailField: HTMLInputElement | null = null;
  for (const selector of selectors) {
    emailField = document.querySelector<HTMLInputElement>(selector);
    if (emailField) break;
  }

  if (!emailField) return;

  let lastCapturedEmail = "";

  emailField.addEventListener("blur", () => {
    const email = emailField!.value.trim();
    if (!email || email === lastCapturedEmail) return;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;

    lastCapturedEmail = email;

    analytics.identify({
      traits: { email },
    });

    analytics.track({
      event: "checkout_email_captured",
      properties: {
        email,
        currency: sfWcData!.currency,
        cart_total: sfWcData!.cart?.total,
        item_count: sfWcData!.cart?.item_count,
      },
    });
  });
}
```

### Event Property Schemas

| Event | Required Properties | Optional Properties |
|---|---|---|
| `product_viewed` | `product_id`, `name`, `price`, `currency` | `sku`, `categories`, `image_url`, `url` |
| `add_to_cart` | `product_id`, `quantity`, `currency` | `name`, `price`, `sku`, `source` |
| `remove_from_cart` | `product_id`, `currency` | `source` |
| `cart_viewed` | `items`, `total`, `item_count`, `currency` | — |
| `checkout_started` | `items`, `total`, `item_count`, `currency` | — |
| `checkout_email_captured` | `email`, `currency` | `cart_total`, `item_count` |
| `order_completed` | `order_id`, `total`, `currency`, `items` | `order_number`, `subtotal`, `tax`, `shipping`, `discount`, `payment_method`, `coupon_codes` |

---

## Phase 3: Form Submission Tracking (WordPress Plugin)

**Goal**: Track form submissions from Contact Form 7 and Elementor Pro.

**Effort**: ~1.5 days

### Files Created/Modified

| File | Change |
|---|---|
| `src/storefront.ts` | **New file** — CF7 + Elementor Pro form tracking |
| `tsdown.config.ts` | Add `src/storefront.ts` as second entry point |
| `includes/class-segmentflow-tracking.php` | Enqueue `storefront.js` on frontend pages |

### `src/storefront.ts`

A lightweight script (~100 lines) that listens for form submission events and forwards them to the SDK:

```typescript
/**
 * Segmentflow Connect — Storefront Form Tracking
 *
 * Listens for form submission events from Contact Form 7 and Elementor Pro,
 * and fires segmentflow.track("form_submitted", ...) for each.
 */

// ---------- Contact Form 7 ----------
document.addEventListener("wpcf7mailsent", ((event: CustomEvent) => {
  const detail = event.detail;
  if (!detail) return;

  const inputs: Array<{ name: string; value: string }> = detail.inputs || [];
  const emailInput = inputs.find(
    (i) => i.name.includes("email") || i.name === "your-email",
  );
  const nameInput = inputs.find(
    (i) => i.name.includes("name") || i.name === "your-name",
  );

  const properties: Record<string, unknown> = {
    form_id: detail.contactFormId,
    form_plugin: "contact_form_7",
    form_title: detail.contactFormTitle || undefined,
  };

  if (emailInput?.value) {
    properties.email = emailInput.value;
    window.segmentflow?.identify({ traits: { email: emailInput.value } });
  }

  if (nameInput?.value) {
    properties.name = nameInput.value;
  }

  window.segmentflow?.track({
    event: "form_submitted",
    properties,
  });
}) as EventListener);


// ---------- Elementor Pro Forms ----------
const $ = (window as any).jQuery;
if ($) {
  $(document).ajaxComplete(
    (_event: any, xhr: any, settings: { data?: string }) => {
      if (!settings.data) return;
      if (
        typeof settings.data !== "string" ||
        !settings.data.includes("action=elementor_pro_forms_send_form")
      ) {
        return;
      }

      let formData: Record<string, string> = {};
      try {
        const params = new URLSearchParams(settings.data);
        for (const [key, value] of params) {
          formData[key] = value;
        }
      } catch {
        return;
      }

      let responseOk = false;
      try {
        const json = typeof xhr.responseJSON === "object"
          ? xhr.responseJSON
          : JSON.parse(xhr.responseText || "{}");
        responseOk = json.success === true;
      } catch {
        responseOk = xhr.status >= 200 && xhr.status < 300;
      }

      if (!responseOk) return;

      const emailField = Object.entries(formData).find(
        ([key, val]) =>
          (key.startsWith("form_fields[") && val.includes("@")) ||
          key.includes("email"),
      );

      const properties: Record<string, unknown> = {
        form_id: formData["form_id"] || formData["post_id"] || undefined,
        form_plugin: "elementor_pro",
      };

      if (emailField?.[1]) {
        properties.email = emailField[1];
        window.segmentflow?.identify({ traits: { email: emailField[1] } });
      }

      window.segmentflow?.track({
        event: "form_submitted",
        properties,
      });
    },
  );
}
```

### Build Configuration Update

```typescript
// tsdown.config.ts
import { defineConfig } from "tsdown";

export default defineConfig({
  entry: ["src/admin.ts", "src/storefront.ts"],
  format: "iife",
  outDir: "assets/js",
  platform: "browser",
  target: "es2020",
  minify: true,
  sourcemap: true,
  clean: true,
});
```

### Enqueue Storefront Script (PHP)

In `class-segmentflow-tracking.php`:

```php
public function enqueue_storefront_assets(): void {
    if ( is_admin() ) return;

    $script_path = SEGMENTFLOW_PLUGIN_DIR . 'assets/js/storefront.js';
    if ( ! file_exists( $script_path ) ) return;

    wp_enqueue_script(
        'segmentflow-storefront',
        SEGMENTFLOW_PLUGIN_URL . 'assets/js/storefront.js',
        [],
        filemtime( $script_path ),
        [ 'in_footer' => true, 'strategy' => 'defer' ]
    );
}
```

---

## Phase 4: Testing & Validation

**Goal**: End-to-end verification of all events in the local dev environment.

**Effort**: ~1–1.5 days

### Manual Testing Checklist

| # | Test | Expected Result |
|---|---|---|
| 1 | Visit a single product page | `product_viewed` event in Network tab |
| 2 | Click "Add to Cart" on shop page (AJAX) | `add_to_cart` with `source: "ajax"` |
| 3 | Click "Add to Cart" on single product page (form) | `add_to_cart` with `source: "single_product"` |
| 4 | Remove item from cart page | `remove_from_cart` event |
| 5 | Visit the cart page | `cart_viewed` with items array |
| 6 | Visit the checkout page | `checkout_started` with items array |
| 7 | Enter email on checkout (blur) | `identify()` + `checkout_email_captured` |
| 8 | Complete an order (COD) | `order_completed` with order details |
| 9 | Refresh the thank-you page | No duplicate `order_completed` |
| 10 | Submit a CF7 form | `form_submitted` with `form_plugin: "contact_form_7"` |
| 11 | Submit an Elementor Pro form | `form_submitted` with `form_plugin: "elementor_pro"` |
| 12 | SDK debug mode | All events logged to console |
| 13 | Events reach API | Check ingest logs / database |

### Automated Testing

- **PHP unit tests**: `tests/test-wc-tracking-data.php` — verify `get_product_data()`, `get_cart_data()`, `get_order_data()` return correct shapes.
- **SDK tests**: `packages/browser/sdk-web/src/plugins/__tests__/woocommerce.plugin.test.ts` — verify event firing based on mocked `window.__sf_wc` data.

### Edge Cases

| Edge Case | Handling |
|---|---|
| Variable product (size/color) | `product_viewed` fires with main product ID; `add_to_cart` includes `variation_id` |
| Grouped product | `product_viewed` for grouped product; child IDs on add-to-cart |
| Empty cart on cart/checkout | No `cart_viewed` / `checkout_started` (cart data is null) |
| Guest checkout | `checkout_email_captured` fires `identify()` with email |
| WooCommerce Blocks checkout | Email selector fallback handles React-based checkout |
| Thank-you page refresh | `already_tracked` flag prevents duplicate `order_completed` |
| Multiple add-to-cart clicks | Each fires separate `add_to_cart` (correct) |
| jQuery not loaded | Cart/email listeners gracefully skip |
| SDK not initialized | `storefront.ts` uses optional chaining (`window.segmentflow?.track()`) |

---

## Dependencies & Assumptions

1. **SDK loads via CDN before events fire** — PHP at priority 0 sets `window.__sf_wc`, SDK at priority 1, plugin reads in `load()`.
2. **jQuery is present** — WooCommerce requires jQuery. `added_to_cart` / `removed_from_cart` are jQuery events.
3. **WooCommerce classic checkout** is primary target — Blocks checkout handled by fallback selectors.
4. **SDK's `WooCommercePlugin` is auto-registered** in `cdn-entry.ts` — no registration changes needed.
5. **Server-side `order_completed`** from webhooks fires independently — client event is additive (browser context), not a replacement.

---

## Open Questions

1. **Should `product_viewed` fire on shop/category pages for each visible product?** Current plan: only on single product pages. Shop page product impressions could be a future enhancement.
2. **WooCommerce Blocks adoption**: Blocks cart/checkout uses React, not jQuery events. The `added_to_cart` / `removed_from_cart` jQuery events won't fire. This is a known gap for a follow-up.
3. **`add_to_cart` on single product pages**: The form submit approach works but navigates away. Should we use `navigator.sendBeacon()` instead of `analytics.flush()` for reliability?
