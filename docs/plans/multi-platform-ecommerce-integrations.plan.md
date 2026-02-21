# Multi-Platform E-Commerce Integrations Plan

**Created**: 2026-02-21
**Status**: Draft
**Estimated Effort**: Phase 1 (WooCommerce + Segmentflow Connect Plugin): ~18.5 working days | Phase 2 (BigCommerce): ~5-7 days
**Last Updated**: 2026-02-21

## Changelog

| Date | Changes |
|------|---------|
| 2026-02-21 | Initial plan created. WooCommerce as first integration, BigCommerce as follow-up. |
| 2026-02-21 | Updated WooCommerce approach: WordPress plugin (`segmentflow-woocommerce-plugin`) in separate public repo, CDN-loaded SDK, dual-direction auto-auth connection flow, WooCommerce SDK plugin. |
| 2026-02-21 | Added comprehensive repo setup for `segmentflow-woocommerce-plugin`: TypeScript (tsdown IIFE build), ESLint 9, Prettier, PHPCS + WordPress Coding Standards, PHPUnit with WP test suite, Changesets for automated releases, GitHub Actions CI/CD (lint + test matrix + WordPress.org SVN deploy), Husky + lint-staged. |
| 2026-02-21 | **Unified plugin redesign**: Renamed from `segmentflow-woocommerce-plugin` to `segmentflow-connect`. Single WordPress plugin works on ANY WordPress site (page views + identify for logged-in users) with conditional WooCommerce enrichment (cart context, WC customer identity, currency). Adopted Smaily Connect-style orchestrator pattern with `integrations/` directory for conditional loading. Repo renamed to `segmentflow-connect`. Plugin supports plain WordPress sites in Phase 1 (no WooCommerce required). Future integrations (CF7, Elementor, etc.) drop into `integrations/` without restructuring. Updated: repo structure, plugin architecture, connection flows, dashboard UI, implementation timeline (+1 day), new files list, Phase 3 scope, testing strategy, success criteria. |

---

## Executive Summary

Segmentflow currently supports a single e-commerce integration (Shopify). The Shopify App Store verification process is slow, creating a business risk. This plan adds WooCommerce as the first additional integration, followed by BigCommerce. The goal is to expand platform coverage from ~28% (Shopify only) to ~69% of the e-commerce market.

### Business Context

Segmentflow's value proposition is:

1. **Talk to your data** -- Natural language segmentation ("Customers who bought twice but haven't opened an email in a month")
2. **Brand-aware creative** -- Auto-pull logos, product photos, and color palettes to generate ready-to-send templates
3. **One-click campaign sending** -- Streamlined campaign launch with real-time delivery stats
4. **Revenue attribution** -- Track the exact dollar amount every campaign puts into a merchant's pocket

All four features depend on deep integration with the merchant's e-commerce platform. Expanding beyond Shopify unlocks these features for WooCommerce (~38% market share) and BigCommerce (~3% market share) merchants while the Shopify app review continues in parallel.

### Competitive Landscape

| Competitor | Integration Approach | Number of Integrations |
|---|---|---|
| **Klaviyo** | Native platform apps via app stores (Shopify, BigCommerce, WooCommerce, Magento, Salesforce, Wix). Deep OAuth flows. 350+ total integrations via marketplace. | 350+ |
| **Customer.io** | Event-driven architecture. JavaScript SDK + REST API. Expects the user to send events. No deep e-commerce platform integrations. | SDK-based |
| **Smaily** | Open-source plugins/modules per platform (WordPress, WooCommerce, Drupal, OpenCart, Magento, PrestaShop). Each is a separate PHP codebase on GitHub. | ~15 |

Segmentflow should follow a hybrid approach:
- **WordPress + WooCommerce**: A single unified WordPress plugin (`segmentflow-connect`) distributed via WordPress.org. Works on ANY WordPress site (page views, identity for logged-in users). Conditionally activates WooCommerce features (cart context, WC customer identity, auto-auth connection flow) when WooCommerce is detected. Future integrations (Contact Form 7, Elementor, etc.) drop into the same plugin via an `integrations/` directory pattern (inspired by Smaily Connect's architecture). Separate public GPL v2+ repository.
- **BigCommerce**: Server-side REST API integration (no app store approval needed)
- **WordPress form plugins**: Future integration modules within `segmentflow-connect` (CF7, Gravity Forms, WPForms, Elementor)
- **Long tail**: Zapier/Make integration as a force multiplier (future phase)

---

## Current Architecture

### How the Shopify Integration Works Today

The Shopify integration is the template for all future integrations. Understanding it is essential context.

#### Connection Flow (OAuth 2.0)

```
Dashboard                      API                           Shopify
   │                            │                              │
   │ POST /integrations/        │                              │
   │   shopify/install          │                              │
   │ { shopDomain }             │                              │
   │ ─────────────────────────> │                              │
   │                            │ Generate OAuth URL           │
   │                            │ (HMAC-signed state param)    │
   │ <── redirectUrl ────────── │                              │
   │                            │                              │
   │ ── Browser redirect ──────────────────────────────────>   │
   │                            │              User authorizes │
   │                            │ <──── code + state ───────── │
   │                            │                              │
   │ GET /integrations/         │                              │
   │   shopify/callback         │                              │
   │ ─────────────────────────> │                              │
   │                            │ Exchange code for token      │
   │                            │ ────────────────────────────>│
   │                            │ <──── access_token ──────────│
   │                            │                              │
   │                            │ Encrypt & store token        │
   │                            │ Register webhooks            │
   │                            │ Create SDK API key           │
   │                            │ Bootstrap segments           │
   │                            │ Bootstrap user properties    │
   │                            │ Trigger historical sync      │
   │                            │                              │
   │ <── redirect to dashboard  │                              │
```

Key files:
- Routes: `services/node/api/src/routes/v1/integrations/shopify.ts`
- Service: `services/node/api/src/services/v1/integrations/shopify.service.ts`
- OAuth: `services/node/api/src/services/v1/integrations/shopify/oauth.ts`
- Types: `services/node/api/src/services/v1/integrations/shopify/types.ts`
- Errors: `services/node/api/src/services/v1/integrations/shopify/errors.ts`
- Theme: `services/node/api/src/services/v1/integrations/shopify/theme.service.ts`
- Config: `services/node/api/src/config/schemas/shopify.schema.ts`

#### Webhook Processing

```
Shopify                        API
   │                            │
   │ POST /public/webhooks/     │
   │   shopify/:organizationId  │
   │ ─────────────────────────> │
   │                            │ 1. Respond 200 immediately
   │ <── 200 OK ─────────────── │
   │                            │ 2. HMAC-SHA256 verify
   │                            │ 3. Dedup check (WebhookEvent table)
   │                            │ 4. Route by topic:
   │                            │    customers/* → submitIdentify()
   │                            │    orders/*    → submitTrack()
   │                            │    products/*  → catalog events
   │                            │    checkouts/* → identity stitching
   │                            │ 5. Mark processed/failed
```

Registered webhook topics:
- **Core**: `orders/create`, `orders/updated`, `orders/paid`, `orders/fulfilled`, `orders/cancelled`, `products/create`, `products/update`, `products/delete`, `app/uninstalled`
- **Protected**: `customers/create`, `customers/update`, `customers/delete`, `checkouts/create`, `checkouts/update`
- **GDPR**: `customers/data_request`, `customers/redact`, `shop/redact`

Key files:
- Routes: `services/node/api/src/routes/v1/webhooks/shopify.ts`
- Service: `services/node/api/src/services/v1/webhooks/shopify.service.ts` (1464 lines)
- Compliance: `services/node/api/src/routes/v1/webhooks/shopify-compliance.ts`

#### Historical Data Sync

On first connect (or manual trigger), the service:
1. Updates `syncStatus` to `in_progress`
2. Paginated fetch via Shopify REST Admin API (250 per page, cursor-based via Link header)
3. For customers: `submitIdentify()` + `appendIdentitySignal()`
4. For orders: `submitTrack()` with `order_synced` + `order_paid` events + identity signals
5. For products: `submitTrack()` with `TRProductCatalogSynced`
6. Supports partial sync (graceful degradation if customer data access is pending Shopify approval)
7. After sync, triggers segment computation for all running segments via Temporal

#### Data Flow Through the System

```
E-Commerce Platform (Shopify / WooCommerce / BigCommerce)
    │
    ├──[Webhooks]──> WebhookService (per platform)
    │                    │
    │                    ├── submitIdentify()  ──> UserEvent table (type: identify)
    │                    ├── submitTrack()     ──> UserEvent table (type: track)
    │                    └── appendIdentitySignal() ──> IdentityBridge table
    │
    ├──[Hist. Sync]──> IntegrationService (per platform)
    │                    │
    │                    ├── submitIdentify()  ──> UserEvent table
    │                    ├── submitTrack()     ──> UserEvent table
    │                    └── appendIdentitySignal() ──> IdentityBridge table
    │
    └──[Browser SDK]──> /api/v1/ingest/
                         │
                         ├── UserEvent table
                         └── IdentityBridge table
                                │
                                v
                      [Temporal Workflows]
                         │
                         ├── Identity Resolution → Profile table + ProfileIdentity table
                         ├── Segment Computation → SegmentMembership table
                         └── User Property Computation → UserPropertyAssignment table
```

The key insight: **everything downstream of `submitIdentify()` / `submitTrack()` / `appendIdentitySignal()` is platform-agnostic.** Adding a new integration means adding a new way to feed data into these three functions.

### Database Models

#### Integration-Specific Models

```prisma
// schema.prisma (line 685)
enum IntegrationType {
  shopify
  // Add other integrations here as needed, e.g.:
  // woocommerce
  // stripe
  @@schema("api")
}

// schema.prisma (line 723)
model ShopifyIntegration {
  id             String @id @db.Uuid
  organizationId String @unique @map("organization_id") @db.Uuid
  shopDomain     String @map("shop_domain")

  // OAuth credentials (encrypted)
  accessToken   String @map("access_token")  // AES-256-GCM encrypted
  accessTokenIv String @map("access_token_iv")
  scope         String

  // Shop metadata
  shopName     String? @map("shop_name")
  shopEmail    String? @map("shop_email")
  shopCurrency String? @map("shop_currency")
  shopTimezone String? @map("shop_timezone")
  shopCountry  String? @map("shop_country")

  // Status tracking
  webhooksRegistered Boolean   @default(false) @map("webhooks_registered")
  lastSyncAt         DateTime? @map("last_sync_at")
  syncStatus         String?   @map("sync_status")
  syncError          String?   @db.Text @map("sync_error")

  // Storefront tracking (Shopify-specific)
  pixelId         String?  @db.VarChar(255) @map("pixel_id")
  appEmbedEnabled Boolean  @default(false) @map("app_embed_enabled")
  scopeVersion    Int      @default(1) @map("scope_version")
  sdkApiKeyId     String?  @db.VarChar(255) @map("sdk_api_key_id")
  sdkConfigError  String?  @db.Text @map("sdk_config_error")

  installedAt DateTime @default(now()) @map("installed_at")
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")

  organization Organization @relation(...)
  @@map("shopify_integration")
}

// schema.prisma (line 784) -- Already generic
model WebhookEvent {
  id             String          @id @db.Uuid
  organizationId String          @map("organization_id") @db.Uuid
  integration    IntegrationType // <-- Works for any integration
  webhookId      String?         @map("webhook_id")
  topic          String
  payload        Json?           @db.JsonB
  status         WebhookStatus   @default(pending)
  error          String?
  processedAt    DateTime?       @map("processed_at")
  createdAt      DateTime        @default(now()) @map("created_at")
  @@map("webhook_event")
}
```

#### Identity System (Already Multi-Platform)

```prisma
// schema.prisma (line 377) -- Already has woocommerce_customer_id
enum IdentityType {
  email
  phone
  external_id
  shopify_customer_id
  woocommerce_customer_id  // <-- Already exists!
  @@schema("api")
}

// schema.prisma (line 1015) -- JSONB supports arbitrary integration IDs
model IdentityBridge {
  id             String @id @db.Uuid
  organizationId String @map("organization_id") @db.Uuid
  anonymousId    String? @map("anonymous_id")
  userId         String? @map("user_id")      // "shopify_123" or "wc_456"
  email          String?
  phone          String?
  integrationIds Json?   @map("integration_ids") @db.JsonB  // { shopify: {...}, woocommerce: {...} }
  signalData     Json?   @map("signal_data") @db.JsonB
  source         String  // "shopify_customer", "woocommerce_customer", etc.
  profileId      String? @map("profile_id")
  resolvedAt     DateTime? @map("resolved_at")
  @@map("identity_bridge")
}
```

### Existing Abstraction Layers

The codebase has a **partially generic integration framework** with clear extension points:

#### Integration Bootstrapper (`integration-bootstrapper.service.ts`)

Central entry point for seeding segments + user properties when an integration connects:

```typescript
export async function bootstrapIntegration(
  organizationId: string,
  integrationType: IntegrationType,
  options: BootstrapIntegrationOptions = {},
): Promise<BootstrapIntegrationResult | null>
```

Delegates to `supportsBootstrap()`, `seedIntegrationSegments()`, `seedIntegrationUserProperties()`.

#### Integration Utils (`integration-utils.ts`)

Already has commented placeholders for WooCommerce:

```typescript
export function formatIntegrationName(integrationType: IntegrationType | string): string {
  switch (integrationType) {
    case IntegrationType.shopify:
      return "Shopify";
    // Future integrations:
    // case IntegrationType.woocommerce:
    //   return "WooCommerce";
  }
}

export function supportsBootstrap(integrationType: IntegrationType): boolean {
  switch (integrationType) {
    case IntegrationType.shopify:
      return true;
    // Future integrations:
    // case IntegrationType.woocommerce:
    //   return true;
  }
}
```

#### Segment & User Property Seeders

Template-based systems that create pre-built segments and user properties per integration type:
- `segment-seeder.service.ts` -- `getTemplatesForIntegration(integrationType)` returns `SegmentTemplate[]`
- `user-property-seeder.service.ts` -- `getUserPropertyTemplatesForIntegration(integrationType)` returns `UserPropertyTemplate[]`
- Existing templates: `SHOPIFY_SEGMENT_TEMPLATES` (11 templates), `SHOPIFY_USER_PROPERTY_TEMPLATES` (22 templates)

#### Dashboard UI

The integrations page (`/integrations/page.tsx`) renders a grid of integration cards. Currently only `ShopifyConnectCard`. The `IntegrationBadge` component has a commented-out WooCommerce placeholder. The onboarding flow's `SetupIntegrationStep` already branches on `primaryPlatform === "woocommerce"` and shows a "Coming Soon" card.

### What Is NOT Abstracted (Must Be Built Per Integration)

| Component | Current State | What's Needed |
|---|---|---|
| Prisma model | `ShopifyIntegration` is Shopify-specific | New `WooCommerceIntegration` model per platform |
| Connection flow | Shopify OAuth embedded in `ShopifyIntegrationService` | WooCommerce uses API key entry (no OAuth) |
| Webhook processing | Hardcoded in `ShopifyWebhookService` | New `WooCommerceWebhookService` per platform |
| Historical sync | Shopify REST API calls in `ShopifyIntegrationService` | New sync logic per platform API |
| Route registration | Manual in `routes/v1/integrations/index.ts` | Add new route registration per platform |

---

## Phase 1: WooCommerce Integration

### Why WooCommerce First

| Factor | WooCommerce | BigCommerce |
|---|---|---|
| Market share | ~38% of e-commerce | ~3% of e-commerce |
| App store approval needed | **No** | No (optional) |
| API authentication | API key (user-generated) | API key or OAuth |
| REST API maturity | v3, well-documented | v3, well-documented |
| Webhook support | Built-in, configurable via API | Built-in |
| Official JS library | `@woocommerce/woocommerce-rest-api` | `node-bigcommerce` |
| Existing codebase support | `IdentityType.woocommerce_customer_id` already exists | Would need to add |

### How WooCommerce Integration Differs from Shopify

| Aspect | Shopify | WooCommerce |
|---|---|---|
| **Connection** | OAuth 2.0 with app store review | Unified WordPress plugin (`segmentflow-connect`) with WC auto-auth (`/wc-auth/v1/authorize`), or direct auto-auth from dashboard. Plain WordPress sites connect via write key only (no WC auth needed). |
| **Authentication** | OAuth access token | Consumer Key + Consumer Secret (HTTP Basic or OAuth 1.0a) |
| **Webhook registration** | REST API call with HMAC secret derived from client secret | REST API call with user-provided webhook secret |
| **Webhook verification** | HMAC-SHA256 with Shopify client secret | HMAC-SHA256 with webhook secret via `X-WC-Webhook-Signature` header |
| **Data endpoints** | `/admin/api/2024-10/customers.json` | `/wp-json/wc/v3/customers` |
| **Pagination** | Link header (cursor-based) | `X-WP-Total` / `X-WP-TotalPages` headers (page-based) |
| **Storefront tracking** | Theme App Extension + Web Pixel | Unified WordPress plugin (`segmentflow-connect`) injects CDN-hosted SDK (`cdn.segmentflow.ai/sdk.js`). Core tracking (page views, identify) works on any WP site. WooCommerce plugin enriches with cart hash, currency, WC customer ID when WC is active. |
| **GDPR requirements** | Mandatory compliance webhooks | Not required by platform |
| **Product images** | `product.images[].src` | `product.images[].src` (same structure) |
| **Brand/Logo** | Theme settings API | WordPress site icon API or URL scraping |
| **Complexity** | High (OAuth, app review, theme API, pixel API, GDPR) | **Low-Medium** (API keys, REST only) |

### WooCommerce REST API Reference

**Base URL**: `https://{store-url}/wp-json/wc/v3/`

#### Relevant Endpoints

| Endpoint | HTTP | Data Retrieved | Maps To |
|---|---|---|---|
| `/customers` | GET | Email, name, billing/shipping address, orders_count, total_spent, avatar_url | `submitIdentify()` |
| `/customers/{id}` | GET | Single customer detail | `submitIdentify()` |
| `/orders` | GET | Line items, totals, status, dates, customer_id, billing email | `submitTrack()` |
| `/orders/{id}` | GET | Single order detail | `submitTrack()` |
| `/products` | GET | Name, images, prices, categories, descriptions, SKU | Product catalog events |
| `/products/{id}` | GET | Single product detail | Product catalog events |
| `/webhooks` | POST | Register webhook subscriptions | Webhook setup |

#### Authentication

WooCommerce uses Consumer Key + Consumer Secret, generated by the store admin at: `WooCommerce > Settings > Advanced > REST API > Add Key`

- **Over HTTPS**: HTTP Basic Auth (`consumer_key:consumer_secret`)
- **Over HTTP**: OAuth 1.0a one-legged authentication

For Segmentflow, we will require HTTPS and use Basic Auth for simplicity.

#### WooCommerce Auto-Auth Endpoint

WooCommerce provides an authentication endpoint at `/wc-auth/v1/authorize` that allows apps to request API key generation from users without manual key creation. This works similarly to OAuth:

1. Segmentflow redirects the user to `{store_url}/wc-auth/v1/authorize?app_name=Segmentflow&scope=read_write&user_id={orgId}&return_url={dashboard_url}&callback_url={api_callback_url}`
2. User approves in their WooCommerce admin
3. WooCommerce POSTs the generated `consumer_key` and `consumer_secret` to our `callback_url`
4. User is redirected back to `return_url`

**We should implement BOTH approaches**:
- **Primary**: Auto-auth endpoint (better UX, similar to OAuth)
- **Fallback**: Manual API key entry (for stores that block the auth endpoint)

#### Webhook Topics

WooCommerce supports these webhook topics (registered via `POST /wp-json/wc/v3/webhooks`):

| WooCommerce Topic | Segmentflow Event | Equivalent Shopify Topic |
|---|---|---|
| `order.created` | `order_created` | `orders/create` |
| `order.updated` | `order_updated` | `orders/updated` |
| `order.deleted` | `order_cancelled` | `orders/cancelled` |
| `order.restored` | `order_restored` | N/A |
| `customer.created` | identify | `customers/create` |
| `customer.updated` | identify | `customers/update` |
| `customer.deleted` | profile deletion | `customers/delete` |
| `product.created` | `TRProductCatalogCreated` | `products/create` |
| `product.updated` | `TRProductCatalogUpdated` | `products/update` |
| `product.deleted` | `TRProductCatalogDeleted` | `products/delete` |

**Webhook delivery format**: JSON POST with `X-WC-Webhook-Signature` (HMAC-SHA256 of body using webhook secret), `X-WC-Webhook-Topic`, `X-WC-Webhook-Resource`, `X-WC-Webhook-Event`, `X-WC-Webhook-Delivery-ID`, `X-WC-Webhook-Source`.

### Technical Implementation

#### 1. Database Schema

##### New Prisma Model: `WooCommerceIntegration`

```prisma
model WooCommerceIntegration {
  id             String @id @db.Uuid
  organizationId String @unique @map("organization_id") @db.Uuid

  // Store metadata
  storeUrl      String  @map("store_url")      // e.g., "https://mystore.com"
  storeName     String? @map("store_name")
  storeEmail    String? @map("store_email")
  storeCurrency String? @map("store_currency")
  storeTimezone String? @map("store_timezone")
  storeCountry  String? @map("store_country")
  wcVersion     String? @map("wc_version")     // WooCommerce version
  wpVersion     String? @map("wp_version")     // WordPress version

  // API credentials (encrypted)
  consumerKey      String @map("consumer_key")       // AES-256-GCM encrypted
  consumerKeyIv    String @map("consumer_key_iv")
  consumerSecret   String @map("consumer_secret")    // AES-256-GCM encrypted
  consumerSecretIv String @map("consumer_secret_iv")

  // Webhook configuration
  webhookSecret      String  @map("webhook_secret")      // For verifying incoming webhooks
  webhookSecretIv    String  @map("webhook_secret_iv")
  webhooksRegistered Boolean @default(false) @map("webhooks_registered")
  webhookIds         Json?   @map("webhook_ids") @db.JsonB // Store WC webhook IDs for cleanup

  // Status tracking
  lastSyncAt  DateTime? @map("last_sync_at")
  syncStatus  String?   @map("sync_status")    // pending | in_progress | completed | failed
  syncError   String?   @db.Text @map("sync_error")

  // Connection method
  connectionMethod String @default("manual") @map("connection_method") // "auto_auth" | "manual"

  // Timestamps
  connectedAt DateTime @default(now()) @map("connected_at")
  createdAt   DateTime @default(now()) @map("created_at")
  updatedAt   DateTime @updatedAt @map("updated_at")

  organization Organization @relation(fields: [organizationId], references: [id], onDelete: Cascade)

  @@index([storeUrl])
  @@map("woocommerce_integration")
  @@schema("api")
}
```

##### Schema Changes

```prisma
// Update IntegrationType enum
enum IntegrationType {
  shopify
  woocommerce   // <-- Add this
  @@schema("api")
}

// Update Organization model to add relation
model Organization {
  // ... existing fields ...
  shopifyIntegration     ShopifyIntegration?
  woocommerceIntegration WooCommerceIntegration?  // <-- Add this
}
```

#### 2. Configuration

New config schema at `services/node/api/src/config/schemas/woocommerce.schema.ts`:

```typescript
// Environment variables needed:
// WOOCOMMERCE_WEBHOOK_BASE_URL - Base URL for webhook delivery (e.g., https://api.segmentflow.ai)
// ENCRYPTION_KEY - Already exists, reuse for encrypting WC credentials
```

WooCommerce does not require a client ID/secret like Shopify. The credentials are per-store, not per-app. The only app-level config is the webhook delivery URL.

#### 3. API Routes

New file: `services/node/api/src/routes/v1/integrations/woocommerce.ts`

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/integrations/woocommerce/connect` | Session | Submit store URL + API credentials (manual method) |
| `POST` | `/integrations/woocommerce/authorize` | Session | Initiate WC auto-auth flow (returns redirect URL) |
| `POST` | `/integrations/woocommerce/callback` | Public | Receive auto-auth callback with generated keys |
| `GET` | `/integrations/woocommerce/status` | Session | Connection status, sync progress, store metadata |
| `POST` | `/integrations/woocommerce/sync` | Session | Trigger manual re-sync |
| `DELETE` | `/integrations/woocommerce/disconnect` | Session | Remove integration, delete webhooks, cleanup |

New file: `services/node/api/src/routes/v1/webhooks/woocommerce.ts`

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/public/webhooks/woocommerce/:organizationId` | HMAC | Receive WooCommerce webhook events |

#### 4. Services

##### `WooCommerceIntegrationService`

File: `services/node/api/src/services/v1/integrations/woocommerce.service.ts`

Core service class. Responsibilities:

**Connection (manual):**
1. Accept store URL + consumer key + consumer secret
2. Validate credentials by calling `GET /wp-json/wc/v3` (returns store info on success)
3. Encrypt credentials with AES-256-GCM (reuse existing encryption utilities)
4. Save `WooCommerceIntegration` record
5. Generate a webhook secret for HMAC verification
6. Register webhooks via `POST /wp-json/wc/v3/webhooks` for each topic
7. Bootstrap segments + user properties via `bootstrapIntegrationAsync()`
8. Trigger historical sync

**Connection (auto-auth -- from dashboard):**
1. User enters store URL in Segmentflow dashboard
2. Generate auth URL: `{storeUrl}/wc-auth/v1/authorize?app_name=Segmentflow&scope=read_write&user_id={orgId}&return_url={dashboardUrl}&callback_url={apiCallbackUrl}`
3. Redirect user to their WooCommerce consent screen
4. User clicks "Approve"
5. WooCommerce POSTs `{ consumer_key, consumer_secret, key_permissions }` to callback
6. Store credentials and proceed with connection steps 2-8 above
7. If the WordPress plugin is installed, the write key is returned and stored in `wp_options` for SDK injection
8. If the plugin is not installed, webhooks + REST sync still work; dashboard shows a prompt to install the plugin for storefront tracking

**Connection (auto-auth -- from WordPress plugin):**
1. User clicks "Connect to Segmentflow" in plugin settings page
2. Plugin redirects to `https://app.segmentflow.ai/integrations/woocommerce/connect?store_url={storeUrl}&return_url={wpAdminUrl}`
3. User logs in / selects organization in Segmentflow dashboard
4. Segmentflow generates the WC auto-auth URL and redirects to the store's consent screen
5. User clicks "Approve" on the WooCommerce consent screen
6. WooCommerce POSTs `{ consumer_key, consumer_secret, key_permissions }` to callback
7. Store credentials and proceed with connection steps 2-8 above
8. Write key is returned to the plugin and stored in `wp_options`
9. User is redirected back to WP admin settings page with success parameter
10. Plugin immediately begins injecting the CDN SDK into the storefront

**Historical Sync:**
1. Paginated `GET /wp-json/wc/v3/customers?per_page=100&page={n}` (use `X-WP-TotalPages` for pagination)
2. Map each customer to `submitIdentify()` with traits: email, firstName, lastName, phone, orders_count, total_spent, etc.
3. `appendIdentitySignal()` with `userId: "wc_{customerId}"`, source: `"woocommerce_customer"`
4. Paginated `GET /wp-json/wc/v3/orders?per_page=100&page={n}`
5. Map each order to `submitTrack()` with event name based on order status
6. Paginated `GET /wp-json/wc/v3/products?per_page=100&page={n}`
7. Map each product to product catalog events
8. Update `syncStatus` throughout

**Disconnection:**
1. Delete registered webhooks via `DELETE /wp-json/wc/v3/webhooks/{id}` for each stored webhook ID
2. Delete `WooCommerceIntegration` record (cascades via Prisma)

##### `WooCommerceWebhookService`

File: `services/node/api/src/services/v1/webhooks/woocommerce.service.ts`

Webhook processing service. Pattern mirrors `ShopifyWebhookService`:

```
1. Respond 200 immediately (async processing)
2. Verify HMAC-SHA256 signature (X-WC-Webhook-Signature header, webhook secret)
3. Dedup check (WebhookEvent table, using X-WC-Webhook-Delivery-ID)
4. Log event as processing
5. Route by topic:
   - customer.created / customer.updated → submitIdentify() + appendIdentitySignal()
   - customer.deleted → handle profile deletion
   - order.created → submitTrack("order_created", ...)
   - order.updated → map WC order status to event:
       status=processing → submitTrack("order_paid", ...)    // <-- key event for revenue
       status=completed  → submitTrack("order_completed", ...)
       status=cancelled  → submitTrack("order_cancelled", ...)
       status=refunded   → submitTrack("order_refunded", ...)
       status=on-hold    → submitTrack("order_on_hold", ...)
   - product.created/updated/deleted → catalog events
6. Mark event as processed/failed
```

##### WooCommerce Order Status Mapping

WooCommerce has richer order statuses than Shopify. Mapping:

| WC Order Status | Segmentflow Event | Notes |
|---|---|---|
| `pending` | `order_created` | Order placed, awaiting payment |
| `processing` | `order_paid` | Payment received (maps to Shopify's `orders/paid`) |
| `on-hold` | `order_on_hold` | Awaiting action (e.g., bank transfer) |
| `completed` | `order_completed` | Order fulfilled (maps to Shopify's `orders/fulfilled`) |
| `cancelled` | `order_cancelled` | Cancelled by admin or customer |
| `refunded` | `order_refunded` | Fully refunded |
| `failed` | `order_failed` | Payment failed |

The `order_paid` event (mapped from `processing` status) is the primary event for revenue attribution and purchase-based segments, matching Shopify's `orders/paid` webhook.

#### 5. Data Mapping

##### WooCommerce Customer to Segmentflow Traits

| WC Customer Field | Segmentflow Trait | Notes |
|---|---|---|
| `id` | `customer_id` | WooCommerce customer ID |
| `email` | `email` | Primary email |
| `first_name` | `firstName` | |
| `last_name` | `lastName` | |
| `billing.phone` | `phone` | |
| `billing.company` | `company` | |
| `billing.address_1` | `address_1` | |
| `billing.address_2` | `address_2` | |
| `billing.city` | `city` | |
| `billing.state` | `province_code` | |
| `billing.postcode` | `zip` | |
| `billing.country` | `country_code` | |
| `orders_count` | `orders_count` | Compute from orders during sync |
| `total_spent` | `total_spent` | Compute from orders during sync |
| `avatar_url` | `avatar_url` | WordPress avatar |
| `date_created` | `date_created` | ISO 8601 |

**Note**: WooCommerce's customer API may not return `orders_count` or `total_spent` directly (unlike Shopify). We need to compute these during historical sync by iterating orders, or by checking if the store's WC version includes these fields. This is a difference the sync logic must handle.

##### WooCommerce Order to Segmentflow Event Properties

| WC Order Field | Event Property | Notes |
|---|---|---|
| `id` | `order_id` | |
| `number` | `order_number` | Display number |
| `status` | `status` | Maps to event name |
| `total` | `total` | Order total (string) |
| `subtotal` | `subtotal` | Before tax/shipping |
| `total_tax` | `total_tax` | |
| `shipping_total` | `shipping_total` | |
| `discount_total` | `discount_total` | |
| `currency` | `currency` | ISO 4217 |
| `line_items[]` | `items` | Array of { product_id, name, quantity, total, sku, image } |
| `coupon_lines[]` | `coupons` | Applied coupons |
| `payment_method` | `payment_method` | |
| `date_created` | `created_at` | ISO 8601 |
| `customer_id` | Links to identity | For identity stitching |
| `billing.email` | Links to identity | Fallback identity signal |

#### 6. Segment Templates

New file: `packages/node/api-schemas/src/segments/templates/woocommerce.templates.ts`

The WooCommerce segment templates mirror the Shopify templates using the same event names:

| Template Name | Definition | Event Used |
|---|---|---|
| Repeat Customers | `order_paid` >= 2 times | `order_paid` |
| High Spenders | `order_paid` >= 5 times | `order_paid` |
| First-Time Buyers | `order_paid` == 1 time | `order_paid` |
| Recent First-Time Buyers | `order_paid` == 1 AND `order_paid` in last 30 days | `order_paid` |
| Churning Customers | `order_paid` >= 2 AND no `order_paid` in 90 days | `order_paid` |
| Active Customers | `order_paid` in last 30 days | `order_paid` |
| Abandoned Cart | `checkout_started` in 1 day AND no `order_paid` in 1 day | `checkout_started` |
| Awaiting Shipment | `order_paid` in 30 days AND no `order_completed` in 30 days | Both |
| Orders Fulfilled | `order_completed` >= 1 | `order_completed` |
| Order Cancelled | `order_cancelled` >= 1 | `order_cancelled` |
| Refund Received | `order_refunded` >= 1 | `order_refunded` |

Because we use the same event names (`order_paid`, `order_completed`, etc.) as Shopify, the segment definitions are structurally identical. We keep separate template files to allow WooCommerce-specific segments in the future (e.g., based on WC-specific statuses like `on-hold`).

#### 7. User Property Templates

New file: `packages/node/api-schemas/src/user-properties/templates/woocommerce.templates.ts`

Same properties as Shopify templates, with adjustments:
- Remove Shopify-specific properties (`tags`, `state`, `verified_email`, `note`) that don't exist in WooCommerce
- Add WooCommerce-specific properties if applicable (e.g., `role` from WordPress user roles)
- Update `description` and `synonyms` to reference "WooCommerce" instead of "Shopify"

#### 8. Integration Framework Updates

##### `integration-utils.ts` -- Uncomment WooCommerce cases

```typescript
case IntegrationType.woocommerce:
  return "WooCommerce";
// ...
case IntegrationType.woocommerce:
  return true;
```

##### `segment-seeder.service.ts` -- Add WooCommerce templates

```typescript
case IntegrationType.woocommerce:
  return [...WOOCOMMERCE_SEGMENT_TEMPLATES];
```

##### `user-property-seeder.service.ts` -- Add WooCommerce templates

```typescript
case IntegrationType.woocommerce:
  return [...WOOCOMMERCE_USER_PROPERTY_TEMPLATES];
```

##### Route Registration

```typescript
// routes/v1/integrations/index.ts -- Add:
import woocommerceRoutes from "./woocommerce";
await fastify.register(woocommerceRoutes, { prefix: "/woocommerce" });

// routes/v1/webhooks/index.ts -- Add:
import woocommerceWebhookRoutes from "./woocommerce";
await fastify.register(woocommerceWebhookRoutes);
```

#### 9. Dashboard UI Changes

##### Integrations Page

Update `/integrations/page.tsx` to add a `<WooCommerceConnectCard />` alongside the existing `<ShopifyConnectCard />`.

##### WooCommerceConnectCard Component

New component: `features/integrations/components/WooCommerceConnectCard.tsx`

Four states (mirroring Shopify card pattern):

**Not Connected:**
- Store URL input field (full URL, not just subdomain)
- "Connect WooCommerce" button (initiates auto-auth: redirect to WC consent screen)
- Note: "For storefront tracking, install the [Segmentflow Connect](https://wordpress.org/plugins/segmentflow-connect/) plugin"
- "Connect with API Keys" expandable fallback (Consumer Key + Consumer Secret fields, for stores that block auto-auth)
- Benefits list (customer sync, order history, real-time events, pre-built segments)

**Connecting:**
- Progress indicator during credential validation and initial webhook registration

**Connected (without plugin):**
- Store name, URL, WC version, currency
- Sync status badge
- Warning banner: "Storefront tracking is not active. Install the Segmentflow Connect plugin to capture page views and add-to-cart events."
- "Sync Now" and "Disconnect" buttons

**Connected (with plugin, plain WordPress):**
- Site name, URL
- Green badge: "Storefront tracking active (page views + identify)"
- Note: "WooCommerce not detected. Install WooCommerce for order tracking, customer sync, and revenue attribution."
- "Disconnect" button

**Connected (with plugin, WooCommerce active):**
- Store name, URL, WC version, currency
- Sync status badge
- Green badge: "Storefront tracking active (full WooCommerce enrichment)"
- "Sync Now" and "Disconnect" buttons

##### `IntegrationBadge.tsx` -- Uncomment WooCommerce

The existing badge component has a commented-out WooCommerce case. Uncomment it.

##### `SetupIntegrationStep.tsx` -- Replace "Coming Soon"

The onboarding step already branches on `primaryPlatform === "woocommerce"`. Replace the "Coming Soon" placeholder with the actual WooCommerce connection form.

#### 10. Storefront Tracking (Unified WordPress Plugin + CDN SDK)

##### Architecture Overview

Storefront tracking uses a **unified WordPress plugin** (`segmentflow-connect`) that works on ANY WordPress site, with WooCommerce-specific enrichment activating conditionally. This follows the Smaily Connect pattern: one plugin, conditional integration loading.

| | Shopify | WordPress (any) | WordPress + WooCommerce |
|---|---|---|---|
| **Delivery mechanism** | Theme App Extension (`segmentflow-embed`) | `segmentflow-connect` plugin (core tracking) | `segmentflow-connect` plugin (core + WC enrichment) |
| **SDK source** | `cdn.segmentflow.ai/sdk.js` | `cdn.segmentflow.ai/sdk.js` (same) | `cdn.segmentflow.ai/sdk.js` (same) |
| **Platform detection** | `ShopifyPlugin` (`shopify.plugin.ts`) | N/A (core SDK handles page views) | `WooCommercePlugin` (`woocommerce.plugin.ts`) |
| **Write key provisioning** | Shopify App Metafield (auto) | `wp_options` (set during connection flow) | `wp_options` (set during WC auto-auth callback) |
| **Customer identity** | `window.__st.cid` + Liquid `{{ customer.id }}` | PHP `wp_get_current_user()` rendered inline (prefix: `wp_`) | PHP `wp_get_current_user()` rendered inline (prefix: `wc_`) + WC customer context |
| **Cart context** | `GET /cart.json` + DOM scraping | N/A | `WC()->cart->get_cart_hash()` + `get_woocommerce_currency()` rendered inline |
| **Repository** | Same repo (`extensions/segmentflow-embed/`) | Separate public repo (`segmentflow-connect`) | Same plugin, `integrations/woocommerce/` activates conditionally |

**Two-layer tracking architecture:**

```
Layer 1: Core Tracking (ALWAYS active on any WordPress site)
├── class-segmentflow-tracking.php hooks into wp_head
├── Injects CDN SDK script tag
├── Provides: write key, API host, debug/consent settings
├── Reads: get_current_user_id(), wp_get_current_user()->user_email, home_url(), get_locale()
├── Identifies logged-in users as wp_{userId}
└── Page views, referrer, browser context work automatically

Layer 2: WooCommerce Enrichment (CONDITIONAL, only when WC is active)
├── class-segmentflow-wc-tracking.php adds WC context via segmentflow_tracking_context filter
├── Adds: cart hash, currency, WC customer ID, store URL
├── Changes user ID prefix from wp_ to wc_
├── Reads: WC()->cart->get_cart_hash(), get_woocommerce_currency()
└── SDK WooCommercePlugin detects WC globals and enriches events with context.woocommerce
```

##### Separate Repository: `segmentflow-connect`

**Why a separate repo:**
1. **WordPress.org requires it** -- plugin directory pulls from SVN; standard practice is public Git repo as source of truth
2. **Different language/tooling** -- PHP vs TypeScript, Composer vs npm
3. **GPL v2+ license required** -- WordPress.org mandate; main repo is proprietary
4. **Separate release cycles** -- plugin updates ship through WordPress.org; API deploys independently
5. **Community contributions** -- public repo lets WordPress developers contribute

**Repository structure:**

```
segmentflow-connect/
├── segmentflow-connect.php                     # Bootstrap (constants, require plugin.php, instantiate orchestrator)
│
├── includes/                                    # ALWAYS loaded -- core classes
│   ├── class-segmentflow.php                   # ORCHESTRATOR: load_dependencies() + init_classes()
│   ├── class-segmentflow-helper.php            # Static helpers: is_woocommerce_active(), language, sanitization
│   ├── class-segmentflow-options.php           # wp_options read/write (write key, API host, settings)
│   ├── class-segmentflow-tracking.php          # wp_head hook: inject CDN SDK + WordPress user context (ALWAYS)
│   ├── class-segmentflow-auth.php              # Connection flow handler (redirect to Segmentflow dashboard)
│   ├── class-segmentflow-api.php               # HTTP client for Segmentflow API (status check, write key)
│   └── class-segmentflow-lifecycle.php         # Activation, deactivation, uninstall, late activation detection
│
├── admin/                                       # ALWAYS loaded -- admin UI
│   ├── class-segmentflow-admin.php             # Admin menu, dynamic tabs, CSS/JS enqueue
│   ├── class-segmentflow-admin-settings.php    # WordPress Settings API registration
│   └── partials/
│       ├── admin-page.php                      # Settings page shell
│       ├── admin-connection.php                # Connection tab (always shown)
│       └── admin-woocommerce.php               # WooCommerce-specific settings (conditional)
│
├── integrations/                                # CONDITIONAL integration code
│   └── woocommerce/                            # Only loaded when WooCommerce is active
│       ├── class-segmentflow-wc-tracking.php   # Enriches SDK injection with WC context (cart, currency, customer)
│       ├── class-segmentflow-wc-auth.php       # WC auto-auth endpoint (/wc-auth/v1/authorize) handling
│       └── class-segmentflow-wc-helper.php     # WC-specific utilities
│   # Future: integrations/cf7/, integrations/elementor/, etc.
│
├── src/                                         # TypeScript source (admin JS)
│   └── admin.ts                                # Settings page JS (connect button, status polling)
├── assets/
│   ├── css/admin.css                           # Settings page styles
│   ├── js/                                     # Compiled JS output (gitignored, built by tsdown)
│   │   └── admin.js                            # Compiled IIFE bundle from src/admin.ts
│   └── images/
│       ├── segmentflow-icon.svg                # Plugin icon
│       └── banner-772x250.png                  # WordPress.org banner
├── tests/
│   ├── bootstrap.php                           # PHPUnit bootstrap (loads WP test suite)
│   ├── test-activation.php                     # Plugin activation/deactivation tests
│   ├── test-tracking.php                       # Core SDK injection tests (without WooCommerce)
│   ├── test-tracking-woocommerce.php           # WC-specific context enrichment tests
│   ├── test-auth.php                           # Connection flow tests
│   ├── test-wc-auth.php                        # WC auto-auth flow tests
│   ├── test-admin.php                          # Settings page tests
│   └── test-helper.php                         # Integration detection tests
├── scripts/
│   ├── bump-version.mjs                        # Bump version in PHP header + readme.txt (for changesets)
│   └── create-zip.mjs                          # Create plugin .zip for distribution
├── languages/
│   └── segmentflow-connect.pot                 # i18n template
├── uninstall.php                               # Cleanup on plugin deletion
├── readme.txt                                  # WordPress.org description (required format)
├── LICENSE                                     # GPL v2+
│
# ── TypeScript / Node tooling ──
├── package.json                                # Node deps (tsdown, eslint, prettier, lint-staged, changesets)
├── pnpm-lock.yaml                              # Lockfile (pnpm, matches main repo)
├── tsconfig.json                               # TypeScript config for admin JS
├── tsdown.config.ts                            # tsdown build config (IIFE bundle)
├── eslint.config.mjs                           # ESLint 9 flat config (TypeScript)
├── .prettierrc                                 # Prettier config
├── .lintstagedrc.json                          # Lint-staged config (TS + PHP)
│
# ── PHP tooling ──
├── composer.json                               # PHP deps (phpcs, phpunit, wp-coding-standards)
├── composer.lock
├── phpcs.xml.dist                              # PHPCS config (WordPress coding standards)
├── phpunit.xml.dist                            # PHPUnit config
│
# ── CI/CD ──
├── .github/
│   └── workflows/
│       ├── ci.yml                              # Lint (PHP + TS) + Test (PHPUnit matrix) on PR
│       ├── release.yml                         # Changeset version + deploy to WordPress.org SVN
│       └── build.yml                           # Build admin JS + create plugin zip artifact
├── .changeset/
│   └── config.json                             # Changesets config
│
# ── Git config ──
├── .nvmrc                                      # Node 24 (match main repo)
├── .gitignore
├── .editorconfig                               # Tabs for PHP, spaces for TS/JS
└── .husky/
    └── pre-commit                              # lint-staged (TS + PHP)
```

**WordPress.org `readme.txt` key fields:**
- Plugin name: Segmentflow Connect
- Description: Connect your WordPress website or WooCommerce store to Segmentflow for AI-powered email marketing, customer segmentation, and revenue attribution.
- Requires at least: WordPress 5.8
- Tested up to: WordPress 6.7
- Requires PHP: 7.4
- WC requires at least: 5.0 (optional -- plugin works without WooCommerce)
- WC tested up to: 9.x
- License: GPL v2+
- Tags: email marketing, analytics, segmentation, woocommerce, tracking

##### Repository Setup & Tooling Best Practices

The `segmentflow-connect` repo is a hybrid PHP + TypeScript project. The PHP code is the WordPress plugin itself (core classes + conditional WooCommerce integration); the TypeScript code is the admin JS (~200 lines) that powers the settings page UI (connect button behavior, status polling, disconnect confirmation). All tooling choices align with the main `segmentflow-ai` monorepo where applicable, adapted for standalone repo context.

###### Package Management: pnpm

Use pnpm for consistency with the main repo. Same Node.js version (24).

**`package.json`:**

```json
{
  "name": "segmentflow-connect",
  "version": "1.0.0",
  "private": true,
  "type": "module",
  "description": "Connect your WordPress site or WooCommerce store to Segmentflow for AI-powered email marketing.",
  "license": "GPL-2.0-or-later",
  "scripts": {
    "build": "tsdown",
    "build:watch": "tsdown --watch",
    "lint": "eslint . && pnpm lint:php",
    "lint:ts": "eslint .",
    "lint:php": "vendor/bin/phpcs",
    "lint:fix": "eslint . --fix && vendor/bin/phpcbf",
    "format": "prettier --write 'src/**/*.ts'",
    "format:check": "prettier --check 'src/**/*.ts'",
    "test": "pnpm test:php",
    "test:php": "vendor/bin/phpunit",
    "prepare": "husky",
    "changeset": "changeset",
    "version": "changeset version && node scripts/bump-version.mjs",
    "release": "pnpm build && pnpm changeset publish",
    "plugin:zip": "pnpm build && node scripts/create-zip.mjs"
  },
  "devDependencies": {
    "@changesets/cli": "^2.29.0",
    "@changesets/changelog-github": "^0.5.0",
    "@eslint/js": "^9.18.0",
    "eslint": "^9.18.0",
    "husky": "^9.1.7",
    "lint-staged": "^15.3.0",
    "prettier": "^3.4.2",
    "tsdown": "^0.10.0",
    "typescript": "^5.9.3",
    "typescript-eslint": "^8.22.0"
  },
  "packageManager": "pnpm@10.27.0",
  "engines": {
    "node": ">=24.0.0"
  }
}
```

###### TypeScript Configuration

**`tsconfig.json`:**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "lib": ["DOM", "DOM.Iterable", "ESNext"],
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "noUncheckedIndexedAccess": true,
    "declaration": false,
    "sourceMap": true,
    "outDir": "./assets/js",
    "rootDir": "./src",
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "isolatedModules": true,
    "verbatimModuleSyntax": true
  },
  "include": ["src/**/*.ts"],
  "exclude": ["node_modules", "assets", "tests"]
}
```

Key decisions:
- `target: ES2020` -- WordPress admin pages support modern browsers; no need for ES5
- `declaration: false` -- no consumers import this TypeScript; it's compiled to a script tag
- `verbatimModuleSyntax: true` -- enforces explicit `type` imports (aligns with main repo's `consistent-type-imports` rule)
- `noUncheckedIndexedAccess: true` -- strict, matches main repo convention

###### tsdown Configuration

**`tsdown.config.ts`:**

```typescript
import { defineConfig } from "tsdown";

export default defineConfig({
  entry: ["src/admin.ts"],
  format: "iife",
  outDir: "assets/js",
  platform: "browser",
  target: "es2020",
  minify: true,
  sourcemap: true,
  clean: true,
  // No external deps -- the admin JS is self-contained
  // No dts -- not consumed as a library
  // IIFE format -- loaded via wp_enqueue_script(), no module system
  globalName: "SegmentflowAdmin",
});
```

Key decisions:
- **IIFE format** -- WordPress enqueues scripts via `wp_enqueue_script()` which loads them as `<script>` tags, not ES modules
- **`globalName: "SegmentflowAdmin"`** -- namespaced to avoid conflicts with other WP plugins
- **`minify: true`** -- always minify; the sourcemap provides debugging capability
- **No `dts`** -- this isn't a library, it's a compiled asset

###### ESLint Configuration

**`eslint.config.mjs`:**

```javascript
import { defineConfig, globalIgnores } from "eslint/config";
import eslint from "@eslint/js";
import tseslint from "typescript-eslint";

export default defineConfig([
  globalIgnores([
    "assets/**",
    "vendor/**",
    "node_modules/**",
    "*.config.ts",
    "*.config.mjs",
  ]),

  // TypeScript files
  {
    name: "segmentflow-connect/typescript",
    files: ["src/**/*.ts"],
    extends: [eslint.configs.recommended, ...tseslint.configs.recommended],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      "@typescript-eslint/no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_" },
      ],
      "@typescript-eslint/no-explicit-any": "warn",
      "@typescript-eslint/consistent-type-imports": [
        "error",
        { prefer: "type-imports", fixStyle: "inline-type-imports" },
      ],
    },
  },
]);
```

Mirrors the main repo's ESLint patterns (flat config, `typescript-eslint`, consistent type imports) but is self-contained -- no import from the main repo.

###### Prettier Configuration

**`.prettierrc`:**

```json
{
  "semi": true,
  "singleQuote": false,
  "tabWidth": 2,
  "trailingComma": "all",
  "printWidth": 100
}
```

Matches the main repo's implicit formatting conventions (the main repo has Prettier as a devDep but no shared config -- this makes it explicit).

###### Lint-Staged & Husky

**`.lintstagedrc.json`:**

```json
{
  "src/**/*.ts": ["eslint --fix", "prettier --write"],
  "**/*.php": ["vendor/bin/phpcbf --standard=phpcs.xml.dist"]
}
```

**`.husky/pre-commit`:**

```sh
pnpm lint-staged
```

Pre-commit linting for both TypeScript and PHP files. Stricter than the main repo (which only has `pre-push`).

###### PHP Tooling: Composer

**`composer.json`:**

```json
{
  "name": "segmentflow/segmentflow-connect",
  "description": "Segmentflow Connect - AI-powered email marketing integration for WordPress and WooCommerce",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=7.4"
  },
  "require-dev": {
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "*",
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  },
  "scripts": {
    "lint": "phpcs",
    "lint:fix": "phpcbf",
    "test": "phpunit"
  }
}
```

###### PHPCS Configuration (WordPress Coding Standards)

**`phpcs.xml.dist`:**

```xml
<?xml version="1.0"?>
<ruleset name="Segmentflow Connect">
    <description>Coding standards for the Segmentflow Connect plugin.</description>

    <!-- Scan these files -->
    <file>segmentflow-connect.php</file>
    <file>includes/</file>
    <file>admin/</file>
    <file>integrations/</file>
    <file>uninstall.php</file>
    <file>tests/</file>

    <!-- Exclude vendor and assets -->
    <exclude-pattern>/vendor/*</exclude-pattern>
    <exclude-pattern>/node_modules/*</exclude-pattern>
    <exclude-pattern>/assets/*</exclude-pattern>

    <!-- WordPress Coding Standards -->
    <rule ref="WordPress-Extra">
        <!-- Allow short array syntax (modern PHP) -->
        <exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
    </rule>
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="segmentflow-connect"/>
            </property>
        </properties>
    </rule>

    <!-- PHP Compatibility -->
    <rule ref="PHPCompatibilityWP"/>
    <config name="testVersion" value="7.4-"/>

    <!-- Minimum WP version for deprecated function checks -->
    <config name="minimum_wp_version" value="5.8"/>
</ruleset>
```

###### PHPUnit Configuration

**`phpunit.xml.dist`:**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    verbose="true"
    beStrictAboutTestsThatDoNotTestAnything="true"
>
    <testsuites>
        <testsuite name="Segmentflow Connect">
            <directory suffix=".php">tests/</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes/</directory>
            <file>segmentflow-connect.php</file>
        </include>
    </coverage>
</phpunit>
```

**`tests/bootstrap.php`:**

```php
<?php
/**
 * PHPUnit bootstrap file.
 *
 * Uses the WordPress test suite scaffolding.
 * See: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Path to WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Load WordPress test framework.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce before the plugin.
 */
tests_add_filter( 'muplugins_loaded', function() {
    // Load WooCommerce if available.
    if ( defined( 'WC_ABSPATH' ) ) {
        require WC_ABSPATH . 'woocommerce.php';
    }
});

/**
 * Load the plugin.
 */
tests_add_filter( 'muplugins_loaded', function() {
    require dirname( __DIR__ ) . '/segmentflow-connect.php';
});

// Start the WP test suite.
require $_tests_dir . '/includes/bootstrap.php';
```

###### Changesets (Automated Releases)

**`.changeset/config.json`:**

```json
{
  "$schema": "https://unpkg.com/@changesets/config@3.1.1/schema.json",
  "changelog": ["@changesets/changelog-github", { "repo": "segmentflow/segmentflow-connect" }],
  "commit": false,
  "fixed": [],
  "linked": [],
  "access": "restricted",
  "baseBranch": "main",
  "updateInternalDependencies": "patch",
  "ignore": []
}
```

**Release workflow:**

1. Developer creates a changeset (`pnpm changeset`) describing the change
2. On merge to `main`, the `release.yml` workflow detects pending changesets
3. If changesets exist: opens a "Version Packages" PR that bumps version in `package.json`, `segmentflow-connect.php` (plugin header `Version:` field), and `readme.txt` (`Stable tag:` field) via `scripts/bump-version.mjs`
4. When the "Version Packages" PR is merged: creates a GitHub Release, which triggers the WordPress.org SVN deploy

**`scripts/bump-version.mjs`** -- Custom script that changesets calls (via the `"version"` script in `package.json`) to sync the version across all three locations:
1. `package.json` -- standard changesets behavior
2. `segmentflow-connect.php` plugin header -- regex replace on `Version: X.Y.Z`
3. `readme.txt` stable tag -- regex replace on `Stable tag: X.Y.Z`

###### GitHub Actions CI/CD

**`.github/workflows/ci.yml`** -- Runs on every PR:

```yaml
name: CI

on:
  pull_request:
    branches: [main]

permissions:
  contents: read

jobs:
  lint-typescript:
    name: Lint TypeScript
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with:
          node-version-file: ".nvmrc"
          cache: "pnpm"
      - run: pnpm install --frozen-lockfile
      - run: pnpm lint:ts
      - run: pnpm format:check
      - run: pnpm build  # Verify tsdown build succeeds

  lint-php:
    name: Lint PHP
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
          tools: composer
      - run: composer install --no-interaction
      - run: composer lint

  test-php:
    name: Test PHP
    runs-on: ubuntu-24.04
    strategy:
      matrix:
        php-version: ["7.4", "8.1", "8.2"]
        wp-version: ["6.4", "latest"]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          tools: composer
          extensions: mysqli
      - run: composer install --no-interaction
      - name: Install WP Test Suite
        run: bash bin/install-wp-tests.sh wordpress_test root '' localhost ${{ matrix.wp-version }}
      - run: composer test
```

**`.github/workflows/release.yml`** -- Changeset version + WordPress.org deploy:

```yaml
name: Release

on:
  push:
    branches: [main]

permissions:
  contents: write
  pull-requests: write

jobs:
  release:
    name: Release
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with:
          node-version-file: ".nvmrc"
          cache: "pnpm"
      - run: pnpm install --frozen-lockfile

      - name: Create Release PR or Publish
        id: changesets
        uses: changesets/action@v1
        with:
          version: pnpm version
          publish: pnpm release
          title: "chore: version packages"
          commit: "chore: version packages"
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}

  deploy-wordpress:
    name: Deploy to WordPress.org
    runs-on: ubuntu-24.04
    needs: release
    if: github.event_name == 'push' && contains(github.event.head_commit.message, 'chore: version packages')
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with:
          node-version-file: ".nvmrc"
          cache: "pnpm"
      - run: pnpm install --frozen-lockfile
      - run: pnpm build  # Build admin.js from TypeScript

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
          tools: composer
      - run: composer install --no-dev --no-interaction

      - name: Deploy to WordPress.org SVN
        uses: 10up/action-wordpress-plugin-deploy@v2
        with:
          generate-zip: true
        env:
          SVN_USERNAME: ${{ secrets.WP_ORG_SVN_USERNAME }}
          SVN_PASSWORD: ${{ secrets.WP_ORG_SVN_PASSWORD }}
          SLUG: segmentflow-connect
          BUILD_DIR: "."
          ASSETS_DIR: ".wordpress-org"
```

**`.github/workflows/build.yml`** -- Manual plugin zip (for distribution before WordPress.org approval):

```yaml
name: Build Plugin Zip

on:
  workflow_dispatch:

jobs:
  build:
    name: Build
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with:
          node-version-file: ".nvmrc"
          cache: "pnpm"
      - run: pnpm install --frozen-lockfile
      - run: pnpm build

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
          tools: composer
      - run: composer install --no-dev --no-interaction

      - name: Create plugin zip
        run: pnpm plugin:zip

      - uses: actions/upload-artifact@v4
        with:
          name: segmentflow-connect
          path: segmentflow-connect.zip
```

###### `.gitignore`

```gitignore
# Dependencies
node_modules/
vendor/

# Build output (admin.js is compiled from src/admin.ts)
assets/js/*.js
assets/js/*.js.map

# IDE
.idea/
.vscode/
*.swp
*.swo

# OS
.DS_Store
Thumbs.db

# Environment
.env

# Composer
composer.phar

# WordPress test suite
/tmp/

# Plugin zip
*.zip
```

###### `.editorconfig`

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.php]
indent_style = tab
indent_size = 4

[*.{ts,js,json,yml,yaml,css}]
indent_style = space
indent_size = 2

[*.md]
trim_trailing_whitespace = false
```

WordPress PHP conventions use tabs (4-wide); TypeScript/JS uses spaces (2-wide). This config handles both in one repo.

###### Tooling Decisions Summary

| Decision | Choice | Rationale |
|---|---|---|
| Admin JS language | TypeScript with tsdown | Type safety even for small code; consistent with main repo |
| tsdown format | IIFE | WordPress `wp_enqueue_script()` loads via `<script>` tags |
| tsdown `globalName` | `SegmentflowAdmin` | Namespaced to avoid conflicts with other WP plugins |
| PHP linting | PHPCS + WordPress-Extra + PHPCompatibility | WordPress.org standard; reviewers expect it |
| PHP testing | PHPUnit 9 with WP test suite | Matrix testing across PHP 7.4-8.2 and WP 6.4-latest |
| Release automation | Changesets + custom version bump script | Auto-changelog, auto-version PR, auto-deploy to WordPress.org SVN |
| Package manager | pnpm 10.27.0 | Consistency with main repo |
| Node.js version | 24 | Match main repo |
| TypeScript version | 5.9.3 | Match main repo |
| ESLint | v9 flat config, same rules as main repo | Consistency (no-unused-vars, no-explicit-any, consistent-type-imports) |
| Prettier | Explicit `.prettierrc` config | Needed for standalone repo (main repo has no shared config) |
| Git hooks | Husky + lint-staged (pre-commit) | Stricter than main repo (which only has pre-push) |
| `.editorconfig` | Tabs for PHP, spaces for TS/JS | Respects both WordPress PHP and JS conventions |

##### Plugin PHP Architecture

**`segmentflow-connect.php`** (bootstrap file):

```php
<?php
/**
 * Plugin Name: Segmentflow Connect
 * Description: Connect your WordPress site or WooCommerce store to Segmentflow for AI-powered email marketing, customer segmentation, and revenue attribution.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.x
 * License: GPL v2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'SEGMENTFLOW_VERSION', '1.0.0' );
define( 'SEGMENTFLOW_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEGMENTFLOW_URL', plugin_dir_url( __FILE__ ) );

// Ensure is_plugin_active() is available everywhere (front-end, cron, REST)
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Load lifecycle hooks (activation, deactivation, uninstall)
require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-lifecycle.php';
$lifecycle = new Segmentflow_Lifecycle();
register_activation_hook( __FILE__, array( $lifecycle, 'activate' ) );
register_deactivation_hook( __FILE__, array( $lifecycle, 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Segmentflow_Lifecycle', 'uninstall' ) );

// Load and instantiate the orchestrator
require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow.php';
new Segmentflow();
```

**`class-segmentflow.php`** (orchestrator -- the core architectural pattern):

```php
<?php
class Segmentflow {

    public function __construct() {
        $this->load_dependencies();
        $this->init_classes();
    }

    private function load_dependencies() {
        // ALWAYS: core classes
        require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-helper.php';
        require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-options.php';
        require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-tracking.php';
        require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-auth.php';
        require_once SEGMENTFLOW_PATH . 'includes/class-segmentflow-api.php';
        require_once SEGMENTFLOW_PATH . 'admin/class-segmentflow-admin.php';
        require_once SEGMENTFLOW_PATH . 'admin/class-segmentflow-admin-settings.php';

        // CONDITIONAL: WooCommerce integration
        if ( Segmentflow_Helper::is_woocommerce_active() ) {
            require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-tracking.php';
            require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-auth.php';
            require_once SEGMENTFLOW_PATH . 'integrations/woocommerce/class-segmentflow-wc-helper.php';
        }

        // FUTURE: Contact Form 7
        // if ( Segmentflow_Helper::is_cf7_active() ) {
        //     require_once SEGMENTFLOW_PATH . 'integrations/cf7/class-segmentflow-cf7.php';
        // }

        // FUTURE: Elementor
        // if ( Segmentflow_Helper::is_elementor_active() ) {
        //     require_once SEGMENTFLOW_PATH . 'integrations/elementor/class-segmentflow-elementor.php';
        // }
    }

    private function init_classes() {
        $options = new Segmentflow_Options();

        // ALWAYS: core tracking (works on any WordPress site)
        $tracking = new Segmentflow_Tracking( $options );
        $tracking->register_hooks();

        // ALWAYS: auth, admin
        $auth = new Segmentflow_Auth( $options );
        $auth->register_hooks();

        $admin = new Segmentflow_Admin( $options );
        $admin->register_hooks();

        // CONDITIONAL: WooCommerce enrichment
        if ( Segmentflow_Helper::is_woocommerce_active() ) {
            $wc_tracking = new Segmentflow_WC_Tracking( $options, $tracking );
            $wc_tracking->register_hooks();

            if ( $options->has_credentials() ) {
                $wc_auth = new Segmentflow_WC_Auth( $options );
                $wc_auth->register_hooks();
            }
        }
    }
}
```

**`class-segmentflow-helper.php`** -- Integration detection (Smaily Connect pattern):

```php
<?php
class Segmentflow_Helper {

    public static function is_woocommerce_active() {
        if ( function_exists( 'is_plugin_active' ) ) {
            return is_plugin_active( 'woocommerce/woocommerce.php' );
        }
        return class_exists( 'WooCommerce' );
    }

    // Future detection methods:
    // public static function is_cf7_active() { ... }
    // public static function is_elementor_active() { ... }
}
```

**`class-segmentflow-tracking.php`** -- Core SDK injection (works on ANY WordPress site):

Hooks into `wp_head` and outputs:

```html
<script>
(function() {
  var config = {
    writeKey: '<?php echo esc_js( $this->options->get( "write_key" ) ); ?>',
    host: '<?php echo esc_js( $this->options->get( "api_host", "https://api.segmentflow.ai" ) ); ?>',
    debug: <?php echo $this->options->get( "debug_mode", false ) ? "true" : "false"; ?>,
    consentRequired: <?php echo $this->options->get( "consent_required", false ) ? "true" : "false"; ?>
  };

  // WordPress user context (available on ANY WordPress site)
  var wpContext = {
    siteUrl: '<?php echo esc_js( home_url() ); ?>',
    userId: <?php echo is_user_logged_in() ? json_encode( get_current_user_id() ) : 'null'; ?>,
    userEmail: <?php echo is_user_logged_in() ? json_encode( wp_get_current_user()->user_email ) : 'null'; ?>,
    locale: '<?php echo esc_js( get_locale() ); ?>'
  };

  <?php
  // Allow integrations to add context (WooCommerce adds cart hash, currency, etc.)
  $extra_context = apply_filters( 'segmentflow_tracking_context', array() );
  if ( ! empty( $extra_context ) ) :
  ?>
  var integrationContext = <?php echo wp_json_encode( $extra_context ); ?>;
  <?php endif; ?>

  var script = document.createElement('script');
  script.src = 'https://cdn.segmentflow.ai/sdk.js';
  script.async = true;
  script.onload = function() {
    window.segmentflow.init(config);

    // Determine user ID prefix based on active integrations
    var prefix = (typeof integrationContext !== 'undefined' && integrationContext.platform === 'woocommerce') ? 'wc_' : 'wp_';

    if (wpContext.userId) {
      var traits = { email: wpContext.userEmail };
      if (typeof integrationContext !== 'undefined') {
        Object.assign(traits, integrationContext.traits || {});
      }
      window.segmentflow.identify(prefix + wpContext.userId, traits);
    }

    // Set context from integration enrichment
    if (typeof integrationContext !== 'undefined' && integrationContext.context) {
      window.segmentflow.setContext(integrationContext.context);
    }
  };
  document.head.appendChild(script);
})();
</script>
```

**`integrations/woocommerce/class-segmentflow-wc-tracking.php`** -- WooCommerce context enrichment:

```php
<?php
class Segmentflow_WC_Tracking {

    public function register_hooks() {
        add_filter( 'segmentflow_tracking_context', array( $this, 'add_woocommerce_context' ) );
    }

    public function add_woocommerce_context( $context ) {
        $context['platform'] = 'woocommerce';
        $context['traits'] = array(
            'woocommerce_customer_id' => get_current_user_id(),
        );
        $context['context'] = array(
            'store_url' => home_url(),
            'currency'  => get_woocommerce_currency(),
            'cart_hash' => WC()->cart ? WC()->cart->get_cart_hash() : null,
        );
        return $context;
    }
}
```

Key architectural decisions:
- Core tracking uses `wp_` prefix for user IDs; WooCommerce enrichment changes it to `wc_`
- WooCommerce context is injected via `segmentflow_tracking_context` filter -- clean separation
- On a plain WordPress site, you get: page views, identify for logged-in users, referrer tracking
- On a WooCommerce site, you additionally get: cart hash, currency, WC customer ID in context

**`admin/class-segmentflow-admin.php`** -- Settings page with dynamic tabs:

Registers a top-level "Segmentflow" menu item in WP admin. Tabs adapt based on active integrations:

| Tab | Shown When | Content |
|---|---|---|
| **Connection** | Always | Connect/disconnect to Segmentflow, write key status |
| **Settings** | Always, if connected | Debug mode, consent required, API host override |
| **WooCommerce** | WooCommerce active AND connected | WC-specific settings, auto-auth status, store info |

Without WooCommerce, the plugin still has a functional settings page -- just fewer tabs.

**`class-segmentflow-auth.php`** -- Connection flow:

Three connection scenarios:

**Scenario 1: Plain WordPress site (no WooCommerce)**
1. User clicks "Connect to Segmentflow" in plugin settings
2. Plugin redirects to Segmentflow dashboard: `https://app.segmentflow.ai/connect/wordpress?site_url={site_url}&return_url={wp_admin_url}`
3. User logs in / selects organization
4. Dashboard generates a write key and redirects back with it as a query parameter
5. Plugin stores write key in `wp_options`
6. SDK injection begins immediately (page views + identify for logged-in users)
7. No WC auto-auth needed -- no webhooks, no REST sync, just client-side tracking

**Scenario 2: WordPress + WooCommerce (from plugin settings)**
1. User clicks "Connect to Segmentflow" in plugin settings
2. Plugin detects WooCommerce is active
3. Plugin redirects to Segmentflow dashboard: `https://app.segmentflow.ai/connect/woocommerce?store_url={store_url}&return_url={wp_admin_url}`
4. User logs in / selects organization in Segmentflow dashboard
5. Segmentflow API generates the auto-auth URL and redirects to: `{store_url}/wc-auth/v1/authorize?app_name=Segmentflow&scope=read_write&user_id={orgId}&return_url={wp_admin_url}&callback_url={api_callback_url}`
6. User sees WooCommerce consent screen, clicks "Approve"
7. WooCommerce POSTs consumer key + secret to Segmentflow API callback
8. API stores credentials, creates write key, registers webhooks, triggers sync
9. User is redirected back to WP admin with success parameter
10. Plugin stores write key in `wp_options`, begins injecting SDK with WC context

**Scenario 3: From Segmentflow Dashboard (WooCommerce)**
1. User clicks "Connect WooCommerce" in dashboard, enters store URL
2. Dashboard redirects to: `{store_url}/wc-auth/v1/authorize?app_name=Segmentflow&scope=read_write&user_id={orgId}&return_url={dashboard_url}&callback_url={api_callback_url}`
3. User sees WooCommerce consent screen, clicks "Approve"
4. WooCommerce POSTs consumer key + secret to Segmentflow API callback
5. API stores credentials, creates write key, registers webhooks, triggers sync
6. User is redirected back to Segmentflow dashboard
7. Dashboard shows connected status
8. **Note**: Storefront tracking won't work until the plugin is installed. Dashboard shows: "Install Segmentflow Connect to enable storefront tracking" with a link to WordPress.org

**`class-segmentflow-lifecycle.php`** -- Late activation handling:

```php
<?php
class Segmentflow_Lifecycle {

    public function activate() {
        // Create wp_options defaults
        // If WooCommerce is active: set up WC-specific options
    }

    // Handle WooCommerce activated AFTER Segmentflow Connect
    public function check_for_dependency( $plugin, $network ) {
        if ( $plugin === 'woocommerce/woocommerce.php' ) {
            // WooCommerce was just activated -- initialize WC features
            // If already connected, offer to upgrade connection with WC auto-auth
        }
    }
}
```

This ensures install order doesn't matter -- WooCommerce can be installed before or after the plugin.

##### SDK WooCommerce Plugin (`woocommerce.plugin.ts`)

New file: `packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts`

Equivalent to `shopify.plugin.ts`. Detects WooCommerce environment and enriches events:

```typescript
export class WooCommercePlugin implements Plugin {
  name = "woocommerce";

  private context: WooCommerceContext = { isWooCommerce: false };

  async load(_analytics: SegmentflowWeb): Promise<void> {
    // Detect WooCommerce by checking for WC-specific globals
    // wc_add_to_cart_params, wc_cart_fragments_params, woocommerce_params
    this.context = getWooCommerceContext();
  }

  track(event): void {
    if (!this.context.isWooCommerce) return;
    event.context.woocommerce = {
      store_url: this.context.storeUrl,
      currency: this.context.currency,
      cart_hash: this.context.cartHash,
      customer_id: this.context.customerId,
    };
  }

  identify(event): void { /* similar enrichment */ }
  page(event): void { /* similar enrichment */ }
}
```

WooCommerce detection signals (check `window` globals):
- `wc_add_to_cart_params` -- present on product pages with AJAX add-to-cart
- `wc_cart_fragments_params` -- present on all pages with cart fragments enabled
- `woocommerce_params` -- general WC JavaScript params
- `wp` object with `woocommerce` properties

**Update to `cdn-entry.ts`**: Auto-register the WooCommerce plugin alongside the existing ones:

```typescript
import { createWooCommercePlugin } from "./plugins/woocommerce.plugin.js";

// In init():
instance.register(createUtmPlugin());
instance.register(createShopifyPlugin());
instance.register(createWooCommercePlugin());
```

Only one platform plugin will activate per page (they check for platform-specific globals and no-op if not detected).

#### 11. Brand Kit Integration

For "brand-aware creative", each platform provides different access to brand assets:

| Asset | Shopify | WooCommerce |
|---|---|---|
| Store logo | Theme settings API | WordPress site icon API (`/wp-json/wp/v2/settings`) or `<link rel="icon">` scraping |
| Product photos | Products API `images[]` | Products API `images[]` (same structure) |
| Color palette | Theme CSS inspection | WordPress theme customizer or CSS scraping |

The `@segmentflow/brand` package handles URL-based brand extraction. For WooCommerce:
1. Use the store URL to fetch favicon/logo via the brand kit's existing URL scraping
2. Pull product images from the Products API during historical sync
3. Color palette extraction via the same CSS analysis the brand kit already does

This should largely work without WooCommerce-specific code.

---

### Implementation Order

| # | Task | Estimated Time | Dependencies |
|---|---|---|---|
| 0 | **`segmentflow-connect` repo setup**: package.json, pnpm, tsconfig, tsdown, eslint, prettier, husky, lint-staged, composer, phpcs, phpunit, changesets, GitHub Actions CI/CD, .gitignore, .editorconfig, .nvmrc | 1.5 days | None |
| 1 | WordPress plugin: Orchestrator pattern (`class-segmentflow.php`, `class-segmentflow-helper.php`, `class-segmentflow-options.php`, `class-segmentflow-lifecycle.php`) | 1 day | #0 |
| 2 | WordPress plugin: Core tracking (`class-segmentflow-tracking.php`) -- SDK injection for ANY WordPress site (page views, identify logged-in users, `segmentflow_tracking_context` filter) | 0.5 day | #1 |
| 3 | WordPress plugin: Core connection flow (`class-segmentflow-auth.php`) -- plain WordPress site connect (write key only, no WC auto-auth) | 0.5 day | #1 |
| 4 | WordPress plugin: Admin UI (`class-segmentflow-admin.php`, `class-segmentflow-admin-settings.php`, partials) -- dynamic tabs, settings page | 0.5 day | #1, #2, #3 |
| 5 | WordPress plugin: WC integration (`integrations/woocommerce/class-segmentflow-wc-tracking.php`) -- cart hash, currency, WC customer ID enrichment via filter | 0.5 day | #2 |
| 6 | WordPress plugin: WC auto-auth (`integrations/woocommerce/class-segmentflow-wc-auth.php`) -- both entry points | 1 day | #4, #5 |
| 7 | WordPress plugin: TypeScript admin JS (`src/admin.ts` + tsdown build) | 0.5 day | #0, #4 |
| 8 | Prisma schema: `WooCommerceIntegration` model + enum update + migration | 0.5 day | None |
| 9 | Config schema for WooCommerce env vars | 0.5 day | None |
| 10 | WooCommerce API client wrapper (REST calls with auth, pagination, error handling) | 1 day | None |
| 11 | `WooCommerceIntegrationService` (connect, validate, register webhooks, disconnect) | 2 days | #8, #9, #10 |
| 12 | `WooCommerceIntegrationService` historical sync (customers, orders, products) | 1.5 days | #11 |
| 13 | `WooCommerceWebhookService` (HMAC verify, dedup, route by topic, emit events) | 1.5 days | #8 |
| 14 | API routes + Zod schemas (connect, authorize, callback, wordpress/connect, status, sync, disconnect, webhook receiver) | 1 day | #11, #13 |
| 15 | Repository: `woocommerce-integration.repository.ts` | 0.5 day | #8 |
| 16 | Segment templates (`WOOCOMMERCE_SEGMENT_TEMPLATES`) | 0.5 day | None |
| 17 | User property templates (`WOOCOMMERCE_USER_PROPERTY_TEMPLATES`) | 0.5 day | None |
| 18 | Integration framework updates (utils, seeders, bootstrapper) | 0.5 day | #16, #17 |
| 19 | SDK: `woocommerce.plugin.ts` + register in `cdn-entry.ts` | 1 day | None |
| 20 | Dashboard: `WooCommerceConnectCard` + `WordPressConnectCard` (with plugin status detection) | 1.5 days | #14 |
| 21 | Dashboard: Update integrations page, IntegrationBadge, onboarding step | 0.5 day | #20 |
| 22 | WordPress.org submission prep (readme.txt, screenshots, plugin assets) | 0.5 day | #1-#7 |
| 23 | Testing + edge cases (API, webhooks, plugin with/without WC, PHPUnit, all connection flows) | 2 days | All above |
| **Total** | | **~18.5 days** | |

### New Files

#### In `segmentflow-ai` (main repo)

| File | Purpose |
|---|---|
| `packages/node/database/prisma/migrations/XXXX_add_woocommerce_integration/migration.sql` | Database migration |
| `services/node/api/src/config/schemas/woocommerce.schema.ts` | Config/env schema |
| `services/node/api/src/lib/integrations/woocommerce/client.ts` | WooCommerce API client wrapper |
| `services/node/api/src/lib/integrations/woocommerce/types.ts` | WC API response types |
| `services/node/api/src/services/v1/integrations/woocommerce.service.ts` | Connection + sync service |
| `services/node/api/src/services/v1/webhooks/woocommerce.service.ts` | Webhook processing |
| `services/node/api/src/routes/v1/integrations/woocommerce.ts` | Integration routes (connect, authorize, callback, status, sync, disconnect) |
| `services/node/api/src/routes/v1/webhooks/woocommerce.ts` | Webhook route |
| `packages/node/database/src/repositories/integrations/woocommerce-integration.repository.ts` | DB repository |
| `packages/node/api-schemas/src/segments/templates/woocommerce.templates.ts` | Segment templates |
| `packages/node/api-schemas/src/user-properties/templates/woocommerce.templates.ts` | User property templates |
| `packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts` | SDK WooCommerce detection + context enrichment plugin |
| `services/web/dashboard/src/features/integrations/components/WooCommerceConnectCard.tsx` | Dashboard card (with plugin status detection) |
| `services/web/dashboard/src/features/integrations/hooks/useWooCommerce.ts` | Dashboard hook |
| `services/web/dashboard/src/features/integrations/hooks/useWooCommerceApi.ts` | API client hook |
| `services/node/api/src/routes/v1/integrations/wordpress.ts` | WordPress connect route (write key generation for plain WP sites, no WC auto-auth) |
| `services/web/dashboard/src/features/integrations/components/WordPressConnectCard.tsx` | Dashboard card for plain WordPress sites (without WooCommerce) |

#### In `segmentflow-connect` (new public repo)

| File | Purpose |
|---|---|
| `segmentflow-connect.php` | Bootstrap file (constants, require plugin.php, instantiate orchestrator) |
| `includes/class-segmentflow.php` | Orchestrator: `load_dependencies()` + `init_classes()` with conditional integration loading |
| `includes/class-segmentflow-helper.php` | Static helpers: `is_woocommerce_active()`, language detection, sanitization |
| `includes/class-segmentflow-options.php` | `wp_options` read/write (write key, API host, settings) |
| `includes/class-segmentflow-tracking.php` | Core `wp_head` hook: inject CDN SDK + WordPress user context (ALWAYS active) |
| `includes/class-segmentflow-auth.php` | Connection flow handler (plain WP + WC auto-auth scenarios) |
| `includes/class-segmentflow-api.php` | HTTP client for Segmentflow API (status check, write key fetch) |
| `includes/class-segmentflow-lifecycle.php` | Activation, deactivation, uninstall, late activation detection |
| `admin/class-segmentflow-admin.php` | Admin menu, dynamic tabs based on active integrations, CSS/JS enqueue |
| `admin/class-segmentflow-admin-settings.php` | WordPress Settings API registration |
| `admin/partials/admin-page.php` | Settings page shell template |
| `admin/partials/admin-connection.php` | Connection tab template (always shown) |
| `admin/partials/admin-woocommerce.php` | WooCommerce-specific settings tab (conditional) |
| `integrations/woocommerce/class-segmentflow-wc-tracking.php` | WC context enrichment via `segmentflow_tracking_context` filter (cart, currency, customer) |
| `integrations/woocommerce/class-segmentflow-wc-auth.php` | WC auto-auth endpoint handling (`/wc-auth/v1/authorize`) |
| `integrations/woocommerce/class-segmentflow-wc-helper.php` | WC-specific utility functions |
| `src/admin.ts` | TypeScript source for admin page JS (connect button, status polling, disconnect) |
| `assets/css/admin.css` | Settings page styles |
| `assets/js/admin.js` | Compiled IIFE bundle from `src/admin.ts` (gitignored, built by tsdown) |
| `assets/images/segmentflow-icon.svg` | Plugin icon |
| `tests/bootstrap.php` | PHPUnit bootstrap (loads WP test suite + optionally WooCommerce + plugin) |
| `tests/test-activation.php` | Plugin activation/deactivation tests |
| `tests/test-tracking.php` | Core SDK injection tests (without WooCommerce) |
| `tests/test-tracking-woocommerce.php` | WC-specific context enrichment tests |
| `tests/test-auth.php` | Connection flow tests (plain WP) |
| `tests/test-wc-auth.php` | WC auto-auth flow tests |
| `tests/test-admin.php` | Settings page tests (with and without WC tabs) |
| `tests/test-helper.php` | Integration detection tests (`is_woocommerce_active()`, etc.) |
| `scripts/bump-version.mjs` | Sync version across package.json, PHP plugin header, and readme.txt |
| `scripts/create-zip.mjs` | Create distributable plugin .zip (excludes dev files) |
| `uninstall.php` | Cleanup on plugin deletion (remove `wp_options`) |
| `readme.txt` | WordPress.org plugin description (covers WordPress + WooCommerce) |
| `LICENSE` | GPL v2+ |
| `package.json` | Node dependencies + scripts (tsdown, eslint, prettier, lint-staged, changesets) |
| `tsconfig.json` | TypeScript config (ES2020 target, browser libs, IIFE output) |
| `tsdown.config.ts` | tsdown build config (IIFE format, browser platform, minified) |
| `eslint.config.mjs` | ESLint 9 flat config for TypeScript (mirrors main repo rules) |
| `.prettierrc` | Prettier formatting config |
| `.lintstagedrc.json` | Lint-staged config (TS lint+format, PHP phpcbf) |
| `.husky/pre-commit` | Pre-commit hook running lint-staged |
| `composer.json` | PHP deps (phpcs, phpunit, wp-coding-standards, phpcompatibility) |
| `phpcs.xml.dist` | PHPCS config (WordPress-Extra + PHPCompatibility, PHP 7.4+, WP 5.8+) |
| `phpunit.xml.dist` | PHPUnit config (tests/ directory, coverage for includes/ + integrations/) |
| `.changeset/config.json` | Changesets config (GitHub changelog, `main` base branch) |
| `.github/workflows/ci.yml` | PR checks: lint TS + lint PHP + test PHP (matrix: PHP 7.4/8.1/8.2 x WP 6.4/latest) |
| `.github/workflows/release.yml` | Changeset version PR + WordPress.org SVN deploy on merge |
| `.github/workflows/build.yml` | Manual workflow: build admin JS + create plugin zip artifact |
| `.nvmrc` | Node.js version (24, matches main repo) |
| `.gitignore` | Ignores node_modules, vendor, build output, .zip, .env |
| `.editorconfig` | Tabs for PHP (4-wide), spaces for TS/JS (2-wide) |

### Modified Files

| File | Change |
|---|---|
| `packages/node/database/prisma/schema.prisma` | Add model, update enum, add Organization relation |
| `services/node/api/src/routes/v1/integrations/index.ts` | Register WooCommerce routes |
| `services/node/api/src/routes/v1/webhooks/index.ts` | Register WooCommerce webhook route |
| `services/node/api/src/services/v1/integrations/integration-utils.ts` | Uncomment WooCommerce cases |
| `services/node/api/src/services/v1/integrations/segment-seeder.service.ts` | Add WooCommerce template case |
| `services/node/api/src/services/v1/integrations/user-property-seeder.service.ts` | Add WooCommerce template case |
| `packages/node/api-schemas/src/segments/templates/index.ts` | Export WooCommerce templates |
| `packages/node/api-schemas/src/user-properties/templates/index.ts` | Export WooCommerce templates |
| `packages/browser/sdk-web/src/cdn-entry.ts` | Import and register `createWooCommercePlugin()` |
| `services/web/dashboard/src/app/(protected)/integrations/page.tsx` | Add WooCommerceConnectCard |
| `services/web/dashboard/src/features/integrations/index.ts` | Export WooCommerce components/hooks |
| `services/web/dashboard/src/components/shared/IntegrationBadge.tsx` | Uncomment WooCommerce badge |
| `services/web/dashboard/src/features/onboarding/components/steps/SetupIntegrationStep.tsx` | Replace "Coming Soon" with WooCommerce connection form |

---

## Phase 2: BigCommerce Integration (Future)

BigCommerce follows the same pattern as WooCommerce with minor differences:

| Aspect | WooCommerce | BigCommerce |
|---|---|---|
| API Base | `/wp-json/wc/v3/` | `/stores/{store_hash}/v3/` |
| Auth | Consumer Key/Secret | OAuth access token or API key |
| Webhooks | REST registration + HMAC | REST registration + webhook secret |
| Pagination | Page-based (`X-WP-TotalPages`) | Cursor-based or page-based |
| Customer data | `/customers` | `/customers` |
| Order data | `/orders` | `/orders` |
| Product data | `/products` | `/catalog/products` |

By the time we implement BigCommerce, the WooCommerce work will have established patterns for API client wrappers, webhook services, and dashboard components that can be largely copied and adapted.

Estimated effort: **5-7 working days** (reduced from Phase 1 because patterns are established).

---

## Phase 3: Integration Platform Expansion (Future)

### WordPress Form Integrations (within `segmentflow-connect`)
- The unified `segmentflow-connect` plugin already works on plain WordPress sites (Phase 1). Phase 3 adds deeper form integrations as new modules in the `integrations/` directory:
  - **Contact Form 7**: `integrations/cf7/` -- hook into `wpcf7_submit`, forward submissions to Segmentflow `identify()` call. Per-form configuration in CF7 editor.
  - **Elementor**: `integrations/elementor/` -- Elementor widget for newsletter sign-up form that submits to Segmentflow.
  - **WPForms / Gravity Forms**: Similar pattern -- detect plugin, hook into submission, forward to Segmentflow.
- Each integration follows the same pattern: `Segmentflow_Helper::is_{integration}_active()` detection, conditional file loading in the orchestrator, `register_hooks()` contract.
- No separate plugin needed -- all modules live in the same `segmentflow-connect` repo.
- Estimated effort per form integration: 1-3 days each
- No additional WordPress.org approval needed -- it's the same plugin with new features

### Zapier / Make Integration
- Force multiplier: one integration gives connections to 5000+ apps
- Publish triggers (new profile, segment membership change) and actions (add profile, track event)
- Estimated effort: 3-5 days
- Zapier review: 2-4 weeks

### Drupal Module
- Smaller market but relevant for enterprise/agencies
- REST API integration similar to WooCommerce
- Estimated effort: 5-7 days

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| WC stores on HTTP (not HTTPS) | OAuth 1.0a is complex, security risk | Require HTTPS. Show clear error for HTTP stores. |
| WC API rate limits vary by host | Sync may be throttled on shared hosting | Implement exponential backoff. Allow configurable page size (default 100, reduce on 429). |
| WC stores behind firewalls/auth | Can't deliver webhooks | Provide polling fallback option (cron-style periodic sync). |
| WC REST API disabled by security plugins | Connection fails | Detect and show specific error message with instructions to whitelist. |
| WC customer API doesn't return `orders_count`/`total_spent` | Missing key traits for segmentation | Compute during historical order sync. Re-compute on each order webhook. |
| Merchants run old WC versions | API incompatibility | Require WC 3.5+ (v3 API). Check version on connect and warn if outdated. |
| Credential rotation | Merchant revokes/regenerates API keys | Detect 401 errors on webhook delivery or sync. Show "reconnect" prompt in dashboard. |
| WordPress.org plugin review takes longer than expected | Delays plugin distribution | Offer direct `.zip` download from Segmentflow website as interim. Submit to WordPress.org early in the implementation cycle. |
| Plugin conflicts with other WP plugins (caching, security, optimization) | SDK script blocked or broken on storefront | Test with popular plugins (WP Super Cache, Wordfence, Yoast, WP Rocket). Add compatibility notes to readme.txt. |
| Merchant connects from dashboard without installing plugin | No storefront tracking (webhooks + REST sync still work) | Show clear warning banner in dashboard. Webhooks and historical sync work independently of the plugin. |
| WooCommerce auto-auth endpoint disabled by security plugin | Connection flow fails | Detect and show specific error. Offer manual API key entry as fallback. |
| WooCommerce installed/uninstalled after plugin activation | Integration features may not activate/deactivate correctly | Late activation detection via `activated_plugin` hook (like Smaily Connect). Create WC-specific options and register hooks dynamically when WC is activated after the plugin. |
| Unified plugin rejected by WordPress.org due to WooCommerce dependency confusion | Plugin listing may be unclear about WooCommerce being optional | Clearly document in readme.txt that WooCommerce is optional. Use "WC requires at least" header (WordPress.org understands this as optional). Test the plugin passes review without WooCommerce installed. |

---

## Testing Strategy

### Unit Tests (main repo)
- WooCommerce API client: mock HTTP responses, test pagination, error handling
- Webhook HMAC verification: test with known signature/secret pairs
- Customer/Order mapping: test trait extraction from WC JSON payloads
- Credential encryption/decryption round-trip

### PHPUnit Tests (`segmentflow-connect` repo)
- **Core tracking (without WooCommerce)**: verify SDK script output contains write key, API host, WordPress user context; verify no WC-specific context is present when WC is absent
- **Core tracking (with WooCommerce)**: verify SDK script includes WC enrichment (cart hash, currency, WC customer ID); verify `segmentflow_tracking_context` filter adds WC context
- **Integration detection**: verify `is_woocommerce_active()` returns correct value with/without WooCommerce loaded
- **Orchestrator**: verify conditional file loading -- WC classes are NOT instantiated when WC is absent
- **Late activation**: verify `activated_plugin` hook triggers WC feature initialization
- **Activation/deactivation**: verify options are created/cleaned up correctly
- **Admin UI**: verify WooCommerce tab only appears when WC is active AND credentials are configured

### Integration Tests (main repo)
- Full connect flow (WooCommerce): submit credentials -> validate -> register webhooks -> bootstrap
- Full connect flow (plain WordPress): generate write key -> store in response -> verify tracking works
- Webhook processing: simulate WC webhook payloads -> verify UserEvent creation
- Historical sync: mock paginated API responses -> verify all profiles/events created
- Disconnect: verify webhook cleanup and record deletion

### E2E Tests (manual)
- **Plain WordPress site**: install plugin, connect, verify page views appear in Segmentflow dashboard
- **WooCommerce store**: install plugin, connect via auto-auth, verify full integration (webhooks, sync, storefront tracking with WC context)
- **WooCommerce activated after plugin**: install plugin on plain WP site, connect, then install WooCommerce -- verify WC features activate and offer to upgrade connection
- Place test orders -> verify webhook delivery -> verify profile updates
- Verify segment computation picks up WC events
- Verify dashboard shows correct status and store metadata

---

## Success Criteria

1. A WooCommerce store owner can connect their store in < 2 minutes via the dashboard or the WordPress plugin
2. A plain WordPress site owner (no WooCommerce) can install the plugin and get page view tracking in < 2 minutes
3. Historical customer and order data syncs within 5 minutes for stores with < 10,000 orders
4. Real-time webhooks process within 5 seconds of store events
5. Pre-built segments (Repeat Customers, etc.) compute correctly from WC data
6. Revenue attribution works: campaigns -> WC orders -> attributed revenue
7. Brand kit can extract logo and product images from WC stores
8. The integration can be disconnected cleanly, removing all webhooks and credentials
9. The plugin can be installed from WordPress.org and connects in < 3 clicks
10. Storefront tracking (page views, identity stitching) works within 30 seconds of plugin activation and connection
11. All three connection scenarios work: plain WordPress from plugin, WooCommerce from plugin, WooCommerce from dashboard
12. WooCommerce features activate/deactivate correctly when WooCommerce is installed/uninstalled after the plugin
13. The plugin works on WordPress sites without WooCommerce -- no errors, no missing functions, no WC-specific output in the SDK script
