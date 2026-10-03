# AGENTS.md

Guidance for working on this plugin, for AI coding agents and human contributors alike.

## What this is

**Nube360 for WooCommerce** connects a WooCommerce store to a [Nube360](https://nube360plus.com)
instance (an ERP). Nube360 calls this plugin's REST API (`/wp-json/nube360/v1/...`) to read and
write the catalog and to read orders; the plugin calls Nube360 back with lightweight webhooks
(`POST {nube360_url}/ecommerce/notifications/central`, body `{event, id, variant_id?}`) and Nube360
asks again with a GET when it needs the detail. Authentication is a Bearer key in both directions.

The plugin is open source and meant for WordPress.org. **Everything in it is in English** (code,
comments, identifiers, UI strings, API messages, readme). Spanish exists only as translations in
`languages/`.

## Layout

```
nube360-for-woocommerce.php   Bootstrap: namespace, constants, Plugin class, HPOS declaration, hooks
includes/                     One class per file, PSR-4: Nube360\WooCommerce\Products -> includes/Products.php
  RestController.php          Routes + the "no echo" guard for requests that modify data
  Products.php                WooCommerce <-> Nube360 product/variation mapping and CRUD
  Webhooks.php                Outgoing events and the anti-loop guard (suppress/resume)
  Images.php                  Background image download (Action Scheduler)
  TaxId.php                   Tax ID field: registration form, My Account, user profile (user meta `nube360_wc_tax_id`)
  Attributes.php              Global attributes (pa_*), their values and groups: find-or-create, GET/PUT /attributes
  AttributeGroups.php         Taxonomy `nube360_wc_attr_group` (the groups) and its term meta (colour, image)
  Swatches.php                `nube360_swatch` attribute type + admin fields (colour picker, image) for groups and values
  AttributeFilter.php         Storefront filter (shortcode, block, widget): swatch palette or list, by value or by group
  AttributeFilterWidget.php   Classic widget of the filter
  Orders.php, Categories.php, Auth.php, HttpClient.php, Admin.php
languages/                    .pot + es_ES/es_AR .po/.mo
tests/unit, tests/integration
```

## Conventions

- Namespace `Nube360\WooCommerce`. Constants are namespaced too (`VERSION`, `DIR`, `URL`, `FILE`,
  `BASENAME`, `REST_NAMESPACE`, `API_BASE`) and need no prefix.
- Classes are autoloaded by a small PSR-4 autoloader in the main file (no Composer at runtime: the
  release has no `vendor/`). A class lives in `includes/<ClassName>.php`, with exactly the same name,
  case included (Linux is case sensitive, macOS is not). Adding a class needs no `require`.
- Everything that is global to WordPress keeps a prefix: options `nube360_url` / `nube360_api_key`
  (the connection, shared by any future Nube360 plugin) and `nube360_wc_*` (specific to this
  plugin: `_connected`, `_last_handshake`), hooks and filters `nube360_wc_*`, product meta
  `_nube360_wc_family_ref`, Action Scheduler hook `nube360_wc_assign_images` (group
  `nube360-for-woocommerce`), CSS `nube360-wc-*`, JS `nube360WcAdmin`, WP_Error codes `nube360_wc_*`.
- Text domain = plugin slug = folder name = `nube360-for-woocommerce`. Strings are English source
  strings wrapped in `__()`; never build them by concatenation.
- The REST contract (routes, JSON keys) is shared with Nube360. Changing it means changing both
  sides. Keys are English: `title`, `description`, `price`, `sale_price`, `variant_id`,
  `family_ref`, `is_family`, `family_title`, `family_description`, `family_attributes`, `results`,
  `customer.tax_id`...
- **No echo to Nube360**: any change that originates from a request to this API must not fire an
  outgoing webhook. `RestController::suppress_webhooks_if_modifying()` turns the guard on for every
  non-GET request until `shutdown`, because WooCommerce syncs the parent of a variable product
  deferred to `shutdown`. Code that changes products outside a REST request (the image job) must call
  `Webhooks::suppress_until_shutdown()` too.
- Attribute values have a group (`group` in the `values` pairs of a variant and in `/attributes`). Nube360
  identifies a value by attribute + group + title, so the same title can exist in two groups: each pair is a
  different term. The slug only has to be unique (variations reference it): `Attributes::slug_for()` gives the
  plain slug of the title to the first value that asks for it, `title-group` to the next one, then a number; a
  value is identified by title + group (never by slug) and variations always use the slug of the resolved
  term, never `sanitize_title(value)`. A group is a term of `nube360_wc_attr_group` with meta
  `nube360_wc_attribute` (the `pa_*` taxonomy); a value points to it with term meta `_nube360_wc_group_id`. The
  swatch (colour / image) of a group lives only in WordPress; Nube360 never sends it.
  Changes made by hand in WordPress go back to Nube360 by term id, like brands and categories: the event
  `attribute_value.updated` (`{event, id}`) is sent for a value whose group changed and, when a group is renamed or
  deleted, for each of its values; Nube360 reads `GET /attributes/values/{id}` (`{id, attribute, value, group}`) and
  keeps the id in `atributos_valores.id_ecommerce`. A group is found by name within its attribute, never by slug
  (renaming it in WordPress does not change its slug).
  The other way round, a value whose group changed in Nube360 is moved with `PUT /attributes`: an item with the `id`
  of its term, or with the `previous_group` it had, moves that same term (same slug, same products) instead of
  creating a new one; a move that would duplicate a value in the new group is skipped and listed in `conflicts`. The storefront filter does
  not query products: its links use WooCommerce's layered-navigation parameters (`filter_{attr}=a,b` +
  `query_type_{attr}=or`) and a group link just lists all the slugs of its values.
- Creating products is idempotent by SKU; a family is found by `family_ref` (meta on the parent).
- Only use WooCommerce CRUD objects (`wc_get_product()`, `wc_get_order()`): the plugin declares HPOS
  compatibility and must not touch `posts`/`postmeta` for orders.
- Release hygiene is enforced by tests (`PluginMetadataTest`, `I18nTest`): versions agree, WooCommerce
  is declared in `Requires Plugins`, `.distignore` excludes development files, every string is translated.

## Tests

```bash
composer install
composer test:unit          # fast, no WordPress, no database (PHPUnit + Brain Monkey + Mockery)
composer test:integration   # real WordPress + WooCommerce (wp-phpunit)
composer test               # both
```

**The integration suite drops and recreates every table of the database it points to.** It uses a
dedicated database, `nube360_wc_tests` (table prefix `wptests_`), and `wp-tests-config.php`
(copied from the versioned `.dist`) refuses to run against any database whose name does not contain `test`. Never point it at a
development or production database.

Set up once:

```bash
mysql -u root -e "CREATE DATABASE nube360_wc_tests CHARACTER SET utf8mb4"
cp tests/integration/wp-tests-config.php.dist tests/integration/wp-tests-config.php   # ignored by git
```

Configuration is by environment variables (all optional): `NUBE360_WC_TESTS_DB_NAME`,
`NUBE360_WC_TESTS_DB_USER` (default `root`), `NUBE360_WC_TESTS_DB_PASSWORD` (default empty),
`NUBE360_WC_TESTS_DB_HOST` (default `localhost`), `WP_CORE_DIR` (default: the WordPress this plugin is
installed in, used read-only) and `WC_PLUGIN_FILE` (default: the WooCommerce next to this plugin).

Conventions:

- Unit tests extend `tests/unit/TestCase.php` (Brain Monkey + Mockery; WordPress/WooCommerce classes
  are minimal stubs in `tests/unit/stubs.php`). Use them for logic that does not need WooCommerce.
- Integration tests extend `tests/integration/TestCase.php`: `$this->api()` calls the REST API
  authenticated as Nube360 would, `$this->sent_events()` returns what the plugin sent to the (fake)
  ERP, and every call to the ERP host is intercepted, so no test touches the network. The database
  rolls back between tests; static state the plugin keeps is reset in `set_up()`/`tear_down()`.
- When you fix a bug, add the test that would have caught it (see the regression tests for the lost
  attribute values and the percent-encoded SKU).

Not covered by tests: `Admin::process_form()` (it redirects and exits), the real Action Scheduler
queue runner, and actual image processing with a non-GD editor.

## Translations

After adding or changing a string: regenerate `languages/nube360-for-woocommerce.pot`
(`wp i18n make-pot . languages/nube360-for-woocommerce.pot`), update the `.po` files
(`msgmerge -U`), translate the new strings and recompile (`msgfmt -o x.mo x.po`). `I18nTest` fails
while the template, the `.po` files and the `.mo` files are out of step.

## Repository

No remote yet. Do not commit unless asked. `.distignore` lists what stays out of the release zip.
