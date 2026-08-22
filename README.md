# WP MCP Suite

Turn WordPress into a [Model Context Protocol](https://modelcontextprotocol.io) (MCP) server. Claude, Cursor, Codex or any MCP-compatible agent gets up to 77 tools to read and manage your site — with an SEO-first toolset, a full change ledger with rollback, and capability-checked safety rails on every call.

- **SEO-first**: one unified field vocabulary across Yoast SEO, Rank Math, Slim SEO, All in One SEO, SEOPress and The SEO Framework (native fallback included), on-page audits scored from real content, meta-tag and JSON-LD generation.
- **Full content stack**: posts, pages, terms, Media Library, redirects, core settings.
- **Page builders**: path-addressed Gutenberg block editing and an id-addressed Elementor engine with a declarative `build-page` constructor.
- **Pro**: WooCommerce store management behind a license gate.
- **Safe by default**: OAuth 2.1 or Application Passwords, per-tool capabilities, write tools disabled until you enable them, `confirm:true` gates on destructive ops, SSRF-guarded sideloads.

Full tool catalog: [docs/tools.md](docs/tools.md).

## Requirements

| | |
|---|---|
| WordPress | 6.4+ |
| PHP | 8.1+ |
| Elementor (optional) | any recent 4.x — unlocks the Elementor tools |
| WooCommerce (optional) | unlocks Pro store tools |

## Installation

1. Upload the `wpmcp` folder to `/wp-content/plugins/`, or zip it (`.\build.ps1`) and install through wp-admin.
2. Activate **WP MCP Suite**.
3. Create an Application Password: *Users → Profile → Application Passwords*.
4. Open **WP MCP → Connection** in wp-admin for copy-paste client configs.

The MCP endpoint is `https://your-site.com/wp-json/wpmcp/v1/mcp` (JSON-RPC over Streamable HTTP). The server can be toggled off entirely from the Connection screen.

## Connecting a client

All snippets below assume `https://example.com` is your site. Basic auth value is base64 of `username:application-password`.

**Claude Code**

```bash
claude mcp add --transport http wordpress https://example.com/wp-json/wpmcp/v1/mcp
```

**Cursor** (`mcp.json`)

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://example.com/wp-json/wpmcp/v1/mcp"
    }
  }
}
```

**Codex** (`config.toml`)

```toml
[mcp_servers.wordpress]
url = "https://example.com/wp-json/wpmcp/v1/mcp"
http_headers = { "Authorization" = "Basic <base64 of user:app-password>" }
```

**Claude Desktop** (`claude_desktop_config.json`)

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "http",
      "url": "https://example.com/wp-json/wpmcp/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64 of user:app-password>"
      }
    }
  }
}
```

### OAuth 2.1 (no copied passwords)

On HTTPS sites, clients can sign in through OAuth 2.1 with PKCE instead of pasting an application password. Discovery documents live at `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`; registration is dynamic; consent happens on an admin screen where connected apps can be revoked later. Local HTTP development requires the `WPMCP_ALLOW_INSECURE_OAUTH` constant.

## What agents can do

| Area | Highlights |
|---|---|
| Content | list/read/create/update/delete posts, pages and CPTs; terms; featured images |
| Media | attachment detail incl. all sizes, metadata edits, SSRF-guarded image sideloads |
| SEO | unified read/write across six SEO plugins + native fallback; keyword extraction; scored on-page audits; dry-run meta/JSON-LD generators that apply through the active plugin |
| Redirects | 301/302/307/308 manager with loop/duplicate protection, hit counts, paginated broken-link scanner |
| Diagnostics | one-call page snapshot digest, performance audit (DB, autoloads, cron, OPcache…), security hardening scan |
| Blocks | browse block schemas, edit any post's block tree by path (insert/update/move/remove, nested blocks) |
| Elementor | widget/schema catalogs, element tree reads, container/widget CRUD, duplicate/move, `build-page` declarative constructor |
| History | every write recorded with before-images; `rollback-change` undoes posts, SEO fields, media, settings, product/order edits |
| WooCommerce *(Pro)* | product catalog + pricing/stock edits, order lookup + status transitions |

## Compact tool mode

Clients that cap tool counts can collapse the whole surface into three meta-tools (`list-tools`, `get-tool-schema`, `call-tool`) from **WP MCP → Tools**. Per-tool toggles still gate what `call-tool` may run; capabilities are unchanged.

## Safety model

- Transport auth first: Application Passwords (HTTP Basic) or OAuth 2.1 bearer tokens. Anonymous requests get 401/403.
- Every tool declares a real WordPress capability; handlers re-check ownership per object.
- Write tools ship **disabled by default** — flip them on per tool under WP MCP → Tools.
- Destructive tools (`delete-post`, `remove-block`, `remove-element`, `clear-page`, `rollback-change`, …) require `confirm: true`.
- Image sideloads allow public hosts only, ports 80/443, image MIME types only, 10 MB cap.
- Every mutation lands in the change ledger (visible under WP MCP → History) with a before-image when reversible.

## Pro

With an active license (enter it under **WP MCP → Connection → License**, or define `WPMCP_PRO` in development builds), WooCommerce tools unlock automatically:

- free `woo-status` teaser reports store health without a license,
- `list-products`, `get-product`, `update-product` — pricing, stock, status, copy,
- `list-orders`, `get-order`, `update-order` — order lookup and status transitions,

all recorded to the change ledger with rollback support and gated by `manage_woocommerce`.

## Development

The repo ships a [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) setup (`.wp-env.json` points at a local core checkout in `.wp-core`).

```bash
npx @wordpress/env start          # http://localhost:8888
php tests/smoke.php               # stubbed unit smoke test, no DB needed

# E2E against the running env (run from the env's workdir):
export WPMCP_PASS="<admin application password>"
node tests/e2e.mjs                # core surface
node tests/e2e-oauth.mjs          # OAuth 2.1 flow
node tests/e2e-blocks.mjs         # Gutenberg tools
node tests/e2e-elementor.mjs      # needs Elementor active in the env
node tests/e2e-woo.mjs            # needs WooCommerce active in the env
```

Optional plugins used by the suites: install via `npx @wordpress/env run cli wp plugin install elementor woocommerce --activate`.

Build a distribution zip:

```powershell
.\build.ps1            # dist/wpmcp-<version>.zip, version parsed from the plugin header
```

Project layout:

```
includes/            core server, registry, auth/OAuth, change log, updater, license
includes/seo/        adapter interface + 7 SEO adapters + manager
includes/tools/      one class per tool domain
includes/elementor/  Elementor document engine
admin/               wp-admin screens (Connection, Tools, History)
tests/               smoke + E2E suites
build.ps1            packaging script
uninstall.php        cleanup on uninstall
```

## Changelog

See [readme.txt](readme.txt) for the release history.

## License

GPL-2.0-or-later
