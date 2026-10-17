# Demo store (DDEV)

The local DDEV store (`https://mlstore-dev.ddev.site`, admin / admin) is the reference for the store test suite and the demo recording.
Default language `en-us`, plus `de` and `fr`. Multi-currency EUR (base), CHF (de, fr), USD (en-us).

## Contents

| Source | What |
|---|---|
| WooCommerce sample data | 18 products, 7 variations, hand-translated test cases (4 translated products per language, 14 per language "sent to translation") |
| WooCommerce Smooth Generator 1.3.0 | ~4,980 products, ~12,300 variations, 1,500 orders (Apr–Oct 2026), all untranslated `en-us` originals |
| `tests/Fixtures/seed-demo.php` | Deliberate multilingual cases, see below |

## Seeded cases

`ddev wp eval-file wp-content/plugins/linguapilot/tests/Fixtures/seed-demo.php`

Refuses to run unless `WP_ENVIRONMENT_TYPE=local`. Idempotent: everything is tagged `_linguapilot_seed`, titled `[Seed] …`, and replaced on each run.
`tests/Store/SeedScenarioStoreTest.php` asserts the expected results.

| Case | Setup | Reported by |
|---|---|---|
| Complete Tee | DE + FR published, identical | nothing (control case) |
| Missing FR Mug | DE only | translation-completeness: FR missing |
| Draft DE Cap | DE draft, FR published | completeness: DE unpublished; consistency: `post_status` deviation |
| Sync Error Scarf | DE SKU changed, FR stock 3 instead of 12 | consistency: two `sync_error`s |
| Variable Hoodie | `pa_color` (Red, Blue) + custom "Size" S \| M \| L; DE correct, FR broken | untranslated-variations: DE review (identical options); FR missing variation, untranslated Blue, options mismatch, label review, invalid value |
| `[Seed] Category` | DE translation only | completeness (`product_cat`): FR missing |
| Manual Price Lamp | manual prices, CHF only; FR regular price differs | currency-price-gaps: USD missing; consistency skips the FR price (`price_checks_skipped`) |
| Manual Price Chair | manual prices CHF + USD | nothing (control case) |
| Hoodie Blue variation | manual prices, USD only | currency-price-gaps: CHF missing |
| 3 orders | Cap 5× via FR + 1× original, Mug 3× via DE (2–4 days ago) | translation-work-queue: Cap 6 units, Mug 3 units, under the originals |

Not seeded (no public API to create them safely): open translation jobs ("in progress"; the 14 hand-sent products cover this) and
outdated translations (`needs_update`).

## Snapshots and backups

| Name | When |
|---|---|
| `before-smooth-generator` | before generating catalog and orders |
| `before-seed` | before the first seed run |
| `before-cancel-scheduled-sales` | before cancelling the generated scheduled sales |
| `wpml-baseline` | seeded store with scheduled sales cancelled: the reference state for the store suite and the demo |

Restore with `ddev snapshot restore <name>`. Gzipped SQL exports of `before-smooth-generator`, `before-seed` and `wpml-baseline` are in the
project root's `_backups/` (outside the repo). They survive a Docker reset and work with any MariaDB version:
`ddev import-db --file=_backups/wpml-baseline-2026-10-14.sql.gz`. Snapshots live in `.ddev/db_snapshots/` and cover the whole database
server, including the integration test DB `wordpress_test`; after `ddev import-db`, recreate it with `bin/install-wp-tests.sh` (without `true`).

## Environment notes

- `WP_ENVIRONMENT_TYPE=local` is set in `.ddev/config.yaml` (`web_environment`). DDEV regenerates `wp-config.php`, so constants added
  with `wp config set` are lost on restart.
- Smooth Generator scheduled ~3,300 sale start/end actions that Action Scheduler ran every few minutes (price meta writes, triggered by
  any request to the site), which made the store suite's write guard trip. They were **cancelled** on 2026-10-14
  (`wp action-scheduler action cancel wc_product_{start,end}_scheduled_sale --all`; snapshot `before-cancel-scheduled-sales` before that).
  The store suite disables WP-Cron for its own process. Daily WooCommerce maintenance jobs can still run, so avoid browsing the DDEV site
  while the suite runs.
- **WooCommerce's daily `woocommerce_scheduled_sales` job** (Action Scheduler, not the cancelled per-product actions) also starts and ends
  sales: 2,312 generated products have sale date ranges until late December, so most daily runs save products (2026-10-17: 413 saves
  after a sale started on 10-16). Any request to the site runs the job once it is due. Verified with MariaDB's general query log; it was
  also the most likely cause of the earlier unexplained guard trip. Since 2026-10-17 the store suite's bootstrap refuses to start when a
  scheduled-sale action is due within 5 minutes and prints the command to run it first; when the guard trips, its message lists the
  Action Scheduler hooks that ran during the test class.
- PHP errors are logged to `wp-content/debug.log` via `.ddev/php/debug-log.ini` (`log_errors`, `error_log`): the equivalent of
  `WP_DEBUG_LOG`, which DDEV's generated `wp-config.php` does not read from the environment. `WP_DEBUG` is on by default in DDEV.
