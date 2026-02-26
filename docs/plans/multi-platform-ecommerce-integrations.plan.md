# Multi-Platform E-Commerce Integrations Plan

**Created**: 2026-02-21
**Status**: Phase 1 Complete | Phase 2-3 Future
**Last Updated**: 2026-02-26

## Changelog

| Date       | Changes                                                                                                                                                                                                                     |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-02-21 | Initial plan created. WooCommerce as first integration, BigCommerce as follow-up.                                                                                                                                           |
| 2026-02-21 | Updated WooCommerce approach: unified WordPress plugin (`segmentflow-connect`), CDN-loaded SDK, dual-direction auto-auth, WooCommerce SDK plugin.                                                                           |
| 2026-02-21 | Added comprehensive repo setup. Unified plugin redesign with orchestrator pattern and `integrations/` directory.                                                                                                            |
| 2026-02-26 | **Phase 1 complete.** All WooCommerce integration work finished across both repos. Server-side event architecture (identity cookie, PHP hooks, SDK cleanup) also complete (see deleted `server-side-order-events.plan.md`). |

---

## Phase 1: WooCommerce Integration -- COMPLETE

All items below have been implemented, tested, committed, and deployed.

### `segmentflow-connect` (WordPress Plugin)

| Component                                                                       | Status | Key Files                                                                                                         |
| ------------------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------- |
| Repo setup (pnpm, tsdown, ESLint, Prettier, PHPCS, PHPUnit, Husky, lint-staged) | Done   | `package.json`, `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`                                             |
| Orchestrator pattern                                                            | Done   | `includes/class-segmentflow.php`                                                                                  |
| Core tracking (any WordPress site)                                              | Done   | `includes/class-segmentflow-tracking.php`                                                                         |
| Connection flow (plain WP + WC auto-auth)                                       | Done   | `includes/class-segmentflow-auth.php`                                                                             |
| Admin UI (dynamic tabs)                                                         | Done   | `admin/class-segmentflow-admin.php`, `admin/class-segmentflow-admin-settings.php`                                 |
| WooCommerce context enrichment                                                  | Done   | `integrations/woocommerce/class-segmentflow-wc-tracking.php`                                                      |
| WC auto-auth                                                                    | Done   | `integrations/woocommerce/class-segmentflow-wc-auth.php`                                                          |
| Unified identity cookie (`sf_id`)                                               | Done   | `includes/class-segmentflow-identity-cookie.php`                                                                  |
| PHP server-side events (cart, forms, identity)                                  | Done   | `includes/class-segmentflow-server-events.php`, `integrations/woocommerce/class-segmentflow-wc-server-events.php` |
| Non-blocking HTTP for fire-and-forget                                           | Done   | `includes/class-segmentflow-api.php`                                                                              |
| PHPUnit tests (49+ tests passing)                                               | Done   | `tests/test-identity-cookie.php`, `tests/test-server-events.php`, `tests/test-wc-server-events.php`, etc.         |

### `segmentflow-ai` (Backend API)

| Component                                                                       | Status | Key Files                                                                                      |
| ------------------------------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------- |
| Prisma `WooCommerceIntegration` model + migrations                              | Done   | `schema.prisma`, 4 migrations applied                                                          |
| `IntegrationType.woocommerce` enum                                              | Done   | `schema.prisma`                                                                                |
| WooCommerce repository                                                          | Done   | `packages/node/database/src/repositories/integrations/woocommerce-integration.repository.ts`   |
| WooCommerce REST API client (auth, pagination, retry)                           | Done   | `services/node/api/src/lib/integrations/woocommerce/client.ts` (395 lines)                     |
| WooCommerce types                                                               | Done   | `services/node/api/src/lib/integrations/woocommerce/types.ts` (229 lines)                      |
| Config schema                                                                   | Done   | `services/node/api/src/config/schemas/woocommerce.schema.ts`                                   |
| Integration service (connect, auto-auth, disconnect, webhooks, historical sync) | Done   | `services/node/api/src/services/v1/integrations/woocommerce.service.ts` (915 lines)            |
| Integration routes (6 endpoints)                                                | Done   | `services/node/api/src/routes/v1/integrations/woocommerce.ts` (365 lines)                      |
| Webhook service (HMAC, dedup, order/customer/product handlers)                  | Done   | `services/node/api/src/services/v1/webhooks/woocommerce.service.ts` (684 lines)                |
| Webhook routes                                                                  | Done   | `services/node/api/src/routes/v1/webhooks/woocommerce.ts`                                      |
| Route registration (integration + webhook)                                      | Done   | Both index files updated                                                                       |
| Integration utils (`formatIntegrationName`, `supportsBootstrap`)                | Done   | `integration-utils.ts`                                                                         |
| Segment seeder (shared `ECOMMERCE_SEGMENT_TEMPLATES`)                           | Done   | `segment-seeder.service.ts`                                                                    |
| User property templates                                                         | Done   | `packages/node/api-schemas/src/user-properties/templates/woocommerce.templates.ts` (225 lines) |
| Event definition seeder                                                         | Done   | `event-definition-seeder.service.ts`                                                           |
| Integration bootstrapper (called on first install)                              | Done   | `integration-bootstrapper.service.ts`                                                          |
| `source` field on batch endpoint                                                | Done   | `batch.ts`, `event-ingestion.service.ts`                                                       |
| Service tests                                                                   | Done   | `woocommerce.service.test.ts` (686 lines)                                                      |
| Webhook tests                                                                   | Done   | `woocommerce-webhook.service.test.ts` (916 lines)                                              |
| Segment seeder tests                                                            | Done   | Tests WooCommerce template selection                                                           |

### `segmentflow-ai` (SDK)

| Component                                                             | Status | Key Files                                                                |
| --------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------ |
| SDK WooCommerce plugin (detection, context enrichment, email capture) | Done   | `packages/browser/sdk-web/src/plugins/woocommerce.plugin.ts` (572 lines) |
| Unified `sf_id` cookie (read/write, merge-on-write)                   | Done   | `packages/browser/sdk-web/src/web.ts`                                    |
| `source: "sdk"` on all events                                         | Done   | `packages/browser/sdk-web/src/web.ts`                                    |
| `source` in `BaseEvent` interface                                     | Done   | `packages/node/sdk-core/src/events/types.ts`                             |
| Cart listeners removed (PHP authoritative)                            | Done   | `woocommerce.plugin.ts` (-451 lines)                                     |
| SDK tests (49 tests)                                                  | Done   | `woocommerce.plugin.test.ts` (799 lines)                                 |
| SDK deployed to CDN                                                   | Done   | `sdk.min.js` (22.5 KB) live                                              |

### `segmentflow-ai` (Dashboard)

| Component                                             | Status | Key Files                                                       |
| ----------------------------------------------------- | ------ | --------------------------------------------------------------- |
| `WooCommerceConnectCard` (all states)                 | Done   | `features/integrations/components/WooCommerceConnectCard.tsx`   |
| `useWooCommerce` hook                                 | Done   | `features/integrations/hooks/useWooCommerce.ts`                 |
| `useWooCommerceApi` hook                              | Done   | `features/integrations/hooks/useWooCommerceApi.ts`              |
| API client hooks (auto-generated, cache invalidation) | Done   | `packages/node/api-clients/src/hooks/integrations.ts`           |
| Integrations page (WooCommerceConnectCard rendered)   | Done   | `app/(protected)/integrations/page.tsx`                         |
| `IntegrationBadge` (woocommerce case active)          | Done   | `components/shared/IntegrationBadge.tsx`                        |
| Onboarding `SetupIntegrationStep` (WC connect form)   | Done   | `features/onboarding/components/steps/SetupIntegrationStep.tsx` |
| Plugin connection page (`/connect/woocommerce`)       | Done   | `app/(public)/connect/woocommerce/page.tsx`                     |
| CSV import (WooCommerce Customers export)             | Done   | `features/segments/components/ImportContactsModal.tsx`          |

### Remaining for Phase 1

| Item                         | Status      | Notes                                                                                                                                             |
| ---------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| WordPress.org SVN submission | Not started | Plugin is functional but hasn't been submitted. CI/CD workflows, changesets, `readme.txt` are in place.                                           |
| End-to-end manual testing    | Ongoing     | Full flow (connect, auto-auth, webhook delivery, historical sync, SDK events, identity stitching) needs verification on a real WooCommerce store. |

---

## Phase 2: BigCommerce Integration -- FUTURE

Estimated effort: **5-7 working days** (patterns established by Phase 1).

BigCommerce follows the same architecture as WooCommerce with minor API differences:

| Aspect     | WooCommerce                    | BigCommerce                        |
| ---------- | ------------------------------ | ---------------------------------- |
| API Base   | `/wp-json/wc/v3/`              | `/stores/{store_hash}/v3/`         |
| Auth       | Consumer Key/Secret            | OAuth access token or API key      |
| Webhooks   | REST registration + HMAC       | REST registration + webhook secret |
| Pagination | Page-based (`X-WP-TotalPages`) | Cursor-based or page-based         |

Work required:

- `BigCommerceIntegration` Prisma model + `IntegrationType.bigcommerce` enum
- `BigCommerceIntegrationService` (connect, sync, disconnect)
- `BigCommerceWebhookService` (HMAC verify, dedup, event routing)
- API routes + config schema
- User property templates (BigCommerce-specific)
- Dashboard `BigCommerceConnectCard` + onboarding step
- No WordPress plugin changes needed (BigCommerce is server-side only)

---

## Phase 3: Integration Platform Expansion -- FUTURE

### WordPress Form Integrations (within `segmentflow-connect`)

The server-side events work already added PHP hooks for CF7 and Elementor Pro. Future work:

- **WPForms / Gravity Forms**: Same pattern -- detect plugin, hook into submission, forward to Segmentflow
- Estimated effort per form integration: 1-3 days each
- No additional WordPress.org approval needed

### Zapier / Make Integration

- Publish triggers (new profile, segment membership change) and actions (add profile, track event)
- Estimated effort: 3-5 days + 2-4 weeks Zapier review
