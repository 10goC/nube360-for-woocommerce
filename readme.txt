=== Nube360 for WooCommerce ===
Contributors: nube360
Tags: woocommerce, ecommerce, sync, erp, nube360
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs the catalog (products, variations, attributes, categories, images) between WooCommerce and Nube360, and notifies Nube360 about orders and manual stock/price changes.

== Description ==

Nube360 is an ERP for Argentine businesses (invoicing, stock, purchasing,
collections, AFIP). This plugin gives Nube360 an HTTP endpoint to sync the
catalog of a self-hosted WordPress/WooCommerce store and reports sales.

**What it does:**

* Exposes a REST API (`/wp-json/nube360/v1/...`) so Nube360 can read and
  write products, variations, attributes, categories and images.
* Notifies Nube360 (a lightweight webhook carrying only the resource id)
  when a new order is created, or when a product's price/stock/data is
  edited by hand from the WooCommerce admin.
* Keeps the product (or family) name and description in sync in both
  directions.
* Does not reimplement anything about checkout, cart, payments or
  shipping — WooCommerce covers that natively.

**What it does NOT do:** this plugin is not a payment gateway or a
shipping plugin. If WooCommerce is not active, the plugin effectively
disables itself (it shows an admin notice) instead of failing.

== External services ==

This plugin connects to a Nube360 instance (the ERP) whose URL you enter
yourself in **Settings > Nube360**. It does not talk to any other server.

* **When:** when you save or test the connection settings (handshake), and
  afterwards whenever an order is created or a product/stock is edited by
  hand in WooCommerce.
* **What is sent:** the site URL and name and the REST API base (handshake);
  afterwards only an event name and a product/order id
  (`{"event": "...", "id": "..."}`). The detail is requested by Nube360
  through this plugin's authenticated REST API. Every request carries the
  API key as a Bearer token.
* **Service:** Nube360 — https://nube360plus.com

== Installation ==

1. Install and activate WooCommerce (required).
2. Upload the `nube360-for-woocommerce` folder to `wp-content/plugins/` (or install the
   zip from the WordPress admin).
3. Activate "Nube360 for WooCommerce" from the plugins list.
4. Go to **Settings > Nube360** and fill in:
   * The base URL of your Nube360 instance (e.g. `https://mydomain.com/nube360/myinstance`).
   * The 64-character API key Nube360 gave you (Nube360 dashboard >
     E-commerce > Integrations).
5. Save. The plugin will try to connect automatically (handshake) and will
   show you whether it ended up linked.

== Frequently Asked Questions ==

= Do I need to configure anything in WooCommerce besides this plugin? =

No. The plugin uses standard WooCommerce functions and objects
(`WC_Product_Simple`, `WC_Product_Variable`, global attributes, etc.), so
no additional configuration is needed.

= What happens if I deactivate WooCommerce? =

The plugin does not cause fatal errors: it detects that WooCommerce is not
active, shows an admin notice, and does not register REST routes or hooks
until WooCommerce is active again.

= Does the plugin send complete orders to Nube360? =

No. When something happens (new order, edited product, edited stock) the
plugin only notifies `{"event": "...", "id": "..."}`. Nube360 decides
whether it wants the full detail, and asks for it with a GET to this same
plugin.

= Are product photos loaded immediately? =

No: product creation responds right away and the photos are downloaded and
processed in the background with Action Scheduler (bundled with
WooCommerce), so a large export does not wait for WordPress to generate the
thumbnails. You can see the queue in **Settings > Nube360** or in
**WooCommerce > Status > Scheduled Actions** (group `nube360-for-woocommerce`). If a
download fails it is retried up to 3 times (waiting 1 and 2 minutes); if it
keeps failing it is marked as failed right there.

The queue advances with WP-Cron, which only runs when the site receives
visits. On a server a real cron is advisable (for example, every minute
`wp action-scheduler run --group=nube360-for-woocommerce`) and, if there is spare CPU,
several processes in parallel. To disable background processing (photos
are loaded in the same request) use the
`nube360_wc_images_in_background` filter returning `false`.

= Can I fill in the customer's tax id (DNI/CUIT) on orders? =

WooCommerce has no native field for it. If your checkout captures it in a
custom field, return it from the `nube360_wc_order_tax_id` filter and it
will be sent to Nube360 with the order.

== Changelog ==

= 1.0.0 =
* Initial release: linking handshake, catalog REST API
  (store/categories/products/orders), order/stock/product webhooks with an
  anti-loop guard, name/description sync of products and families, and
  background image processing.
