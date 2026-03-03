# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2026-03-03

### Added (Initial Release)

RakunCMS 1.0.0 marks the first stable release of the project. It is an ultra-fast flat-file CMS built on a custom PHP micro-framework, specifically designed for shared hosting environments (cPanel/Plesk) and optimized for maximum performance without a traditional database.

The following sections detail the architecture and features included in this milestone:

#### 🚀 Core Micro-Framework (`Rkn\Framework`)
- **PSR Compliance:** Full adherence to PSR-7 (HTTP messages), PSR-11 (Container), PSR-15 (Middleware Dispatcher), and PSR-16 (Simple Cache).
- **High-Performance Routing:** Integration with `nikic/fast-route` via a custom wrapper to handle static, dynamic, and catch-all routes for flat-file content resolution.
- **Twig Template Engine:** Native integration with `twig/twig` (v3.x) as a first-class citizen, featuring custom extensions for content access, translations, and asset management.
- **Middleware Pipeline:** Architecture driven by a PSR-15 pipeline to handle the request lifecycle: `ErrorHandler`, `PageCacheReader`, `LocaleDetector`, `ContentRouter`, `YoyoHandler`, and `PageCacheWriter`.

#### 📝 Flat-File Content Engine (`Rkn\Cms\Content`)
- **Markdown-Based Database:** Content is managed via `.md` files with YAML frontmatter (utilizing `league/commonmark` and `spatie/yaml-front-matter`).
- **OPcache-Optimized Indexer:** A content indexer that scans the `content/` directory, builds structured indices (by collection, tag, date, locale), and compiles them into a native PHP file using `var_export()` for <1ms memory lookups.
- **Fluent Query API (`Query`):** An immutable, modern Query Builder to search and filter content directly from the cached index (`collection()`, `where()`, `sort()`, `limit()`, `paginate()`).
- **Native Taxonomy System:** Out-of-the-box support for dynamic routing based on categories, tags, and date-based archives (year/month).

#### ⚡ Reactive Components (`Rkn\Cms\Components`)
- **Yoyo Integration (htmx):** Built-in support for stateful PHP/Twig components that update seamlessly without page reloads or custom JavaScript, powered by Yoyo and htmx (Livewire-style).

#### 🛡️ Multi-Level Caching
- **4-Layer Cache System:**
  1. Native PHP OPcache.
  2. Twig template compilation to PHP.
  3. Compiled content index (`var_export`).
  4. Full-Page Static Cache: Saves the final response as a `.html` file in `cache/pages/`, enabling 1-3ms response times served directly by the web server (Apache/Nginx).

#### 🌍 Native Internationalization (i18n)
- **Multi-Locale Content:** Automatic language resolution based on file suffixes (e.g., `post.md` for default, `post.en.md` for English).
- **Interface Translations:** Ultra-fast translation dictionaries using native PHP arrays in the `lang/` folder.
- **Language Detection:** Middleware-based detection via URL prefix, cookies, or the `Accept-Language` header.

#### 🔍 SEO, Analytics & WebMCP
- **Automated SEO Generators:** Built-in generation of Meta tags (Open Graph, Twitter Cards) and Schema.org JSON-LD structured data for Blog posts, Organizations, and Breadcrumbs.
- **GDPR Consent Manager:** Integrated system for conditional injection of Google Analytics and Facebook Pixel based on user consent (localStorage-based).
- **WebMCP Integration:** Site exposure as a context model (Web Model Context Protocol), allowing AI agents to structuredly interact with and read site content.

#### 🔎 Integrated Full-Text Search
- **DB-less Search Engine:** An inverted index generator (`SearchIndexer`) that tokenizes Markdown content for ultra-fast full-text searches, filtered by locale, without requiring external services or MySQL.

#### 🛠️ CLI Tool (`bin/rakun`)
- **Console Interface:** A robust CLI based on `symfony/console` including:
  - `rakun serve`: Local development server.
  - `rakun index:rebuild`: Full content index reconstruction.
  - `rakun cache:clear` / `cache:warmup`: Static and template cache management.
  - `rakun make:component` / `make:collection`: Rapid code scaffolding.
  - `rakun queue:process`: Background task processing.

#### 📨 Forms & Security
- **Multi-Layer Form Security:** Protection suite including HMAC-based CSRF tokens (stateless), invisible honeypots, temporal validation (rejection of automated submissions < 3s), and file-based IP rate limiting.
- **Email Integration:** Async delivery through `PHPMailer`, configurable via YAML to leverage local (cPanel) or external SMTP servers.

#### ⏳ Task Queues & Scheduling
- **Filesystem-Based Queues (`FileQueue`):** Async task processing (e.g., email delivery) via JSON jobs with atomic locking (`flock`), compatible with Cronjobs or web-request piggybacking.
- **Scheduled Publishing & Drafts:** Support for future publishing dates (`publish_date`) and cryptographic preview tokens for draft entries.

#### 💻 Developer Experience (DX)
- **Laravel Herd Integration:** Custom Valet driver (`RakunCmsValetDriver`) and `rakun herd:install` command for automatic site discovery on macOS.
- **Clean Architecture:** Total production dependencies kept under 5MB, ensuring a lightweight footprint for any hosting environment.
