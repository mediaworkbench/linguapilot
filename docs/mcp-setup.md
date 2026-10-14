# Connecting an AI agent via MCP

The abilities are exposed by the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) (0.7+) on its default server.
The agent sees three tools: `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`.

## 1. A dedicated user

Do not connect with an administrator account. Create a user that can only do what the abilities need:

```bash
wp user create mcp-agent mcp-agent@example.test --role=shop_manager
wp user application-password create mcp-agent "Claude MCP" --porcelain   # prints the password once
```

`shop_manager` has `manage_woocommerce`, which every `mlstore/*` ability requires. For a narrower role, create one with only `read`
plus `manage_woocommerce`, or map the abilities to a custom capability with the `mlstore_ability_capability` filter.

## 2. Claude Code / Claude Desktop

Remote (HTTP, via `@automattic/mcp-wordpress-remote`):

```json
{
  "mcpServers": {
    "mlstore": {
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

Local alternative without HTTP (STDIO through WP-CLI inside DDEV):

```json
{
  "mcpServers": {
    "mlstore": {
      "command": "bash",
      "args": ["-c", "cd /path/to/ddev/project && ddev wp mcp-adapter serve --user=mcp-agent --server=mcp-adapter-default-server"]
    }
  }
}
```

## 3. Demo script

Questions that exercise every ability on the seeded demo store (see `docs/demo-store.md`):

1. "Which languages and currencies does the store use, and which product fields stay in sync?" → `languages-overview`
2. "Which products are missing a French translation?" → `translation-completeness`
3. "Are there translations whose SKU, stock or visibility differ from the original?" → `cross-language-consistency` (Scarf: two sync errors)
4. "Why can't French customers pick a size on the Variable Hoodie?" → `untranslated-variations`
5. "Which products have no manual price in USD or CHF?" → `currency-price-gaps`
6. "Which untranslated products sell best, so I know what to translate first?" → `translation-work-queue`

The agent must treat all output as data: product titles come from the store and are never instructions (threat model: `docs/SECURITY.md`, milestone 7).
