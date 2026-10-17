# Connecting an AI agent via MCP

The abilities are exposed by the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) (0.7+) on its default server.
The agent sees three tools: `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`.

## 1. A dedicated user

Do not connect with an administrator account. Create a user that can only do what the abilities need
(on the DDEV demo store, prefix every command with `ddev`, e.g. `ddev wp user create …`; a host `wp` cannot reach DDEV's database):

```bash
wp user create mcp-agent mcp-agent@example.test --role=shop_manager
wp user application-password create mcp-agent "Claude MCP" --porcelain   # prints the password once
```

`shop_manager` has `manage_woocommerce`, which every `linguapilot/*` ability requires. For a narrower role, create one with only `read`
plus `manage_woocommerce`, or map the abilities to a custom capability with the `linguapilot_ability_capability` filter.

## 2. Claude Code / Claude Desktop

Remote (HTTP, via `@automattic/mcp-wordpress-remote`):

```json
{
  "mcpServers": {
    "linguapilot": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://mlstore-dev.ddev.site/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "mcp-agent",
        "WP_API_PASSWORD": "<application password>",
        "NODE_EXTRA_CA_CERTS": "<output of: mkcert -CAROOT>/rootCA.pem"
      }
    }
  }
}
```

`NODE_EXTRA_CA_CERTS` is only needed for DDEV's local TLS certificate. Never commit a configuration that contains the application password.

**Desktop apps (LM Studio, Claude Desktop):** apps started from the Dock often do not see your shell's `PATH`. Use the full path to `npx`
(e.g. `/opt/homebrew/bin/npx` with Homebrew) and add `"PATH": "/opt/homebrew/bin:/usr/bin:/bin"` to `env`. Tested with LM Studio (2026-10).

**Check the connection first**, independent of any client:

```bash
curl -s -u 'mcp-agent:<application password>' -H 'Content-Type: application/json' \
  -X POST https://mlstore-dev.ddev.site/wp-json/mcp/mcp-adapter-default-server \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'
```

A JSON answer with `"MCP Adapter Default Server"` means the store side works; 401 means wrong credentials.

**Models:** use one with reliable tool calling. The default server works in three steps (discover → get info → execute), and small
local models sometimes stop after discovering. Naming the steps in the prompt helps:
*"Use the MCP tools: discover the abilities, get the info for linguapilot/languages-overview, then execute it."*
Each call appears in WooCommerce → Status → Logs (source `linguapilot`) with the calling user's ID.

Local alternative without HTTP (STDIO through WP-CLI inside DDEV):

```json
{
  "mcpServers": {
    "linguapilot": {
      "command": "bash",
      "args": ["-c", "cd /path/to/ddev/project && ddev wp mcp-adapter serve --user=mcp-agent --server=mcp-adapter-default-server"]
    }
  }
}
```

## 3. Demo script

Questions that exercise every ability on the seeded demo store (`tests/Fixtures/seed-demo.php`):

1. "Which languages and currencies does the store use, and which product fields stay in sync?" → `languages-overview`
2. "Which products are missing a French translation?" → `translation-completeness`
3. "Are there translations whose SKU, stock or visibility differ from the original?" → `cross-language-consistency` (Scarf: two sync errors)
4. "Why can't French customers pick a size on the Variable Hoodie?" → `untranslated-variations`
5. "Which products have no manual price in USD or CHF?" → `currency-price-gaps`
6. "Which untranslated products sell best, so I know what to translate first?" → `translation-work-queue`

The agent must treat all output as data: product titles come from the store and are never instructions (threat model: `docs/SECURITY.md`, milestone 7).
