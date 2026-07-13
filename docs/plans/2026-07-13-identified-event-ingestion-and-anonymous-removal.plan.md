# Segmentflow Connect Identified Event Ingestion and Anonymous Removal Implementation Plan

**Status:** Ready for independent parallel implementation

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert `segmentflow-connect` to identified-only WordPress and WooCommerce event production, remove its anonymous identity lifecycle, and emit retry-safe payloads that satisfy the frozen Segmentflow ingest contract.

**Architecture:** WordPress/WooCommerce hooks obtain customer identity only from trusted hook data: canonical email and, when present, the persistent WordPress or WooCommerce customer ID. A shared pure payload builder creates the exact identified-ingest items, and a dedicated ingest client owns response parsing and bounded retry with the same message IDs. The plugin contains no anonymous cookie, anonymous queue, Profile ID, tenant ID, source-instance selection, or identity namespace. Browser page/product/checkout-started interactions remain owned by the shared Segmentflow SDK; server hooks own customer lifecycle, forms, identified cart mutations, and checkout identity.

**Tech Stack:** PHP 8+, WordPress, WooCommerce, TypeScript 5.9, Vitest, PHPUnit, PHPCS, ESLint, pnpm, and tsdown.

## Repository Scope

- This plan modifies only `/Users/olivernaaris/git-projects/segmentflow-connect`.
- Do not edit, commit, stage, or run formatters against `segmentflow-ai` while executing this plan.
- The backend may be implemented in parallel because every request, response, identity, retry, and fixture decision needed here is frozen below.
- Repository completion does not require a running backend or a completed sibling worktree. Local tests validate the contract structurally; the real backend schema validation is a coordinated gate after both plans complete.

## Global Constraints

- Remove `Segmentflow_Identity_Cookie`, the `sf_id` cookie, anonymous UUID generation, anonymous local storage, anonymous request fields, consent-time browse queues, and anonymous migration behavior in one hard cutover.
- Do not add feature flags, compatibility readers, deprecated delegates, forwarding classes, dual payloads, anonymous fallbacks, old-schema parsers, or intentionally dead code.
- Old anonymous payloads are invalid. Do not accept, transform, or silently discard them through a compatibility path.
- The plugin never sends `organizationId`, `sourceInstanceId`, `identityNamespace`, `profileId`, or any other Segmentflow internal routing identifier.
- Canonical email is a top-level transport locator. Do not search arbitrary traits or properties to discover routing identity.
- `identify` requires canonical email. `track` and `page` require email and/or external `userId`; neither creates a Profile.
- On a WooCommerce-connected Website, account identities use `wc_<customerId>`. On plain WordPress, account identities use `wp_<userId>`. Guest form and checkout identity may omit `userId` and use email only.
- The authenticated WriteKey belongs to the persistent Website. The backend, not this plugin, derives `wordpress:<websiteId>` or `woocommerce:<websiteId>` and preserves it across reconnection/credential rotation.
- Session IDs, cart hashes, checkout tokens, UTM values, form IDs, and order IDs are event data or occurrence inputs only; none is a customer identity.
- Every emitted item has a retry-stable `messageId`. A retry reuses the exact ID and payload.
- No persistent retry queue, new WordPress table, WP-Cron worker, or Action Scheduler dependency is introduced for this MVP.
- One bounded synchronous ingest attempt is allowed, followed by at most one retry for a lost response, route HTTP 503, or item outcome with `retryable: true`. The timeout per attempt is 1.5 seconds. Accepted, duplicate, dropped, rejected, HTTP 400, and HTTP 409 outcomes are never retried.
- A mixed batch retries only items whose result is `retryable: true`, preserving their original order and message IDs.
- Browser analytics consent remains enforced. Consent state contains no identity and grants no permission to collect anonymous history.
- Pre-email guest cart/page/form activity is not sent or queued. Simple cart abandonment starts only after customer identity is available.
- Each event category has exactly one authoritative producer according to the ownership table below.
- Preserve UTM/locale order metadata and existing transactional order/webhook behavior unless this plan explicitly changes its identity transport.
- Run PHP formatting/linting with the repository's PHPCS rules and TypeScript formatting/linting with its existing scripts.

## Frozen Identified-Ingest Contract

This is the complete backend contract needed by this repository. Do not inspect
or modify backend source to infer a different shape.

```ts
export type IdentifiedIngestItem =
  | {
      type: "identify";
      messageId: string;
      email: string;
      userId?: string;
      traits?: Record<string, unknown>;
      timestamp?: string;
      source?: string;
    }
  | {
      type: "track";
      messageId: string;
      email?: string;
      userId?: string;
      event: string;
      properties?: Record<string, unknown>;
      timestamp?: string;
      source?: string;
    }
  | {
      type: "page";
      messageId: string;
      email?: string;
      userId?: string;
      name?: string;
      properties?: Record<string, unknown>;
      timestamp?: string;
      source?: string;
    };

export interface IdentifiedBatchRequest {
  writeKey: string;
  batch: IdentifiedIngestItem[];
  consent?: { analytics: boolean; marketing: boolean };
}

export type IngestItemOutcome =
  | { status: "accepted"; eventId: string; duplicate: false; retryable: false }
  | { status: "duplicate"; reason: "duplicate_occurrence"; eventId: string; duplicate: true; retryable: false }
  | { status: "dropped"; reason: "profile_not_found" | "analytics_consent_revoked" | "identity_suppressed"; duplicate: false; retryable: false }
  | { status: "rejected"; reason: "identity_conflict" | "invalid_input"; duplicate: false; retryable: false }
  | { status: "failed"; reason: "event_write_failed" | "journey_enrollment_failed"; duplicate: false; retryable: true };

export type BatchItemOutcome = IngestItemOutcome & {
  index: number;
  messageId: string;
};

export interface IdentifiedBatchResponse {
  success: true;
  results: BatchItemOutcome[];
  summary: {
    accepted: number;
    duplicate: number;
    dropped: number;
    rejected: number;
    failed: number;
  };
}
```

Contract rules:

- `identify` requires top-level canonical `email`; `userId` is optional for
  email-only form submissions and guest checkout.
- `track` and `page` require at least one of top-level `email` or `userId` and
  never create a Profile. Email nested only in traits/properties is not an
  identity locator.
- Every item requires a retry-stable `messageId`. Batch items are processed in
  order, so accepted `identify` may establish the Profile for a later item.
- Browser/plugin input never contains `anonymousId`, `organizationId`,
  `sourceInstanceId`, `identityNamespace`, or `profileId`. Optional `source`
  is origin metadata only and never affects tenant or identity routing.
- WordPress users use `wp_<userId>`; WooCommerce customers use
  `wc_<customerId>`. The server derives `wordpress:<websiteId>` or
  `woocommerce:<websiteId>` from the authenticated WriteKey context.
- Mixed batches return HTTP 200 with one ordered result per item. Callers retry
  only `retryable: true` items and reuse their original `messageId`.
- Standalone identify/track use the same item outcome without `index` and use
  HTTP 200 for accepted/duplicate/dropped, 400 for invalid input, 409 for
  identity conflict, and 503 for retryable persistence failure.

Plugin transport rules:

- Send batch requests to `POST /api/v1/ingest/batch` with `writeKey` in the
  body, matching the current authenticated plugin transport.
- Batch items are processed in order. For registration, comments, forms, and
  checkout where an event follows identity establishment, send `identify`
  immediately before `track` in one batch.
- Email is always top-level. It may also remain in traits/properties as useful
  customer/event data, but nested email is never the routing locator.
- Optional `source` is metadata only: use `wordpress` for plain WordPress hook
  events and `woocommerce` for WooCommerce hook events. It never selects the
  Organization, Website, namespace, or Profile.
- Every item requires `messageId`. The plugin never generates or sends
  `occurrenceKey`; the backend derives that opaque value.
- A batch-wide network failure or HTTP 503 retries the unconfirmed items once.
  A successful mixed response retries only items marked retryable.
- HTTP 200 accepted/duplicate/dropped results are terminal. HTTP 400, HTTP 409,
  and non-retryable item results are terminal and observable through the
  client's structured return value.

## Message-ID Contract

Message IDs are opaque, contain no raw email/phone/session/token, and use the
prefix `sfc:v1:`.

| Occurrence | Message-ID input |
| --- | --- |
| WordPress registration identify | `wordpress:user_registered:identify:<userId>` |
| WordPress registration track | `wordpress:user_registered:track:<userId>` |
| WordPress login | a UUIDv7 generated once at hook entry and reused by the client retry |
| Comment identify/track | `wordpress:comment:<identify-or-track>:<commentId>` |
| CF7/Elementor submission | one UUIDv7 generated at confirmed submission entry; derive distinct identify/track IDs from it |
| WooCommerce checkout identity | `woocommerce:checkout:identify:<orderId>`; classic and Blocks hooks produce the same ID |
| Identified add/remove cart | one UUIDv7 generated at hook entry and reused by the client retry |

For deterministic rows, emit `sfc:v1:<event-kind>:<sha256(canonical-input)>`.
For request-only occurrences, emit `sfc:v1:<event-kind>:<uuidv7>`. Generate the
UUID before building the item, never inside the HTTP attempt loop.

## Event Ownership

| Category | Authoritative producer after this plan |
| --- | --- |
| Identified page/product/cart-page/checkout-started browser interactions | shared SDK WooCommerce Adapter in `segmentflow-ai`; this repository only provides page context |
| WordPress/WooCommerce registration and login | `Segmentflow_Server_Events` |
| WordPress comments | `Segmentflow_Server_Events` |
| CF7 and Elementor form submission | `Segmentflow_Server_Events` PHP hooks |
| Identified WooCommerce add/remove cart mutations | `Segmentflow_WC_Server_Events` |
| Checkout email/customer identity | `Segmentflow_WC_Server_Events` |
| Order/refund lifecycle and authoritative revenue facts | existing trusted webhook/API integration; not the browser and not an identified-ingest fixture invented here |

`src/storefront.ts` owns neither WooCommerce behavioral emission nor browser
form emission after this change. It may retain consent wiring, UTM capture, and
context/bootstrap responsibilities that do not create events.

---

### Task 1: Add the identified payload builder and bounded ingest client

**Files:**
- Create: `includes/class-segmentflow-ingest-event.php`
- Create: `includes/class-segmentflow-ingest-client.php`
- Create: `tests/test-ingest-event.php`
- Create: `tests/test-ingest-client.php`
- Modify: `includes/class-segmentflow.php`
- Modify: `tests/bootstrap.php`

**Interfaces:**
- Consumes: canonical email, optional external user ID, event/trait/property data, consent, source metadata, and a caller-created message ID.
- Produces: `Segmentflow_Ingest_Event::identify(...)`, `Segmentflow_Ingest_Event::track(...)`, `Segmentflow_Ingest_Event::page(...)`, and `Segmentflow_Ingest_Client::send_batch(...)`. Builders return only the frozen item fields. The client returns a typed normalized result and owns at most one retry.

- [ ] **Step 1: Write payload-builder tests**

Assert identify rejects missing/invalid email; track/page reject when both email and user ID are absent; all items require non-empty message ID; email is top-level; optional traits/properties/source/timestamp are preserved; no builder accepts anonymous/tenant/source-instance/namespace/Profile fields; `wp_42` and `wc_42` remain external user IDs only; and generated payloads exactly match the frozen field names.

- [ ] **Step 2: Write ingest-client retry tests**

Mock `Segmentflow_API::request` and prove: accepted/duplicate/dropped/rejected items receive one attempt; a network failure or HTTP 503 gets one retry with byte-equivalent items/message IDs; a mixed response retries only retryable items; the retry preserves original order; the second failure is returned without a third attempt; the timeout is 1.5 seconds; and response parsing rejects a missing/duplicate/reordered `index` or mismatched `messageId` as a transport failure.

- [ ] **Step 3: Run focused tests and confirm failure**

Run: `pnpm test:php -- --filter 'Test_(Ingest_Event|Ingest_Client)'`

Expected: FAIL because the two classes do not exist.

- [ ] **Step 4: Implement the pure builders and client**

Keep WordPress hook inspection out of both classes. Normalize email before building. Use the existing `Segmentflow_API` only as the HTTP Adapter. Set `blocking => true` and `timeout => 1.5` for ingest requests. Parse the exact batch response, retry once under the global policy, and return terminal outcomes to the hook owner for structured logging/testing. Do not create a queue, cron task, table, transient backlog, or compatibility payload.

- [ ] **Step 5: Register the new classes**

Require the files from `includes/class-segmentflow.php`; construct one ingest client from the existing Options/API objects and inject it into server-event owners. Update the test bootstrap with the same explicit construction. Do not use a service locator or global singleton.

- [ ] **Step 6: Validate and commit the task**

Run: `pnpm test:php -- --filter 'Test_(Ingest_Event|Ingest_Client)' && pnpm lint:php`

Expected: focused tests and PHPCS pass.

Commit: `feat: add identified ingest contract client`

### Task 2: Remove anonymous identity state and simplify consent handling

**Files:**
- Delete: `includes/class-segmentflow-identity-cookie.php`
- Delete: `tests/test-identity-cookie.php`
- Modify: `includes/class-segmentflow.php`
- Modify: `includes/class-segmentflow-tracking.php`
- Modify: `includes/class-segmentflow-consent-cookie.php`
- Modify: `src/consent.ts`
- Modify: `src/global.d.ts`
- Modify: `src/__tests__/consent.test.ts`
- Modify: `tests/test-wc-locale.php`

**Interfaces:**
- Consumes: the existing `sf_consent` analytics/marketing decision only.
- Produces: consent state passed to the shared SDK and PHP request envelope without an identity cookie, anonymous ID, browse queue, or direct batch network path from `consent.ts`.

- [ ] **Step 1: Rewrite consent and bootstrap tests**

Assert plugin boot never creates or reads `sf_id`; absent consent creates no identity and queues nothing; analytics denial drops browser analytics and clears only analytics-owned state; granting consent changes permission but creates no identifier or historical flush; revocation emits no anonymous telemetry; `sf_utm` remains attribution metadata rather than identity; PHP bootstrap has no identity-cookie require/init hook; and locale tests do not manufacture anonymous identity.

- [ ] **Step 2: Run tests in the failing state**

Run: `pnpm test:ts -- src/__tests__/consent.test.ts && pnpm test:php -- --filter 'Test_(Consent_Cookie|WC_Locale)'`

Expected: FAIL while the gate queues events and PHP registers `Segmentflow_Identity_Cookie`.

- [ ] **Step 3: Delete the anonymous lifecycle**

Remove the identity-cookie class, `ensure_anonymous_id` hook, codec, UUID generation, cookie writes/migration, `decodeIdCookie`, `encodeIdCookie`, direct `postBatch`, queued event state, queue-flush telemetry, and `anonymousId` parameters. Do not retain an identified form of `sf_id`; hook data and shared SDK state replace it.

- [ ] **Step 4: Keep consent as permission only**

Reduce `ConsentGate` to reading/writing `sf_consent`, exposing analytics/marketing flags, and forwarding those flags to the shared SDK. With absent/denied analytics, drop browser analytics without queuing. Trusted PHP lifecycle/form/checkout hooks continue under their existing legal-purpose policy and attach the recorded consent snapshot when present.

- [ ] **Step 5: Run removal audit and focused validation**

Run:

```bash
rg -n 'Segmentflow_Identity_Cookie|sf_id|anonymousId|anonymous_id|identity cookie|identity-cookie|consent_queue_flushed' includes integrations src tests
pnpm test:ts -- src/__tests__/consent.test.ts
pnpm test:php -- --filter 'Test_(Consent_Cookie|WC_Locale)'
```

Expected: zero runtime/test hits for removed identity/queue concepts and all focused tests pass.

- [ ] **Step 6: Commit the task**

Commit: `refactor: remove anonymous plugin identity state`

### Task 3: Convert WordPress lifecycle, comment, and form hooks to identified intake

**Files:**
- Modify: `includes/class-segmentflow-server-events.php`
- Modify: `tests/test-server-events.php`
- Modify: `includes/class-segmentflow.php`

**Interfaces:**
- Consumes: `Segmentflow_Ingest_Event`, `Segmentflow_Ingest_Client`, hook-provided canonical email, persistent WordPress/WooCommerce user IDs, hook/domain occurrence IDs, and consent snapshots.
- Produces: identified registration, login, comment, CF7, and Elementor items. Every event is skipped when its hook provides neither canonical email nor persistent user ID; no cookie/session fallback exists.

- [ ] **Step 1: Rewrite WordPress server-event tests**

Cover registration as ordered identify→`user_registered` track; login identify; approved human comment as ordered identify→`comment_posted`; CF7 and Elementor confirmed submissions as ordered identify→`form_submission`; bot/spam/unconfirmed forms skipped; email-only form identity with no fake email-as-userId; WooCommerce-connected account IDs normalized to `wc_<id>` and plain WordPress IDs to `wp_<id>`; top-level email on every identify and email-resolved track; stable deterministic IDs for registration/comment; one generated base UUID reused across each form identify/track pair and any retry; no anonymous/source-instance/namespace/Profile fields; and exact consent/source metadata behavior.

- [ ] **Step 2: Run focused tests in the failing state**

Run: `pnpm test:php -- --filter Test_Server_Events`

Expected: FAIL while hooks resolve identity from `sf_id` and use private legacy builders.

- [ ] **Step 3: Replace identity resolution and payload construction**

Delete cookie merge/read/fallback logic and the private legacy builders. Resolve canonical email directly from `WP_User`, comment, CF7, or Elementor hook data. Select `wc_<id>` for WooCommerce-connected customer accounts and `wp_<id>` for plain WordPress. Never substitute email into `userId`. Call the shared builder with top-level email and send one ordered batch through the ingest client.

- [ ] **Step 4: Make occurrence IDs retry-stable**

Use the Message-ID Contract verbatim. Registration/comment identifiers derive from persistent row IDs. Login and form callbacks create their UUID once before building or sending. Both items in a form pair derive distinct IDs from the same base occurrence. The client's second HTTP attempt receives the same already-built items.

- [ ] **Step 5: Validate and commit the task**

Run: `pnpm test:php -- --filter Test_Server_Events && pnpm lint:php`

Expected: lifecycle/comment/form tests and PHPCS pass.

Commit: `refactor: send identified WordPress events`

### Task 4: Convert WooCommerce cart and checkout hooks to identified intake

**Files:**
- Modify: `integrations/woocommerce/class-segmentflow-wc-server-events.php`
- Modify: `tests/test-wc-server-events.php`
- Modify: `tests/test-wc-locale.php`
- Modify: `includes/class-segmentflow.php`

**Interfaces:**
- Consumes: the shared event builder/client, logged-in WooCommerce customer data, checkout billing email/customer ID, order ID, product/cart event data, consent, UTM, and locale metadata.
- Produces: identified add/remove cart track items and checkout identify items. Guest cart mutation before email is silently absent. Classic and Blocks checkout converge on one order-derived identity occurrence.

- [ ] **Step 1: Rewrite WooCommerce hook tests**

Assert logged-in add/remove cart sends top-level email plus `wc_<customerId>` and a per-callback stable message ID; guest cart before email sends nothing; cart/session/hash values are properties only; checkout requires valid billing email, may omit user ID for a guest, and uses `wc_<customerId>` for an account; classic and Blocks checkout produce the same `woocommerce:checkout:identify:<orderId>` occurrence and therefore deduplicate; consent snapshot is attached when present; UTM/locale stamping still works; no hook writes an identity cookie; and all batches satisfy the frozen contract.

- [ ] **Step 2: Run focused tests in the failing state**

Run: `pnpm test:php -- --filter 'Test_WC_(Server_Events|Locale)'`

Expected: FAIL while cart identity depends on `sf_id` and Blocks checkout lacks the shared identity path.

- [ ] **Step 3: Replace WooCommerce identity resolution**

For cart mutation, read the logged-in WooCommerce customer and canonical email; skip if absent. Generate the event UUID once at hook entry and build the identified track. For checkout, share one handler used by classic and Blocks hooks, normalize billing email/customer ID, build one identify item with the order-derived message ID, and rely on backend duplicate handling if both hooks fire.

- [ ] **Step 4: Preserve non-identity metadata**

Keep UTM and locale order stamping. A WooCommerce session ID may remain order metadata only if an existing trusted order consumer uses it; remove comments and behavior claiming it stitches identity. Cart hash, checkout token, order ID, and provider data never become `userId` or namespace input.

- [ ] **Step 5: Validate and commit the task**

Run: `pnpm test:php -- --filter 'Test_WC_(Server_Events|Locale)' && pnpm lint:php`

Expected: identified cart/checkout, metadata, deduplication, and PHPCS checks pass.

Commit: `refactor: send identified WooCommerce events`

### Task 5: Enforce singular browser/server event ownership

**Files:**
- Modify: `src/storefront.ts`
- Modify: `src/global.d.ts`
- Modify: `src/__tests__/consent.test.ts`
- Modify: `includes/class-segmentflow-tracking.php`
- Modify: affected PHP/TypeScript tests found by `rg 'product_viewed|cart_viewed|checkout_started|form_submission' src includes integrations tests`.

**Interfaces:**
- Consumes: shared SDK initialization/context and the Event Ownership table above.
- Produces: no browser form producer and no repository-local WooCommerce page/product/checkout-started producer. The plugin still exposes the page context consumed by the shared SDK WooCommerce Adapter.

- [ ] **Step 1: Add event-ownership tests**

Assert `src/storefront.ts` registers no CF7/Elementor emission listener and contains no `product_viewed`, `cart_viewed`, or `checkout_started` call; PHP form hooks remain registered exactly once; server cart mutation hooks remain exactly once; tracking bootstrap still loads the shared SDK and injects WooCommerce context; UTM capture remains non-event metadata; and an unknown visitor produces no event or deferred callback.

- [ ] **Step 2: Run focused tests in the failing state**

Run: `pnpm test:ts && pnpm test:php -- --filter 'Test_(Server_Events|Tracking)'`

Expected: FAIL while storefront and server form/page paths overlap.

- [ ] **Step 3: Remove overlapping producers**

Delete WooCommerce page/product/cart-page/checkout-started emission and browser CF7/Elementor emission from `src/storefront.ts`. Keep consent bootstrap, UTM capture, and context wiring only where still owned here. Do not recreate those events in another plugin file.

- [ ] **Step 4: Validate and commit the task**

Run: `pnpm test:ts && pnpm test:php -- --filter 'Test_(Server_Events|Tracking)' && pnpm build`

Expected: TypeScript/PHP ownership tests and the bundle build pass.

Commit: `refactor: enforce plugin event ownership`

### Task 6: Generate real contract fixtures and update current-state documentation

**Files:**
- Create: `scripts/generate-identified-ingest-fixture.php`
- Create: `tests/fixtures/identified-ingest-payloads.json`
- Create: `tests/test-identified-ingest-contract.php`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/multi-platform-ecommerce-integrations.plan.md`
- Modify: `docs/local-testing.md`

**Interfaces:**
- Consumes: the real `Segmentflow_Ingest_Event` builder and deterministic fixed fixture inputs.
- Produces: a committed JSON fixture accepted by the frozen contract and usable by the backend's black-box validator. Documentation describes identified-only behavior as current state and the identity cookie as removed history.

- [ ] **Step 1: Add the fixture generator**

Require the pure builder without booting WordPress. Generate fixed-timestamp examples for plain WordPress registration/login/comment/form, WooCommerce identified cart mutation, guest checkout identify, account checkout identify, and sequential identify→track batches. Use fixed persistent IDs or fixed UUIDv7 values so regeneration is byte-stable. Do not hand-author payload-shaped arrays outside the real builder.

- [ ] **Step 2: Add the local contract test**

Load the generated JSON and assert every item has `messageId`; identify has top-level email; track/page has email and/or user ID; user IDs use only `wp_`/`wc_`; batch order is preserved; no item/envelope contains `anonymousId`, `organizationId`, `sourceInstanceId`, `identityNamespace`, `profileId`, `occurrenceKey`, session identity, or cart-hash identity; and the fixture covers each supported server producer. Assert order/refund lifecycle is not fabricated as an identified-ingest payload.

- [ ] **Step 3: Generate and verify the fixture**

Run:

```bash
php scripts/generate-identified-ingest-fixture.php
pnpm test:php -- --filter Test_Identified_Ingest_Contract
git diff --exit-code -- tests/fixtures/identified-ingest-payloads.json
```

Expected: the generator is deterministic, the contract test passes, and immediate regeneration produces no diff.

- [ ] **Step 4: Update documentation**

Mark the former identity-cookie/anonymous queue architecture as removed. Describe identified-only events, pre-email dropping, stable message IDs, one event producer per category, bounded request retry, shared SDK page ownership, and the final local/cross-repository validation commands. Do not leave a current-state statement saying identity stitching or anonymous cart history is supported.

- [ ] **Step 5: Validate and commit the task**

Run: `pnpm test:php -- --filter Test_Identified_Ingest_Contract && pnpm format:check`

Expected: fixture, docs formatting, and focused tests pass.

Commit: `test: add identified ingest contract fixture`

### Task 7: Run final removal, behavior, and repository validation

**Files:**
- Modify only remaining files returned by the audits below when the hit describes live anonymous identity/event behavior.

**Interfaces:**
- Consumes: Tasks 1–6.
- Produces: an independently validated `segmentflow-connect` branch with no anonymous identity path and a real contract fixture ready for backend validation.

- [ ] **Step 1: Run removal and ownership audits**

Run:

```bash
rg -n 'Segmentflow_Identity_Cookie|sf_id|anonymousId|anonymous_id|identity cookie|identity-cookie|anonymous queue|consent_queue_flushed' includes integrations src tests CHANGELOG.md docs
rg -n 'product_viewed|cart_viewed|checkout_started|form_submission' src includes integrations tests
rg -n 'organizationId|sourceInstanceId|identityNamespace|profileId|occurrenceKey' includes integrations src tests/fixtures/identified-ingest-payloads.json
```

Expected: the first search has no live code/test hits and documentation mentions only explicit removal; the second search matches only the owners in the Event Ownership table and their tests; the third search has no producer/fixture fields and may mention forbidden names only in negative contract tests.

- [ ] **Step 2: Run targeted tests**

Run: `pnpm test:ts && pnpm test:php`

Expected: all Vitest and PHPUnit tests pass.

- [ ] **Step 3: Run final repository validation**

Run:

```bash
pnpm lint
pnpm test
pnpm build
pnpm format:check
```

Expected: ESLint, PHPCS, Vitest, PHPUnit, tsdown, and formatting checks pass.

- [ ] **Step 4: Prepare the Segmentflow Connect changeset**

Review the branch diff by concern and keep generated contract fixture changes with their generator/tests. This repository may be completed and reviewed independently. Do not deploy it until the coordinated integration gate below passes against the completed backend branch.

Commit: `refactor: complete identified-only plugin cutover`

---

## Locked Decisions—No Implementation-Time Questions

- This repository never creates or transmits anonymous identity.
- `sf_consent` remains permission state; `sf_id` is deleted completely.
- No unknown/pre-email activity is queued for later recovery.
- Canonical email is the top-level transport locator.
- WooCommerce account IDs use `wc_<id>`; plain WordPress account IDs use `wp_<id>`.
- Guest forms/checkout may identify by email without inventing a `userId`.
- Website namespace and tenant are derived by the backend from WriteKey context.
- Message IDs use `sfc:v1:` and contain no raw PII.
- The PHP client performs at most two bounded attempts and has no durable queue.
- Browser page/product/checkout-started events belong to the shared SDK.
- Browser form events are removed; PHP form hooks own them.
- Guest cart mutation before email is skipped.
- Classic and Blocks checkout share the same order-derived identity occurrence.
- Order/refund revenue events remain trusted webhook/API behavior, not browser/plugin identified-ingest inventions.
- There are no feature flags, compatibility shims, dual payloads, or dead identity code.

## Completion Checklist

- [ ] The repository has no `Segmentflow_Identity_Cookie`, `sf_id`, anonymous UUID, anonymous payload field, anonymous storage, or anonymous queue.
- [ ] Consent grants permission only and never creates identity or flushes history.
- [ ] Every identify item has top-level canonical email and retry-stable message ID.
- [ ] Every track/page item has email and/or normalized external user ID plus a retry-stable message ID.
- [ ] The plugin never sends tenant, source-instance, namespace, Profile, or occurrence-key fields.
- [ ] WordPress and WooCommerce external user IDs follow the locked prefixes.
- [ ] Lost-response/retryable retries reuse byte-equivalent items and message IDs; non-retryable outcomes are terminal.
- [ ] Registration, login, comments, forms, identified cart mutation, and checkout identity have one tested owner.
- [ ] Browser WooCommerce page/product/checkout-started and form duplicates are removed.
- [ ] Pre-email guest activity is absent rather than stitched later.
- [ ] UTM/locale/order metadata remains non-identity data and existing trusted order behavior is preserved.
- [ ] The real builder generates a deterministic contract fixture.
- [ ] Documentation no longer presents anonymous stitching as current architecture.
- [ ] `pnpm lint`, `pnpm test`, `pnpm build`, and `pnpm format:check` pass.

## Coordinated Integration Gate After Both Plans Complete

This is not a dependency for implementing or reviewing this repository. After
both independent branches are complete, run from the completed
`segmentflow-ai` worktree:

```bash
pnpm contract:identified-ingest -- /Users/olivernaaris/git-projects/segmentflow-connect
```

Expected: `tests/fixtures/identified-ingest-payloads.json` parses through the
final backend Zod schemas, retains item order and message IDs, and contains no
anonymous or server-owned routing fields. Then run each repository's final
validation commands on its own branch. Only after this gate passes may the two
coordinated hard-cutover branches be deployed.
