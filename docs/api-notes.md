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

## 5. WPML / WCML: nothing verified yet

The concept says "WPML 4.7+" / "WCML 5.x". The zips in `_licensed/` are **WPML 5.1.0 / WCML 5.6.3**, so the minimum versions need updating.
WCML is a free plugin on wordpress.org (5.6.3 current).

The public hooks reference (wpml.org, last updated 2016) only lists hook names. These all exist:
`wpml_active_languages`, `wpml_default_language`, `wpml_element_language_details`, `wpml_element_language_code`,
`wpml_object_id`, `wpml_element_trid`, `wpml_get_element_translations`, `wpml_element_type`,
`wpml_original_element_id`, `wpml_post_language_details`, `wpml_setting`, `wpml_current_language`, and the `wpml_loaded` action.
Parameters and return shapes are **not** documented there and must be read from the installed code.

❓ Open questions for Patrick (no guessing, per §14.7):
1. **Detection:** `ICL_SITEPRESS_VERSION` vs `wpml_loaded` vs `has_filter('wpml_active_languages')`? And WCML: `WCML_VERSION`?
2. **Element types:** confirm `post_product`, `post_product_variation`, `tax_product_cat`, `tax_product_tag`, `tax_pa_{slug}`.
3. **Batching:** is a direct `icl_translations` query by `trid` acceptable for page-level batching (one query per page)?
   Which columns are reliable (`element_id, element_type, trid, language_code, source_language_code`)?
4. **"Synced" fields (§6.4):** which of SKU / stock / prices / tax class / visibility are governed by WCML settings
   (option key and structure?) and which by WPML's custom-field translation preference ("copy" vs "translate")?
   This decides `sync_error` vs `deviation`.
5. **Multi-currency:** option key for currencies and their language mapping; meta keys for the manual-price flag
   (`_wcml_custom_prices_status`?) and per-currency prices (`_regular_price_{CUR}`, `_sale_price_{CUR}`, `_price_{CUR}`?), for variations too.
6. **Local (custom) attributes:** how does WCML store their translations (the `_product_attributes` meta on the translation? a WCML-specific meta?)
7. **Order item product IDs:** does an order placed in FR record the FR product ID in `wc_order_product_lookup`,
   or does WCML normalize to the original? (§6.6 assumes the translated ID.)
8. **Variations:** are variations always their own `icl_translations` rows, or can a translated parent exist without them under WCML's auto-sync?
10. **Minimum versions:** which WPML / WCML versions should `Dependencies` require? (Currently presence-only.)
   Are `ICL_SITEPRESS_VERSION` / `WCML_VERSION` the right constants, defined by `plugins_loaded`?
9. ~~Inspecting code~~ **Decided:** wait until WPML/WCML are installed in DDEV, then read the installed code.

---

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
