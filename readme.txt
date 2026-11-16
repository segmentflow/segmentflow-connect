=== Segmentflow Connect ===
Contributors: olivernaaris
Tags: email marketing, analytics, segmentation, woocommerce, tracking
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 8.2
x-release-please-start-version: .
Stable tag: 2.6.0
x-release-please-end: .
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI email marketing for WooCommerce: Growth Copilot, segments, journeys, broadcasts, newsletters, lead magnets and an email builder.

== Description ==

Segmentflow Connect links your WordPress site or WooCommerce store to [Segmentflow](https://segmentflow.ai), AI email marketing for ecommerce. Your customers, orders, and products sync to Segmentflow, and you get:

* **Growth Copilot** -- Describe a goal in plain English, like "win back customers who haven't ordered in 60 days". Growth Copilot drafts the audience, the emails, and the journey or broadcast. Nothing is sent until you approve it.
* **Segments** -- 11 ready-to-use customer segments, such as Repeat Customers and Dormant Customers, that update as orders come in. Describe any other segment in plain English and the AI builds it.
* **Journeys** -- Automated welcome, abandoned cart, post-purchase, and win-back emails, with delays, splits, and A/B tests.
* **Broadcasts** -- One-off campaigns with delivery, open, and click stats, plus the orders and revenue each one brings in.
* **Newsletters** -- Recurring newsletters, with an edition for each language your customers speak.
* **Lead magnets** -- Popup, inline, and slide-in signup forms for your site. New subscribers join a segment and can get a lead magnet email or start a journey.
* **Email builder** -- Edit emails by hand, add products from your store, or ask Email AI to write or rewrite any part. Your brand kit (logo, colors, and fonts) is built from your website.

= Works on Any WordPress Site =

Segmentflow Connect works on **any WordPress site** -- WooCommerce is optional. On a plain WordPress site, you get:

* Page view tracking
* Automatic visitor identification for logged-in users
* Browser context and referrer tracking

= WooCommerce Enrichment (Optional) =

When WooCommerce is active, Segmentflow Connect automatically enables additional features:

* **Automatic data sync** -- Customers, orders, and products sync to Segmentflow
* **Real-time webhooks** -- New orders, customer updates, and product changes are sent instantly
* **Cart context** -- Cart hash, currency, WooCommerce customer ID, and checkout language (`locale`) enrichment
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
* PHP 8.2 or later
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

On plain WordPress: page views and logged-in user identity. With WooCommerce: customer profiles (name, email, address, language preference), order history (items, totals, status, checkout locale), and product catalog (name, images, prices). No sensitive payment information is transmitted.

= How is customer language tracked? =

Segmentflow stores checkout/browsing language as the `locale` profile property for segmentation and localized campaigns. This is separate from billing/shipping country (`country_code`).

The plugin resolves the active WordPress locale at checkout (`determine_locale()` → `get_user_locale()` → `get_locale()`), stamps it on the order as `_segmentflow_locale`, and exposes the same value to the storefront SDK as `context.locale` in `window.__segmentflow_integration_context`. Segmentflow normalizes values to BCP-47 style (for example `ja` → `ja-JP`). Language is never inferred from billing or shipping country.

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
Segmentflow's CDN (https://cdn.cloud.segmentflow.ai/v1/sdk.js) on all frontend pages.
This SDK collects page view events and, for logged-in users, sends their
WordPress user ID and email address to Segmentflow for visitor identification.

When WooCommerce is active, additional data is included: WooCommerce customer ID,
cart hash, store currency, and the resolved checkout/browse locale (`context.locale`).

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

1. Growth Copilot -- Describe a goal and get a whole campaign to review: the audience, the emails, and the journey
2. Segments -- A customer segment with its rules and live count, and Segment AI to build new ones in plain English
3. Journeys -- A welcome journey that tests two wait times against each other
4. Broadcasts -- Delivery, opens, clicks, and the orders and revenue a broadcast brought in
5. Newsletters -- One newsletter with an edition for each language
6. Lead magnets -- A signup form for your site that delivers a lead magnet to new subscribers
7. Email builder -- Edit by hand or with Email AI, with a live preview and products from your store
8. Connection page -- Connect your WordPress site to Segmentflow with one click
9. Connected state -- Organization name, platform, write key, and tracking status
10. Settings -- Debug mode, consent required, API host override

== Changelog ==

= 2.5.1 =
* Refresh the WordPress.org listing with a new description and feature screenshots

= 2.5.0 =
* Save the shopper's checkout language on each WooCommerce order and pass it to the storefront tracking script (`context.locale`), so you can segment and send campaigns by language
* Require PHP 8.2 or later, and declare compatibility with WordPress 7.1 and WooCommerce 11.2

= 2.4.0 =
* Add discount endpoints under `/wp-json/segmentflow/v1/discounts` so Segmentflow can create, look up, and delete WooCommerce percentage coupons, and list the orders that used them. Requests are signed with your webhook secret. On older plugin versions, Segmentflow shows a "plugin update required" message.

= 2.3.1 =
* Fix the Segmentflow icon overflowing the WordPress admin menu

= 2.3.0 =
* Add a cookie-consent gate: until a visitor consents, the plugin does not set the Segmentflow identity cookie and holds browsing events in the browser. The events are sent if the visitor accepts and dropped if they refuse. Orders, logins, and form submissions are still sent.

= 2.2.0 =
* Track `checkout_started`, `product_viewed`, and `cart_viewed` events on WooCommerce stores, which the abandoned cart segment needs

= 2.1.2 =
* Shorten the short description to fit the WordPress.org 150-character limit

= 2.1.1 =
* Update the logo and icons to the new Segmentflow brand

= 2.1.0 =
* Save first-touch UTM parameters on WooCommerce orders, so Segmentflow can attribute each order to the campaign that first brought the shopper

= 2.0.0 =
* Breaking change: Contact Form 7 and Elementor Pro form captures now send the `form_submission` event instead of `form_submitted`. Update any journeys, segments, or reports that use the old event name.

= 1.5.0 =
* Add the `[segmentflow_form]` shortcode for embedding a Segmentflow form inline

= 1.4.0 =
* Register WooCommerce webhooks from the plugin when you connect, and keep them registered
* Load the tracking SDK from the versioned `/v1/` CDN path

= 1.3.0 =
* Add the WooCommerce session ID to tracking and orders, to link anonymous browsing to purchases
* Stop tracking WooCommerce order notes as comments

= 1.2.0 =
* Add a setting to turn the WooCommerce integration on or off
* Stop sending an empty user ID for anonymous visitors

= 1.1.1 =
* Deploy only the plugin's runtime files to WordPress.org

= 1.1.0 =
* Add the WordPress.org directory icon, banner, and screenshots

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
