# WP MCP Suite — Tool Reference

Generated from a live `tools/list` call. Up to 110 tools depending on what is installed and licensed: WooCommerce tools appear only with an active Pro license and WooCommerce; Elementor tools only when Elementor is active; Forms tools only with CF7/WPForms/Gravity Forms; MetaBox tools only with MetaBox; tools marked **pro** need a license.

Legend: **write** = mutates the site · **confirm** = requires `confirm: true` · **pro** = requires an active license.

All tools enforce a WordPress capability per call (usually `edit_posts`, `manage_options` or `manage_woocommerce`) on top of transport auth.


## Content

Posts, pages and terms.


### `list-posts`

List posts, pages or any public post type. Filter by status, search term, author. Returns id, title, status, type, date and permalink.

| Argument | Type / constraints |
|---|---|
| `post_type` | string; default: `"post"`; Post type slug, e.g. post, page |
| `status` | string; default: `"publish"`; publish, draft, pending, private, future, any |
| `search` | string; Search keyword matched against title and content |
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |
| `orderby` | string; one of: `date`, `modified`, `title`, `id`; default: `"date"` |
| `order` | string; one of: `asc`, `desc`; default: `"desc"` |


### `get-post`

Read one post in full: content (raw + rendered), excerpt, status, taxonomies, featured image, meta and the active SEO plugin data.

| Argument | Type / constraints |
|---|---|
| `id` | integer; Post ID **required** |
| `raw_content` | boolean; default: `true`; Include raw post_content |


### `create-post` **write**

Create a post, page or custom post type with title, HTML content, excerpt, status, slug, date, categories, tags and featured image.

| Argument | Type / constraints |
|---|---|
| `title` | string **required** |
| `content` | string; HTML body content |
| `excerpt` | string |
| `post_type` | string; default: `"post"` |
| `status` | string; one of: `draft`, `publish`, `pending`, `private`; default: `"draft"` |
| `slug` | string |
| `date` | string; ISO 8601 date, e.g. 2026-08-21T10:00:00 |
| `author_id` | integer |
| `categories` | array; Category IDs (posts) |
| `tags` | array; Tag IDs (posts) |
| `featured_image_id` | integer |


### `update-post` **write**

Update any subset of a post: title, content, excerpt, status, slug, categories, tags, featured image. Only passed fields change.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `title` | string |
| `content` | string |
| `excerpt` | string |
| `status` | string; one of: `draft`, `publish`, `pending`, `private`, `future`, `trash` |
| `slug` | string |
| `categories` | array |
| `tags` | array |
| `featured_image_id` | integer |


### `delete-post` **write** **confirm**

Trash a post (default) or delete permanently when force:true. Destructive; requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `force` | boolean; default: `false`; true bypasses trash and deletes permanently |
| `confirm` | boolean; Must be true to run this destructive tool **required** |


### `list-terms`

List categories, tags or any public taxonomy terms.

| Argument | Type / constraints |
|---|---|
| `taxonomy` | string; default: `"category"` |
| `search` | string |
| `per_page` | integer; default: `50` |


### `create-term` **write**

Create a category, tag or taxonomy term.

| Argument | Type / constraints |
|---|---|
| `name` | string **required** |
| `taxonomy` | string; default: `"category"` |
| `slug` | string |
| `description` | string |
| `parent_id` | integer |


### `list-post-types`

Registered public post types with labels, hierarchy flags and published counts.



### `list-taxonomies`

Registered taxonomies with labels and object types; optionally include their terms.

| Argument | Type / constraints |
|---|---|
| `include_terms` | boolean; default: `false`; Include each taxonomy terms list (name, slug, count) |


## Media

Media Library reads, metadata edits and SSRF-guarded image sideloads.


### `list-media`

List and search Media Library attachments. Filter by mime type and search term; newest first.

| Argument | Type / constraints |
|---|---|
| `search` | string; Matches title, alt text, caption and description |
| `mime_type` | string; e.g. image, image/jpeg, application/pdf |
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |


### `get-media`

Read a Media Library attachment: URL, all registered sizes, dimensions, alt text, title, caption, description and mime type.

| Argument | Type / constraints |
|---|---|
| `id` | integer; Attachment ID **required** |


### `update-media` **write**

Edit attachment metadata: alt text, title, caption, description. One call accessibility/SEO fix.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `alt_text` | string |
| `title` | string |
| `caption` | string |
| `description` | string |


### `delete-media` **write** **confirm**

Permanently delete an attachment and its files. Destructive; requires confirm:true. The before-image in the change ledger keeps the metadata.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `confirm` | boolean **required** |


### `sideload-image` **write**

Download an image from a public http(s) URL into the Media Library. SSRF-guarded: public hosts only, 10 MB cap, image types only.

| Argument | Type / constraints |
|---|---|
| `url` | string; Direct image URL **required** |
| `post_id` | integer; Optional post to attach to |
| `alt_text` | string |


### `search-images`

Search Openverse (Creative Commons) for free stock images. Returns direct URLs ready for sideload-image or add-stock-image. No API key required.

| Argument | Type / constraints |
|---|---|
| `query` | string; Search phrase, e.g. modern office interior **required** |
| `license` | string; Comma separated CC licenses, e.g. cc0,by,pdm. Default: all |
| `per_page` | integer; default: `12` |
| `page` | integer; default: `1` |


### `add-stock-image` **write**

Search Openverse and sideload the chosen result straight into the Media Library in one call. Pass query + index, or a direct image_url from search-images. License credit stored as caption.

| Argument | Type / constraints |
|---|---|
| `query` | string; Search phrase when using index |
| `index` | integer; default: `0`; Result index from the query |
| `image_url` | string; Direct result URL from search-images (overrides query/index) |
| `alt_text` | string |


### `resize-media` **write**

Scale or crop an image attachment in place. Keeps a .wpmcp-bak backup of the original file and records it in the change ledger - rollback-change restores the original pixels.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `mode` | string; one of: `scale`, `crop`; default: `"scale"` |
| `width` | integer; scale: max width; crop: exact width |
| `height` | integer; scale: optional max height; crop: required |
| `quality` | integer; default: `82` |


## Settings

Curated core settings with typed values.


### `get-settings`

Read core WordPress settings from a curated allowlist (general, reading, discussion, media) plus the active SEO plugin settings.

| Argument | Type / constraints |
|---|---|
| `keys` | array; Optional subset of setting keys |


### `update-settings` **write**

Batch-update allowlisted WordPress settings. Unknown keys are reported in skipped[]. Permalink changes flush rewrite rules.

| Argument | Type / constraints |
|---|---|
| `values` | object; Map of setting key to new value **required** |


## SEO

Unified SEO layer over Yoast, Rank Math, Slim SEO, AIOSEO, SEOPress, The SEO Framework or the native fallback, plus audits and generators.


### `seo-read`

Read SEO data through one unified field vocabulary regardless of which SEO plugin is active. Operations: get-post-seo, get-term-seo, get-settings, get-status. Active plugin: Native (no SEO plugin detected).

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `get-post-seo`, `get-term-seo`, `get-settings`, `get-status`; Omit to list available operations |
| `post_id` | integer; For get-post-seo |
| `term_id` | integer; For get-term-seo |
| `taxonomy` | string; default: `"category"`; For get-term-seo |


### `seo-write` **write**

Write SEO metadata through the unified field vocabulary: title, description, canonical, noindex, nofollow, focus_keyword, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image. Fields a plugin does not support are reported in unsupported[]. Operations: update-post-seo, update-term-seo.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `update-post-seo`, `update-term-seo`; Omit to list available operations |
| `post_id` | integer |
| `term_id` | integer |
| `taxonomy` | string; default: `"category"` |
| `fields` | object |


### `audit-page-seo`

Scored on-page SEO report computed from the real post content: title/meta presence and length, H1 count, heading hierarchy, image alt coverage, internal/external links, word count and target-keyword usage. Read-only, no AI cost.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `target_keyword` | string; Optional keyword to check usage for |


### `extract-keywords-from-content`

Most frequent meaningful words and two-word phrases from a post (stop-word filtered, no external service). Use to pick a target keyword before auditing or generating meta tags.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `limit` | integer; default: `20` |


### `generate-meta-tags` **write**

Propose an SEO title (<=60 chars) and meta description (<=155 chars) from the page content, keyword-front-loaded when target_keyword is given. Dry-run by default; apply:true writes them through the active SEO plugin.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `target_keyword` | string |
| `apply` | boolean; default: `false`; false returns a proposal only; true writes to the active SEO plugin |


### `generate-schema-markup` **write**

Generate JSON-LD structured data: Article, LocalBusiness, FAQPage, Product or auto-inferred. Dry-run by default; apply:true stores it and outputs it on the page front end (replaced in place on re-apply).

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `type` | string; one of: `auto`, `Article`, `LocalBusiness`, `FAQPage`, `Product`; default: `"auto"` |
| `business` | object; For LocalBusiness: name, address, phone, url, image, price_range, geo {latitude, longitude} |
| `faqs` | array; For FAQPage: [{question, answer}] |
| `apply` | boolean; default: `false` |


## History

Change ledger with before-image rollback.


### `list-changes`

Recent MCP-made changes, newest first. Filter by domain (content, media, settings, seo, redirects) or rolled_back state.

| Argument | Type / constraints |
|---|---|
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |
| `domain` | string; Optional domain filter |
| `rolled_back` | string; one of: `yes`, `no`; Optional rollback-state filter |


### `get-change`

One change entry in full, including its before-image (the data needed to undo it).

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |


### `rollback-change` **write** **confirm**

Undo a recorded change from its before-image: restores post fields, SEO metadata, media fields or settings. Destructive; requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `confirm` | boolean **required** |


## Redirects

Managed 301/302/307/308 redirects and a broken-link scanner.


### `redirect-read`

List configured redirects with hit counts.



### `redirect-write` **write**

Manage 301/302/307/308 redirects. Operations: add {from,to,code}, update {index,to,code,enabled}, delete {index}. Loop and duplicate protected; every change is logged.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `add`, `update`, `delete`; Omit to list operations |
| `from` | string; add: source path, e.g. /old-page/ |
| `to` | string; add: target URL or path |
| `code` | integer; one of: `301`, `302`, `307`, `308`; default: `301` |
| `index` | integer; update/delete: position from redirect-read list |
| `enabled` | boolean |


### `scan-broken-links`

Scan published content for broken links. Processes a bounded batch per call (default 10 posts) and returns a cursor for the next batch. Outbound requests: GET with short timeout, capped body read.

| Argument | Type / constraints |
|---|---|
| `batch_size` | integer; default: `10` |
| `cursor` | integer; Post offset returned by the previous call |
| `post_types` | array; default: `["post", "page"]` |
| `check_external` | boolean; default: `true` |


## Diagnostics

One-call page digest, performance and security audits.


### `get-page-snapshot`

One normalized digest of a post so an agent can reason about it from a single call: content outline, heading/word/link/image counts, images missing alt text, the active SEO plugin data with lengths, JSON-LD schema presence and structural warnings.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |


### `analyze-performance`

Read-only server and WordPress performance audit: PHP config, database size, autoloaded-options weight, post revisions, cron backlog, object cache, OPcache, plugin count. Scored 0-100 with A-F grade and ranked recommendations.



### `scan-security`

Read-only security audit: hardening checks (file editing, debug output, admin username, XML-RPC, version disclosure, HTTPS, security headers), outdated plugins/themes/core vs wordpress.org data, and a bounded scan for PHP files inside uploads. Scored 0-100 with A-F grade. No file contents are returned.

| Argument | Type / constraints |
|---|---|
| `scan_uploads_php` | boolean; default: `true`; Count .php files under wp-content/uploads (should be zero on healthy sites) |


## Blocks

Gutenberg block editing via path-addressed trees.


### `list-blocks`

Catalog of registered Gutenberg block types with categories and attribute names. Filter by search term.

| Argument | Type / constraints |
|---|---|
| `search` | string |
| `per_page` | integer; default: `50` |


### `get-block-schema`

Real attribute names, types and defaults for one block type, straight from its registration.

| Argument | Type / constraints |
|---|---|
| `name` | string; e.g. core/heading **required** |


### `get-post-blocks`

Parsed block tree of a post. Each node carries a numeric path (index per level) you pass back to insert/update/remove/move.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `max_depth` | integer; default: `4` |
| `include_html` | boolean; default: `false`; Include each block innerHTML (truncated) |


### `insert-block` **write**

Insert a block into a post at an optional path position (append by default). Provide the block name, attributes and its rendered HTML.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `name` | string; Block name, e.g. core/paragraph **required** |
| `attrs` | object |
| `content_html` | string; Rendered inner HTML, e.g. <p>Hi</p> |
| `path` | array; Optional insertion path; last int = sibling index. Omit to append. |


### `update-block-attrs` **write**

Merge attributes into a block identified by path (from get-post-blocks). Optionally replace its HTML too.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `path` | array **required** |
| `attrs` | object |
| `content_html` | string |


### `remove-block` **write** **confirm**

Delete a block by path. Requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `path` | array **required** |
| `confirm` | boolean **required** |


### `move-block` **write**

Move a block to another path/index. Moving into own subtree is refused as a no-op.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `path` | array; Source path **required** |
| `to_path` | array; Destination parent path + target index as last element **required** |


### `list-patterns`

Registered block patterns (prebuilt compositions). Filter by category or search term.

| Argument | Type / constraints |
|---|---|
| `search` | string; Substring match on name, title or description |
| `category` | string |


### `insert-pattern` **write**

Insert a registered block pattern into a post by name, at an optional path position.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `pattern_name` | string; e.g. core/query-standard-posts **required** |
| `path` | array; Optional insertion path; omit to append |


### `duplicate-block` **write**

Clone the block at an index path (with its inner blocks) and insert the copy immediately after it.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `path` | array **required** |


## Elementor

Elementor page building via id-addressed element trees. Registers only when Elementor is active.


### `elementor-status`

Detect Elementor version and whether it is usable over MCP. Read-only.



### `list-elementor-widgets`

Catalog of registered Elementor widgets (name, title, categories). Filter by search.

| Argument | Type / constraints |
|---|---|
| `search` | string |
| `per_page` | integer; default: `100` |


### `get-widget-schema`

Content controls of one Elementor widget: real setting names, types, labels, defaults.

| Argument | Type / constraints |
|---|---|
| `widget_type` | string; e.g. heading **required** |


### `get-page-structure`

Normalized Elementor element tree for a page: ids, types, widget names, summarized settings.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |


### `add-container` **write**

Append a flexbox container (or nest into an existing container) with settings. Returns the new element id.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `settings` | object |
| `parent_id` | string |
| `index` | integer |


### `add-widget` **write**

Insert a widget into a container with settings. Omit container_id to use the first container; creates nothing if the page has none.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `widget_type` | string; Widget name from list-elementor-widgets, e.g. heading **required** |
| `settings` | object |
| `container_id` | string |
| `index` | integer |


### `update-element` **write**

Merge settings into any element by id. Only passed settings change.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `element_id` | string **required** |
| `settings` | object **required** |


### `duplicate-element` **write**

Deep-clone an element subtree with fresh ids, placed right after the original. Styles travel with the copy via its own settings.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `element_id` | string **required** |


### `move-element` **write**

Re-parent or reorder an element by id. Moving inside its own subtree is refused.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `element_id` | string **required** |
| `parent_id` | string; New parent; omit to move to top level |
| `index` | integer |


### `remove-element` **write** **confirm**

Delete an element subtree by id. Destructive; requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `element_id` | string **required** |
| `confirm` | boolean **required** |


### `clear-page` **write** **confirm**

Remove every Elementor element from a page. Destructive; requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `confirm` | boolean **required** |


### `build-page` **write**

Composite: create a page (or reuse one) and build containers + widgets from a declarative JSON tree in a single call. structure = [{settings:{}, widgets:[{type, settings}]}]. Returns page id and all element ids.

| Argument | Type / constraints |
|---|---|
| `title` | string; Page title (creates a draft page) |
| `post_id` | integer; Existing page to rebuild instead of creating |
| `structure` | array; Container list **required** |
| `status` | string; one of: `draft`, `publish`; default: `"draft"` |
| `page_template` | string; one of: `canvas`, `full-width`, `default`; default: `"default"`; Elementor page layout: canvas = blank page (no theme chrome), full-width = theme header/footer kept, default = theme template. |


### `find-element`

Search a page for elements by widget type, element type or text inside settings. Returns matching element ids and settings previews.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `widget_type` | string; e.g. heading, button |
| `element_type` | string; one of: `container`, `widget` |
| `search_text` | string; Case-insensitive match in scalar settings values |


### `reorder-elements` **write**

Reorder the children of one container by passing their ids in the desired order. Unlisted children keep positions after the ordered ones.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `container_id` | string **required** |
| `order` | array **required** |


### `batch-update` **write** **pro**

Apply settings updates to many elements of one page in a single save. Each operation = { element_id, settings }.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `operations` | array **required** |


### `export-page`

Export a page's full Elementor data (elements + page settings) as JSON for backup or transfer.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |


### `import-template` **write** **pro**

Import an Elementor JSON structure into a page, replacing its content by default.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `template_json` | array; Elementor elements array (as produced by export-page) **required** |
| `replace_all` | boolean; default: `true`; false appends instead of replacing |


### `save-as-template` **write** **pro**

Save a page's Elementor content as a reusable template in the Elementor library.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `title` | string **required** |


### `apply-template` **write** **pro**

Append a saved Elementor library template's content to a page with fresh element ids.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `template_id` | integer; Elementor library post ID (from list-templates) **required** |


### `update-global-colors` **write** **pro**

Replace the site-wide Elementor color palette (system + custom colors). Affects every element using global colors.

| Argument | Type / constraints |
|---|---|
| `colors` | array **required** |


### `update-global-typography` **write** **pro**

Replace site-wide Elementor typography presets (primary/secondary/tertiary text etc.).

| Argument | Type / constraints |
|---|---|
| `typography` | array **required** |


### `update-page-settings` **write**

Merge settings into a page's Elementor document settings (layout template, custom_css, background etc). Only passed keys change.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `settings` | object **required** |
| `template` | string; one of: `canvas`, `full-width`, `default` |


### `global-classes` **write** **confirm** **pro**

Manage the Elementor Class Manager design system. Operations: list, create, update, delete (confirm), reorder. Requires Elementor with the atomic-elements experiment active.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list`, `create`, `update`, `delete`, `reorder` **required** |
| `label` | string; create: class label |
| `class_id` | string; update/delete/reorder target g-id |
| `styles` | object; create/update: props object e.g. {"color":"#f00"} |
| `order` | array; reorder: full ordered id list |
| `confirm` | boolean; delete only |


### `get-element-settings`

Full raw settings of one element by id (container or widget), including every stored key.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `element_id` | string **required** |


### `set-element-label` **write**

Set an element's Navigator label so it reads as a human name inside the Elementor editor panel.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `element_id` | string **required** |
| `label` | string **required** |


### `list-pages`

Lists posts/pages/CPTs built with Elementor, newest modified first.

| Argument | Type / constraints |
|---|---|
| `post_type` | string; default: `"page"` |
| `status` | string; default: `"publish"` |
| `search` | string |
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |


## WooCommerce

Store management. Free `woo-status` teaser; the six data tools require a Pro license and register only when WooCommerce is active.


### `woo-status`

Detect WooCommerce, its version and store counts (products, orders). Free; the six woo-* data tools require a Pro license.



### `list-products` **pro**

Catalog of WooCommerce products with type, price, stock and status. Filter by search term, status or category slug.

| Argument | Type / constraints |
|---|---|
| `search` | string; Matches title, SKU and content |
| `status` | string; one of: `publish`, `draft`, `pending`, `private`, `any`; default: `"any"` |
| `category` | string; Product category slug |
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |


### `get-product` **pro**

Full detail for one product: pricing, stock, categories, attributes summary.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |


### `update-product` **write** **pro**

Update pricing, stock, status or copy on a product. Only passed fields change. Recorded to the change ledger with a before-image.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `regular_price` | string |
| `sale_price` | string |
| `stock_quantity` | integer |
| `manage_stock` | boolean |
| `stock_status` | string; one of: `instock`, `outofstock`, `onbackorder` |
| `status` | string; one of: `publish`, `draft`, `pending`, `private` |
| `description` | string |
| `short_description` | string |


### `list-orders` **pro**

Recent WooCommerce orders with status, total and customer. Filter by status, date range or search.

| Argument | Type / constraints |
|---|---|
| `status` | string; auto-draft, pending, processing, on-hold, completed, cancelled, refunded, failed or any |
| `search` | string |
| `after` | string; Y-m-d or full date, created-at lower bound |
| `before` | string |
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |


### `get-order` **pro**

Full order detail: status, totals, customer, line items.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |


### `update-order` **write** **pro**

Change an order status (or customer note). Recorded to the change ledger; rollback restores the prior status.

| Argument | Type / constraints |
|---|---|
| `id` | integer **required** |
| `status` | string; one of: `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed` |
| `customer_note` | string |


## Users

User directory reads (admin-only) and Pro user management with admin-account protections.


### `user-read`

Read WordPress users. Operations: list-users (filter by role/search), get-user (profile detail; admins flagged, never off-limits to read). Admin-only.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list-users`, `get-user`; default: `"list-users"` |
| `role` | string; Filter by role slug, e.g. editor |
| `search` | string; Matches login, email and display name |
| `id` | integer; get-user: user ID |
| `per_page` | integer; default: `20` |
| `page` | integer; default: `1` |


### `user-write` **write** **pro**

Create or edit users. Creates are non-admin with an auto-generated password (returned once); edits never touch roles or passwords on admins. Requires manage_options.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `create-user`, `update-user` **required** |
| `username` | string; create-user only |
| `email` | string |
| `id` | integer; update-user only |
| `first_name` | string |
| `last_name` | string |
| `display_name` | string |
| `description` | string |
| `url` | string |
| `role` | string; Non-admin role slug; admin/edit_network roles refused |


## Plugins & Themes

Plugin and theme lifecycle. Write operations ship disabled; WP MCP Suite can never be managed through its own server.


### `plugin-manage` **write** **confirm**

Manage installed plugins. Operations: list, activate, deactivate, install (wordpress.org slug), update, delete (confirm:true). Write operations ship disabled; this plugin can never be deactivated or deleted over MCP.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list`, `activate`, `deactivate`, `install`, `update`, `delete` **required** |
| `plugin` | string; Plugin file path relative to wp-content/plugins, e.g. hello.php or elementor/elementor.php |
| `slug` | string; install: wordpress.org slug |
| `search` | string; list: filter by name substring |
| `confirm` | boolean; delete only |


### `theme-manage` **write** **confirm**

Manage themes. Operations: list, switch (activate an installed theme), delete (inactive themes only, confirm:true). Write operations ship disabled.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list`, `switch`, `delete` **required** |
| `theme` | string; Theme directory slug |
| `confirm` | boolean; delete only |


## Menus

Navigation menu reads, plus full menu management (items, locations, ordering).


### `menu-read`

Read navigation menus. Operations: list-menus, get-menu (nested item tree), list-locations, render (HTML).

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list-menus`, `get-menu`, `list-locations`, `render`; default: `"list-menus"` |
| `menu` | string; Menu ID, slug or name (get-menu / render) |
| `location` | string; Theme location slug (get-menu / render) |
| `depth` | integer; render: levels to include, 0 = all |


### `menu-write` **write** **confirm**

Manage navigation menus. Operations: create-menu, rename-menu, delete-menu (confirm), assign-location, unassign-location, add-item, update-item, delete-item, reorder-items. Write operations ship disabled.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `create-menu`, `rename-menu`, `delete-menu`, `assign-location`, `unassign-location`, `add-item`, `update-item`, `delete-item`, `reorder-items` **required** |
| `menu` | string; Menu ID, slug or name |
| `name` | string; create-menu / rename-menu: new name |
| `location` | string |
| `item_id` | integer |
| `title` | string; add-item / update-item: label |
| `url` | string; add-item custom URL |
| `object_id` | integer; add-item: post/page ID |
| `object` | string; add-item: post, page, custom, category |
| `parent_id` | integer; 0 = top level |
| `confirm` | boolean; delete-menu only |
| `order` | array; reorder-items: item ids in order |


## Filesystem

Read-only filesystem access inside the WordPress install; writes are Pro and refuse wp-config.php / .htaccess.


### `fs-read`

Read-only filesystem access inside the WordPress install. Operations: read-file (offset/limit for big files), list-directory (recursive up to 5 levels), search-files (bounded content grep). wp-config.php and .htaccess are refused.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `read-file`, `list-directory`, `search-files` **required** |
| `path` | string; Relative to the WordPress root |
| `offset` | integer; read-file: 1-based start line |
| `limit` | integer; read-file: number of lines |
| `recursive` | boolean; default: `false`; list-directory |
| `query` | string; search-files: substring (case-sensitive) |
| `extensions` | array; search-files: e.g. ["php","css"] |
| `max_results` | integer; default: `100`; search-files cap |


### `fs-write` **write** **confirm** **pro**

Create/overwrite a file or replace an exact string in one (both back up the original first), or delete a file (confirm:true). Refuses wp-config.php and .htaccess.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `write-file`, `edit-file`, `delete-file` **required** |
| `path` | string **required** |
| `content` | string; write-file: full new content |
| `search` | string; edit-file: exact string to find |
| `replace` | string; edit-file: replacement |
| `confirm` | boolean; delete-file only |


## Database

Read-only SQL and table inspection; parameterized row writes are Pro and protect users/options tables.


### `db-read`

Read-only database access. Operations: list-tables (sizes), describe-table (columns/keys), query (SELECT/SHOW/DESCRIBE/EXPLAIN only, results capped).

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list-tables`, `describe-table`, `query` **required** |
| `table` | string; Full table name, e.g. wp_posts |
| `sql` | string; query: read-only SQL |
| `limit` | integer; default: `100`; query: row cap |


### `db-write` **write** **confirm** **pro**

Parameterized row writes. Operations: insert-row, update-rows (equality WHERE required), delete-rows (confirm:true). Users/options tables are protected. Every write snapshots a before-image for rollback reference.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `insert-row`, `update-rows`, `delete-rows` **required** |
| `table` | string **required** |
| `data` | object; Column => value |
| `where` | object; Equality WHERE: column => value (non-empty) |
| `confirm` | boolean; delete-rows only |


## Accessibility

WCAG-oriented page audits with dry-run-first fixes for contrast and alt text.


### `audit-page-a11y`

WCAG-oriented accessibility audit of the rendered page: images without alt, heading order, generic link text, unlabeled form fields, lang attribute, and (for Elementor pages) text/background color-pair contrast. Returns a scored report.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |


### `fix-color-contrast` **write** **pro**

Propose adjusted text colors so failing pairs meet WCAG AA (4.5:1). Dry-run by default; apply:true rewrites the Elementor element colors. Every change lands in the change ledger.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `apply` | boolean; default: `false` |
| `target_ratio` | number; default: `4.5`; WCAG AA normal text = 4.5, large text = 3.0 |


### `add-alt-text-from-context` **write** **pro**

Propose alt text for images missing it, derived from filename, post title and nearest heading. Dry-run by default; apply:true writes alt text to the Media Library items.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `apply` | boolean; default: `false` |
| `limit` | integer; default: `20` |


## Themes

Active theme context and theme_mod management, child-theme generation (Pro).


### `theme-read`

Read the active theme: context (name, version, parent/child, block-theme, menu locations) and theme_mod values. Operations: get-theme-context, get-mods.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `get-theme-context`, `get-mods`; default: `"get-theme-context"` |
| `keys` | array; get-mods: only return these mod keys |


### `theme-write` **write** **confirm** **pro**

Write the active theme's settings: set-mods merges theme_mod values; create-child-theme generates and optionally activates a child theme (confirm:true). Pro only. Write ships disabled.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `set-mods`, `create-child-theme` **required** |
| `mods` | object; set-mods: key => value |
| `name` | string; create-child-theme: child theme name |
| `activate` | boolean; default: `false`; create-child-theme: activate after creation |
| `confirm` | boolean; create-child-theme only |

## Forms

Contact-form reads across CF7 / WPForms / Gravity Forms; entry management is Pro. Registers only when at least one supported form plugin is active.


### `forms-read`

Read contact-form data across installed form plugins (Contact Form 7, WPForms, Gravity Forms). Operations: providers, list-forms, get-form. Entries are not included in wave one.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `providers`, `list-forms`, `get-form`; default: `"list-forms"` |
| `provider` | string; one of: `cf7`, `wpforms`, `gravityforms`; Omit with operation=providers to list available providers |
| `form_id` | integer; get-form only |


### `forms-write` **write** **pro**

Manage form entries (WPForms, Gravity Forms). Operations: set-entry-status, delete-entry (confirm:true). Pro only.

| Argument | Type / constraints |
|---|---|
| `provider` | string; one of: `wpforms`, `gravityforms` **required** |
| `operation` | string; one of: `set-entry-status`, `delete-entry` **required** |
| `entry_id` | integer **required** |
| `status` | string; set-entry-status: provider-specific status word |
| `confirm` | boolean; delete-entry only |

## MetaBox

MetaBox custom-field reads and writes. Registers only when MetaBox is active.


### `metabox-read`

Read MetaBox data: list-field-groups, get-field-group, get-fields (values for one post).

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list-field-groups`, `get-field-group`, `get-fields`; default: `"list-field-groups"` |
| `group_id` | integer; get-field-group: group post ID |
| `post_id` | integer; get-fields: post ID |


### `metabox-write` **write** **pro**

Write MetaBox custom-field values for a post. Unknown field names are skipped; no deletes; ids immutable. Pro only.

| Argument | Type / constraints |
|---|---|
| `post_id` | integer **required** |
| `fields` | object **required** |

## Memory

Durable project memory: approved site guidance plus recent session summaries, so an agent does not re-guess context across sessions.


### `memory-read`

Read approved site guidance (guardrails, facts, conventions) and recent session summaries so the agent does not re-guess context. Operations: approved, sessions, pending (admins only).

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `approved`, `sessions`, `pending`; default: `"approved"` |
| `type` | string; Filter approved items by type: guardrail, fact, convention, instruction |


### `memory-write` **write** **pro** 

Propose durable guidance (stored pending until an admin approves), approve pending proposals, forget entries, or save a session summary. Admin-only.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `propose`, `approve`, `forget`, `save-session` **required** |
| `type` | string; one of: `guardrail`, `fact`, `convention`, `instruction`; propose only |
| `text` | string; propose: guidance text; save-session: summary text |
| `id` | string; approve/forget target id |

## Brand Kits

Bundled color + typography kits applied to the Elementor site kit. Requires Elementor.


### `brand-kits-list`

Bundled color + typography kits. Each apply snapshots the current Elementor kit first (rollback via the change ledger).



### `brand-kit-apply` **write** **pro**

Apply a bundled brand kit to the Elementor site kit (system colors + heading/body typography). Snapshots the previous values into the change ledger before writing.

| Argument | Type / constraints |
|---|---|
| `kit` | string; Kit slug from brand-kits-list **required** |

## Export & Restore

Git-friendly JSON mirrors of content under `uploads/wpmcp-exports/`, one file per post.


### `export-content` **write**

Export posts/pages to git-friendly JSON files (content, excerpt, slug, meta, terms) under uploads/wpmcp-exports/. One file per post.

| Argument | Type / constraints |
|---|---|
| `post_type` | string; default: `"page"` |
| `search` | string |
| `limit` | integer; default: `20`; maximum: `200` |


### `list-exports`

List exported JSON mirror files with sizes and dates.



### `restore-content` **write** **confirm**

Restore a post from its export file (creates or updates by slug). Overwrites the content of an existing post with the same slug. Destructive; requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `file` | string; File name from list-exports **required** |
| `confirm` | boolean **required** |

## Comments

Comment reads for editors and moderation writes (moderate_comments required).


### `comment-read`

List and read comments. Operations: list-comments (filter by status/post/search), get-comment. Author email is included only for users who can moderate.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list-comments`, `get-comment`; default: `"list-comments"` |
| `status` | string; one of: `all`, `hold`, `approve`, `spam`, `trash`; default: `"all"` |
| `post_id` | integer |
| `search` | string |
| `id` | integer; get-comment only |
| `per_page` | integer; default: `20`; maximum: `100` |
| `page` | integer; default: `1` |


### `comment-write` **write**

Moderate and create comments. Operations: create-comment (held for review by default), reply, set-status (approve/hold/spam/trash), delete (confirm:true; permanent). Status changes are recorded to the change ledger with the prior status and can be rolled back.

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `create-comment`, `reply`, `set-status`, `delete` **required** |
| `post_id` | integer; create-comment only |
| `parent_id` | integer; reply only: parent comment id |
| `id` | integer; set-status/delete target comment id |
| `content` | string; create-comment/reply body |
| `author_name` | string; create-comment only |
| `author_email` | string; create-comment only |
| `author_url` | string; create-comment only |
| `approve_now` | boolean; default: `false`; create-comment/reply: approve immediately instead of holding |
| `status` | string; one of: `approve`, `hold`, `spam`, `trash`; set-status only |
| `confirm` | boolean; delete only |

## Revisions

Post revision inspection and confirm-gated restore with ledger rollback.


### `revision-read`

List and inspect post revisions. Operations: list-revisions, get-revision (full revision fields side by side with the current values).

| Argument | Type / constraints |
|---|---|
| `operation` | string; one of: `list-revisions`, `get-revision`; default: `"list-revisions"` |
| `post_id` | integer; list-revisions only |
| `revision_id` | integer; get-revision only |


### `restore-revision` **write** **confirm**

Restore a post to a saved revision. The current title/content/excerpt/status are captured in the change ledger first and can be rolled back via rollback-change. Destructive; requires confirm:true.

| Argument | Type / constraints |
|---|---|
| `revision_id` | integer **required** |
| `confirm` | boolean **required** |

