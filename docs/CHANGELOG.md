# Changelog

All notable changes to RakunCMS are documented in this file.

## [Unreleased] - 2026-02-28

### Added

#### SEO System (4 new generators + Twig extension)

- **`MetaTagGenerator`** (`src/Cms/Seo/MetaTagGenerator.php`) — Pure class that generates HTML meta tags from context + config: `<meta name="description">`, keywords, author, robots, canonical `<link>`, Open Graph (og:title, og:description, og:url, og:type, og:image, og:locale, og:site_name), Twitter Cards, hreflang alternates, and Google/Bing site-verification tags.
- **`JsonLdGenerator`** (`src/Cms/Seo/JsonLdGenerator.php`) — Generates `<script type="application/ld+json">` blocks for WebSite (with SearchAction), Organization, LocalBusiness (with PostalAddress), BreadcrumbList, and BlogPosting schemas. Config-driven.
- **`ConsentManager`** (`src/Cms/Seo/ConsentManager.php`) — GDPR/cookie consent for Google Analytics and Facebook Pixel. Renders analytics scripts inside `<template data-consent>` blocks (never executed until user accepts). Fixed-position banner with Accept/Reject buttons, inline JS with `localStorage('rkn_consent')`.
- **`WebMcpGenerator`** (`src/Cms/Seo/WebMcpGenerator.php`) — W3C Web Model Context Protocol integration. Registers four tools via `navigator.modelContext.registerTool()`: `site_search`, `site_navigation`, `list_content`, `current_page`. Feature-detected, silent on non-AI browsers.
- **`SeoExtension`** (`src/Cms/Template/Extensions/SeoExtension.php`) — Twig extension exposing 5 functions: `seo_head()`, `seo_jsonld()`, `seo_consent()`, `seo_analytics()`, `seo_webmcp()`. All marked `is_safe: html`. Degrades gracefully outside full app context.

#### Content Query & Pagination

- **`Query`** (`src/Cms/Content/Query.php`) — Fluent immutable query builder over the content index. Supports `collection()`, `locale()`, `where(field, operator, value)` with operators `=`, `===`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `in`, `has`. Also `sort()`, `limit()`, `offset()`, `get()`, `first()`, `count()`, `findBySlug()`.
- **`Paginator`** (`src/Cms/Content/Paginator.php`) — Wraps a `Query` with `$perPage` and `$currentPage`. Exposes `items()`, `currentPage()`, `totalPages()`, `totalItems()`, `hasNextPage()`, `hasPreviousPage()`, `nextPageUrl()`, `previousPageUrl()`, `pageUrl(int)`.

#### Taxonomy System

- **`TaxonomyRouter`** (`src/Cms/Content/TaxonomyRouter.php`) — Resolves URL segments to taxonomy queries. Recognizes patterns: `/{collection}/tag/{tag}`, `/{collection}/category/{category}`, `/{collection}/archive/{year}`, `/{collection}/archive/{year}/{month}`. Returns structured array with pre-filtered `Query`.
- **`TaxonomyGenerator`** (`src/Cms/Content/TaxonomyGenerator.php`) — Enumerates all static taxonomy pages for build. Iterates `indices.by_tag` and `indices.by_date`, cross-products with configured locales.

#### Draft Preview

- **`DraftResolver`** (`src/Cms/Content/DraftResolver.php`) — Token-based draft preview. `isValidToken()` uses `hash_equals` against `config('preview.token')`. `findDraft()` scans `.md` files for `draft: true` entries. `injectDraftBanner()` prepends an amber "DRAFT PREVIEW" bar.

#### Scheduled Publishing

- **`ScheduleChecker`** (`src/Cms/Content/ScheduleChecker.php`) — Handles future publishing via `publish_date` frontmatter. `shouldPublish()`, `isScheduled()`, `findPublishableEntries()` scan the content filesystem directly.

#### Full-Text Search

- **`SearchIndexer`** (`src/Cms/Search/SearchIndexer.php`) — Builds and caches an inverted full-text search index at `cache/search-index.php`. Strips Markdown/HTML, tokenizes (MB-safe), filters stop words (EN/ES), deduplicates. Produces flat `entries` map + `inverted` word-to-keys map. Public `tokenize()` for query-time consistency. `exportJson()` for client-side integration.

#### Events & Webhooks

- **`Event`** (`src/Cms/Events/Event.php`) — PSR-14-style event value object with `name`, `payload`, and `stopPropagation()` support.
- **`EventDispatcher`** (`src/Cms/Events/EventDispatcher.php`) — In-process event bus. `listen()`, `dispatch()`, wildcard `*` listeners, `stopPropagation` respected.
- **`WebhookListener`** (`src/Cms/Events/WebhookListener.php`) — Pushes `webhook` jobs to `FileQueue` on event dispatch. Includes target URL, JSON body, optional headers, HMAC-SHA256 signature. Static factory `registerFromConfig()` wires from config.

#### ContentExtension — New Twig Functions

- `search(query, limit)` — Full-text search via SearchIndexer + SearchEngine, locale-filtered.
- `request_param(key, default)` — Exposes `$_GET` query parameters to templates.
- `unique_tags(collectionName)` — Collects all unique tags from a collection, sorted.
- `paginate(query, perPage)` — Wraps Query in Paginator for template-side pagination.

#### Tests (19 new test files, 329 total tests passing)

- `tests/Cms/Seo/MetaTagGeneratorTest.php`
- `tests/Cms/Seo/JsonLdGeneratorTest.php`
- `tests/Cms/Seo/ConsentManagerTest.php`
- `tests/Cms/Seo/WebMcpGeneratorTest.php`
- `tests/Cms/Seo/IntegrationTest.php`
- `tests/Cms/Template/Extensions/SeoExtensionTest.php`
- `tests/Cms/Content/PaginatorTest.php`
- `tests/Cms/Content/QueryTest.php`
- `tests/Cms/Content/TaxonomyRouterTest.php`
- `tests/Cms/Content/TaxonomyGeneratorTest.php`
- `tests/Cms/Content/ScheduleCheckerTest.php`
- `tests/Cms/Content/DraftResolverTest.php`
- `tests/Cms/Search/SearchIndexerTest.php`
- `tests/Cms/Search/SearchEngineTest.php`
- `tests/Cms/Events/EventDispatcherTest.php`
- `tests/Cms/Events/WebhookListenerTest.php`
- `tests/Cms/Cli/BackupCommandsTest.php`
- `tests/Cms/Cli/BoostInstallCommandTest.php`
- `tests/Cms/Http/Controllers/ContentApiControllerTest.php`

### Changed

#### Entry.php — Tags Support

- Added `private array $tags = []` constructor parameter with `@param list<string>` docblock.
- `fromArray()` now reads `$data['tags'] ?? []`.
- `toArray()` now includes `'tags' => $this->tags`.
- New public accessor `tags(): array`.

#### Engine.php — SeoExtension Registration

- Added `$twig->addExtension(new SeoExtension())` alongside existing CMS extensions.

#### InitCommand.php — SEO Scaffold

- `getConfigContent()` now emits `seo:` block in `config/rakun.yaml` with commented-out keys for site_name, default_image, twitter_handle, google_analytics, facebook_pixel, verification, organization, local_business.
- `getBaseTwigContent()` now includes `{{ seo_head() }}` in `<head>`, `{{ seo_webmcp() }}` and `{{ seo_consent() }}` before `</body>`.
- New `getSeoPartialContent()` method scaffolds `_partials/seo.twig` as a reference template.

### Fixed

- **`ServeCommand.php`** — Added `use Symfony\Component\Console\Helper\QuestionHelper` import and `@var QuestionHelper` annotation to resolve pre-existing PHPStan error where `getHelper('question')` returns `HelperInterface` but `->ask()` only exists on `QuestionHelper`.
- **`SearchEngine.php`** — Added `$indexWord = (string) $indexWord` cast in prefix-match loop. PHP's `var_export()`/`require` converts numeric string array keys to integers, causing `str_starts_with()` TypeError under strict types.

### Quality

- **329 tests passing** (PestPHP)
- **0 PHPStan errors**
