# Technical Concept: Multilingual Store Abilities for WooCommerce

**Working title:** Multilingual Store Abilities (wordpress.org slug: `multilingual-store-abilities-for-woocommerce`)
**Author:** Patrick / Media Workbench
**Status:** Concept v0.1 for development in Claude Code
**Date:** 2026-10-13

---

## 1. Purpose

A WordPress plugin that registers **read-only, schema-defined abilities** (WordPress Abilities API) for multilingual WooCommerce stores running **WPML + WooCommerce Multilingual (WCML)**. The abilities can be exposed to AI agents through the official MCP Adapter, so a merchant can ask an agent questions like:

- "Which products are missing a French translation?"
- "Are there translations whose SKU, stock or visibility differ from the original?"
- "Which untranslated products sell best, so I know what to translate first?"
- "Which products have no manual price in CHF?"

### Why this plugin
- Core WooCommerce (11.2) ships only 7 product/order abilities; Woo's extensions are adding read-only abilities for their own domains. **No multilingual abilities exist** as of this writing.
- Generic "MCP for WordPress" plugins are crowded; they wrap CRUD and cannot answer cross-language questions.
- The plugin doubles as a **portfolio piece** demonstrating WPML/WCML domain depth, WooCommerce internals, and safe AI integration.

### Non-goals (v0.1)
- No write operations of any kind.
- No own MCP server or transport. Use the official MCP Adapter only.
- No calls to any AI provider. The plugin never talks to an LLM.
- No Polylang support (possible later via the gateway interface, see §5.2).
- No admin UI beyond a minimal settings page (v0.3).

---

## 2. Target environment

| Component | Minimum | Notes |
|---|---|---|
| PHP | 8.1 | Typed properties, enums, readonly props allowed |
| WordPress | 6.9 | Abilities API in core since 6.9 |
| WooCommerce | 10.9 | Canonical abilities + metadata conventions introduced in 10.9; test against 11.2 |
| WPML Multilingual CMS | 4.7+ (verify) | Paid; required |
| WooCommerce Multilingual | 5.x (verify) | Paid/free addon; required for currency + sync logic |
| MCP Adapter | 0.7.x | Optional at runtime; abilities work without it (REST, CLI) |
| HPOS | Required compatible | Declare compatibility; never query posts table for orders |

Plugin header must declare `Requires Plugins: woocommerce` (WPML is not on wordpress.org, so it cannot be listed there; check at runtime instead, see §5.6).

---

## 3. VERIFY FIRST (before writing code)

The Abilities API and MCP Adapter are pre-1.0 / recently stabilized and have changed between versions. **Do not code from memory.** Verify against current sources:

1. **Ability registration signature**: `wp_register_ability()` args, the hook name (`wp_abilities_api_init`), category registration (`wp_register_ability_category()` on `wp_abilities_api_categories_init`), and the required `category` field.
   - https://developer.wordpress.org/apis/abilities-api/
2. **Annotation / meta shape**: exact keys for read-only, destructive, idempotent annotations, REST visibility (`show_in_rest`?), and how an ability is flagged **MCP-public** for the default MCP server.
   - https://github.com/WordPress/mcp-adapter (README + CHANGELOG)
3. **WooCommerce conventions**: metadata shape of Woo 10.9 abilities, paginated output envelope, and the `woocommerce_mcp_include_ability` filter (needed if our namespace should appear in Woo's MCP server).
   - https://developer.woocommerce.com/docs/features/mcp/
   - https://developer.woocommerce.com/2026/06/01/expanding-abilities-across-woocommerce-extensions/
   - https://github.com/woocommerce/wc-mcp-ability (demo plugin)
4. **Install the agent skills** into this repo and follow them:
   - https://github.com/WordPress/agent-skills (`wp-abilities-api`, `wp-abilities-audit`, `wp-abilities-verify`)
   - https://github.com/woocommerce/agent-skills (`abilities-api-implement`)
5. **WPML / WCML APIs** used in §6: confirm hook names, return shapes and WCML meta keys against the installed versions.
   - https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/

Record findings in `docs/api-notes.md` so later sessions don't repeat the research.

---

## 4. Architecture overview

```
MCP client (Claude, Cursor, ...)
        │  MCP
        ▼
MCP Adapter (official plugin)           ← optional transport
        │
        ▼
WordPress Abilities API (core)          ← registry, permission + schema checks
        │
        ▼
┌──────────────── this plugin ────────────────┐
│ Registrar  →  Ability classes (one per ability)│
│                     │                          │
│                     ▼                          │
│               Domain services                  │
│   TranslationAudit · ConsistencyChecker ·      │
│   CurrencyPriceAudit · SalesRanking            │
│                     │                          │
│        ┌────────────┴────────────┐             │
│        ▼                         ▼             │
│  MultilingualGateway       WooGateway          │
│  (interface; WPML impl)    (wc_get_products,   │
│                             analytics lookup)  │
└──────────────────────────────────────────────┘
```

**Principles**
- **Thin abilities, fat services.** Ability classes only map input → service call → output envelope. All logic lives in domain services, which are unit-testable without WordPress.
- **Gateway pattern for WPML.** Every WPML/WCML call goes through `MultilingualGateway`. This makes the code testable (fake gateway), isolates version differences, and keeps a later Polylang implementation possible.
- **Public APIs first.** Use WPML hooks/filters. Direct queries on `icl_translations` only where a hook-based approach is too slow for large catalogs; each such query is isolated in the gateway, documented with the reason, and covered by a test.
- **Bounded output.** Every list ability is paginated with a hard `per_page` maximum (100). No ability returns unbounded data.
- **No PII.** v0.1 abilities return product/term data and aggregates only. No customer names, emails or addresses.

---

## 5. Plugin structure

```
multilingual-store-abilities/
├── multilingual-store-abilities.php      # bootstrap, header, HPOS declaration
├── composer.json                         # PSR-4 autoload, dev deps
├── readme.txt                            # wordpress.org readme
├── uninstall.php
├── src/
│   ├── Plugin.php                        # wires everything, dependency checks
│   ├── Dependencies.php                  # runtime checks for WC, WPML, WCML
│   ├── Abilities/
│   │   ├── Registrar.php                 # loader only: category + abilities
│   │   ├── AbstractAbility.php           # shared: permission, envelope, errors, audit
│   │   ├── LanguagesOverview.php
│   │   ├── TranslationCompleteness.php
│   │   ├── UntranslatedVariations.php
│   │   ├── CrossLanguageConsistency.php
│   │   ├── CurrencyPriceGaps.php
│   │   └── TranslationWorkQueue.php
│   ├── Domain/
│   │   ├── TranslationAudit.php
│   │   ├── ConsistencyChecker.php
│   │   ├── CurrencyPriceAudit.php
│   │   ├── SalesRanking.php
│   │   └── Model/                        # small readonly value objects
│   ├── Gateway/
│   │   ├── MultilingualGateway.php       # interface
│   │   ├── WpmlGateway.php               # WPML + WCML implementation
│   │   └── WooGateway.php
│   ├── Support/
│   │   ├── OutputEnvelope.php            # paginated envelope builder
│   │   ├── Pagination.php
│   │   └── AuditLogger.php
│   └── Admin/                            # v0.3 settings page
├── tests/
│   ├── Unit/                             # no WP; fake gateways
│   ├── Integration/                      # WP test suite + WC (+ WPML locally in DDEV)
│   └── Fixtures/                         # demo catalog definitions
├── docs/
│   ├── api-notes.md                      # findings from §3
│   ├── abilities.md                      # generated/maintained ability reference
│   └── SECURITY.md                       # threat model (§9)
├── languages/                            # .pot (de_DE .po kept as import source for translate.wordpress.org, not shipped)
├── bin/install-wp-tests.sh               # WP test suite installer (wp scaffold plugin-tests)
├── phpunit.xml.dist
├── .gitignore                            # vendor/, node_modules/, *.zip, caches
├── phpcs.xml.dist                        # WPCS + PHPCompatibilityWP
├── phpstan.neon.dist                     # level 6+, szepeviktor/phpstan-wordpress
└── .github/workflows/ci.yml
```

### 5.1 Naming
- **PHP namespace:** `MediaWorkbench\MultilingualStoreAbilities`
- **Ability namespace:** `mlstore/` (e.g. `mlstore/translation-completeness`). Own namespace, never `woocommerce/` or `wpml/`.
- **Ability category:** `mlstore-multilingual-catalog` (verify naming rules for categories).
- **Text domain:** `multilingual-store-abilities-for-woocommerce` (= wordpress.org slug; the repo folder and main file keep `multilingual-store-abilities`)
- **Trademark note:** wordpress.org disallows slugs starting with a trademark. The display name is "Multilingual Store Abilities for WooCommerce". wordpress.org derived the slug `multilingual-store-abilities-for-woocommerce` from it at submission and requires the text domain to equal the slug. "WPML" cannot appear in the name at all (restricted term "wp"). WooCommerce and WPML are named in the short description, tags and readme instead.

### 5.2 `MultilingualGateway` interface (draft)

```php
interface MultilingualGateway {
    /** @return list<Language> active languages incl. default flag */
    public function activeLanguages(): array;
    public function defaultLanguage(): string;

    /** Language code of an element, or null if unregistered. */
    public function elementLanguage( int $id, string $elementType ): ?string;

    /** Map of language code => element id for the element's translation group. */
    public function translations( int $id, string $elementType ): array;

    /** Original (source-language) id for any translation id. */
    public function originalId( int $id, string $elementType ): int;

    /** WCML: which product fields are synced across translations. */
    public function syncSettings(): SyncSettings;

    /** WCML multi-currency: enabled? currencies? */
    public function currencies(): CurrencySettings;

    /** WCML: manual price per currency for a product, null if not set. */
    public function manualPrice( int $productId, string $currency ): ?string;
}
```

`$elementType` follows WPML conventions: `post_product`, `post_product_variation`, `tax_product_cat`, `tax_pa_{attribute}` (verify).

### 5.3 `AbstractAbility` responsibilities
- `permission_callback`: `current_user_can( 'manage_woocommerce' )`. Filterable via `mlstore_ability_capability` per ability.
- Input normalization (defaults, clamping `per_page` to 1–100).
- Wrap service exceptions into `WP_Error` with stable error codes (`mlstore_dependency_missing`, `mlstore_invalid_language`, `mlstore_currency_disabled`, ...).
- Build the output envelope (§5.4).
- Call `AuditLogger` after execution.
- Provide annotations: `readonly: true`, `destructive: false`, `idempotent: true` for all v0.1 abilities (exact key names per §3).

### 5.4 Output envelope (align with Woo's playbook shape; verify)

```json
{
  "items": [ ... ],
  "pagination": { "page": 1, "per_page": 50, "total_items": 312, "total_pages": 7 },
  "meta": {
    "generated_at": "2026-10-13T18:00:00Z",
    "default_language": "de",
    "languages": ["de", "en", "fr"],
    "plugin_version": "0.1.0"
  }
}
```

Summary-type abilities return `{ "summary": {...}, "meta": {...} }` without pagination.

### 5.5 Audit logging (v0.1: lightweight)
- Use `wc_get_logger()` with source `mlstore-abilities` (visible under WooCommerce → Status → Logs).
- Log: timestamp, user ID, ability name, **hash** of normalized input, result count, duration ms, error code if any.
- No raw input values (could contain search strings), no output data.
- Filter `mlstore_audit_enabled` (default true).
- Later (v0.2, when writes arrive): own table with retention setting.

### 5.6 Dependency handling
- On `plugins_loaded`: check WooCommerce, WPML (`defined( 'ICL_SITEPRESS_VERSION' )` or `has_filter( 'wpml_active_languages' )`, verify), WCML.
- Missing WPML/WCML: register **no** abilities, show one admin notice. Never fatal.
- Missing WCML but WPML present: register abilities that don't need WCML; `currency-price-gaps` and the sync-aware part of `cross-language-consistency` return `mlstore_dependency_missing`.
- Declare HPOS compatibility via `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`.

---

## 6. Ability specifications (v0.1)

All abilities: read-only, `manage_woocommerce`, paginated where lists are returned. Schemas below are drafts in JSON Schema terms; implement exactly as `input_schema` / `output_schema`.

### 6.1 `mlstore/languages-overview`
**Purpose:** Orientation for the agent. First call in any session.
**Input:** none.
**Output (summary):**
- `default_language`, `languages[]` (`code`, `native_name`, `english_name`, `active`)
- `wcml_active` (bool), `multi_currency_enabled` (bool), `currencies[]` (`code`, `languages[]` they're shown for)
- `sync_settings`: which product fields WCML syncs (stock, prices, SKU, tax class, ...)
- `catalog_size`: count of original-language products, variations, product categories

### 6.2 `mlstore/translation-completeness`
**Purpose:** Which catalog objects lack translations, and coverage per language.
**Input:**
| Field | Type | Default | Notes |
|---|---|---|---|
| `object_type` | enum: `product`, `product_cat`, `product_tag`, `attribute_term` | `product` | |
| `languages` | string[] | all active non-default | validated against active languages |
| `status` | enum: `publish`, `any` | `publish` | products only |
| `page`, `per_page` | int | 1, 50 | max 100 |

**Output:** envelope; `summary` per language (`total`, `translated`, `missing`, `coverage_pct`) plus `items[]`:
`{ id, type, title, sku?, missing_languages[], edit_url }` (`id` = original-language ID).
**Logic notes:** iterate original-language objects only; use `translations()` to detect missing languages. Treat a translation in `draft`/`pending` as **present but unpublished** → separate field `unpublished_languages[]`.

### 6.3 `mlstore/untranslated-variations`
**Purpose:** Variable products whose parent is translated but variations or attribute terms are not, the classic cause of broken variation selectors in translated shops.
**Input:** `languages`, `product_id` (optional, limit to one product), `page`, `per_page`.
**Output items:** `{ product_id, title, language, issues[] }`, where each issue is one of:
- `variation_missing` (`variation_id`)
- `attribute_term_untranslated` (`taxonomy`, `term_id`, `term_name`)
- `local_attribute_untranslated` (`attribute_name`), custom per-product attributes (verify how WCML stores these)

### 6.4 `mlstore/cross-language-consistency`
**Purpose:** Find translations that differ from their original in fields that should match.
**Input:** `fields` (subset of `sku`, `stock_status`, `stock_quantity`, `manage_stock`, `tax_class`, `catalog_visibility`, `post_status`, `regular_price`, `sale_price`), `languages`, `page`, `per_page`.
**Default `fields`:** all.
**Logic:**
- For each original product, compare each field with each translation.
- **Sync-aware:** if WCML syncs a field, a difference is a `sync_error` (data corruption, failed sync). If not synced, a difference is reported as `deviation` (may be intentional). This distinction is the core domain value. Never report intentional differences as errors.
- Prices: skip when multi-currency with manual prices is active for that language/currency; that's §6.5's job.
- `post_status`: original `publish` + translation `draft` → `deviation`.

**Output items:** `{ product_id, title, language, translation_id, differences[]: { field, original, translation, severity: "sync_error" | "deviation" } }`

### 6.5 `mlstore/currency-price-gaps`
**Requires:** WCML multi-currency enabled; otherwise `mlstore_currency_disabled`.
**Purpose:** Products with "set prices manually" enabled that are missing a price in one or more secondary currencies (they fall back to exchange-rate conversion, often unintentionally).
**Input:** `currencies` (default: all secondary), `include_variations` (bool, default true), `page`, `per_page`.
**Output items:** `{ product_id, variation_id?, title, manual_pricing: true, missing_currencies[], base_regular_price }`
**Note:** verify WCML meta keys (`_wcml_custom_prices_status`, `_regular_price_{CURRENCY}` etc.) in `api-notes.md`.

### 6.6 `mlstore/translation-work-queue`
**Purpose:** Prioritized list of what to translate first, ranked by sales. The ability no generic tool can provide.
**Input:**
| Field | Type | Default |
|---|---|---|
| `languages` | string[] | all non-default |
| `period_days` | int 7–365 | 90 |
| `rank_by` | enum `revenue`, `units` | `revenue` |
| `limit` | int 1–50 | 20 |

**Logic:**
- Sales data from WooCommerce Analytics lookup table (`wc_order_product_lookup`) for the period, not by loading orders (performance; HPOS-agnostic). Fallback to `wc_get_orders()` batch aggregation if the lookup table is unavailable/unsynced; flag `meta.data_source`.
- **Normalize product IDs to originals** with `originalId()`. Orders placed in a translated shop reference the translated product ID; without normalization, sales are split across language versions. Document this in `abilities.md`; it's a typical multilingual pitfall worth showing.
- Join with translation status; keep only products missing ≥1 requested language.
- Revenue in store base currency (lookup table values); state this in `meta.currency`.

**Output items:** `{ product_id, title, revenue, units_sold, missing_languages[], unpublished_languages[], edit_url }`

---

## 7. MCP exposure

- Abilities are registered for REST + Abilities API regardless of MCP.
- MCP visibility: follow the current MCP Adapter mechanism (§3 item 2). Default: **all v0.1 abilities MCP-public**, since they're read-only and capability-gated.
- Also hook `woocommerce_mcp_include_ability` so the abilities appear in WooCommerce's MCP server when the merchant uses that one (verify filter signature).
- Provide `docs/mcp-setup.md`: dedicated WP user with a custom role holding `manage_woocommerce` only (or narrower once §5.3 filter is used), application password, example config for Claude Code / Claude Desktop via `@automattic/mcp-wordpress-remote`.

---

## 8. Performance

- Target: every ability < 2 s on a catalog of 5,000 products × 4 languages on modest hosting.
- Batch translation lookups per page (prefer one query per page via gateway over N+1 hook calls when hooks prove too slow; measure first, document the decision).
- Short-lived transient cache (5 min) for `languages-overview` and sync settings; no caching of report results in v0.1.
- Never `posts_per_page => -1`.
- Integration test with a generated large fixture catalog (WP-CLI script in `tests/Fixtures/`).

---

## 9. Security & threat model (→ `docs/SECURITY.md`)

| Risk | Mitigation |
|---|---|
| Overbroad agent permissions | Capability check per ability; recommend dedicated MCP user/role; filterable capability |
| Prompt injection via product content (titles/descriptions containing instructions) | Return data only, never instructions; titles returned as plain text, stripped of HTML; descriptions not returned at all in v0.1; document that agents must treat output as data |
| Data exfiltration / PII | No customer data in any v0.1 output; aggregates only for sales |
| Resource exhaustion | Hard pagination limits; `period_days` and `limit` clamped |
| Accidental writes | No write abilities in v0.1; "readonly-but-writes" check from `wp-abilities-verify` in CI |
| Information disclosure to unauthenticated users | Abilities require logged-in user with capability; test anonymous + `customer` + `shop_manager` roles |

---

## 10. Testing strategy

**Unit (`tests/Unit`, no WordPress):**
- Domain services against `FakeMultilingualGateway` and `FakeWooGateway`.
- Cover: sync-aware severity, ID normalization in sales ranking, pagination clamping, unpublished vs missing translations, currency gaps for variations.

**Integration (`tests/Integration`, WP test suite):**
- CI: WordPress + WooCommerce only → tests that the plugin loads safely without WPML, registers nothing, shows notice, no fatals.
- Local (DDEV, WPML installed manually, see §13): full ability tests against a seeded store (DE default, EN, FR, multi-currency EUR/CHF/USD).
- Permission tests per role for every ability.
- Schema tests: every output validates against its own `output_schema`.

**Tooling:** PHPUnit 10, Brain Monkey (or WP_Mock) for unit isolation, PHPCS with WPCS 3, PHPStan level 6+ with WordPress + WooCommerce stubs, `wp-abilities-verify` skill checks.

**CI (GitHub Actions):** lint (PHPCS), PHPStan, unit tests on PHP 8.1–8.4, integration smoke test (no WPML) on latest WP + WC using a MySQL service container and `bin/install-wp-tests.sh`. DDEV is for local development only.

---

## 11. Milestones

### v0.1 (target: 2 weeks)
1. Scaffold: composer, bootstrap, dependency checks, HPOS declaration, CI, test suite setup.
2. Verify APIs (§3) → `docs/api-notes.md`.
3. `MultilingualGateway` + `WpmlGateway` + fakes.
4. `AbstractAbility`, `Registrar`, envelope, audit logger.
5. Abilities in this order: `languages-overview` → `translation-completeness` → `cross-language-consistency` → `untranslated-variations` → `currency-price-gaps` → `translation-work-queue`.
6. Demo store seed script + screen recording with Claude via MCP.
7. `readme.txt`, `SECURITY.md`, `abilities.md`, German translation.
8. Submit to wordpress.org.

### v0.2
- First write ability: `mlstore/create-translation-drafts`: creates **draft** translations (never published), with a `dry_run` preview mode by default; annotated non-readonly, non-destructive.
- Audit log table with retention.

### v0.3
- Settings page: per-ability enable/disable and MCP exposure toggles; default off for any write ability.
- Optional: Polylang gateway.

---

## 12. Definition of done (per ability)

- [ ] Input + output schema complete; output validated in tests
- [ ] Permission callback tested for anonymous, customer, shop_manager, administrator
- [ ] Unit tests for domain logic incl. edge cases
- [ ] Integration test against seeded WPML store (local)
- [ ] Annotations correct (verified with `wp-abilities-verify`)
- [ ] Pagination bounded; performance checked against large fixture
- [ ] Strings translatable; documented in `docs/abilities.md` with an example agent question and example output
- [ ] PHPCS + PHPStan clean

---

## 13. Development environment

**Principle:** local development runs in **DDEV**. The DDEV project (WordPress, WooCommerce, WPML) is **not** under version control. The **git repository is only the plugin folder** inside it.

**Requirements:** DDEV (with Docker or OrbStack), Git.

### 13.1 Layout

```
~/dev/mlstore-dev/                          ← DDEV project, NOT a git repo
├── .ddev/
├── _licensed/                              ← WPML zips (manual, never committed)
├── wp-config.php, wp-admin/, wp-includes/
└── wp-content/plugins/
    ├── woocommerce/
    ├── mcp-adapter/
    ├── sitepress-multilingual-cms/         ← WPML (manual)
    ├── wpml-string-translation/            ← WPML (manual)
    ├── woocommerce-multilingual/
    └── multilingual-store-abilities/       ← THE git repo
```

Claude Code is started inside `wp-content/plugins/multilingual-store-abilities/`. All commands that touch WordPress go through `ddev` from the project root (or with `ddev exec -d`).

### 13.2 Create the DDEV project

```bash
mkdir -p ~/dev/mlstore-dev && cd ~/dev/mlstore-dev
ddev config --project-type=wordpress --php-version=8.2 --database=mariadb:10.11
ddev start
ddev wp core download
ddev wp core install --url='https://mlstore-dev.ddev.site' \
  --title='ML Store Dev' --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.test --skip-email
```

Debug settings:

```bash
ddev wp config set WP_DEBUG true --raw
ddev wp config set WP_DEBUG_LOG true --raw
ddev wp config set WP_DEBUG_DISPLAY false --raw
ddev wp config set SCRIPT_DEBUG true --raw
ddev wp config set WP_ENVIRONMENT_TYPE local
```

### 13.3 WooCommerce, MCP Adapter, store basics

```bash
ddev wp plugin install woocommerce wordpress-importer --activate
ddev wp plugin install mcp-adapter --activate   # verify availability on wordpress.org (§3); else install the GitHub release zip
ddev wp rewrite structure '/%postname%/' --hard
ddev wp option update timezone_string 'Europe/Berlin'
ddev wp option update woocommerce_default_country 'DE'
ddev wp option update woocommerce_currency 'EUR'
ddev wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
ddev wp import wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=skip
```

HPOS is enabled by default on new WooCommerce installs; confirm under WooCommerce → Settings → Advanced → Features.

### 13.4 WPML (manual, licensed)

Patrick does this step by hand:

1. Put the WPML zips into `~/dev/mlstore-dev/_licensed/`.
2. Install from inside the container:
   ```bash
   ddev wp plugin install _licensed/sitepress-multilingual-cms.zip _licensed/wpml-string-translation.zip --activate
   ddev wp plugin install woocommerce-multilingual --activate
   ```
3. Register the license, run the WPML setup wizard: DE (default), EN, FR. Then WCML: enable multi-currency with EUR / CHF / USD and manual pricing.
4. Translate a subset of sample products by hand, deliberately creating test cases: some fully translated, some missing languages, some variations untranslated, a few SKU/stock mismatches, some products with missing CHF prices. (Later replaced by a seed script in `tests/Fixtures/`.)
5. **Take a snapshot**: `ddev snapshot --name=wpml-baseline`. Restore any time with `ddev snapshot restore wpml-baseline`. This is the reference state for local integration tests and the demo recording.

### 13.5 Plugin repository

```bash
cd ~/dev/mlstore-dev/wp-content/plugins
git clone git@github.com:mediaworkbench/multilingual-store-abilities.git   # or: mkdir + git init
ddev exec -d /var/www/html/wp-content/plugins/multilingual-store-abilities composer install
ddev wp plugin activate multilingual-store-abilities
```

The plugin's `.gitignore` covers `vendor/`, `node_modules/`, `*.zip`, test caches and `.claude/settings.local.json`. Nothing from WordPress, WooCommerce or WPML ever enters the repo.

### 13.6 Running tests locally

- **Unit tests** (no DB): `ddev exec -d /var/www/html/wp-content/plugins/multilingual-store-abilities vendor/bin/phpunit --testsuite unit`
- **Integration tests** need a separate test database:
  ```bash
  ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wordpress_test; GRANT ALL ON wordpress_test.* TO 'db'@'%';"
  ddev exec -d /var/www/html/wp-content/plugins/multilingual-store-abilities \
    bash bin/install-wp-tests.sh wordpress_test db db db latest true
  ```
  Integration tests that need WPML load it from the DDEV install's plugin directory (path configurable via env var `MLSTORE_WPML_PATH`). If WPML is absent they are skipped, which is how CI runs.

### 13.7 Useful DDEV commands

| Command | Purpose |
|---|---|
| `ddev launch` / `ddev launch wp-admin` | open site / admin (admin / admin) |
| `ddev xdebug on` / `off` | step debugging |
| `ddev logs -f` | container logs; `wp-content/debug.log` for PHP |
| `ddev launch -m` | Mailpit (catches WooCommerce emails) |
| `ddev snapshot` / `ddev snapshot restore` | save / reset DB state |
| `ddev wp ...` | WP-CLI in the container |

### 13.8 MCP connection for the demo

- Create a dedicated user `mcp-agent` with the Shop Manager role (or a narrower custom role) and an application password.
- Endpoint: `https://mlstore-dev.ddev.site/wp-json/mcp/mcp-adapter-default-server` (verify path in §3).
- Connect from Claude Code / Claude Desktop via `@automattic/mcp-wordpress-remote` with `WP_API_URL`, `WP_API_USERNAME`, `WP_API_PASSWORD`. DDEV's local TLS certificate may need `NODE_EXTRA_CA_CERTS` pointing at mkcert's root CA (`mkcert -CAROOT`).

---

## 14. Instructions for Claude Code

1. Start by reading this document and §3. Do the verification research **before** scaffolding ability code; write results to `docs/api-notes.md`.
2. Install both agent-skill repos into `.claude/skills/` and use them for ability scaffolding and verification.
3. Work milestone by milestone; one ability per branch/PR-sized commit.
4. Write domain service tests first (fake gateways), then the ability class.
5. Never call WPML/WCML functions outside `WpmlGateway`.
6. Never add a write operation in v0.1, including caching that writes to product meta.
7. When a WPML/WCML behavior is unclear, stop and ask Patrick; he has long WPML experience and a licensed local setup. Don't guess meta keys or table structures.
8. Keep `docs/api-notes.md` updated whenever an API assumption turns out wrong.
