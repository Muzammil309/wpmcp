---
description: Plan + implement feature parity with EMCP Tools (emcptools.com) — gap analysis of this WP MCP plugin, then implement missing features in phases.
agent: build
---

You are working in the WP MCP Suite repository — a WordPress plugin that exposes the site as an MCP server (SEO tools, content/media/settings, blocks, Elementor engine, WooCommerce pro tools, users/plugins/menus/filesystem/database management, change ledger with rollback).

# Mission

Replicate and exceed the feature set of the competitor plugin at https://emcptools.com/ so this plugin reaches full functional parity and beyond.

# Step 1 — Research the competitor

- Fetch https://emcptools.com/ and any docs/tool-list pages linked from it. Extract the complete tool catalog: every tool name, its category, read/write/destructive flags, free-vs-pro gating, and what each one does.
- If the site blocks fetching or the catalog is JS-rendered, say so explicitly and fall back to the competitor intelligence already documented in `docs/tools.md` comparisons and the changelog, plus web search for "EMCP Tools wordpress mcp plugin tools list".

# Step 2 — Analyze this project

- Inventory every registered tool by reading `includes/tools/*.php` (each class registers tools via `$this->registry->register()`), plus `wpmcp.php` for the bootstrap list.
- Read `docs/tools.md` for the current user-facing catalog and `readme.txt` for version history.
- Note the architecture conventions you MUST follow for anything new:
  - One class per domain in `includes/tools/class-wpmcp-tool-<domain>.php`, registered in `wpmcp.php` (require + instantiate in `WPMCP_Plugin::register_tools()`).
  - Operation-dispatch style for wide domains (see `seo-read`/`seo-write`, `plugin-manage`, `db-read`) to keep tool count compact.
  - Pro gating via `'pro' => true` in the registration config; capability per tool; `'write' => true` for mutations; `'confirm' => true` for destructive ops.
  - Every write records a before-image through `$this->log->record(...)` and gets a rollback case in `class-wpmcp-tool-history.php`.
  - Availability guards (e.g. `WPMCP_Tool_Woo::available()`) when a tool depends on another plugin.

# Step 3 — Gap analysis deliverable

Produce a markdown table: competitor tool → ours (exists / partial / missing) → priority (high/medium/low) → proposed implementation sketch. Flag anywhere we already EXCEED the competitor (free SEO suite, rollback everywhere, compact mode, OAuth 2.1). Present this table and WAIT for approval before writing code.

# Step 4 — Implementation (after approval)

- Implement in priority order, high first. Match existing code style exactly (WordPress PHP, typed properties where present, sanitize everything, no comments unless the surrounding file uses them).
- After each domain: `php -l` every touched file, then extend/add coverage under `tests/` following `tests/e2e-system.mjs` conventions (self-contained .mjs, check() helper, cleanup of fixtures), and keep `tests/smoke.php` green.
- When all phases land: bump the version in `wpmcp.php` + `readme.txt` (stable tag + changelog entry), regenerate `docs/tools.md` from a live `tools/list` call, and rebuild the zip with `python` zipfile packaging (NOT Compress-Archive — backslash separator bug) or `build.ps1`.

# Hard rules

- Never break existing tools; the full test suite must stay green before claiming done.
- Destructive or security-sensitive tools always get confirm gates and are disabled-by-default writes.
- Do not touch `.wp-core/` — it is the local WordPress checkout used by wp-env.
