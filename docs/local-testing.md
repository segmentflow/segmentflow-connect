# Local Testing Guide

How to set up a local WordPress environment for developing and testing Segmentflow Connect.

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (must be running)
- Node.js 24+ and pnpm 10+
- PHP 8.1+ and Composer

## Quick start

```bash
# 1. Install dependencies
pnpm install
composer install

# 2. Build the admin JS
pnpm build

# 3. Install wp-env (one-time)
npm install -g @wordpress/env

# 4. Start WordPress + WooCommerce
wp-env start
```

Open http://localhost:8888/wp-admin and log in with `admin` / `password`.

Both Segmentflow Connect and WooCommerce are pre-installed and activated. The plugin directory is mounted live -- PHP and CSS changes appear immediately, no restart needed. For TypeScript changes, run `pnpm build` (or `pnpm build:watch` for auto-rebuild).

## Stopping and resetting

```bash
wp-env stop              # Stop containers (keeps data)
wp-env start             # Start again (data preserved)
wp-env clean all         # Reset database to fresh install
wp-env destroy           # Remove everything (containers + data)
```

## Running WP-CLI inside the environment

```bash
wp-env run cli wp option list --search=segmentflow_*
wp-env run cli wp plugin list
```

## Linting

```bash
pnpm lint                # ESLint + PHPCS
pnpm lint:fix            # Auto-fix
```

## Running tests

```bash
wp-env run tests-cli --env-cwd=wp-content/plugins/segmentflow-connect \
  vendor/bin/phpunit
```

## Generating translation files

```bash
wp i18n make-pot . languages/segmentflow-connect.pot
```

## Building the plugin ZIP

```bash
pnpm plugin:zip
```

## Manual testing checklist

### Activation and deactivation

1. Activate the plugin -- no PHP errors or warnings
2. Verify options exist: `wp-env run cli wp option list --search=segmentflow_*`
3. Deactivate -- options should be preserved
4. Delete the plugin -- all `segmentflow_*` options should be removed

### Admin UI (disconnected)

1. Go to the Segmentflow menu item in the sidebar
2. Only the "Connection" tab should be visible
3. Page shows "Connect to Segmentflow" button and a signup link
4. If WooCommerce is active, the description mentions order tracking and customer sync

### Connection flow

1. Click "Connect to Segmentflow" -- redirects to `app.segmentflow.ai`
2. Complete the auth flow in the Segmentflow dashboard
3. On return, the page should show:
   - Connected status with organization name
   - Truncated write key
   - Platform (WordPress or WooCommerce)
   - Tracking status badge
4. "Settings" tab should now be visible
5. If WooCommerce is active, "WooCommerce" tab should also appear

### Frontend tracking (disconnected)

1. Visit any frontend page
2. View page source -- no Segmentflow SDK script should be present

### Frontend tracking (connected)

1. Visit any frontend page
2. View page source -- look for `<!-- Segmentflow Connect v1.0.0 -->`
3. `cdn.segmentflow.ai/sdk.js` should be loaded async
4. If logged in, `segmentflow.identify()` should include user ID and email
5. Enable Debug Mode in settings, check browser console for SDK output

### WooCommerce enrichment (connected + WooCommerce active)

1. Visit a frontend page while logged in
2. View page source -- `integrationContext` variable should include:
   - `platform: "woocommerce"`
   - `traits.woocommerce_customer_id`
   - `context.currency`, `context.cart_hash`, `context.store_url`
3. Add items to cart and verify `cart_hash` updates

### Settings tab

1. Toggle Debug Mode on/off -- value persists after save
2. Toggle Require Consent on/off -- value persists
3. Change API Host -- value persists (restore default after)

### WooCommerce tab

1. Connected as WooCommerce: shows store URL, WC version, currency, tracking status
2. Connected as WordPress: shows notice suggesting reconnection

### Disconnect

1. Click Disconnect -- confirmation dialog appears
2. Confirm -- page reloads to disconnected state
3. SDK script no longer appears on frontend
4. "Settings" and "WooCommerce" tabs disappear

### Late WooCommerce activation

1. Connect the plugin without WooCommerce installed
2. Install and activate WooCommerce
3. A dismissible admin notice should appear suggesting a connection upgrade

### Consent mode

1. Enable "Require Consent" in settings
2. View page source -- SDK config should include `consentRequired: true`
3. SDK should not track until consent is granted
