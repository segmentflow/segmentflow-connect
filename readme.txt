=== Segmentflow for WooCommerce ===
Contributors: segmentflow
Tags: email marketing, segmentation, woocommerce, analytics, customer data
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Segmentflow for AI-powered email marketing and customer segmentation.

== Description ==

Segmentflow for WooCommerce connects your store to [Segmentflow](https://segmentflow.ai), enabling:

* **Talk to your data** -- Create customer segments using natural language ("Customers who bought twice but haven't opened an email in a month")
* **Brand-aware creative** -- Automatically pull your logos, product photos, and color palettes to generate ready-to-send email templates
* **One-click campaigns** -- Launch email campaigns with real-time delivery stats
* **Revenue attribution** -- Track the exact dollar amount every campaign generates

= How It Works =

1. Install and activate this plugin
2. Click "Connect to Segmentflow" in WooCommerce > Settings > Segmentflow
3. Approve the connection in your Segmentflow dashboard
4. Your customer data, orders, and products sync automatically
5. Real-time webhooks keep everything up to date

= Features =

* **Automatic data sync** -- Customers, orders, and products sync to Segmentflow
* **Real-time webhooks** -- New orders, customer updates, and product changes are sent instantly
* **Storefront tracking** -- Page views and customer identity are captured via the Segmentflow SDK
* **Pre-built segments** -- 11 ready-to-use customer segments (Repeat Customers, Churning Customers, etc.)
* **Zero configuration** -- Connect once, everything works automatically

= Requirements =

* WordPress 5.8 or later
* WooCommerce 5.0 or later
* PHP 8.2 or later
* A [Segmentflow](https://segmentflow.ai) account

== Installation ==

1. Upload `segmentflow-woocommerce` to the `/wp-content/plugins/` directory, or install directly through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce > Settings > Segmentflow
4. Click "Connect to Segmentflow" and follow the prompts

== Frequently Asked Questions ==

= Do I need a Segmentflow account? =

Yes. Sign up for free at [segmentflow.ai](https://segmentflow.ai).

= What data is synced? =

Customer profiles (name, email, address), order history (items, totals, status), and product catalog (name, images, prices). No sensitive payment information is transmitted.

= Does this plugin slow down my store? =

No. The tracking SDK is loaded asynchronously from a CDN and does not block page rendering. Webhook processing happens server-to-server.

= Is this plugin GDPR compliant? =

The plugin includes a "Require Consent" setting that prevents tracking until the user has given consent. You can integrate this with your existing cookie consent solution.

== Changelog ==

= 1.0.0 =
* Initial release
* WooCommerce store connection via auto-auth
* Customer, order, and product data sync
* Real-time webhook processing
* Storefront tracking via CDN SDK
* Settings page under WooCommerce > Settings > Segmentflow

== Upgrade Notice ==

= 1.0.0 =
Initial release of Segmentflow for WooCommerce.
