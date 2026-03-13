=== Segmentflow Connect ===
Contributors: olivernaaris
Tags: email marketing, analytics, segmentation, woocommerce, tracking
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.1
x-release-please-start-version: .
Stable tag: 1.2.0
x-release-please-end: .
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress website or WooCommerce store to Segmentflow for AI-powered email marketing, customer segmentation, and revenue attribution.

== Description ==

Segmentflow Connect links your WordPress site to [Segmentflow](https://segmentflow.ai), enabling:

* **Talk to your data** -- Create customer segments using natural language ("Customers who bought twice but haven't opened an email in a month")
* **Brand-aware creative** -- Automatically pull your logos, product photos, and color palettes to generate ready-to-send email templates
* **One-click campaigns** -- Launch email campaigns with real-time delivery stats
* **Revenue attribution** -- Track the exact dollar amount every campaign generates

= Works on Any WordPress Site =

Segmentflow Connect works on **any WordPress site** -- WooCommerce is optional. On a plain WordPress site, you get:

* Page view tracking
* Automatic visitor identification for logged-in users
* Browser context and referrer tracking

= WooCommerce Enrichment (Optional) =

When WooCommerce is active, Segmentflow Connect automatically enables additional features:

* **Automatic data sync** -- Customers, orders, and products sync to Segmentflow
* **Real-time webhooks** -- New orders, customer updates, and product changes are sent instantly
* **Cart context** -- Cart hash, currency, and WooCommerce customer ID enrichment
* **Pre-built segments** -- 11 ready-to-use customer segments (Repeat Customers, Churning Customers, etc.)
* **Revenue attribution** -- Track which email campaigns drive revenue

= How It Works =

1. Install and activate this plugin
2. Click "Connect to Segmentflow" in the Segmentflow settings page
3. Approve the connection in your Segmentflow dashboard
4. Tracking begins immediately
5. If WooCommerce is active, customer data, orders, and products sync automatically

= Requirements =

* WordPress 5.8 or later
* PHP 8.1 or later
* A [Segmentflow](https://segmentflow.ai) account
* WooCommerce 5.0 or later (optional -- for e-commerce features)

== Installation ==

1. Upload `segmentflow-connect` to the `/wp-content/plugins/` directory, or install directly through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to the Segmentflow settings page in the admin menu
4. Click "Connect to Segmentflow" and follow the prompts

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Segmentflow Connect works on any WordPress site. WooCommerce features activate automatically when WooCommerce is installed.

= Do I need a Segmentflow account? =

Yes. Sign up for free at [segmentflow.ai](https://segmentflow.ai).

= What data is synced? =

On plain WordPress: page views and logged-in user identity. With WooCommerce: customer profiles (name, email, address), order history (items, totals, status), and product catalog (name, images, prices). No sensitive payment information is transmitted.

= Does this plugin slow down my site? =

No. The tracking SDK is loaded asynchronously from a CDN and does not block page rendering. Webhook processing happens server-to-server.

= Is this plugin GDPR compliant? =

The plugin includes a "Require Consent" setting that prevents tracking until the user has given consent. You can integrate this with your existing cookie consent solution.

= What happens if I install WooCommerce after this plugin? =

The plugin detects WooCommerce automatically and offers to upgrade your connection for full e-commerce features. No reinstallation needed.

== Third-Party Services ==

This plugin connects to [Segmentflow](https://segmentflow.ai), a third-party
email marketing and customer data platform. A Segmentflow account is required.

= Segmentflow Tracking SDK =

When the plugin is connected, a JavaScript tracking SDK is loaded from
Segmentflow's CDN (https://cdn.cloud.segmentflow.ai/sdk.js) on all frontend pages.
This SDK collects page view events and, for logged-in users, sends their
WordPress user ID and email address to Segmentflow for visitor identification.

When WooCommerce is active, additional data is included: WooCommerce customer ID,
cart hash, and store currency.

The "Require Consent" setting can be enabled to prevent tracking until the
visitor has given consent via your cookie consent solution.

= Segmentflow API =

The plugin communicates with the Segmentflow API (https://api.cloud.segmentflow.ai) to:

* Check connection status during the initial setup flow
* Notify Segmentflow when the plugin is disconnected or uninstalled

= Segmentflow Dashboard =

During the connection flow, the user is redirected to the Segmentflow dashboard
(https://dashboard.segmentflow.ai) to authorize the connection. When WooCommerce is
active, this flow includes granting Segmentflow read/write API access to your
store's customers, orders, and products via WooCommerce's built-in authorization
screen.

= Links =

* [GitHub Repository](https://github.com/segmentflow/segmentflow-connect)
* [Segmentflow Terms of Service](https://segmentflow.ai/terms)
* [Segmentflow Privacy Policy](https://segmentflow.ai/privacy)

== Screenshots ==

1. Connection page -- Connect your WordPress site to Segmentflow with one click
2. Connected state -- Organization name, platform, write key, and tracking status
3. Settings -- Debug mode, consent required, API host override
4. WooCommerce integration -- Store URL, WC version, currency, and enrichment status

== Changelog ==

= 1.0.6 =
* Add dedicated Forms tab with site URL copy button, dashboard links, setup guide, and testing tip
* Remove Lead Magnets & Forms row from Connection tab (moved to Forms tab)

= 1.0.5 =
* Add Lead Magnets & Forms row to plugin connection page linking to the Segmentflow dashboard

= 1.0.4 =
* Fix PHPCS warnings: sanitize and unslash cookie input
* Fix missing version parameter on enqueued CDN script
* Prefix all global variables in admin template files

= 1.0.3 =
* Fix Stable tag format in readme.txt

= 1.0.2 =
* Fix Version header format for WordPress.org compatibility

= 1.0.1 =
* Use wp_enqueue_script() and wp_add_inline_script() for SDK injection instead of inline script tags
* Fix Plugin URI to valid public URL
* Add GitHub repository link for source code access

= 1.0.0 =
* Initial release
* Works on any WordPress site (page views + identify for logged-in users)
* Conditional WooCommerce enrichment (cart context, currency, customer ID)
* Connection flow from plugin settings or Segmentflow dashboard
* Top-level Segmentflow admin menu with dynamic tabs
* Settings: debug mode, consent required, API host override
* WooCommerce auto-auth connection flow

== Upgrade Notice ==

= 1.0.4 =
Fix WordPress PHPCS coding standards warnings for plugin review compliance.

= 1.0.3 =
Fix Stable tag format in readme.txt.

= 1.0.2 =
Fix Version header format for WordPress.org compatibility.

= 1.0.1 =
WordPress.org compliance fixes: script enqueue improvements and updated plugin metadata.

= 1.0.0 =
Initial release of Segmentflow Connect.
