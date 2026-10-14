# API notes (verification results for §3 of the technical concept)

Research date: 2026-10-13. Every claim below was checked against source code, not docs from memory,
unless marked otherwise. Update this file whenever an assumption turns out wrong (§14.8).

## Versions checked

| Component | Version inspected | Source |
|---|---|---|
| WordPress core | 7.1.3 (current) | wordpress.org release zip, `wp-includes/abilities-api/*` |
| WooCommerce | 11.2.1 (current; `Requires at least: 7.0`) | wordpress.org plugin zip |
| MCP Adapter | 0.7.0 (released 2026-10-02, on wordpress.org) | plugin zip + `WordPress/mcp-adapter@7bdbaa5` |
| WooCommerce agent-skills | `woocommerce/agent-skills@89cc969` | `skills/abilities-api-implement` |
| WordPress agent-skills | `WordPress/agent-skills@55cd0f7` | `wp-abilities-api`, `-audit`, `-verify` |
| wc-mcp-ability demo | `@1c37114` (Sep 2025) | **stale**: predates WC 10.9 conventions, do not copy from it |
| WPML / WCML | 5.1.0 / 5.6.3 (zips present locally) | **not yet inspected**, see "Open questions" |

Legend: ✅ verified · ⚠️ concept was wrong or outdated · ❓ open, needs Patrick or the installed code

---

## 1. Ability registration (WordPress core)

✅ Hooks: categories on `wp_abilities_api_categories_init`, abilities on `wp_abilities_api_init`.
Registering outside these hooks → `_doing_it_wrong` + `null`. Both registries refuse to init before `init` fired.

✅ `wp_register_ability( string $name, array $args ): ?WP_Ability`. Args:
`label` (req), `description` (req), `category` (req, must already be registered),
`execute_callback` (req), `permission_callback` (req), `input_schema`, `output_schema`,
`meta`, `ability_class` (must extend `WP_Ability`).

✅ Name rule: `/^[a-z0-9-]+\/[a-z0-9-]+$/` → exactly **one** slash. `mlstore/translation-completeness` is valid.

✅ Category slug rule: `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`. `mlstore-multilingual-catalog` is valid
(but see §3 for the category decision).

✅ Execution pipeline (`WP_Ability::execute()`), in order:
1. `wp_ability_invoked` action (7.1+), raw input
2. `wp_pre_execute_ability` filter (7.1+), short-circuit
3. `normalize_input()`: applies **only the top-level** schema `default`, and only when input is `null`
4. `validate_input()`: `rest_validate_value_from_schema()` (JSON Schema draft-4 subset). Missing input schema + non-null input → `ability_missing_input_schema`
5. `check_permissions()`: any `WP_Error` from our callback is **swallowed** and replaced by the generic `ability_invalid_permissions` (only logged via `_doing_it_wrong`)
6. `wp_before_execute_ability` action
7. execute callback. A thrown `Throwable` becomes `ability_callback_exception` **with the exception message in it**
8. `validate_output()` against `output_schema`. **A schema mismatch returns `ability_invalid_output` to the caller**
9. `wp_after_execute_ability` action

Consequences for us:
- ⚠️ Property-level `default`s are **not** applied. `AbstractAbility` must apply defaults itself (the concept already plans this; it is mandatory, not optional).
- Every input schema gets a top-level `'default' => array()` so a zero-argument call validates. This matches what Woo core does (`ProductsQuery`). Our callback must treat input as an array (cast defensively).
- The callback receives an argument **only if** `input_schema` is non-empty. `languages-overview` (no input) either declares an empty object schema with a default, or has a callback with no required params. Decision: declare `{type: object, properties: {}, additionalProperties: false, default: []}` for uniformity.
- Never let exceptions escape to core (they would leak messages). `AbstractAbility` catches and maps them to stable `mlstore_*` codes.
- Output validation runs on every production call, so the "output validates against its own schema" tests (§10) are load-bearing.

✅ Annotations: `meta.annotations.{readonly, destructive, idempotent}`, each `bool|null`, default `null`.
MCP Adapter maps them to `readOnlyHint` / `destructiveHint` / `idempotentHint`.

✅ REST: `/wp-json/wp-abilities/v1/abilities/{name}/run`. **`readonly: true` abilities must be called with GET**
(input in the `input` query param). Updates use POST and destructive ones DELETE. Core coerces query-string scalars to schema types.

### Exposure flags

✅ `meta.show_in_rest` (bool, default false): REST exposure.
⚠️ `meta.public` (bool) exists **only since WP 7.1.0**. It seeds `show_in_rest` and MCP exposure.
On WP 6.9/7.0 it is stored as inert meta.

Decision: set all three explicitly on every v0.1 ability so it works on WP 6.9 → 7.1+:
```php
'meta' => array(
    'show_in_rest' => true,
    'public'       => true,                                  // WP 7.1+
    'mcp'          => array( 'public' => true, 'type' => 'tool' ),
    'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
),
```

### WP 7.1-only hooks (not usable as our only mechanism while we support 6.9)
`wp_ability_invoked`, `wp_pre_execute_ability`, `wp_ability_normalize_input`, `wp_ability_validate_input`,
`wp_ability_permission_result`, `wp_ability_execute_result`, `wp_ability_validate_output`, and filtering args for `wp_get_abilities()`.
→ Audit logging stays inside `AbstractAbility` as the concept plans; do not rely on `wp_ability_invoked`.

---

## 2. MCP Adapter 0.7.0

✅ Available on wordpress.org (`wp plugin install mcp-adapter`). §13.3 works as written.

✅ MCP exposure is resolved in `includes/Abilities/McpAbilityExposure.php`: an explicit `meta.mcp.public`
wins; otherwise `meta.public === true`. `meta.mcp` must be an array or the ability is rejected (`mcp_ability_invalid_meta`).

✅ The default server is at `/wp-json/mcp/mcp-adapter-default-server` (§13.8 is correct). It exposes **three meta-tools**
(`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`), not one tool per ability.
The agent discovers, inspects, then executes. The server itself requires a logged-in user with `read` (filterable via
`mcp_adapter_{discover_abilities,get_ability_info,execute_ability}_capability`). Our own permission callbacks still apply.

✅ Empty `{}` arguments for an ability with an input schema that has a top-level `default` → the default is applied
(`AbilityArgumentNormalizer`). So the decision in §1 works over MCP too.

✅ Protocol revisions `2025-11-25` and `2026-07-28`. Under 2025-11-25, `structuredContent` is dropped when a tool returns a JSON **list**.
→ Every ability returns a JSON **object** at the top level (already the plan).

⚠️ Bundling the MCP Adapter as a Composer library is deprecated since 0.7.0. We don't bundle it; it stays an optional plugin.

ℹ️ Optional later: a dedicated custom server (`mcp_adapter_init` → `create_server()`) would list our six abilities as direct tools.
Not in v0.1 scope; note it for the demo if the three-step discovery feels clumsy.

---

## 3. WooCommerce conventions (WC 10.9+ / 11.2.1)

✅ Woo's domain abilities (`src/Internal/Abilities/Domain/*`) use exactly the meta block in §1
(`show_in_rest`, `mcp.public`, `mcp.type: tool`, all three annotations) plus input schema `'default' => array()`,
`additionalProperties: false`, and `per_page` with `minimum: 1, maximum: 100`.

⚠️ **Output envelope differs from concept §5.4.** Woo's canonical list shape (`AbstractDomainAbility::get_collection_output_schema`,
skill ref `paginated-output-envelope.md`):
```json
{ "<collection_key>": [ ... ], "total_pages": 7, "page": 1, "per_page": 10 }
```
It is flat, with a named collection key (`products`, `orders`), no `items`, no nested `pagination` object, and no total count.
Woo's schema uses `additionalProperties: false`, but adding sibling keys to *our* schemas is fine.
**Decided (Patrick, 2026-10-13):** follow Woo's shape and add our extras as siblings:
```json
{ "products": [...], "page": 1, "per_page": 50, "total_pages": 7, "total_items": 312,
  "summary": { ... }, "meta": { "generated_at": "...", "default_language": "de", "languages": [...], "plugin_version": "0.1.0" } }
```
Summary-type abilities (`languages-overview`) return their natural object shape plus `meta`, without pagination fields.

⚠️ **Category.** The Woo extension playbook (`woo-extension-primitives.md` §5) says extensions use the shared
`woocommerce` category (registered by Woo core 10.9+). It calls a plugin-specific category an "antipattern",
and re-registering `woocommerce` triggers `_doing_it_wrong`.
Concept §5.1 wants our own `mlstore-multilingual-catalog`.
**Decided (Patrick, 2026-10-13):** use `woocommerce` and register no category of our own. This follows the ecosystem convention,
and the `mlstore/` namespace already groups our abilities.

⚠️ **Namespace.** The playbook says the namespace = the plugin slug. `mlstore/` deviates from `multilingual-store-abilities/`.
It is short and stable, and core does not enforce slug = namespace. **Proposal:** keep `mlstore/`, as the concept does. Flagged only for awareness.

⚠️ **Loader.** The playbook registers through Woo's loader: a static `AbilityDefinition` per class, appended via the
`woocommerce_ability_definition_classes` filter. That interface is all static methods, which fights our DI of gateways
and services and makes unit testing with fakes awkward.
**Decided (Patrick, 2026-10-13):** our own `Registrar` on the core hooks (`wp_abilities_api_init`), with instance-based ability classes, as in the concept.
This uses public core API only and needs no Woo `Internal\` classes. Woo itself also only calls `wp_register_ability()`.

⚠️ **`woocommerce_mcp_include_ability` (concept §7).** The signature is verified: `( bool $include, string $ability_id ): bool`, since 10.3.0.
**But** it only feeds the **deprecated** `/wp-json/woocommerce/mcp` endpoint, which is behind the `mcp_integration` feature flag
and authenticates with WC REST API keys. Woo docs: "deprecated and should not be used for new integrations".
The playbook says do **not** opt into it. **Decided:** dropped from v0.1. The default MCP Adapter server is the only target.

ℹ️ Woo's permission convention is a shared capability helper, not inline `current_user_can()` per ability.
This matches our `AbstractAbility` + `mlstore_ability_capability` filter.

ℹ️ Playbook budget: ~2,000 tokens for a plugin's ability surface in `tools/list`. It only matters on a custom server,
but keep descriptions tight.

### Analytics lookup table (for `translation-work-queue`)
✅ `{prefix}wc_order_product_lookup`: `order_item_id, order_id, product_id, variation_id, customer_id, date_created,
product_qty, product_net_revenue, product_gross_revenue, coupon_amount, tax_amount, shipping_amount, shipping_tax_amount`.
- `product_id` is the parent and `variation_id` is a separate column. Rank on `product_id`.
- The table has **no status column**. Join `{prefix}wc_order_stats.status` and exclude the statuses Woo Analytics excludes
  (`woocommerce_excluded_report_order_statuses` option + `woocommerce_analytics_excluded_order_statuses` filter, plus `auto-draft`/`trash`).
  Use Woo's own resolution if a public method exists; `DataStore::get_excluded_report_order_statuses()` is protected, so we may have to mirror it.
- **Never select `customer_id`** (PII).
- Values are in store base currency. Refunds appear as their own orders (negative values).
- The table is populated asynchronously (Action Scheduler) and can lag or be unsynced. The fallback plus `meta.data_source` (§6.6) stay.

---

## 4. Agent skills

✅ Both repos exist and contain the skills named in §3.4. Install paths:
- WordPress: `npx skills add WordPress/agent-skills --skill <name>` or copy `skills/<name>` into `.claude/skills/`.
- WooCommerce: `node shared/scripts/skillpack-build.mjs --clean && node shared/scripts/skillpack-install.mjs --dest=<repo> --targets=claude`.
Not installed yet; planned as the first step of milestone 1.

⚠️ `abilities-api-implement` is a **PR-driven playbook that hard-requires an audit doc from `wp-abilities-audit`**,
which audits an *existing REST surface*. We have none; this is a greenfield plugin. We use these skills as **references**
(conventions, gotchas, test-the-public-contract) and the `wp-abilities-verify` checks, not as an end-to-end runner.

⚠️ **Transient cache vs. "read-only".** `wp-abilities-verify` flags `set_transient` / `update_option` in a `readonly: true`
ability as FAIL unless suppressed with `// verify-ignore: readonly -- <reason>`. Concept §8 plans a 5-minute transient
for `languages-overview` and the sync settings. Without a persistent object cache, transients are rows in `wp_options`, i.e. DB writes.
That conflicts with the hard rule "no writes anywhere".
**Decided:** no transients in v0.1. Use the per-request `wp_cache_*` group / in-memory memoization only. Revisit only if measurements demand it.

ℹ️ Logging via `wc_get_logger()` (§5.5) writes log files or DB rows. That is observability, which the verify skill accepts with a
`verify-ignore` annotation. **Decided (Patrick, 2026-10-13):** audit logging is the one allowed exception to "no writes anywhere".

---

## 5. WPML / WCML (verified against installed WPML 5.1.0 / WCML 5.6.3, 2026-10-13)

### 5.0 ⚠️ WCML already registers ability *extensions*
`WCML\Abilities\Hooks` (WCML 5.6.3) uses core's `wp_register_ability_args` filter to add `lang` and `currency` input params
to `woocommerce/products-query` and `woocommerce/orders-query`. It also switches WPML language and WCML currency around execution
(`wp_before_execute_ability` / `wp_after_execute_ability`). It only touches the `woocommerce/` namespace, so it does not affect `mlstore/*`.
→ The concept's "no multilingual abilities exist" (§1) is outdated. More precisely: WCML lets Woo's generic queries read *one* language/currency,
but nothing audits *across* languages. That is still our gap, and a better pitch.

### 5.1 Detection ✅
- `ICL_SITEPRESS_VERSION` is defined at file load (`sitepress.php`). WPML defines `ICL_PLUGIN_INACTIVE = true` when it can't run.
- `WCML_VERSION` is defined at file load **unconditionally**. `$GLOBALS['woocommerce_wpml']` exists only if WCML's WPML version check passed.
- `RuntimeEnvironment` checks both (version constant + "really running" signal).
- WCML 5.6.3 itself requires WPML ≥ 5.0.0 and WC ≥ 3.9 (`WCML_Dependencies`).

### 5.2 Element types and translation data ✅
- `apply_filters( 'wpml_element_type', 'product' )` → `post_product`; `'product_cat'` → `tax_product_cat`.
  Seen in the DB: `post_product`, `post_product_variation`, `tax_product_cat`, `tax_pa_color`, `tax_pa_size` (so `tax_pa_{slug}`).
  `tax_product_tag` is presumably by the same rule; no rows yet.
- **Language codes are not always two letters**: this install uses `en-us`. Validate against active languages and never assume `^[a-z]{2}$`.
  `icl_translations.language_code` is `varchar(7)`.
- `{prefix}icl_translations`: `translation_id, element_type, element_id, trid, language_code, source_language_code`;
  UNIQUE `(trid, language_code)`, UNIQUE `(element_type, element_id)`, KEY `(element_id, element_type, language_code)`.
  The original has `source_language_code IS NULL`.
- `wpml_element_language_details` (args `['element_id', 'element_type']`) → `stdClass{element_id, trid, language_code, source_language_code}`.
- `wpml_element_trid( null, $id, $type )` → trid **as string**.
- `wpml_get_element_translations( null, $trid, $type )` → `array<lang, stdClass{trid, translation_id, language_code, element_id, source_language_code,
  element_type, original ("1"/"0"), post_title, post_status}>` (post types). All values are strings.
- `wpml_original_element_id( null, $id, $type )` → original ID as string.
- Batching: one query per page against `icl_translations` (`WHERE element_type = %s AND trid IN (...)`) is safe given those indexes.
  This lives in `WpmlGateway` only.

### 5.2a ⚠️ Hook quirks found by the store suite (milestone 3)
- **Front-end visibility policy.** Outside wp-admin (REST, MCP, front end; `is_admin()` false), `wpml_get_element_translations`
  filters rows by post status and the current user's capabilities (`WPML_Translations::applies_visibility_policy()`).
  It returned **an empty group for product variations**. Fix: pass `$all_statuses = true` (5th argument:
  `( null, $trid, $type, $skip_empty = false, $all_statuses = true )`). `wp eval` and wp-admin don't show the bug,
  which is why the store suite loads WordPress as a non-admin request.
- **`wpml_original_element_id`** goes through the same policy with no opt-out. The gateway derives the original from the
  all-statuses group instead (the row with empty `source_language_code`).
- **`wpml_element_trid` ignores the element type for posts:** `( 25, 'post_product' )` returns the trid of *variation* 25.
  The gateway therefore checks `element_type` on every returned row.
- For terms, `wpml_get_element_translations` rows also carry `term_id`, `name` and `instances`.

### 5.3 ⚠️ WPML filters queries by the *current language*
`WP_Query` / `wc_get_products()` only return current-language posts while WPML is active.
The gateway must make language explicit: either query originals via `icl_translations` and then load products by ID,
or switch language (`wpml_switch_language`) and restore it in `finally`. Never rely on the request's language.
(WCML's own ability hooks do the switch/restore dance, which confirms the issue.)

### 5.4 What is "synced" (for `cross-language-consistency`) ✅
Two mechanisms:
1. **WPML custom-field preferences**, stored in `icl_sitepress_settings['translation-management']['custom_fields_translation'][meta_key]`:
   `0` ignore, `1` copy, `2` translate, `3` copy-once (WPML constants `WPML_*_CUSTOM_FIELD`).
   WCML ships defaults in `wpml-config.xml`: **copy** for `_sku`, `_manage_stock`, `_stock_status`, `_tax_class`, `_tax_status`, `_regular_price`,
   `_sale_price`, `_price`, `_backorders`, `_weight`, ... and **ignore** for `_stock`, `_product_attributes`, `_wcml_custom_prices_status`.
   The admin can override these (read-only config vs custom settings).
2. **WCML code**, independent of the preferences: stock quantity (`Synchronization\Component\Stock`, even though `_stock` is "ignore"),
   stock status (`woocommerce_product_set_stock_status`), catalog visibility (`product_visibility` terms, `sync_product_translations_visibility`).

Proposed rule: a field is *synced* if its preference is `copy` (1), or WCML syncs it in code (stock quantity, stock status, catalog visibility).
`copy-once` (3) and `translate`/`ignore` → *not synced* → differences are `deviation`. `post_status` is never synced → `deviation`.

### 5.5 Multi-currency ✅ (data) / ❓ (semantics)
- `_wcml_settings['enable_multi_currency']`: `0` disabled, `2` enabled (`WCML_MULTI_CURRENCIES_INDEPENDENT`).
- `_wcml_settings['currency_options'][CUR]['languages'][lang] = 1|0`: currencies and the languages they're shown in.
  The store default currency is `woocommerce_currency` and has no custom prices.
- Manual pricing is **per product/variation, on the original**: post meta `_wcml_custom_prices_status` (truthy = manual).
  WCML always reads it from the *original* (`get_original_product_id`).
- Per-currency values on the original: `_regular_price_{CUR}`, `_sale_price_{CUR}`, `_price_{CUR}`,
  plus `_sale_price_dates_from_{CUR}` / `_sale_price_dates_to_{CUR}`, `_wcml_schedule_{CUR}`.
- WCML uses custom prices only if `_price_{CUR}` is set; otherwise it falls back to exchange-rate conversion.
- ⚠️ **`WCML_Custom_Prices::get_product_custom_prices()` writes post meta** (`update_post_meta( _price_{CUR} )` for sale-date recalculation).
  The gateway must **never** call it. Read raw meta with `get_post_meta()` instead (read-only rule).

### 5.6 Attributes and variations ✅
- Global attributes: terms in `pa_*` taxonomies, translated as `tax_pa_{slug}`. `_wcml_settings['attributes_settings'][pa_x] = "1"` = translatable.
- Local attributes: each translation's own `_product_attributes` meta (key = sanitized name, `is_taxonomy = 0`, `value` = translated "a | b | c").
  Labels in post meta `attr_label_translations[lang][key]` on the translation.
- Variations: their own `post_product_variation` rows in `icl_translations`, same trid logic.
- Order items: WCML's `OrderItems\LineItem\{Product,Variation}` can switch an item's `product_id` to the translated product.
  `wc_order_product_lookup` may therefore hold either ID. Normalizing with `original_id()` covers both.

### 5.7 Languages ✅
- `wpml_active_languages( null, ['skip_missing' => 0, 'orderby' => 'code'] )` → `array<code, array{code, id, native_name, major, active,
  default_locale, encode_url, tag, country, display_code, translated_name, url, country_flag_url, language_code}>`.
- ⚠️ In that array **`active` means "is the current language"**, not "enabled". Every returned language is enabled. Never expose WPML's `active` as-is.
- `translated_name` is in the *current admin* language. English name: `apply_filters( 'wpml_translated_language_name', null, $code, 'en' )` → "German".
- `wpml_default_language` → e.g. `en-us`. `wpml_setting( null, 'setup_complete' )` → `1` when the wizard is finished.

### 5.8 Terms ⚠️
For `tax_*` element types, `icl_translations.element_id` is the **`term_taxonomy_id`**, not the `term_id`
(`WPML_Term_Translation::adjust_ttid_for_term_id()`). On this install both IDs happen to be equal for every term, so tests here would
not catch a mix-up. The gateway exposes `term_id`s and converts in its SQL (join `term_taxonomy`).

### 5.9 Decisions (Patrick, 2026-10-13)
1. **Currency gap:** a product/variation with manual pricing on is missing currency X when `_regular_price_{X}` on the original is empty or absent.
2. **WPML setup unfinished** (`setup_complete` falsy) → blocking dependency problem with an admin notice.
3. **Minimum versions:** WPML ≥ 5.0.0, WCML ≥ 5.6.0.
4. **WPML integration tests** run as a separate read-only PHPUnit suite (`store`) against the DDEV dev DB (the `wpml-baseline` snapshot),
   loading `wp-load.php` without the WP test framework. CI runs only the no-WPML suite.

### 5.10 Still open
- **Untranslated local attribute (§6.3), decided 2026-10-14 (Patrick: "go ahead"):** replace the single `local_attribute_untranslated` issue with
  `local_attribute_missing` (error), `local_attribute_options_mismatch` (error), `variation_attribute_value_invalid` (error),
  `local_attribute_identical` (review), `local_attribute_label_untranslated` (review). Before implementing `variation_attribute_value_invalid`,
  verify in WooCommerce how variations match local attribute values (exact text vs sanitized slug).
- **Store setup vs. §13.4:** the DDEV store currently has default `en-us` + `de` (no FR), only EUR (no CHF/USD), and no translated products.
  The store-suite tests and the seeded test cases need the final setup and the `wpml-baseline` snapshot.

## 6. Environment (resolved 2026-10-13)

- The DDEV project `mlstore-dev` lives at `~/www/multilingual-store-abilities` (Patrick's choice; it deviates from
  §13's `~/dev/mlstore-dev`). The project root holds WordPress core and is not a git repo. The git repo is
  `wp-content/plugins/multilingual-store-abilities/`. Container paths match §13 exactly.
- The URL is `https://mlstore-dev.ddev.site:33000`, not the §13.8 URL without a port, because the DDEV router fell back
  from 443. The MCP endpoint is therefore `https://mlstore-dev.ddev.site:33000/wp-json/mcp/mcp-adapter-default-server`.
- `_licensed/` sits in the project root, outside the repo, matching §13.1.
- Installed: WP 7.1.3, WooCommerce 11.2.1, MCP Adapter 0.7.0, WooCommerce sample data (18 products, 7 variations).
  The web server is nginx-fpm, so the `.htaccess` warning from `wp rewrite` is harmless.
- ⚠️ HPOS was **off** after the CLI install (concept §13.3 assumed it is on by default). Enabled with `ddev wp wc hpos enable`;
  compatibility sync is off.
- ℹ️ WooCommerce 11.2.1 requires WP ≥ 7.0. Our header can keep `Requires at least: 6.9` (for WC 10.9/11.0 users),
  but the CI "latest" matrix runs WP 7.1 + WC 11.2.
- ℹ️ zsh gotcha: `ddev wp config set $var` does not word-split in zsh. Pass the arguments literally.

## 7. Tooling findings (milestone 1)

- ⚠️ **PHPUnit 9.6, not 10 (concept §10).** The WP 7.1 test suite requires `yoast/phpunit-polyfills ^1.1`, which supports PHPUnit ≤ 9.
  Both suites use 9.6 so there is one toolchain. Two config files: `phpunit.xml.dist` (unit) and `phpunit-integration.xml.dist`.
- ⚠️ `bin/install-wp-tests.sh` (wp-cli scaffold) needs `svn`, which neither the DDEV web container nor GitHub runners have.
  It is modified to fetch `tests/phpunit/{includes,data}` from the `WordPress/wordpress-develop` GitHub mirror (sparse git clone).
- ℹ️ `FeaturesUtil::get_compatible_plugins_for_feature()` only lists *activated* plugins. The WP test bootstrap loads plugins
  without activating them, so the integration test asserts the hook wiring. The registry was checked on the DDEV site:
  `multilingual-store-abilities/multilingual-store-abilities.php` is listed as HPOS-compatible.
- ℹ️ WPCS's `Squiz.Commenting.FunctionComment.IncorrectTypeHint` cannot parse PHPStan generics, so it is disabled; PHPStan level 8 covers types.
- ℹ️ Stubs: `php-stubs/wordpress-stubs` 7.1.2 (includes the Abilities API), `woocommerce-stubs` 10.9.4, `wordpress-tests-stubs` 7.1.0.

## 8. Ability plumbing (milestone 4)

- ✅ Verified against WP 7.1.3 core through the public boundary (`tests/Integration/AbilityContractTest.php`, test-only `ProbeAbility`):
  registration into the `woocommerce` category, a zero-argument `execute()` (top-level `'default' => array()`), core's output validation
  against our envelope schemas, `ability_invalid_permissions` for anonymous and customer, shop_manager and administrator allowed,
  REST `GET .../run?input[...]` returning 200, and POST refused for a read-only ability.
- Core rejects `per_page` > 100 and unknown keys with `ability_invalid_input` before our code runs. `AbstractAbility` still clamps,
  because direct PHP callers skip validation.
- Error codes (public contract; never rename) and HTTP statuses: `mlstore_invalid_language` 400, `mlstore_invalid_input` 400,
  `mlstore_not_found` 404, `mlstore_currency_disabled` 409, `mlstore_dependency_missing` 424, `mlstore_internal_error` 500
  (generic message; details only in the WooCommerce log, exception message only with WP_DEBUG).
- `languages` input: empty = all active non-default languages. Unknown codes **and the default language** → `mlstore_invalid_language`.
  The result keeps the site's language order.
- Audit log: `wc_get_logger()->log()`; WooCommerce turns the `source` into the log file name (`wc-logs/mlstore-abilities-*.log`).
  Context: ability, user_id, 16-char input hash, result_count, duration_ms, error_code. No raw input, no output.

## 9. `mlstore/languages-overview` (milestone 5, ability 1)

- ⚠️ Concept §6.1 `languages[].active` replaced by **`is_default`**. Every language WPML returns is enabled, and WPML's own `active`
  key means "current language" (§5.7), so the field would be either redundant or misleading.
- Field names otherwise follow §6.1: `wcml_active`, `multi_currency_enabled`, `default_currency`, `currencies[]` (+ `is_default`),
  `sync_settings{synced_fields, unsynced_fields}`, `catalog_size{products, variations, product_categories, product_tags}` (originals only).
- Without WCML: multi-currency is reported off (its stored settings are ignored) and the WCML-code-synced fields (stock quantity,
  catalog visibility) count as unsynced. `stock_status` stays synced when its WPML preference is copy.
- Currency `languages` are returned in the site's language order. WCML stores them in arbitrary order and may still list removed languages.
- No caching (decision §4), and none is needed: one call runs a handful of hook calls plus 4 indexed COUNT queries.
- ✅ Verified over **real MCP** (MCP Adapter 0.7.0, STDIO, protocol 2025-11-25): discover lists the ability, get-ability-info returns the
  annotations, execute succeeds. Before this milestone the input schema's top-level default was `array()`, which encodes as `[]`;
  it is now `(object) array()` → `{}`.

## 10. `mlstore/translation-completeness` (milestone 5, ability 2)

- Decisions (Patrick, 2026-10-14): `attribute_term` audits all translatable `pa_*` taxonomies, optionally one (`taxonomy`);
  **trashed translations count as missing**.
- One status rule, `Domain\Model\TranslationState::from_post_status()`: publish = translated; absent/trash/auto-draft = missing;
  everything else = unpublished. Terms: exists = translated.
- ⚠️ **Direct SQL on `icl_translations` (justified per concept §4/§8):** the summary needs every original's translations and the item
  list must filter "incomplete" *before* paginating. Hooks would mean one round-trip per original on every call. The gateway uses
  3 queries (coverage GROUP BY, incomplete page plus its count, the page's members) and mirrors TranslationState in SQL.
  `tests/Store/TranslationCoverageStoreTest` cross-checks the fast path against a hook-based reference (`translations()` +
  `get_post_status()`) for published products, products in any status, categories and all attributes. All agree on the DDEV store.
- ⚠️ **`$wpdb` swallows SQL errors** and returns empty results, which an audit would report as "0 % translated". It happened during
  development (placeholder order). WpmlGateway now checks `$wpdb->last_error` after every query and throws; AbstractAbility
  returns `mlstore_internal_error`.
- ⚠️ **Rows with `element_id = NULL` (corrected 2026-10-14):** 14 `de` and 14 `fr` `post_product` rows in `icl_translations` have
  **no element at all**, so they are not deleted posts (an earlier note here was wrong). Patrick did not delete anything. In WPML such
  rows are, to my understanding (unverified), placeholders for translations that were started (e.g. a translation job) but not completed.
  They are reported as missing (no translation shoppers can see). ❓ Open: is "translation in progress" worth its own state? It would
  need WPML's job/status table, whose semantics must be confirmed with Patrick first.
- Translatable check via `wpml_is_translated_post_type` / `wpml_is_translated_taxonomy`; non-translatable attribute taxonomies are
  skipped in "all attributes" mode, and requesting one explicitly is an error.
- `WooGateway` (new): `attribute_taxonomies()` via `wc_get_attribute_taxonomy_names()`, `describe_posts()` (primes caches, plain-text
  titles, `_sku`, admin edit URL), `describe_terms()` (direct SQL, because WPML may filter `get_term()` IDs).
- Timing on DDEV (18 products, 8 attribute terms): 2–6 ms per call. Not representative; the 5,000-product check (concept §8) needs the
  generated catalog (Smooth Generator, pending).

## 11. Decision for `mlstore/cross-language-consistency` (Patrick, 2026-10-14)

- Price fields (`regular_price`, `sale_price`) are skipped **per language**: only where the translation's language uses a secondary
  currency for which the original has manual prices set (`_wcml_custom_prices_status` on, and `_regular_price_{CUR}` non-empty).
  Everywhere else prices are compared as usual.
- ⚠️ **WCML filters `get_post_metadata` for prices** (`WCML_Multi_Currency_Prices::product_price_filter`, priority 10): outside admin it
  returns `_price`, `_regular_price`, `_sale_price` and the min/max variation keys **converted to the visitor's currency**. For manual
  prices it calls `get_product_custom_prices()`, which **writes post meta** (§5.5). The key list (`wcml_price_custom_fields()`) is
  filterable, so other plugins can extend it. Rule: **product field and price values are read as raw meta via SQL**, never via
  `get_post_meta()` / `WC_Product` getters. This also applies to the gateway's `_regular_price_{CUR}` read.
  (`WCML_Products::filter_product_data` additionally rewrites the whole-meta read `get_post_meta( $id )` without a key.)

## 12. `mlstore/cross-language-consistency` (milestone 5, ability 3)

- Scope: products **and variations** (Patrick, 2026-10-14), originals in any status except trash, vs. every existing translation
  (any status except trash/auto-draft). Missing translations are translation-completeness' job. Variations skip `catalog_visibility`;
  `post_status` there means enabled (`publish`) vs disabled (`private`).
- ⚠️ Deviation from concept §5.6: the ability **works without WCML**. Severity then follows WPML's copy preferences only (SyncRules),
  instead of returning `mlstore_dependency_missing`.
- Equality rules (`Domain\FieldComparator`): empty = missing; numeric fields compare by value; `standard` tax class = `''`;
  stock quantity only when one side manages stock. Catalog visibility from `product_visibility` terms, mapped like
  `WC_Product::get_catalog_visibility()`.
- Price skip per decision §11. `summary.price_checks_skipped` makes it visible.
- Data access: `WpmlGateway::translation_pairs()` (one SQL), `WooGateway::product_fields()` (raw SQL per batch of 200 originals,
  `IN ( ... )` of int-cast IDs so the primary-key index is used; FIND_IN_SET on postmeta would full-scan it). Store tests cross-check
  pairs against the hook reference and fields against `WC_Product` getters in edit context.
- New shared trait `Gateway\AssertsQueries` (query error check + safe int ID list).

## 13. Scale test (concept §8), 2026-10-14

- Generated with WooCommerce Smooth Generator 1.3.0 (backup first: DDEV snapshot `before-smooth-generator` plus
  `_backups/before-smooth-generator-2026-10-14.sql.gz` in the project root): **4,994 original products, 12,321 variations,
  21 categories, 40 tags**, all `en-us` originals. 1,500 orders (2026-04-15 to 2026-10-13) for translation-work-queue.
- Timings on DDEV, 3 languages, `ddev wp eval` (no HTTP overhead):

  | Ability / input | Time |
  |---|---|
  | languages-overview | 24 ms |
  | translation-completeness (default) | 57 ms |
  | translation-completeness, page 50 × 100 | 89 ms |
  | translation-completeness, status any | 65 ms |
  | translation-completeness, attribute_term | 12 ms |
  | cross-language-consistency (default) | 18 ms |

  All far below the 2 s target, about 12 MB peak memory each.
- ⚠️ Not yet representative for cross-language-consistency: only 8 translation pairs exist (generated products are untranslated).
  Needs translated volume (seed script, milestone 6).
- Store suite on this catalog: 25 tests, 2,353 assertions, about 2 minutes (the hook-based references walk all originals; that is
  exactly why the abilities use SQL).
- ⚠️ **Analytics lookup tables lag behind orders:** after generating 1,500 orders, `wc_order_product_lookup` and `wc_order_stats`
  were **empty**. WooCommerce 11 defaults new installs to `woocommerce_analytics_scheduled_import = yes`, i.e. orders are imported in
  batches (here: next run 12 h later) instead of immediately. On live stores the lookup table can therefore lag. translation-work-queue
  must detect this (compare lookup coverage with recent orders) and report `meta.data_source` / freshness instead of "no sales".
  For the demo, the historical import was run via `ReportsSync::regenerate_report_data()` plus
  `wp action-scheduler run --group=wc-admin-data`: 8,279 lookup rows, 1,499 orders, 4,037 products.

## 14. Translation jobs (`icl_translation_status`), verified 2026-10-14

- Patrick confirmed: the 14 + 14 `element_id = NULL` rows (§10) are products he **sent to translation without completing**.
- `{prefix}icl_translation_status` joins on `translation_id`. Statuses are WPML constants (`inc/constants.php`): `0` not translated,
  `1` waiting for translator, `2` in progress, `3` needs update, `4` ready to download, `9` duplicate, `10` complete, `30` needs review,
  `40` ATE needs retry, `42` ATE cancelled, `102` pending (translation service). Column `needs_update` = 1 when the original changed after translation.
- DDEV data: the 28 NULL rows → status `1`; the 8 real translations → status `10`.
- ✅ Implemented (Patrick, 2026-10-14): translation-completeness reports `in_progress_languages` (no element yet, open job:
  1/2/4/30/40/102; `TranslationState::InProgress`) separately from `missing_languages`, and `outdated_languages` (existing translation
  with `needs_update = 1`; still counted as translated/unpublished, but the object is listed). The summary per language gains
  `in_progress` and `outdated`.
- Public hook `apply_filters( 'wpml_tm_translation_status', null, ['trid' => …, 'language_code' => …] )` returns the job status,
  **but WPML registers it lazily** (`wpml_tm_load_element_translations()`): in a front-end/REST request it is not registered and
  returns null. Another reason the gateway reads `icl_translation_status` in its SQL. The store reference test loads it explicitly.
  `needs_update` has no public hook.
- Seed data for consistency `sync_error`/`deviation` examples: in the milestone 6 seed script (Patrick, 2026-10-14).

## 15. `mlstore/untranslated-variations` (milestone 5, ability 4), 2026-10-14

- ✅ WooCommerce 11.2 matching (`WC_Product_Data_Store_CPT::find_matching_product_variation()`): variation meta `attribute_` +
  `sanitize_title( name )`, only parent attributes with `is_variation`, **exact `!==` comparison** with the selected value; empty =
  "any". Custom attribute options come from `wc_get_text_attributes()`: `html_entity_decode`, split at `WC_DELIMITER` (`|`), trim,
  drop empty values (`ProductAttribute::split_options()` mirrors this). Global attributes store the term **slug**.
- ✅ WCML keeps the attribute *key* (sanitized original name) on translations (`WCML_Attributes::sync_product_attr()`); translated
  labels in the translation's `attr_label_translations` meta as `[language][key] => label`.
- Issue table as decided in §5.10. Only attributes used for variations are checked; taxonomies WPML does not translate are skipped
  (terms are shared). `variation_missing` = no translation, or the translation is not a non-trashed child of the translated parent.
- All reads are raw SQL (`WooGateway::variable_product_ids()`, `product_attributes()`, `children()`, `variation_attribute_values()`,
  `object_terms()`; `MultilingualGateway::attribute_label_translations()`), because WCML filters meta reads and WPML filters term queries.
  `tests/Store/VariationDataStoreTest` checks them against `WC_Product::get_attributes()`, `get_children()`,
  `wc_get_product_terms()` and the variation attributes on the generated catalog: 100 products, 200 variations, all equal.
- ⚠️ No end-to-end example on the DDEV store yet: none of the real translations is a variable product (V-Neck T-Shirt's `de`/`fr` are
  still "sent to translation"). Logic is covered by unit and integration tests; real cases come from the milestone 6 seed script.
- ⚠️ **Store-suite guard vs. WP-Cron (2026-10-14):** the guard tripped once in `TranslationCoverageStoreTest`. The cause was not plugin
  code: WP-Cron ran `wc_product_start_scheduled_sale` (Smooth Generator gave many products scheduled sales), which updates price meta.
  The store bootstrap itself had spawned cron via `wp-load.php`. Fix: `DISABLE_WP_CRON` in `tests/Store/bootstrap.php`, plus a guard
  message naming the changed tables and the likely external writers.

## 16. `mlstore/currency-price-gaps` (milestone 5, ability 5), 2026-10-14

- Gap rule per §5.9.1. Manual pricing = `_wcml_custom_prices_status` not in `('', '0')` on the **original** (WCML always reads it there).
- Requires WCML (`requires_wcml()`) and multi-currency (`mlstore_currency_disabled`). `currencies` must be secondary currencies; the base
  currency or unknown codes → `mlstore_invalid_input` with the valid list.
- Variable parents are skipped (no own price); variations checked unless `include_variations: false`.
- `summary.missing_by_currency` is a **list** of `{currency, missing}`, not a map: an empty PHP map would JSON-encode as `[]`.
- New gateway methods (raw SQL): `manual_price_originals( CatalogScope )` (originals with the flag, one indexed query) and
  `manual_regular_prices( ids, currencies )` (batch meta read). Store test checks both against `get_post_meta()` over every original.
- ⚠️ DDEV store: no product uses manual prices yet, so the ability returns an empty list (5.7 ms). Real cases: milestone 6 seed script.

## 17. `mlstore/translation-work-queue` (milestone 5, ability 6), 2026-10-14

- Decisions (Patrick, 2026-10-14): Analytics is the source; fall back to raw order items only when Analytics is unavailable or empty
  while orders exist; otherwise report the lag as `meta.analytics_missing_orders`. Count the same orders as Analytics.
- ✅ Excluded statuses mirror WC 11.2 `DataStore::get_excluded_report_order_statuses()`: option `woocommerce_excluded_report_order_statuses`
  (default via `woocommerce_analytics_settings_default_excluded_order_statuses`: pending, cancelled, failed), plus auto-draft and trash,
  then `woocommerce_analytics_excluded_order_statuses`, with a `wc-` prefix, truncated to 20 characters.
- ⚠️ The fallback does **not** use `wc_get_orders()` (concept §6.6): `WCML\OrderItems\Hooks::getOrderItems` (filter
  `woocommerce_order_get_items`) swaps line-item product IDs to translations in memory, and loading orders is heavy. Instead: one raw SQL
  aggregation over `woocommerce_order_items` / `_itemmeta`, joined with the table `OrderUtil::get_table_for_orders()` reports
  (HPOS-aware; the legacy posts table only when WooCommerce itself uses it).
- Normalization: `MultilingualGateway::originals_of()` (one SQL), translation states via `coverage_of()`, which shares
  `rows_for_originals()` with incomplete_originals(). The state rule stays in one place.
- ✅ Store tests: Analytics units per product equal order-item units minus the line items of orders Analytics has not imported;
  `originals_of()` equals `original_id()` per ID.
- Real data: order 17954 (completed, 2026-04-21) is missing from `wc_order_stats`: a genuine import gap. The ability reports it
  (`analytics_missing_orders`) whenever the period includes it.
- ⚠️ Timing on the generated store: 90 days = 2,573 sold products, **~780 ms** (7 days: 94 ms). Within §8's 2 s, but the slowest ability.
  The cost is translation states for every sold product in batches of 100. Optimisation candidate: one coverage query for all sold
  originals, or a larger batch size.

## 18. Demo seed (milestone 6), 2026-10-14

- `tests/Fixtures/seed-demo.php` (run with `wp eval-file`; no `strict_types`, because eval() rejects it). Cases and expectations in
  `docs/demo-store.md`, assertions in `tests/Store/SeedScenarioStoreTest.php`. All six abilities report exactly the planned issues on real WPML/WCML data.
- Order of operations verified: build originals completely, then create and link translations (`wpml_set_element_language_details`),
  then apply deliberate mistakes as direct meta writes on translations. Done this way, WCML did not "repair" any of the deliberate mistakes.
- WooCommerce rejects a duplicate SKU on a new post until WPML knows it is a translation, so translation SKUs are written after linking.
- Orders are imported into Analytics immediately via `OrdersScheduler::import()` (internal WooCommerce API, dev tooling only).
- ⚠️ **DDEV regenerates `wp-config.php`** (`#ddev-generated`): the constants from milestone 1 (`wp config set WP_ENVIRONMENT_TYPE` etc.)
  were gone, `wp_get_environment_type()` returned `production`, and the seed refused to run. Fixed durably with
  `ddev config --web-environment-add=WP_ENVIRONMENT_TYPE=local`; `WP_DEBUG` comes from DDEV's own wp-config-ddev.php.
  `WP_DEBUG_LOG` is currently off (re-add via `web_environment` if needed).
- After the restart the site URL is `https://mlstore-dev.ddev.site` (port 443 was free); `wp-config-ddev.php` follows `DDEV_PRIMARY_URL`.
- `php-stubs/wp-cli-stubs` cannot be installed next to `wordpress-stubs` 7.1 (it requires ≤ 6.x); `tests/phpstan/wp-cli-stub.php` declares
  the three WP-CLI methods the seed uses.
- Not seeded: open translation jobs and `needs_update` (no safe public API). The 14 hand-sent products cover "in progress".
