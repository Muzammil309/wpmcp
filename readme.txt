=== WP MCP Suite ===
Contributors: yourname
Tags: mcp, ai, seo, model context protocol, claude
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into an MCP server for AI agents. SEO-first: unified SEO read/write across Yoast, Rank Math and Slim SEO, on-page audits, meta and JSON-LD generation.

== Description ==

WP MCP Suite exposes your WordPress site as a Model Context Protocol (MCP) server so Claude, Cursor, Codex and any MCP-compatible agent can manage it directly.

* SEO-first toolset

* `seo-read` / `seo-write` - read and write SEO metadata through one unified field vocabulary (title, description, canonical, robots, focus keyword, Open Graph, Twitter) regardless of which SEO plugin is active. Adapters for Yoast SEO, Rank Math, Slim SEO, All in One SEO, SEOPress and The SEO Framework; native fallback (with front-end meta output) when no SEO plugin is installed.
* `audit-page-seo` - scored report from the real content: title/meta length, H1 count, heading hierarchy, image alt coverage, link profile, word count, target-keyword usage.
* `extract-keywords-from-content` - stop-word filtered keyword extraction, no external service.
* `generate-meta-tags` - proposes title/description, dry-run by default, applies through the active SEO plugin.
* `generate-schema-markup` - JSON-LD (Article / LocalBusiness / FAQPage / Product), dry-run by default.

**Content, media and settings**

* Posts, pages and custom post types: list, read, create, update, trash/delete.
* Media Library: full attachment detail, metadata edits, SSRF-guarded image sideload.
* Redirect manager: 301/302/307/308 redirects with loop/duplicate protection, hit counts, and a paginated broken-link scanner.
* Change ledger with one-click undo: `list-changes`, `get-change`, `rollback-change` restore posts, SEO metadata, media fields and settings from before-images.
* Core settings from a curated allowlist with typed values.
* Every write recorded to a change ledger visible in wp-admin under History.

**Safety**

* OAuth 2.1 sign-in (PKCE S256, dynamic client registration, rotating refresh tokens, admin consent screen) plus Application Passwords.
* Every tool maps to a real WordPress capability; per-post ownership checks.
* Write tools ship disabled by default; destructive tools require confirm:true.
* Sideloads are SSRF-guarded: public hosts only, ports 80/443, image types only, 10 MB cap.

**Pro**

* With an active license (or `WPMCP_PRO` in development builds), WooCommerce store tools unlock automatically: product catalog and pricing/stock edits, order lookup and status transitions - all recorded to the change ledger with rollback support. A free `woo-status` teaser reports store health without a license.

== Installation ==

1. Upload the `wpmcp` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Create an Application Password under Users -> Profile.
4. Open WP MCP -> Connection in wp-admin for copy-paste client configs.

== Changelog ==

= 0.7.0 =
* New: Accessibility toolkit - audit-page-a11y (scored WCAG report: alt text, heading order, generic links, form labels, lang attr, Elementor color-pair contrast), fix-color-contrast (dry-run by default, apply writes through the ledger), add-alt-text-from-context (proposes alt from filename/title/headings).
* New: Forms wave 1 - forms-read (CF7 / WPForms / Gravity Forms providers, auto-detects installs) and Pro forms-write (entry status + delete for WPForms/GF).
* New: MetaBox integration - metabox-read free, metabox-write Pro.
* New: Theme management - theme-read (context + mods) and Pro theme-write (set-mods, child-theme generator with confirm gate).
* New: Project memory - memory-read recalls approved guardrails/facts/conventions and session summaries; Pro memory-write proposes guidance pending admin approval, approves, forgets.
* New: Brand kits - brand-kits-list plus Pro brand-kit-apply applies a bundled color/typography kit to the Elementor site kit (previous values snapshotted to the change ledger).
* New: Content export - export-content/list-exports write git-friendly JSON mirrors under uploads/wpmcp-exports/; restore-content recreates or updates a post by slug (confirm-gated, before-image in the ledger).
* New Elementor: update-page-settings, Elementor 4 Global Classes CRUD (global-classes, requires the e_classes experiment), plus get-element-settings, set-element-label, list-pages, container schema support.
* New: Comments - comment-read (status/post/search filters, email visible to moderators only) and comment-write (create held-by-default comments and replies, approve/hold/spam/trash with ledger rollback, confirm-gated permanent delete).
* New: Revisions - revision-read (list + side-by-side compare) and restore-revision (confirm-gated; prior content captured to the change ledger).
* Fix: db-read no longer appends a second LIMIT clause.

= 0.6.4 =
* New: list-post-types and list-taxonomies reads.
* New: Openverse stock images - search-images (free CC search, no API key) and one-call add-stock-image with license credit stored as caption.
* New: esize-media - scale/crop images in place with automatic .wpmcp-bak backups and full rollback via the change ledger.
* New Elementor tools: get-element-settings, set-element-label (Navigator labels), list-pages, plus container support in get-widget-schema. Document engine gained a dirty-flag API used by label writes.

= 0.6.0 =
* New: Media Library listing (`list-media`) and permanent attachment deletion with ledger before-images (`delete-media`, confirm-gated).
* New: Gutenberg pattern tools - `list-patterns`, `insert-pattern` - plus `duplicate-block` for path-addressed cloning.
* New: User directory (`user-read`) and Pro user management (`user-write`: create non-admin users, edit profiles/roles; administrator accounts are off-limits).
* New: Plugin & theme management (`plugin-manage`, `theme-manage`): list, activate/deactivate, install/update from wordpress.org, switch themes, deletes confirm-gated. WP MCP Suite itself can never be deactivated or deleted through its own server.
* New: Navigation menu management (`menu-read`, `menu-write`): menus, items, locations, ordering.
* New: Read-only filesystem access (`fs-read`: read-file, list-directory, search-files). Pro `fs-write` adds write/edit/delete with automatic backups; wp-config.php and .htaccess are always refused.
* New: Read-only database access (`db-read`: list-tables, describe-table, capped SELECT queries). Pro `db-write` adds parameterized insert/update/delete rows with before-images; users/options tables are protected.
* New: Advanced Custom Fields integration (`acf-read`, Pro `acf-write`). Registers automatically when ACF is active.
* New: Elementor additions - `find-element` (search by widget type or settings text), `reorder-elements`, Pro `batch-update`, `export-page`, `import-template`, `save-as-template`, `apply-template`, and site-wide global colors / typography updates.
* New (opt-in): Pro `run-wp-cli` in-process runner behind the WPMCP_ALLOW_WP_CLI constant; eval/shell/db/config command families are refused.
* Tests: new e2e-system.mjs (48 checks) covering gate states, all new domains, safety refusals and rollback round-trips.

= 0.5.0 =
* New: WooCommerce Pro tools (require an active license; register automatically when WooCommerce is active) - free `woo-status` teaser, then `list-products`, `get-product`, `update-product` (price, stock, status, copy), `list-orders`, `get-order`, `update-order` (status transitions, customer note). All writes carry capability checks (`manage_woocommerce`), before-images and ledger rollback support.
* New: change-ledger rollback for the woocommerce domain - `update-product` and `update-order` entries restore prior pricing/stock/status.
* Tests: new e2e-woo.mjs covering both gate states (unlicensed refusal, licensed flows) plus product and order rollback round-trips.

= 0.4.0 =
* New: Gutenberg block tools - `list-blocks`, `get-block-schema`, `get-post-blocks`, `insert-block`, `update-block-attrs`, `remove-block`, `move-block`. Path-addressed tree editing (including nested inner blocks) with change-log integration and confirm gates on destructive ops.
* New: Elementor tools (register automatically when Elementor is active) - `elementor-status`, `list-elementor-widgets`, `get-widget-schema`, `get-page-structure`, `add-container`, `add-widget`, `update-element`, `duplicate-element`, `move-element`, `remove-element`, `clear-page` and the composite `build-page` declarative page constructor.
* New: GitHub release updater and license manager infrastructure for the Pro distribution.
* Fix: block paths are now dense indices - freeform whitespace artifacts from parse_blocks no longer skew insert/update/move/remove targets.
* Fix: Elementor nested-element mutations (update/duplicate/move/remove) silently no-oped due to a by-reference bug in the document engine; duplicate of a root container corrupted its element hash; top-level move-to-index appended instead of positioning.
* Fix: `get-widget-schema` and `list-elementor-widgets` no longer require a post context.
* Compat: read WP_Block_Type::$example directly (WP 7.x removed get_example()).
* Tests: new e2e-blocks.mjs (27 checks) and e2e-elementor.mjs (42 checks); full suite green across smoke, core E2E, blocks, Elementor and OAuth.

= 0.3.0 =
* New: `get-page-snapshot` - one normalized digest of a post: content outline, element counts, word count, image alt coverage, internal/external/generic link profile, the active SEO plugin data with title/description lengths, JSON-LD presence and structural warnings.
* New: Compact tool mode (Tools tab) - collapse the whole surface into 3 meta-tools (`list-tools`, `get-tool-schema`, `call-tool`) for clients that cap tool counts. Per-tool toggles still gate what `call-tool` may run; capability checks are unchanged.
* New: `analyze-performance` - scored read-only audit: PHP config, database size, autoloaded-options weight, post revisions, cron health, object cache, OPcache, plugin count, with ranked recommendations.
* New: `scan-security` - scored read-only hardening audit: file editing, debug output, admin username, XML-RPC, version disclosure, HTTPS, security headers, outdated plugins/themes/core vs wordpress.org data, and a bounded scan for executable PHP under uploads.

= 0.2.0 =
* New: OAuth 2.1 sign-in for MCP clients - RFC 9728 discovery at /.well-known/oauth-authorization-server and /.well-known/oauth-protected-resource, dynamic client registration, PKCE S256 authorization-code flow with an admin consent screen, 1h access tokens and rotating 30-day refresh tokens, revocation endpoint and a Connected-apps list with one-click Revoke in wp-admin.
* New: Rollback tools - list-changes / get-change / rollback-change undo recorded writes from their before-images (posts, SEO metadata, media fields, settings; schema removal).
* New: Redirect manager - redirect-read / redirect-write for 301/302/307/308 redirects with path normalization, loop and duplicate protection, hit counting on match, plus a paginated scan-broken-links tool that probes external links in published content in bounded batches.
* New SEO adapters: All in One SEO (v4 custom-table storage), SEOPress, The SEO Framework. Six plugin adapters total plus the native fallback.

= 0.1.0 =
* Initial release: MCP server (JSON-RPC over Streamable HTTP), unified SEO layer (Yoast, Rank Math, Slim SEO, native), SEO audit toolkit, content/media/settings tools, change ledger, admin UI.
