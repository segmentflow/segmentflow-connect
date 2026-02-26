# Unified Identity & Server-Side Event Architecture

**Created**: 2026-02-26
**Status**: Draft (Expanded)
**Estimated Effort**: ~7-10 days across 5 milestones
**Last Updated**: 2026-02-26
**Repos Affected**: `segmentflow-connect` (WordPress plugin), `segmentflow-ai` (CDN SDK + backend)

## Changelog

| Date       | Changes                                                                                                                                                                                                                                                                                                |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 2026-02-26 | Initial plan created. Research complete, implementation spec finalized.                                                                                                                                                                                                                                |
| 2026-02-26 | **Revised**: Discovered existing WooCommerce webhook infrastructure in `segmentflow-ai`. Eliminated Phases 1-2 (no new PHP server-side event class needed). Reduced scope to client-side cleanup only.                                                                                                 |
| 2026-02-26 | **Expanded**: Broadened scope from order race condition fix to full server-side event architecture with unified identity cookie. Added Milestones 2-5 for identity cookie, PHP event layer, SDK cleanup, and backend adjustments. See [How We Got Here](#how-we-got-here) for the full decision trail. |
| 2026-02-26 | **Cross-platform audit**: Verified plan compatibility with Shopify integration (SDK plugin, liquid embed, webhook service, identity bridge). Added Phase 5.2 (`source` field on batch endpoint), refined M2 cookie merge-on-write requirement, refined M4 form event dedup strategy.                   |

---

## Executive Summary

Build a complete server-side event layer in the WordPress plugin with a unified identity cookie that bridges PHP and SDK events. This follows the Klaviyo architecture pattern: browsing events from the browser, transactional/cart events from PHP hooks, order lifecycle events from webhooks.

The work started as a fix for an identity race condition on the WooCommerce thankyou page, and expanded after analysis revealed that the SDK was handling several event types that should be server-side (cart mutations, form submissions, identity). The plan is organized into 5 independently deployable milestones.

---

## How We Got Here

### Starting Point: The Identity Race Condition

On the WooCommerce order confirmation (thankyou) page, two competing `identify` calls fire:

```
Timeline on thankyou page load:

1. PHP renders <script> at wp_head priority 0:
   window.__sf_wc = { page: "thankyou", order: { billing_email: "customer@example.com", ... } }

2. PHP renders <script> at wp_head priority 1:
   Loads CDN SDK, calls init(), then:
   script.onload fires AFTER init() returns:
     segmentflow.identify({ userId: "wc_1", traits: { email: "admin@store.com" } })
                                                                  ^ WordPress user email (WRONG)

3. SDK init() calls register(WooCommercePlugin):
   WooCommercePlugin.load() runs synchronously:
     analytics.identify({ traits: { email: "customer@example.com" } })  <- billing email (CORRECT)
     analytics.track({ event: "order_completed", ... })

4. register() awaits plugin.load() -> microtask boundary -> init() returns

5. PHP script.onload callback continues (step 2):
   segmentflow.identify({ userId: "wc_1", traits: { email: "admin@store.com" } })
                                                                  ^ OVERWRITES the correct email
```

**Result**: The logged-in WordPress user's email always overwrites the customer's billing email because the PHP identify fires after the SDK identify due to the async `register()` microtask boundary.

**Impact**: Revenue attribution tied to wrong profile, customer never properly identified, affects every order placed by a logged-in WP user whose email differs from billing email.

**Root cause**: Not a timing bug fixable with `setTimeout` — it's a fundamental architectural conflict between server-rendered identity and client-side identity with no coordination mechanism.

**Previously attempted fixes** (deployed but insufficient):

1. Commit `660703d` (`segmentflow-connect`): Added `billing_email` to `window.__sf_wc.order`
2. Commit `a121e09` (`segmentflow-ai`): Added SDK identify with billing email before `order_completed`

Both ensure the SDK fires the correct identify, but the PHP identify still fires last and overwrites it.

### Discovery 1: Existing Webhook Infrastructure

The original plan proposed building a new PHP class (`Segmentflow_WC_Server_Events`) to push order events from PHP to `POST /api/v1/ingest/batch`. During audit, we discovered that `segmentflow-ai` **already has a complete WooCommerce webhook system** handling all order lifecycle events server-side:

| Component                     | File                                          | What It Does                                                                                         |
| ----------------------------- | --------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| **Webhook receiver endpoint** | `routes/v1/webhooks/woocommerce.ts`           | `POST /public/webhooks/woocommerce/:organizationId` — receives WC webhooks, responds 200 immediately |
| **HMAC-SHA256 verification**  | `services/v1/webhooks/woocommerce.service.ts` | Verifies webhook signature using per-store encrypted webhook secret                                  |
| **Order event handler**       | `woocommerce.service.ts:handleOrder()`        | Parses WC order payload, maps status to event name, extracts billing email, handles guest identity   |
| **Customer event handler**    | `woocommerce.service.ts:handleCustomer()`     | Identity stitching, trait extraction, profile creation                                               |
| **Event deduplication**       | Redis-based `isProcessed`/`markProcessed`     | Per-order, per-event-type dedup prevents duplicates                                                  |
| **Identity resolution**       | `appendIdentitySignal()` with billing email   | Links `wc_{customer_id}` or guest email to profile                                                   |
| **Revenue tracking**          | `order_transaction` event                     | Positive amounts for orders, negative for refunds                                                    |
| **Order status mapping**      | `WC_ORDER_STATUS_TO_EVENT`                    | `pending->order_created`, `processing->order_paid`, `completed->order_completed`, etc.               |
| **Webhook registration**      | `integrations/woocommerce.service.ts`         | Registers `order.created`, `order.updated`, `customer.*`, `product.*` during connection              |
| **Historical backfill**       | `woocommerce.service.ts:runHistoricalSync()`  | Paginated pull of all historical orders/customers/products on initial connection                     |

This meant the SDK's `case "thankyou"` block was a **duplicate** of what the webhook system already does — and the duplicate was causing the identity race condition. No new PHP server-side event class was needed for orders.

### Discovery 2: How Klaviyo Actually Works (Verified from SVN Source)

We reviewed Klaviyo's WordPress plugin source from `plugins.svn.wordpress.org/klaviyo/trunk/`. Key findings:

**Client-side JS (Klaviyo.js)**:

- `Viewed Product` — PHP builds product data, passes to JS via `wp_localize_script()`, JS fires the event
- `Started Checkout` — same hybrid approach: PHP builds checkout data, JS fires via `klaviyo.track()`
- Browser identity — `kl-identify-browser.js` identifies logged-in WP users and commenters

**Server-side PHP**:

- `Added to Cart` — `woocommerce_add_to_cart` action hook (priority 25), reads `__kla_id` cookie for identity, fire-and-forget `wp_remote_post()` with `'blocking' => false`
- Newsletter/SMS consent — `woocommerce_checkout_update_order_meta` hook + Blocks Store API extension with Action Scheduler for async delivery

**Webhooks (server-to-server)**:

- All order lifecycle events via WooCommerce REST API webhooks
- No `woocommerce_thankyou` hook, no client-side order events at all

**Not tracked by Klaviyo plugin**:

- No `remove_from_cart`
- No `cart_viewed`
- No form events (CF7, Elementor — not their market)

### Discovery 3: Klaviyo's Identity Cookie (`__kla_id`)

Klaviyo uses a single base64-encoded JSON cookie set by their client-side JS:

```json
{
  "$exchange_id": "abc123def456",
  "email": "customer@example.com",
  "$phone_number": "+1234567890"
}
```

- **JS sets it** and enriches it over time (anonymous ID on first visit, email on form submit, etc.)
- **PHP reads it** — `$_COOKIE['__kla_id']` decoded with `json_decode(base64_decode(...))`
- Used for identity in `kl_added_to_cart_event()` — if cookie doesn't exist, event is silently dropped
- Multiple identity signals in one cookie means PHP always has the best available identity

Why base64: Cookie-safe encoding for JSON with special characters (`{}`, `"`, `@` in emails).

### Discovery 4: The `add_to_cart` Gap

Comparing our SDK's approach to Klaviyo's revealed a coverage gap:

| Scenario                                 | Klaviyo (PHP hook) | Segmentflow (client JS)    |
| ---------------------------------------- | ------------------ | -------------------------- |
| Classic AJAX add-to-cart (archive pages) | Caught             | Caught (jQuery listener)   |
| Single product form submit               | Caught             | Caught (form listener)     |
| Blocks-based Store API                   | Caught             | Caught (fetch interceptor) |
| Programmatic `WC()->cart->add_to_cart()` | **Caught**         | **Missed**                 |
| Custom AJAX (some themes)                | **Caught**         | **Missed**                 |
| Headless / decoupled frontend            | **Caught**         | **Missed**                 |
| JavaScript disabled / blocked            | **Caught**         | **Missed**                 |
| Buy-now / quick-checkout plugins         | **Caught**         | **Missed**                 |

The SDK's three mechanisms cover ~95% of real stores, but a PHP hook catches 100%.

### Decision: Expand Scope to Full Architecture

After mapping the complete event landscape, it became clear that the order race condition fix was just one symptom of a broader architectural gap. The SDK was handling events that should be server-side (cart mutations), and there was no shared identity mechanism between PHP and JS.

We decided to build the full Klaviyo-style architecture:

1. **Fix the immediate bug** (order race condition) — Milestone 1
2. **Build a unified identity cookie** (like Klaviyo's `__kla_id`) — Milestone 2
3. **Add PHP server-side event hooks** (like Klaviyo's `woocommerce_add_to_cart`) — Milestone 3
4. **Clean up SDK** (remove now-redundant client-side cart listeners) — Milestone 4
5. **Backend adjustments** (non-blocking HTTP, source tagging) — Milestone 5

### Identity Bridging: Options Considered

Three options were evaluated for bridging PHP and SDK identity:

| Option       | Approach                                                     | Pros                                                                                    | Cons                                                                                             |
| ------------ | ------------------------------------------------------------ | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **Option 1** | Read `sf_anon_id` cookie from PHP (Klaviyo's exact approach) | Simple, one cookie read. SDK already sets this cookie.                                  | First-visit gap (cookie may not exist before JS runs). Safari ITP 7-day limit on JS-set cookies. |
| **Option 2** | Use WooCommerce session ID as bridge                         | WC session exists for every visitor. Not affected by ITP.                               | Changes needed in all three layers (PHP, SDK, backend identity). WC session ID changes on login. |
| **Option 3** | Read `sf_anon_id` + set it server-side from PHP if missing   | No first-visit gap. Server-set cookie immune to Safari ITP. SDK picks it up seamlessly. | Need PHP UUIDv7 generator. Two writers for same cookie.                                          |

**Chosen: Option 3** (improved Klaviyo approach), but with a **multi-signal cookie** instead of just the anonymous ID. Single base64-encoded JSON cookie carrying anonymousId, userId, email, and phone — so PHP always has the best available identity for any server-side event.

Klaviyo uses Option 1 (JS-only cookie), accepts the first-visit gap and Safari ITP limitation. Option 3 is strictly better — it eliminates both gaps while maintaining the same architecture.

---

## Architecture Overview

### Event Ownership (Final State)

Three parallel data paths feeding into the same unified ingest pipeline:

| Channel                                                        | Events                                                                                                     | Identity Source                        | How It Works                                                                                                       |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| **Client-side SDK** via `POST /api/v1/ingest/batch`            | `product_viewed`, `cart_viewed`, `checkout_started`, `checkout_email_captured`                             | `sf_id` cookie (anonymousId + userId)  | SDK fires browsing/funnel events. Auth via `writeKey`. Source: `"sdk"`.                                            |
| **PHP hooks** via `POST /api/v1/ingest/batch`                  | `add_to_cart`, `remove_from_cart`, `form_submitted`, `user_registered`, `comment_posted`                   | `sf_id` cookie (all fields)            | PHP fires cart mutations and identity capture events. Fire-and-forget. Auth via `writeKey`. Source: `"wordpress"`. |
| **WC Webhooks** via `POST /public/webhooks/woocommerce/:orgId` | `order_created`, `order_paid`, `order_completed`, `order_cancelled`, `order_refunded`, `order_transaction` | Billing email/phone from order payload | WooCommerce sends webhooks on order status changes. Auth via HMAC-SHA256. Source: `"woocommerce"`.                 |

### What Changed from the Original Two-Channel Architecture

| Before (2 channels)                               | After (3 channels)                                       |
| ------------------------------------------------- | -------------------------------------------------------- |
| SDK handles browsing + cart + order events        | SDK handles browsing events only                         |
| No PHP event hooks                                | PHP handles cart mutations + identity capture            |
| Webhooks handle orders                            | Webhooks handle orders (unchanged)                       |
| Two separate cookies (`sf_anon_id`, `sf_user_id`) | One unified cookie (`sf_id`) readable by both PHP and JS |
| Identity conflicts on thankyou page               | No competing identity calls                              |

### Comparison with Klaviyo's Architecture

| Aspect                  | Klaviyo                                        | Segmentflow (Final)                                      |
| ----------------------- | ---------------------------------------------- | -------------------------------------------------------- |
| **Identity cookie**     | `__kla_id` — base64 JSON, JS-set only          | `sf_id` — base64 JSON, PHP-set (primary) + JS-set        |
| **Safari ITP**          | 7-day cookie expiry (JS-set)                   | Full lifetime (server-set cookie not subject to ITP)     |
| **First-visit gap**     | Accepts gap — if cookie missing, event dropped | No gap — PHP ensures cookie exists before any hook fires |
| **Add to cart**         | PHP hook (`woocommerce_add_to_cart`)           | PHP hook (same)                                          |
| **Remove from cart**    | Not tracked                                    | PHP hook (`woocommerce_cart_item_removed`)               |
| **Viewed product**      | Hybrid: PHP builds data, JS fires event        | JS (SDK plugin)                                          |
| **Started checkout**    | Hybrid: PHP builds data, JS fires event        | JS (SDK plugin)                                          |
| **Order events**        | WC REST API webhooks                           | WC REST API webhooks (same)                              |
| **Form events**         | Not tracked (not their market)                 | PHP hooks (CF7, Elementor) + JS backup                   |
| **Delivery method**     | `wp_remote_post()` with `blocking: false`      | Same                                                     |
| **Consent at checkout** | PHP + Action Scheduler for Blocks              | Future consideration                                     |

---

## Implementation Plan

### Milestone 1: Fix Order Race Condition (~1 day)

Fixes the immediate bug. No identity cookie changes needed — just stop the SDK from duplicating what webhooks do, and stop the PHP identify from competing on the thankyou page.

#### Phase 1.1: Remove Client-Side `order_completed` from SDK

**Edit**: `segmentflow-ai/packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts`

Remove the entire `case "thankyou"` block from `firePageLoadEvents()` (lines 354-384):

```typescript
// REMOVE this entire block:
case "thankyou":
    if (data.order && !data.order.already_tracked) {
        if (data.order.billing_email) {
            analytics.identify({
                traits: { email: data.order.billing_email },
            });
        }

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
```

Also remove `order` from the `SfWcPageData` interface (lines 125-146).

The SDK will still load on the thankyou page for page view tracking and context enrichment.

**Edit tests**: `segmentflow-ai/packages/browser/sdk-web/test/woocommerce.plugin.test.ts`

- Remove `thankyouPageData()` test factory (lines 99-130)
- Remove "should fire identify with billing email then order_completed on thank-you page" test (line 280)
- Remove "should NOT fire order_completed or identify when already_tracked is true" test (line 301)

#### Phase 1.2: Stop Injecting Order Data Client-Side

**Edit**: `integrations/woocommerce/class-segmentflow-wc-tracking.php`

Remove the thankyou case from `inject_page_data()` (lines 126-131):

```php
// REMOVE:
if ( 'thankyou' === $page_type ) {
    $order_data = $this->get_order_data();
    if ( $order_data ) {
        $data['order'] = $order_data;
    }
}
```

Also remove the `get_order_data()` private method (lines 185-194) since it's no longer called.

#### Phase 1.3: Skip PHP Identify on Thankyou Page

**Edit**: `includes/class-segmentflow-tracking.php`

Add a guard to skip the PHP identify on the thankyou page (lines 133-153):

```php
if (wpContext.userId) {
    <?php if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) : ?>
    // On thankyou page: webhook-based identity handles this via appendIdentitySignal().
    <?php else : ?>
    var traits = { email: wpContext.userEmail };
    // ... existing identify logic ...
    window.segmentflow.identify(identifyParams);
    <?php endif; ?>
}
```

Webhook's `handleOrder()` calls `appendIdentitySignal()` with the authoritative billing email. Adding a client-side identify would create a competing signal.

#### Phase 1.4: Clean Up Dedup Logic

**Edit**: `integrations/woocommerce/class-segmentflow-wc-helper.php`

Remove `_sf_order_tracked` meta flag setting and `already_tracked` from `get_order_data()` (lines 145-179). The webhook system has its own Redis-based deduplication.

**Edit tests**: `tests/test-wc-tracking-data.php`

- Update `test_get_order_data_shape` (line 148): Remove assertion for `already_tracked` key
- Remove `test_get_order_data_dedup_flag` (line 202): Dedup is the webhook system's responsibility

#### Milestone 1 Deployment

```
Step 1: Deploy PHP changes (Phases 1.2, 1.3, 1.4)
        Effect: window.__sf_wc.order removed. SDK thankyou block becomes no-op.
                PHP identify skipped on thankyou. Webhooks unaffected.

Step 2: Test in local WordPress environment

Step 3: Deploy SDK changes (Phase 1.1)
        Commit + push to segmentflow-ai, trigger sdk-cdn-deploy.yml
```

Safe overlap: Between Step 1 and Step 3, old SDK's `if (data.order && ...)` evaluates to `false` because `data.order` is `undefined`.

---

### Milestone 2: Unified Identity Cookie (`sf_id`) (~2-3 days)

Replace the two separate cookies (`sf_anon_id`, `sf_user_id`) with a single base64-encoded JSON cookie that both PHP and JS can read and write.

#### Cookie Format

```
sf_id = base64(JSON({
  "a": "01926a3b-...",           // anonymousId (UUIDv7, always present)
  "u": "wc_42",                  // userId (when logged in, prefixed)
  "e": "customer@example.com",   // email (when known)
  "p": "+1234567890"             // phone (when known)
}))
```

Short keys to minimize cookie size (4KB limit). Cookie is set with:

- `httponly: false` — JS must be able to read it
- `SameSite: Lax` — sent on top-level navigations
- `Secure` — if HTTPS
- `path: /`
- Server-set cookies are immune to Safari ITP 7-day limit

#### Phase 2.1: PHP Identity Cookie Class

**New file**: `includes/class-segmentflow-identity-cookie.php`

```php
class Segmentflow_Identity_Cookie {
    const COOKIE_NAME = 'sf_id';

    // Read the cookie, return associative array or null
    public static function read(): ?array;

    // Write/merge fields into the cookie (preserves existing fields)
    public static function write(array $fields): void;

    // Get anonymousId, generating + setting server-side if missing
    public static function ensure_anonymous_id(): string;

    // Get the best available userId (from cookie or WP session)
    public static function get_user_id(string $prefix = 'wc_'): ?string;

    // Merge email into cookie
    public static function set_email(string $email): void;

    // Merge phone into cookie
    public static function set_phone(string $phone): void;

    // Generate UUIDv7 (timestamp-based, ~20 lines, no composer dependency)
    private static function generate_uuidv7(): string;
}
```

**Key behavior**:

- `ensure_anonymous_id()` is called on PHP `init` hook (priority 1, early). If `sf_id` cookie doesn't exist or has no `a` field, generates UUIDv7 and sets the cookie server-side via `setcookie()`.
- `read()` decodes base64, JSON-decodes, returns associative array. Falls back to reading legacy `sf_anon_id` cookie for migration.
- `write()` merges new fields with existing cookie contents, re-encodes, sets cookie.

**Critical: Merge-on-write semantics (both PHP and SDK)**:

Both PHP and JS write to the same `sf_id` cookie. Every write **must** read-then-merge to avoid destroying fields set by the other writer. Neither writer may overwrite the cookie with only its own fields.

PHP pattern (`Segmentflow_Identity_Cookie::write()`):

```php
public static function write(array $fields): void {
    $existing = self::read() ?? [];
    $merged = array_merge($existing, $fields); // new fields win on conflict
    $encoded = base64_encode(wp_json_encode($merged));
    setcookie(self::COOKIE_NAME, $encoded, ...);
}
```

SDK pattern (in `web.ts`):

```typescript
function updateSfIdCookie(updates: Partial<SfIdData>): void {
  const existing = readSfIdCookie() ?? {};
  const merged = { ...existing, ...updates }; // new fields win on conflict
  document.cookie = `sf_id=${btoa(JSON.stringify(merged))}; ...`;
}
```

Timeline safety: PHP writes on `init` (before page render). JS writes on user actions (after page render). No simultaneous writes possible within a single page load. Across navigations, the browser sends the latest cookie value, so both writers always read the most recent state.

#### Phase 2.2: Wire Cookie Into Orchestrator

**Edit**: `includes/class-segmentflow.php`

```php
// In load_dependencies():
require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-identity-cookie.php';

// In init_classes():
add_action('init', [Segmentflow_Identity_Cookie::class, 'ensure_anonymous_id'], 1);
```

#### Phase 2.3: Update PHP Tracking to Use Identity Cookie

**Edit**: `includes/class-segmentflow-tracking.php`

Update `inject_sdk()` to read identity from the `sf_id` cookie instead of only `get_current_user_id()`:

```php
// Read identity from unified cookie
$sf_identity = Segmentflow_Identity_Cookie::read();
$user_id     = $sf_identity['u'] ?? null;
$user_email  = $sf_identity['e'] ?? (is_user_logged_in() ? wp_get_current_user()->user_email : null);
```

Also update the PHP identify to write back to the cookie when it fires (on non-thankyou pages):

```php
// After identify fires for logged-in user, update cookie
if (is_user_logged_in()) {
    Segmentflow_Identity_Cookie::write([
        'u' => $prefix . get_current_user_id(),
        'e' => wp_get_current_user()->user_email,
    ]);
}
```

#### Phase 2.4: Update SDK to Read/Write `sf_id`

**Edit**: `segmentflow-ai/packages/browser/sdk-web/src/web.ts`

On constructor:

1. Read `sf_id` cookie first, parse base64 JSON, extract `a` (anonymousId), `u` (userId)
2. Fall back to legacy `sf_anon_id`/`sf_user_id` cookies for migration
3. If `sf_id` found, use its values; otherwise generate new anonymousId and write `sf_id`

On `identify()`:

1. Update `sf_id` cookie with `u` (userId) and `e` (email from traits)

On `reset()`:

1. Clear `sf_id`, generate new anonymousId, write new `sf_id`

On WooCommerce plugin `checkout_email_captured`:

1. Update `sf_id` cookie with `e` (email)

Keep writing legacy cookies during a transition period (~30 days), then remove.

#### Phase 2.5: Tests

**New file**: `tests/test-identity-cookie.php`

- Test `read()` / `write()` round-trip
- Test `ensure_anonymous_id()` generates UUIDv7 when cookie missing
- Test migration from legacy `sf_anon_id` cookie
- Test `set_email()` / `set_phone()` merge behavior (preserves existing fields)

**Update**: SDK tests for new cookie format.

---

### Milestone 3: PHP Server-Side Event Layer (~3-4 days)

New PHP classes that hook into WordPress and WooCommerce action hooks and send events to the ingest API.

#### Event Inventory

**WooCommerce Shopping Events** (new class: `integrations/woocommerce/class-segmentflow-wc-server-events.php`):

| Hook                                   | Priority | Event Name                 | Identity Source                                 | Properties                                                                                               |
| -------------------------------------- | -------- | -------------------------- | ----------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `woocommerce_add_to_cart`              | 25       | `add_to_cart`              | `sf_id` cookie                                  | product_id, name, price, sku, quantity, variation_id, currency, source: "server"                         |
| `woocommerce_cart_item_removed`        | 10       | `remove_from_cart`         | `sf_id` cookie                                  | product_id, name, currency, source: "server"                                                             |
| `woocommerce_checkout_order_processed` | 10       | identify (stitching event) | `sf_id` cookie + billing email/phone from order | Merges billing email + phone into cookie. Sends identify to stitch anonymous browsing to known customer. |

**WordPress Identity Events** (new class: `includes/class-segmentflow-server-events.php`):

| Hook                | Event Name                           | Identity Captured                                  |
| ------------------- | ------------------------------------ | -------------------------------------------------- |
| `user_register`     | `identify` + `user_registered` track | WP user email -> cookie `e`, user ID -> cookie `u` |
| `wp_login`          | `identify`                           | WP user email -> cookie `e`, user ID -> cookie `u` |
| `wp_insert_comment` | `identify` + `comment_posted` track  | Comment author email -> cookie `e`                 |

**Form Events** (PHP backup hooks, supplements existing JS in `storefront.ts`):

| Hook                                             | Event Name                          | Identity Captured              |
| ------------------------------------------------ | ----------------------------------- | ------------------------------ |
| `wpcf7_mail_sent` (Contact Form 7)               | `identify` + `form_submitted` track | Form email field -> cookie `e` |
| `elementor_pro/forms/new_record` (Elementor Pro) | `identify` + `form_submitted` track | Form email field -> cookie `e` |

#### Architecture Pattern

Every hook callback follows the same pattern:

```php
public function on_add_to_cart($cart_item_key, $product_id, $quantity, ...) {
    // 1. Read identity from cookie
    $identity = Segmentflow_Identity_Cookie::read();
    if (!$identity || empty($identity['a'])) {
        return; // Can't identify visitor, skip (Klaviyo does the same)
    }

    // 2. If new identity info available, merge into cookie
    // (e.g., on checkout: set_email(), set_phone())

    // 3. Build event payload
    $product = wc_get_product($product_id);
    if (!$product) return;

    $event = [
        'type'        => 'track',
        'event'       => 'add_to_cart',
        'userId'      => $identity['u'] ?? $identity['a'],
        'anonymousId' => $identity['a'],
        'properties'  => [
            'product_id' => $product_id,
            'name'       => $product->get_name(),
            'price'      => $product->get_price(),
            'sku'        => $product->get_sku(),
            'quantity'   => $quantity,
            'currency'   => get_woocommerce_currency(),
            'source'     => 'server',
        ],
    ];

    // 4. Fire-and-forget POST to ingest API
    $this->send_event($event);
}

private function send_event(array $event): void {
    $this->api->request('POST', '/api/v1/ingest/batch', [
        'writeKey' => $this->options->get_write_key(),
        'batch'    => [$event],
    ], [], ['blocking' => false]);
}
```

#### Phase 3.1: WooCommerce Server Events Class

**New file**: `integrations/woocommerce/class-segmentflow-wc-server-events.php`

```php
class Segmentflow_WC_Server_Events {
    private Segmentflow_Options $options;
    private Segmentflow_API $api;

    public function register_hooks(): void {
        add_action('woocommerce_add_to_cart', [$this, 'on_add_to_cart'], 25, 6);
        add_action('woocommerce_cart_item_removed', [$this, 'on_remove_from_cart'], 10, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'on_checkout'], 10, 3);
    }
}
```

Priority 25 for `add_to_cart` matches Klaviyo — fires after WooCommerce's own hooks (priority 20) to ensure cart state is complete.

The `on_checkout()` handler is important for identity stitching. It fires at the moment of purchase, before the webhook (which is async), and merges the billing email/phone into the cookie + sends an identify event. This creates an immediate bridge between anonymous browsing and the known customer profile.

#### Phase 3.2: WordPress Core Server Events Class

**New file**: `includes/class-segmentflow-server-events.php`

```php
class Segmentflow_Server_Events {
    private Segmentflow_Options $options;
    private Segmentflow_API $api;

    public function register_hooks(): void {
        add_action('user_register', [$this, 'on_user_register'], 10, 2);
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_insert_comment', [$this, 'on_comment'], 10, 2);

        // Form plugins (only if active)
        if (defined('WPCF7_VERSION')) {
            add_action('wpcf7_mail_sent', [$this, 'on_cf7_submit'], 10, 1);
        }
        if (defined('ELEMENTOR_PRO_VERSION')) {
            add_action('elementor_pro/forms/new_record', [$this, 'on_elementor_submit'], 10, 2);
        }
    }
}
```

#### Phase 3.3: Wire Into Orchestrator

**Edit**: `includes/class-segmentflow.php`

```php
// In load_dependencies():
require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-server-events.php';

// Conditional WC:
if (Segmentflow_Helper::is_woocommerce_active()) {
    require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-server-events.php';
}

// In init_classes():
$api = new Segmentflow_API($options);

$server_events = new Segmentflow_Server_Events($options, $api);
$server_events->register_hooks();

if (Segmentflow_Helper::is_woocommerce_active()) {
    $wc_server_events = new Segmentflow_WC_Server_Events($options, $api);
    $wc_server_events->register_hooks();
}
```

#### Phase 3.4: Tests

**New file**: `tests/test-wc-server-events.php`

- Test `on_add_to_cart` builds correct payload
- Test `on_remove_from_cart` builds correct payload
- Test `on_checkout` merges email/phone into cookie and sends identify
- Test events are skipped when cookie has no anonymous ID
- Test events are skipped when not connected (no write key)

**New file**: `tests/test-server-events.php`

- Test `on_user_register` sends identify with WP email
- Test `on_login` updates cookie identity
- Test `on_comment` captures comment author email
- Test `on_cf7_submit` extracts email from CF7 inputs

---

### Milestone 4: SDK Cleanup (~1 day)

After Milestone 3 is deployed and verified, remove the now-redundant client-side cart event listeners from the SDK. PHP hooks are the authoritative source.

#### Phase 4.1: Remove Client-Side Cart Listeners

**Edit**: `segmentflow-ai/packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts`

- Remove `bindCartListeners()` method (lines 397-488) — jQuery `added_to_cart`, `removed_from_cart`, form submit listener
- Remove `bindBlocksCartListeners()` method (lines 669-758) — fetch interceptor for Store API
- Remove calls to both methods from `load()` (lines 288-289)
- Remove `WcAddToCartParams` interface (lines 40-48)
- Remove the `wc_add_to_cart_params` property from `WooCommerceWindow` (line 153)
- Remove the `wc_add_to_cart_params` check from `getWooCommerceContext()` (line 185)

**Keep**:

- `bindCheckoutEmailListener()` / `bindBlocksCheckoutEmailListener()` — still needed for real-time email capture. The PHP `woocommerce_checkout_order_processed` hook fires on form submit; the JS blur listener captures email earlier for immediate cookie enrichment.
- All page-load events (`product_viewed`, `cart_viewed`, `checkout_started`) — these require browser context.

#### Phase 4.2: Update storefront.ts (Form Event Dedup Strategy)

**Edit**: `src/storefront.ts`

After M3 adds PHP hooks for CF7 and Elementor forms, both JS and PHP would fire `form_submitted` track events for the same submission. The backend has no dedup for track events from different sources (unlike order events which have Redis dedup by order ID). This would cause duplicate events, inaccurate analytics, and potentially duplicate journey triggers.

**Strategy**: Make PHP authoritative for the `form_submitted` track event. Keep the JS `identify()` call for immediate browser-side cookie enrichment (updates `sf_id` with the form email before the next navigation). Remove the JS `track('form_submitted')` call.

Changes to `storefront.ts`:

- **Keep**: JS `identify()` calls on CF7 `wpcf7mailsent` and Elementor form success — these update the `sf_id` cookie immediately in the browser with the captured email
- **Remove**: JS `track('form_submitted')` calls — PHP hooks handle these authoritatively via `wpcf7_mail_sent` and `elementor_pro/forms/new_record`
- **Result**: One `form_submitted` track event per submission (from PHP), plus one `identify` call (from JS for immediate cookie enrichment, and separately from PHP for identity stitching via the batch endpoint)

#### Phase 4.3: Update Tests

**Edit**: `segmentflow-ai/packages/browser/sdk-web/test/woocommerce.plugin.test.ts`

- Remove add-to-cart tests (AJAX, form submit, Blocks fetch intercept)
- Remove remove-from-cart tests
- Keep email capture tests
- Keep page-load event tests

---

### Milestone 5: Backend & API Adjustments (~0.5 day)

Small changes to support PHP-originated events: non-blocking HTTP for fire-and-forget delivery, and `source` field propagation through the batch ingest endpoint.

#### Phase 5.1: Non-Blocking HTTP in API Client

**Edit**: `includes/class-segmentflow-api.php`

Add support for `'blocking' => false` in the `request()` method:

```php
public function request(string $method, string $endpoint, array $body = [], array $headers = [], array $options = []): array {
    // ... existing setup ...

    $args = [
        'method'   => strtoupper($method),
        'headers'  => array_merge($default_headers, $headers),
        'timeout'  => $options['blocking'] === false ? 0.5 : 15,
        'blocking' => $options['blocking'] ?? true,
    ];

    // ... rest of method ...
}
```

When `blocking: false`, `wp_remote_request()` sends the request and returns immediately without waiting for a response. The 0.5s timeout is a safety net.

#### Phase 5.2: Add `source` Field to Batch Endpoint

**Problem**: The batch endpoint (`POST /api/v1/ingest/batch`) is the only ingest route that doesn't propagate a `source` field to the stored `UserEvent` record. The dedicated `/ingest/track` route hardcodes `source: "sdk"`, the `/ingest/identify` route hardcodes `source: "sdk"`, and the webhook services pass `source: "shopify"` or `source: "woocommerce"`. But `submitUserEvent()` (called by the batch handler) never forwards `source` to `createEvent()`, so all SDK batch events and future PHP batch events are stored with `source: null`.

**Why**: The `source` field is stored on the `UserEvent` database record (`source String?` in Prisma schema). It enables filtering/debugging events by origin ("was this `add_to_cart` from the SDK or PHP?"), prevents duplicate analysis during the M3→M4 transition (both SDK and PHP firing temporarily), and provides operational visibility for all event sources.

**Edit 1**: `segmentflow-ai/packages/node/sdk-core/src/events/types.ts`

Add `source` to `BaseEvent` (propagates to all event types via `extends`):

```typescript
export interface BaseEvent {
  messageId?: string;
  timestamp?: string;
  context?: Record<string, unknown>;
  anonymousId?: string;
  userId?: string;
  source?: string; // Event origin: "sdk", "wordpress", "woocommerce", etc.
}
```

**Edit 2**: `segmentflow-ai/packages/browser/sdk-web/src/web.ts`

Add `source: "sdk"` in the `enrich()` method so all SDK events are tagged:

```typescript
private enrich<T extends Record<string, unknown>>(params: T) {
  return {
    ...params,
    userId: this.userId,
    anonymousId: this.anonymousId,
    source: "sdk", // NEW — tag all SDK-originated events
    context: {
      ...getGlobalContext(),
      ...(params.context as Record<string, unknown> | undefined),
    },
  };
}
```

**Edit 3**: `segmentflow-ai/services/node/api/src/routes/v1/ingest/batch.ts`

Add `source` to the Zod `BaseEventFields`:

```typescript
const BaseEventFields = {
  messageId: z.string().optional(),
  timestamp: z.string().datetime({ offset: true }).optional(),
  context: z.record(z.string(), z.unknown()).optional(),
  anonymousId: z.string().optional(),
  userId: z.string().optional(),
  source: z.string().optional(), // NEW — event origin
};
```

No other schema changes needed — `BaseEventFields` spreads into all three discriminated union branches.

**Edit 4**: `segmentflow-ai/services/node/api/src/services/v1/ingest/event-ingestion.service.ts`

Add `source` to `UserEventInput` interface and forward it in `submitUserEvent()`:

```typescript
// Add to UserEventInput interface:
export interface UserEventInput extends BaseUserEvent {
  // ... existing fields ...
  source?: string; // NEW — event origin
}

// In submitUserEvent(), forward to createEvent():
await createEvent({
  organizationId: payload.organizationId,
  userId: payload.userId,
  eventType,
  properties,
  timestamp: payload.timestamp ? new Date(payload.timestamp) : new Date(),
  source: payload.source, // NEW — forward source from batch item
});
```

**Source values by integration**:

| Integration                    | `source` value  | Set by                               |
| ------------------------------ | --------------- | ------------------------------------ |
| SDK (browser) → batch          | `"sdk"`         | `web.ts` `enrich()` method           |
| PHP WordPress events → batch   | `"wordpress"`   | PHP `Segmentflow_Server_Events`      |
| PHP WooCommerce events → batch | `"wordpress"`   | PHP `Segmentflow_WC_Server_Events`   |
| Shopify webhooks               | `"shopify"`     | `shopify.service.ts` (unchanged)     |
| WooCommerce webhooks           | `"woocommerce"` | `woocommerce.service.ts` (unchanged) |
| SDK → `/track` (direct)        | `"sdk"`         | `track.ts` hardcoded (unchanged)     |
| SDK → `/identify` (direct)     | `"sdk"`         | `identify.ts` hardcoded (unchanged)  |

**Backward compatibility**: The field is `z.string().optional()`. Existing clients that don't send it get `source: null` in the database (same as today). The Prisma `UserEvent.source` column is already `String?` — no migration needed.

**Deploy order**: Backend first (accepts and forwards `source`), then SDK (sends `source: "sdk"`). Between deploys, SDK events continue to store `source: null` — same as today.

#### Phase 5.3: Verify Batch Endpoint Handles PHP Events

The batch endpoint already:

- Accepts `anonymousId` in the event schema (`anonymousId: z.string().optional()`)
- Falls back to anonymousId when userId is absent (`userId: item.userId ?? item.anonymousId ?? "anonymous"`)
- Processes identity signals via `submitUserEvent()` -> `processIdentitySignals()`

**Verified**: PHP events with `userId` set to the anonymous ID (for guests without a WP account) are handled correctly. The `processIdentitySignals()` function already extracts `anonymousId` and appends to IdentityBridge. No changes needed.

---

## Deployment Strategy

```
Milestone 1 (Order race condition fix):
  Deploy PHP (1.2, 1.3, 1.4) -> test locally -> deploy SDK (1.1)
  Estimated: 1 day

Milestone 5 (Backend + API adjustments):
  Phase 5.1: Deploy API client non-blocking option (PHP plugin)
  Phase 5.2: Deploy backend `source` field (batch.ts, event-ingestion.service.ts) FIRST
             Then deploy SDK `source: "sdk"` (sdk-core types, web.ts)
  Phase 5.3: Verify batch endpoint handles PHP events (no code change)
  Can be done alongside M1/M2, needed by M3
  Estimated: 0.5 day

Milestone 2 (Identity cookie):
  Deploy PHP cookie class + orchestrator wiring -> deploy SDK cookie changes
  Estimated: 2-3 days

Milestone 3 (PHP server events):
  Deploy WC server events + WP server events + orchestrator wiring
  PHP events will include source: "wordpress" on batch items
  Estimated: 3-4 days

Milestone 4 (SDK cleanup):
  Deploy SDK changes (remove cart listeners, form track events) after M3 verified
  Keep JS identify() for form email capture (immediate cookie enrichment)
  Estimated: 1 day
```

Milestones 1 and 5 can be done in parallel. M2 depends on M5 (needs non-blocking API for future use). M3 depends on M2 (needs identity cookie) and M5 (needs `source` field on batch endpoint). M4 depends on M3 (needs PHP events to replace SDK events).

---

## File Change Summary

### `segmentflow-connect` (WordPress Plugin)

| File                                                              | Action   | Milestone | Description                                                                                   |
| ----------------------------------------------------------------- | -------- | --------- | --------------------------------------------------------------------------------------------- |
| `includes/class-segmentflow-identity-cookie.php`                  | **NEW**  | M2        | Unified identity cookie read/write with UUIDv7 generation                                     |
| `includes/class-segmentflow-server-events.php`                    | **NEW**  | M3        | WP core server events (registration, login, comment, forms)                                   |
| `integrations/woocommerce/class-segmentflow-wc-server-events.php` | **NEW**  | M3        | WC server events (add_to_cart, remove_from_cart, checkout)                                    |
| `includes/class-segmentflow.php`                                  | **EDIT** | M2, M3    | Wire new classes into orchestrator                                                            |
| `includes/class-segmentflow-tracking.php`                         | **EDIT** | M1, M2    | Skip thankyou identify (M1), use identity cookie (M2)                                         |
| `includes/class-segmentflow-api.php`                              | **EDIT** | M5        | Add non-blocking request option                                                               |
| `integrations/woocommerce/class-segmentflow-wc-tracking.php`      | **EDIT** | M1        | Remove order data injection and `get_order_data()` method                                     |
| `integrations/woocommerce/class-segmentflow-wc-helper.php`        | **EDIT** | M1        | Remove dedup flag logic                                                                       |
| `src/storefront.ts`                                               | **EDIT** | M4        | Remove form `track()` calls (PHP authoritative), keep form `identify()` for cookie enrichment |
| `tests/test-wc-tracking-data.php`                                 | **EDIT** | M1        | Update order data shape test, remove dedup test                                               |
| `tests/test-identity-cookie.php`                                  | **NEW**  | M2        | Identity cookie unit tests                                                                    |
| `tests/test-wc-server-events.php`                                 | **NEW**  | M3        | WC server events unit tests                                                                   |
| `tests/test-server-events.php`                                    | **NEW**  | M3        | WP server events unit tests                                                                   |

### `segmentflow-ai` (CDN SDK + Backend)

| File                                                                  | Action   | Milestone  | Description                                                                                        |
| --------------------------------------------------------------------- | -------- | ---------- | -------------------------------------------------------------------------------------------------- |
| `packages/node/sdk-core/src/events/types.ts`                          | **EDIT** | M5         | Add `source` to `BaseEvent` interface                                                              |
| `packages/browser/sdk-web/src/web.ts`                                 | **EDIT** | M2, M5     | Read/write `sf_id` unified cookie (M2), add `source: "sdk"` to `enrich()` (M5)                     |
| `packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts`          | **EDIT** | M1, M2, M4 | Remove thankyou block (M1), update checkout email to write cookie (M2), remove cart listeners (M4) |
| `packages/browser/sdk-web/test/woocommerce.plugin.test.ts`            | **EDIT** | M1, M4     | Remove thankyou tests (M1), remove cart event tests (M4)                                           |
| `services/node/api/src/routes/v1/ingest/batch.ts`                     | **EDIT** | M5         | Add `source` to Zod `BaseEventFields` schema                                                       |
| `services/node/api/src/services/v1/ingest/event-ingestion.service.ts` | **EDIT** | M5         | Add `source` to `UserEventInput`, forward in `submitUserEvent()` to `createEvent()`                |

### Minimal Backend Changes

| Component                      | Change?       | Detail                                                                                            |
| ------------------------------ | ------------- | ------------------------------------------------------------------------------------------------- |
| Batch ingest route             | **EDIT (M5)** | Add `source` to Zod schema — 1 line                                                               |
| Event ingestion service        | **EDIT (M5)** | Add `source` to `UserEventInput` interface, forward to `createEvent()` — 2 lines                  |
| Backend webhook services       | Unchanged     | `woocommerce.service.ts`, `shopify.service.ts` already pass `source` via `submitTrack()` directly |
| Backend ingest pipeline        | Unchanged     | `submitTrack()`, `submitIdentify()`, `appendIdentitySignal()` unchanged                           |
| Database schema                | Unchanged     | `UserEvent.source` column already exists as `String?` — no migration needed                       |
| Identity resolution (Temporal) | Unchanged     | Existing append-only IdentityBridge handles new signal sources                                    |

---

## Testing Plan

### Milestone 1: Order Race Condition Fix

1. Place test order as logged-in user whose WP email differs from billing email
2. Verify no client-side `order_completed` fires (DevTools Network tab)
3. Verify PHP identify skipped on thankyou page
4. Verify `window.__sf_wc` has no `order` key on thankyou page
5. Verify other pages (product, cart, checkout) unaffected
6. Verify webhook events still deliver `order_completed` in backend

### Milestone 2: Identity Cookie

1. Visit store as anonymous user — verify `sf_id` cookie set with `a` field (server-set)
2. Log in — verify `sf_id` updated with `u` and `e` fields
3. Enter email on checkout — verify `sf_id` updated with `e` field
4. Clear cookies, visit again — verify new anonymous ID generated
5. Test migration: set legacy `sf_anon_id` cookie, verify SDK reads it and migrates to `sf_id`
6. Test Safari ITP: verify server-set `sf_id` cookie persists beyond 7 days

### Milestone 3: PHP Server Events

1. Add product to cart — verify `add_to_cart` event arrives in backend with `source: "wordpress"` or equivalent
2. Remove product from cart — verify `remove_from_cart` event arrives
3. Complete checkout — verify identify event stitches anonymous ID to billing email
4. Register new user — verify `user_registered` event + identify with email
5. Post comment — verify `comment_posted` event + identify with comment author email
6. Submit CF7 form — verify `form_submitted` event + identify with form email

### Milestone 4: SDK Cleanup

1. Add product to cart — verify event comes from PHP only (no SDK network call)
2. Verify product_viewed, cart_viewed, checkout_started still fire from SDK
3. Verify checkout email capture still fires from SDK
4. Submit CF7 form — verify one `form_submitted` track (from PHP) and one `identify` (from JS)
5. Run full SDK test suite — all remaining tests pass

### Milestone 5: Backend & API Adjustments

1. Verify SDK batch requests include `source: "sdk"` on every event (DevTools Network tab)
2. Verify stored `UserEvent` records have `source: "sdk"` (database query)
3. Verify Shopify webhook events still have `source: "shopify"` (unchanged)
4. Verify WooCommerce webhook events still have `source: "woocommerce"` (unchanged)
5. Send a test batch request without `source` field — verify it stores `source: null` (backward compat)
6. Verify `wp_remote_post()` with `blocking: false` returns immediately and event arrives in backend

---

## Risks & Mitigations

| Risk                                                       | Impact                                          | Mitigation                                                                                                                                                                   |
| ---------------------------------------------------------- | ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| WC webhooks not registered for a store                     | Order events not delivered                      | Check `webhooksRegistered` flag. Dashboard shows webhook status. Re-registration on reconnect.                                                                               |
| Cookie too large (4KB limit)                               | Cookie silently dropped by browser              | Short keys (`a`, `u`, `e`, `p`). Monitor cookie size. No unbounded fields.                                                                                                   |
| PHP `wp_remote_post` with `blocking: false` killed by host | Events lost                                     | Action Scheduler queue as future enhancement. Fire-and-forget is acceptable for Klaviyo (same approach).                                                                     |
| Safari ITP changes                                         | Server-set cookies may be affected in future    | Monitor WebKit ITP updates. Server-set first-party cookies are currently unaffected.                                                                                         |
| CDN cache serves old SDK during M4 transition              | SDK fires duplicate cart events alongside PHP   | Deploy M3 (PHP events) first. Briefly both fire. Backend deduplication is eventual (IdentityBridge append-only). Remove SDK listeners in M4 after verifying PHP events work. |
| Legacy cookie migration period                             | Some visitors have `sf_anon_id` but not `sf_id` | Both PHP and SDK read legacy cookies as fallback during 30-day transition.                                                                                                   |
| Two writers for same cookie (PHP + JS)                     | Race condition between cookie writes            | Last-write-wins is acceptable — both writers merge, not overwrite. PHP writes on `init` (before page render). JS writes on user actions (after page render).                 |

---

## Decision Log

| Decision                                         | Options Considered                                                                            | Chosen                  | Rationale                                                                                                                                                            |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------- | ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Order event mechanism                            | New PHP class vs Existing WC webhooks                                                         | Existing webhooks       | Already built with full lifecycle, dedup, identity, revenue tracking.                                                                                                |
| Identity cookie format                           | Separate cookies vs Single base64 JSON                                                        | Single base64 JSON      | Matches Klaviyo pattern. One read/write for all identity fields. Cookie-safe encoding.                                                                               |
| Cookie writer                                    | JS-only (Klaviyo) vs PHP-only vs Both (PHP primary)                                           | Both (PHP primary)      | Improves on Klaviyo: no first-visit gap, no Safari ITP 7-day limit.                                                                                                  |
| Identity bridging approach                       | Read `sf_anon_id` (Option 1) vs WC session (Option 2) vs Server-set + multi-signal (Option 3) | Option 3                | Eliminates first-visit gap and ITP issue. No backend identity system changes needed (uses existing `anon` bridge type).                                              |
| `add_to_cart` source                             | Keep client-side vs Move to PHP hook vs Both                                                  | Move to PHP hook        | Catches 100% of cart additions (programmatic, headless, custom themes). Matches Klaviyo approach.                                                                    |
| `remove_from_cart` source                        | Keep client-side vs Move to PHP hook                                                          | Move to PHP hook        | Consistent with `add_to_cart` decision. Klaviyo doesn't track this, but we should.                                                                                   |
| Form events (CF7, Elementor)                     | Keep JS-only vs Add PHP backup vs Replace with PHP                                            | Add PHP backup          | JS provides instant client-side enrichment, PHP provides reliability when JS blocked. Both fire with different source tags.                                          |
| PHP HTTP delivery                                | Blocking vs Fire-and-forget vs Action Scheduler                                               | Fire-and-forget         | Matches Klaviyo exactly. No page load latency. Acceptable loss rate for events. Action Scheduler as future enhancement.                                              |
| WordPress core events (register, login, comment) | In scope vs Deferred                                                                          | In scope                | Small incremental work once identity cookie and server event pattern are built. High value for identity capture.                                                     |
| Batch endpoint `source` field                    | Add to schema (Option A) vs Extract from context (Option B)                                   | Option A (schema)       | Explicit, self-documenting, matches webhook pattern, avoids context-merge bug where `source` gets buried in `properties.context`.                                    |
| Cookie write semantics                           | Overwrite vs Merge-on-write                                                                   | Merge-on-write          | Essential for two-writer safety. Both PHP and SDK must read-then-merge to avoid destroying fields set by the other writer.                                           |
| Form event dedup (CF7, Elementor)                | Accept duplicates vs PHP authoritative + JS identify only vs Backend dedup                    | PHP track + JS identify | PHP fires authoritative `form_submitted` track. JS fires `identify()` only for immediate cookie enrichment. One track event per submission, no backend dedup needed. |

---

## What Was Eliminated from Earlier Plans

| Component                                                                  | Eliminated?  | Reason                                                                                                    |
| -------------------------------------------------------------------------- | ------------ | --------------------------------------------------------------------------------------------------------- |
| New PHP class for order events (`Segmentflow_WC_Server_Events` for orders) | **Yes**      | Backend webhook system already handles full order lifecycle with dedup, identity, revenue tracking        |
| `woocommerce_thankyou` PHP hook for order events                           | **Yes**      | Requires page visit, no retry, blocks page render, single lifecycle moment only. Webhooks are superior.   |
| WooCommerce session ID as identity bridge (Option 2)                       | **Yes**      | Required changes in all three layers. Session ID changes on login (migration complexity).                 |
| JS-only cookie (Option 1, Klaviyo exact)                                   | **Improved** | Option 3 eliminates first-visit gap and Safari ITP limit while maintaining the same architecture pattern. |
