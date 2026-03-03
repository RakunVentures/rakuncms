# RakunCMS

**The flat-file PHP CMS that deploys to shared hosting in minutes.**

RakunCMS is a blazing fast, flat-file CMS built for shared hosting environments — powered by Markdown, Twig, Yoyo reactive components, and PSR standards. No database required.

[![Latest Stable Version](https://poser.pugx.org/rkn/cms/v/stable)](https://packagist.org/packages/rkn/cms)
[![Total Downloads](https://poser.pugx.org/rkn/cms/downloads)](https://packagist.org/packages/rkn/cms)
[![License](https://poser.pugx.org/rkn/cms/license)](https://packagist.org/packages/rkn/cms)

## Why RakunCMS?

We built RakunCMS as an alternative to CMSs like WordPress, Grav, Statamic, and Kirby. Our core philosophy is simplicity, performance, and accessibility. 

- **No database — pure Markdown**: Your content lives in simple Markdown files with YAML frontmatter. Your folder structure defines your site structure.
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

- **Flat-File Architecture**: No MySQL configuration, no database backups. Everything is stored in Markdown and YAML.
- **Reactive Yoyo Components**: Build interactive, stateful PHP components (similar to Livewire) using HTMX under the hood.
- **Built-in SEO Engine**: Automatically generates Open Graph tags, JSON-LD markup, handles consent management, and integrates with WebMCP.
- **Full-Text Search**: Features an integrated inverted index for blazing fast search capabilities without needing external services like Algolia or Elasticsearch.
- **Native i18n**: Out-of-the-box support for multi-locale routing and content localization.
- **Multi-Level Caching**: Achieve 1-3ms response times in production using static HTML full-page caching coupled with OPcache.
- **CLI Tools**: Integrated `rakun` command-line tool to easily clear the cache, make components, or run background queue workers.

## Developer Experience (DX)
- **Laravel Herd Integration**: Includes a custom Valet driver (`RakunCmsValetDriver`) and the `php rakun herd:install` command for automatic site discovery and blazingly fast local development on macOS.
- **Clean Architecture**: Built on a highly optimized, custom PSR-compliant micro-framework. Total production dependencies are kept strictly under 5MB, ensuring a lightweight footprint for any hosting environment.

## How It Works

1. **Write Content**: Create or edit `.md` files directly in the `content/` directory.
2. **Customize Design**: Edit the `.twig` templates located in `templates/` to match your brand.
3. **Add Interactivity**: Build stateful Yoyo components in `src/Components/` with matching views in `templates/yoyo/`.

## Documentation

Full documentation, guides, deployment instructions, and API reference can be found at:  
[https://rakuncms.com/docs](https://rakuncms.com/docs)

## Contributing

We welcome all contributions from the community! If you're looking to help out, report bugs, or understand how RakunCMS works under the hood, please read our [Contributing Guide](CONTRIBUTING.md) which includes an in-depth **Architecture Overview**.

## License

GPL-3.0-or-later
