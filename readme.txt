=== Multilingual Store Abilities for WooCommerce ===
Contributors: mediaworkbench
Tags: woocommerce, multilingual, translation, mcp, ai
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only abilities that let AI agents audit translations, cross-language consistency and multi-currency prices in WooCommerce stores running WPML.

== Description ==

Multilingual stores drift: a product is translated into German but not French, a translated variation is missing so French customers cannot pick a size, a SKU was changed in one language only, a manually priced product has no CHF price, and nobody knows which of the 400 untranslated products to translate first.

This plugin answers those questions. It registers six **read-only** abilities with the WordPress Abilities API (WordPress 6.9+). Agents such as Claude can call them through the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/), or you can use the REST API or PHP.

**Abilities**

* `mlstore/languages-overview`: languages, currencies per language, which product fields stay in sync across translations, and catalog size. Meant as an agent's first call.
* `mlstore/translation-completeness`: products, categories, tags or attribute terms missing a translation, with coverage per language. Distinguishes missing, unpublished (draft), in progress (sent to translation) and outdated translations.
* `mlstore/cross-language-consistency`: translations whose SKU, stock, tax class, visibility, status or prices differ from the original. Each difference is a `sync_error` (the field should be synced) or a `deviation` (may be intentional).
* `mlstore/untranslated-variations`: why a translated variable product's variation selector breaks: missing variations, untranslated attribute terms, mismatched custom attribute options, values shoppers cannot select.
* `mlstore/currency-price-gaps`: products and variations with "set prices manually" that lack a price in a secondary currency (WooCommerce Multilingual multi-currency).
* `mlstore/translation-work-queue`: untranslated products ranked by sales from WooCommerce Analytics. Sales of all language versions count for the original.

**Example questions for an agent**

* "Which products are missing a French translation?"
* "Are there translations whose SKU, stock or visibility differ from the original?"
* "Why can't French customers select a size on this product?"
* "Which products have no manual price in CHF?"
* "Which untranslated products sell best, so I know what to translate first?"

**Safe by design**

* Strictly read-only: no ability writes anything. This is verified by tests that checksum every database table.
* Requires the `manage_woocommerce` capability (Shop Manager or Administrator), filterable per ability.
* No customer data in any output; sales appear only as per-product totals.
* Bounded output: paginated, at most 100 items per call.
* Store content (titles) is returned as plain text. Agents must treat it as data, never as instructions.
* Every call is logged to WooCommerce → Status → Logs (source `mlstore-abilities`) without raw input.
* The plugin sends no data anywhere and talks to no AI service itself.

**Requirements**

* WooCommerce 10.9 or newer
* WPML Multilingual CMS 5.0 or newer, with the setup wizard completed
* WooCommerce Multilingual & Multicurrency 5.6 or newer (optional; without it, multi-currency checks are unavailable and sync detection uses WPML settings only)
* MCP Adapter 0.7 or newer to connect AI agents (optional; the abilities also work through REST and PHP)

WPML and WooCommerce are trademarks of their respective owners. This plugin is not affiliated with or endorsed by them.

== Installation ==

1. Install and activate WooCommerce, WPML and (recommended) WooCommerce Multilingual & Multicurrency.
2. Install and activate this plugin. If a requirement is missing, an admin notice explains which one.
3. To connect an AI agent: install the MCP Adapter, create a dedicated user with the Shop Manager role and an application password, and point your MCP client at `https://your-store.example/wp-json/mcp/mcp-adapter-default-server`. Never connect with an administrator account.

== Frequently Asked Questions ==

= Does the plugin change my store? =

No. Every ability is read-only. The only thing written is one line per call in the WooCommerce log, which you can turn off with the `mlstore_audit_enabled` filter.

= Who can run the abilities? =

Users with `manage_woocommerce`. Change it per ability with the `mlstore_ability_capability` filter, e.g. to a custom capability for a dedicated agent role.

= Why is a translated product reported although it exists? =

Only published translations count as translated. Drafts, pending or private translations are reported as unpublished, translations sent to a translator as in progress, and trashed ones as missing.

= Why does the work queue say Analytics is missing orders? =

WooCommerce imports orders into Analytics in batches, so the newest orders can be missing for a while. The ranking reports how many (`analytics_missing_orders`) instead of silently ignoring them.

= Does it support Polylang? =

Not yet. The plugin talks to WPML through a single gateway, so other multilingual plugins can be added later.

== Changelog ==

= 0.1.0 =
* First release: six read-only abilities for WPML + WooCommerce stores.
