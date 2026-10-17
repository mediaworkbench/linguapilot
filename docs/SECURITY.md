# Security

## Reporting a vulnerability

Please report security issues privately to the author (see the plugin header) and do not open a public issue. Include the plugin version,
WordPress / WooCommerce / WPML / WCML versions, and steps to reproduce.

## What the plugin does

It registers six **read-only** WordPress abilities (`linguapilot/*`) that report on a multilingual WooCommerce catalog. They are reachable
through the Abilities API in PHP, its REST route (`GET /wp-json/wp-abilities/v1/abilities/linguapilot/<name>/run`) and, with the MCP Adapter,
by AI agents. The plugin ships no MCP server of its own, calls no AI provider and sends no data anywhere.

## Threat model

| Risk | Mitigation | How it is verified |
|---|---|---|
| **Unauthorised access** | Every ability requires `manage_woocommerce` (Shop Manager, Administrator); anonymous visitors and customers are refused (`ability_invalid_permissions`). The capability can be changed per ability with the `linguapilot_ability_capability` filter; a filter returning anything but a non-empty string denies access. The MCP Adapter additionally requires an authenticated user. | Integration tests per ability for anonymous, customer, shop_manager and administrator; unit tests for the filter. |
| **Overbroad agent permissions** | `docs/mcp-setup.md` recommends a dedicated user (`mcp-agent`, Shop Manager or a narrower custom role) with an application password, never an administrator. Application passwords can be revoked individually. | Documentation. |
| **Accidental writes** | No ability writes. The only side effect is the audit log line (WooCommerce logger, file-based). Data that plugins rewrite on read (WCML converts price meta to the visitor's currency and may write while doing so; WPML filters term reads to the current language) is read with raw SQL instead of `get_post_meta()` / `WC_Product` getters, so no third-party read hook can write. The abilities are annotated `readonly: true, destructive: false, idempotent: true`; the REST route therefore only accepts GET. | `tests/Store/ReadOnlyGuaranteeStoreTest`: checksums of **every database table** before and after running all abilities twice, plus identical results. Every store test class checks content tables are unchanged. `bin/check-readonly.php` (CI) scans the plugin code for write APIs (database, options, meta, transients, object cache, files, HTTP, cron, Action Scheduler, `do_action`) and fails on anything but the audit log, which is marked `verify-ignore`; the scanner itself is unit-tested. |
| **Data exfiltration / customer data** | No ability returns customer data: no names, emails, addresses, customer or order IDs. Sales appear only as per-product aggregates (units, revenue). The SQL never selects `customer_id`. | Output schemas list every field (`additionalProperties: false`), and WordPress validates every response against them. Reviewed per ability. |
| **Prompt injection via store content** | Product titles and term names are returned as plain text (HTML stripped, entities decoded); descriptions and other free text are never returned. Agents must treat all output as data, never as instructions (stated in `docs/mcp-setup.md` and the readme). | `WooCommerceGateway::plain_text()`; store tests compare titles with `wp_strip_all_tags()`. |
| **Resource exhaustion** | Every list is paginated with `per_page` ≤ 100 (enforced by the input schema and clamped again in code); `period_days` is 7–365 and `limit` 1–50. Queries are batched (≤ 200 objects per query) and use indexed lookups; no `posts_per_page => -1`. | Unit tests for clamping; integration tests that out-of-range input is rejected; timings on a 5,000-product catalog (all abilities 12–780 ms). |
| **SQL injection** | All dynamic values go through `$wpdb->prepare()`. Where an `IN ( … )` list keeps an index usable, only integer IDs (`wp_parse_id_list()`) are interpolated; table names come from `$wpdb->prefix`, enum values and slug/key lists go through `esc_sql()` (lists via `FIND_IN_SET()`). | PHPCS (WordPress `PreparedSQL` sniffs; every exception is annotated with its reason); PHPStan level 8 with `literal-string` checks on `prepare()`; Plugin Check on the release build (no errors, no warnings). |
| **Silent wrong answers** | `$wpdb` returns empty results on SQL errors, which an audit would report as "0 % translated". Every direct query is followed by an error check that turns the failure into `linguapilot_internal_error`. | Code review rule: every gateway query is followed by `assert_query_succeeded()`. |
| **Error message leakage** | Unexpected exceptions are caught; the caller gets a generic `linguapilot_internal_error`. The exception class and location go to the WooCommerce log, and the message only with `WP_DEBUG`. Permission errors never reveal why access was denied (WordPress core behaviour). | Unit and integration tests (`crash` path of the probe ability). |
| **Audit trail** | Every call logs ability name, user ID, a 16-character hash of the normalized input (no raw values, which may contain search terms), result count, duration and error code to WooCommerce → Status → Logs (source `linguapilot`). It can be switched off per ability with the `linguapilot_audit_enabled` filter. | Unit tests (`AuditLoggerTest`), including that raw input never appears in the log. |

## Out of scope for v0.1

- Write abilities (planned for v0.2 with a `dry_run` default and non-readonly annotations).
- Rate limiting: left to the MCP client, the web server or the MCP Adapter.
- Multisite network-wide queries: abilities run per site.

## Development tooling

`tests/Fixtures/seed-demo.php` writes demo data and calls WPML APIs directly. It is never loaded by the plugin, is excluded from release
archives (`.gitattributes`) and refuses to run unless `WP_ENVIRONMENT_TYPE` is `local`.
