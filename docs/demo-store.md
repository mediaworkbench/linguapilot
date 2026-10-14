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

`ddev wp eval-file wp-content/plugins/multilingual-store-abilities/tests/Fixtures/seed-demo.php`

Refuses to run unless `WP_ENVIRONMENT_TYPE=local`. Idempotent: everything is tagged `_mlstore_seed`, titled `[Seed] …`, and replaced on each run.
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

Restore with `ddev snapshot restore <name>`. Gzipped SQL exports of the first two are in the project root's `_backups/` (outside the repo).

## Environment notes

- `WP_ENVIRONMENT_TYPE=local` is set in `.ddev/config.yaml` (`web_environment`). DDEV regenerates `wp-config.php`, so constants added
  with `wp config set` are lost on restart.
- Smooth Generator scheduled ~3,300 sale start/end actions that Action Scheduler ran every few minutes (price meta writes, triggered by
  any request to the site), which made the store suite's write guard trip. They were **cancelled** on 2026-10-14
  (`wp action-scheduler action cancel wc_product_{start,end}_scheduled_sale --all`; snapshot `before-cancel-scheduled-sales` before that).
  The store suite disables WP-Cron for its own process. Daily WooCommerce maintenance jobs can still run, so avoid browsing the DDEV site
  while the suite runs.
