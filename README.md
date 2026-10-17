# LinguaPilot – Multilingual Store Abilities for WooCommerce

[![CI](https://github.com/mediaworkbench/linguapilot/actions/workflows/ci.yml/badge.svg)](https://github.com/mediaworkbench/linguapilot/actions/workflows/ci.yml)

Read-only [WordPress Abilities](https://developer.wordpress.org/apis/abilities-api/) that let AI agents audit a multilingual
WooCommerce store running **WPML** and **WooCommerce Multilingual & Multicurrency**: missing translations, translations that
drifted from the original, broken variation selectors, missing prices per currency, and which untranslated products to translate
first.

Agents call the abilities through the official [MCP Adapter](https://github.com/WordPress/mcp-adapter); the same abilities work
over REST and PHP. The plugin talks to no AI service itself and never writes to the store.

## Why

Multilingual stores drift. A product is translated into German but not French. A translated variation is missing, so French
customers cannot pick a size. A SKU was changed in one language only. A manually priced product has no CHF price. Finding this by
hand means clicking through WPML's translation dashboard product by product. An agent with these abilities can answer it in one
question:

> *"Which products are missing a French translation, and which of them sell best?"*
> *"Why can't French customers select a size on this hoodie?"*
> *"Are there translations whose SKU or stock differs from the original?"*

## Abilities

| Ability | Answers |
|---|---|
| `linguapilot/languages-overview` | Languages, currency per language, which product fields WPML/WCML keep in sync, catalog size. An agent's first call. |
| `linguapilot/translation-completeness` | Products, categories, tags and attribute terms without a translation, with coverage per language. Separates *missing*, *unpublished*, *in progress* (sent to a translator) and *outdated*. |
| `linguapilot/cross-language-consistency` | Translations whose SKU, stock, tax class, visibility, status or prices differ from the original, each rated `sync_error` (the field should be synced) or `deviation` (may be intentional). |
| `linguapilot/untranslated-variations` | Why a translated variable product's variation selector breaks: missing variations, untranslated attribute terms, mismatched custom options. |
| `linguapilot/currency-price-gaps` | Products and variations with "set prices manually" that lack a price in a secondary currency. |
| `linguapilot/translation-work-queue` | Untranslated products ranked by sales from WooCommerce Analytics, with sales of all language versions credited to the original. |

Example (`linguapilot/cross-language-consistency`, demo store, shortened):

```json
{
  "translations": [
    {
      "product_id": 19009,
      "title": "[Seed] Sync Error Scarf",
      "language": "de",
      "translation_id": 19020,
      "differences": [
        { "field": "sku", "original": "SEED-SCARF", "translation": "SEED-SCARF-DE", "severity": "sync_error" }
      ]
    }
  ],
  "page": 1, "per_page": 50, "total_pages": 1, "total_items": 3,
  "summary": { "translations_checked": 24, "sync_errors": 2, "deviations": 1, "price_checks_skipped": 4 }
}
```

Full input/output reference with examples: [docs/abilities.md](docs/abilities.md).

## Safe by design

- **Strictly read-only.** No ability writes anything; the only side effect is one audit line per call in the WooCommerce log.
  Abilities are annotated `readonly`, `idempotent`, non-`destructive`, so the REST route only accepts `GET`.
- **No customer data.** No names, emails, addresses, customer or order IDs. Sales appear only as per-product totals.
- **Least privilege.** Every ability requires `manage_woocommerce`, filterable per ability. The docs recommend a dedicated
  Shop Manager account with an application password for the agent.
- **Bounded.** Every list is paginated (max. 100 per call); queries are batched and indexed.
- **Prompt-injection aware.** Titles are returned as plain text; descriptions are never returned.

Threat model and how each point is verified: [docs/SECURITY.md](docs/SECURITY.md).

## How it is built

```
MCP client ──▶ MCP Adapter ──▶ Abilities API ──▶ Abilities/*        thin: schema, permission, envelope, audit
                                                      │
                                                      ▼
                                                 Domain/*           pure PHP: classification, comparison, ranking
                                                      │
                                    ┌─────────────────┴─────────────────┐
                                    ▼                                   ▼
                          Gateway/WpmlGateway                 Gateway/WooCommerceGateway
                          (the only WPML/WCML code)           (products, Analytics tables)
```

- **One WPML boundary.** Every WPML/WCML call lives in `WpmlGateway`. Domain services see WordPress object IDs only and are
  unit-tested against in-memory fake gateways. A Polylang gateway could be added without touching the domain.
- **Raw reads where plugins rewrite reads.** WCML converts price meta to the visitor's currency on read (and can write while
  doing so); WPML filters term queries to the current language. Those values are read with prepared SQL instead, so no
  third-party read hook can change the answer or the store.
- **No silent wrong answers.** `$wpdb` returns empty results on SQL errors, which an audit would report as "0 % translated".
  Every query is checked and turns a failure into an error.
- WPML and WCML behaviour (meta keys, option structures, sync rules, how hooks behave outside wp-admin) was verified against
  a licensed install of WPML 5.1 / WCML 5.6, not assumed.

## Tests

| Suite | Runs | What it proves |
|---|---|---|
| Unit | CI, PHP 8.1–8.4 | Domain logic against fake gateways: translation states, sync-aware severities, currency rules, sales ranking, pagination clamping. |
| Integration | CI (WP + WooCommerce, no WPML) | Registration, schemas, permissions for anonymous / customer / shop manager / administrator; safe degradation without WPML. |
| Store | Local (licensed WPML + WCML) | Every ability against a seeded store with known problem cases, and against a 5,000-product catalog. |
| Read-only, runtime | Local | Checksums **every database table** before and after running all abilities twice; results must be identical. |
| Read-only, static | CI | `bin/check-readonly.php` fails on any write API in plugin code except the marked audit log. |

Also in CI: PHPCS (WordPress Coding Standards 3, PHPCompatibility) and PHPStan level 8. The release build passes
[Plugin Check](https://wordpress.org/plugins/plugin-check/) without errors or warnings.

## Requirements

- WordPress 6.9+, PHP 8.1+, WooCommerce 10.9+
- WPML Multilingual CMS 5.0+ (setup wizard completed)
- WooCommerce Multilingual & Multicurrency 5.6+ (optional: needed for multi-currency checks)
- MCP Adapter 0.7+ (optional: to connect AI agents)

## Getting started

1. Install and activate the plugin. If a requirement is missing, an admin notice says which.
2. To connect an agent, follow [docs/mcp-setup.md](docs/mcp-setup.md): dedicated user, application password, MCP client config
   for `/wp-json/mcp/mcp-adapter-default-server`.
3. Without MCP: `GET /wp-json/wp-abilities/v1/abilities/linguapilot/languages-overview/run`.

## Development

```bash
composer install
composer lint              # PHPCS
composer analyse           # PHPStan
composer check:readonly    # static read-only check
composer test:unit
composer test:integration  # needs the WP test suite: bin/install-wp-tests.sh
composer test:store        # local store with licensed WPML/WCML only (see below)
bash bin/build-release.sh  # release folder + zip in build/
```

The store suite needs a local WordPress with WooCommerce, licensed WPML + WCML and `WP_ENVIRONMENT_TYPE=local`.
Seed its test cases with `wp eval-file wp-content/plugins/linguapilot/tests/Fixtures/seed-demo.php` (refuses to run outside `local`);
set `LINGUAPILOT_WP_LOAD` if `wp-load.php` is not in the WordPress install the plugin lives in. The suite is read-only and fails if
anything in the database changes. The reference setup is DDEV.
WPML is licensed software and is never part of this repository.

## Roadmap

- **v0.1** (this): six read-only abilities.
- **v0.2**: first write ability, `linguapilot/create-translation-drafts` (draft translations only, `dry_run` by default).
- **v0.3**: settings page to enable abilities individually; possibly a Polylang gateway.

## License

GPL-2.0-or-later. WPML and WooCommerce are trademarks of their respective owners; this project is not affiliated with or endorsed
by them.