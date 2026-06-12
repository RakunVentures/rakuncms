# RakunCMS

**The flat-file PHP CMS that deploys to shared hosting in minutes.**

RakunCMS is a blazing fast, flat-file CMS built for shared hosting environments — powered by Markdown, Twig, Yoyo reactive components, and PSR standards. Content can live in flat Markdown files or a database — no heavyweight stack required.

[![Latest Stable Version](https://poser.pugx.org/rkn/cms/v/stable)](https://packagist.org/packages/rkn/cms)
[![Total Downloads](https://poser.pugx.org/rkn/cms/downloads)](https://packagist.org/packages/rkn/cms)
[![License](https://poser.pugx.org/rkn/cms/license)](https://packagist.org/packages/rkn/cms)

## Why RakunCMS?

We built RakunCMS as an alternative to CMSs like WordPress, Grav, Statamic, and Kirby. Our core philosophy is simplicity, performance, and accessibility. 

- **Flat-file or database**: content lives in Markdown files (folder = site structure) by default, or in MySQL via the opt-in content store — same Query API either way.
- **Runs on $3/mo shared hosting (cPanel/Plesk)**: Deploy effortlessly via FTP/cPanel. No persistent processes, no VPS required.
- **No Node.js or Docker required**: Everything runs in a pure PHP ecosystem.
- **Composer-installable**: Manage your site easily with Composer.
- **Reactive components without writing JavaScript**: Build interactive UIs with PHP components that update seamlessly without full page reloads, powered by Yoyo (htmx).
- **PSR-7/11/15/16 compliant**: Built on a custom micro-framework with under 5MB of total dependencies.

## Installation & Quick Start

Getting started with RakunCMS is incredibly fast:

```bash
# 1. Create a new site via Composer
composer create-project rkn/cms my-website
cd my-website

# 2. Initialize the site structure
php rakun init

# 3. Start the built-in development server
php rakun serve
```

Open `http://localhost:8080` in your browser to see your site running!

## Core Features

- **Flat-File Architecture (default)**: By default, everything is stored in Markdown and YAML — no MySQL configuration, no database backups. Opt in to a MySQL content store by setting `content.driver: mysql` in config, powered by `ContentStorageFactory` selecting between `FileContentStorage` and `MysqlContentStorage` via the `ContentStorage` interface. A SQLite-backed content index (set `index.driver: sqlite`) is also available for large collections where a PHP-array index would be memory-intensive.
- **Reactive Yoyo Components**: Build interactive, stateful PHP components (similar to Livewire) using HTMX under the hood.
- **Built-in SEO Engine**: Automatically generates Open Graph tags, JSON-LD markup, handles consent management, and integrates with WebMCP.
- **Full-Text Search**: Features an integrated inverted index for blazing fast search capabilities without needing external services like Algolia or Elasticsearch.
- **Native i18n**: Out-of-the-box support for multi-locale routing and content localization.
- **Multi-Level Caching**: Achieve 1-3ms response times in production using static HTML full-page caching coupled with OPcache.
- **CLI Tools**: Integrated `rakun` command-line tool to easily clear the cache, make components, or run background queue workers.
- **Static Export**: `bin/rakun build` renders every published entry to `dist/<url>/index.html` and generates `sitemap.xml`/`rss.xml`/`robots.txt` — source-agnostic and fully compatible with both `file` and `mysql` content stores. Deploy to any cheap or static host.
- **Optional Admin Panel**: `rakuncms-admin` is a separate, optional Laravel + Filament application that manages one or more RakunCMS sites over the HTTP content API. Flat-file sites can still be edited by hand or via Git with no admin panel required.

## Developer Experience (DX)
- **Laravel Herd Integration**: Includes a custom Valet driver (`RakunCmsValetDriver`) and the `php rakun herd:install` command for automatic site discovery and blazingly fast local development on macOS.
- **Clean Architecture**: Built on a highly optimized, custom PSR-compliant micro-framework. Total production dependencies are kept strictly under 5MB, ensuring a lightweight footprint for any hosting environment.

## How It Works

1. **Write Content**: Create or edit `.md` files directly in the `content/` directory.
2. **Customize Design**: Edit the `.twig` templates located in `templates/` to match your brand.
3. **Add Interactivity**: Build stateful Yoyo components in `src/Components/` with matching views in `templates/yoyo/`.

## Deployment — Plesk via Git + GitHub webhook

RakunCMS sites deploy cleanly on **Plesk** using Plesk's built-in **Git** integration pulling from
**GitHub**, with a **GitHub webhook** that triggers a deploy on every push. This is the canonical
reference for the RakunCMS ecosystem (the sites built on top of `rkn/cms` follow the same flow).

**Flow:** GitHub `push` → webhook → Plesk pulls the branch → Plesk runs the deployment actions
(`composer install` + cache/sitemap warmup) → the new code is live under the domain's document root.

### 1. Prepare the domain in Plesk
- Create or choose the subscription/domain that hosts the site.
- **Hosting Settings → PHP**: select **PHP 8.2+** (8.3 recommended) with `mbstring` enabled.
- Ensure the **Git** component is installed (Plesk → *Tools & Settings → Updates → Add Components → Git*).

### 2. Connect the GitHub repository — *Websites & Domains → Git → Add Repository*
- Repository URL:
  - Public repo: `https://github.com/<org>/<repo>.git`
  - Private repo: use SSH `git@github.com:<org>/<repo>.git` and add the **deploy key** Plesk shows to
    **GitHub → repo → Settings → Deploy keys** (read-only access is enough).
- **Branch:** `main`.
- **Deployment path:** the folder Plesk publishes to (e.g. the domain's `httpdocs`).

### 3. Point the document root at `public/`
RakunCMS serves from the `public/` front controller. Set **Hosting Settings → Document root** to
`<deployment-path>/public` (e.g. `httpdocs/public`).

### 4. Enable automatic deployment + actions
In the repository's settings in Plesk:
- **Deployment mode:** *Automatic* (deploy when the repository is updated) — this is what the webhook drives.
- **Additional deployment actions** (run after each pull, from the repo root):
  ```bash
  /opt/plesk/php/8.3/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader --no-interaction
  /opt/plesk/php/8.3/bin/php vendor/bin/rakun cache:clear
  /opt/plesk/php/8.3/bin/php vendor/bin/rakun cache:warmup
  /opt/plesk/php/8.3/bin/php vendor/bin/rakun sitemap:generate
  # If the site uses the SQLite/MySQL content index, also run:
  # /opt/plesk/php/8.3/bin/php vendor/bin/rakun index:rebuild
  ```
  > Use the **explicit Plesk PHP 8.3 binary** (`/opt/plesk/php/8.3/bin/php`) — the default `php` in
  > deployment actions can be an older version. Adjust the `composer.phar` path to your Plesk install
  > (or use a `composer` available on `PATH`).

### 5. Add the GitHub webhook
Plesk shows a **Webhook URL** for the repository once *Automatic deployment* is on. Copy it, then in
**GitHub → repo → Settings → Webhooks → Add webhook**:
- **Payload URL:** the Plesk webhook URL.
- **Content type:** `application/json`.
- **Which events:** *Just the push event*.
- Save. Pushing to `main` now calls Plesk and deploys automatically.

### 6. Writable paths & config
- Ensure `cache/` and `storage/` are writable by the subscription's web user.
- Site config lives in `config/rakun.yaml`; keep secrets (API keys, mail, payment keys) in environment
  variables / `.env` on the server — never commit them.
- First deploy of a brand-new site only: scaffold the structure with `php bin/rakun init`.

> RakunCMS also ships its own `deploy:*` commands (atomic FTP/SSH deploys for shared hosting without
> Git). The Plesk + GitHub webhook flow above is the recommended path when the host runs Plesk.

## Documentation

Full documentation, guides, deployment instructions, and API reference can be found at:  
[https://rakuncms.com/docs](https://rakuncms.com/docs)

## Contributing

We welcome all contributions from the community! If you're looking to help out, report bugs, or understand how RakunCMS works under the hood, please read our [Contributing Guide](CONTRIBUTING.md) which includes an in-depth **Architecture Overview**.

## License

GPL-3.0-or-later
