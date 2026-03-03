# Contributing to RakunCMS

Thank you for considering contributing to RakunCMS! Our goal is to provide a fast, flexible, and easy-to-use flat-file CMS for shared hosting environments. We welcome all contributions, from bug reports and feature requests to code patches and documentation improvements.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Pull Requests](#pull-requests)
- [Development Guide](#development-guide)
  - [Prerequisites](#prerequisites)
  - [Setting up the Development Environment](#setting-up-the-development-environment)
  - [Coding Standards](#coding-standards)
  - [Running Tests](#running-tests)
- [Architecture Overview](#architecture-overview)
  - [Core Micro-Framework](#core-micro-framework)
  - [CMS Module Architecture](#cms-module-architecture)
  - [Database (Optional)](#database-optional)
  - [Caching](#caching)
  - [SEO & WebMCP](#seo--webmcp)
  - [Background Queue](#background-queue)
  - [CLI Tool (`rakun`)](#cli-tool-rakun)

---

## Code of Conduct

By participating in this project, you agree to abide by our Code of Conduct. Please read it before contributing.

---

## How Can I Contribute?

### Reporting Bugs

If you find a bug, please open an issue on GitHub. Include as much detail as possible, such as:

*   **RakunCMS version:** e.g., v1.0.0
*   **PHP version:** e.g., 8.2
*   **Environment:** e.g., cPanel, local Docker, etc.
*   **Steps to reproduce:** A clear, step-by-step guide to reproduce the issue.
*   **Expected behavior:** What you expected to happen.
*   **Actual behavior:** What actually happened, including error messages or stack traces.

### Suggesting Enhancements

We are always open to new ideas. If you have a feature request or an idea for an enhancement, please open an issue and use the "Feature Request" label (if available) or prefix the title with `[Feature]`.

Describe your idea clearly, explaining why it would be beneficial and providing any relevant examples or use cases.

### Pull Requests

1.  **Fork the repository** and create your branch from `main` (or the appropriate feature branch).
2.  **Ensure your code follows our coding standards** (see below).
3.  **Write tests** for your changes, if applicable. We aim for high test coverage.
4.  **Make sure the test suite passes** before submitting your pull request.
5.  **Write clear and descriptive commit messages.**
6.  **Update documentation** if your changes affect how users interact with the CMS.
7.  **Submit your pull request** with a detailed description of your changes, the problem they solve, and any relevant issue numbers.

---

## Development Guide

### Prerequisites

*   PHP 8.2 or higher
*   Composer

### Setting up the Development Environment

1.  Fork and clone the repository:
    ```bash
    git clone https://github.com/YOUR_USERNAME/rakuncms.git
    cd rakuncms
    ```
2.  Install dependencies:
    ```bash
    composer install
    ```
3.  Set up the environment file (optional, if you need specific settings):
    ```bash
    cp .env.example .env
    ```

### Coding Standards

*   We follow the **PSR-12** coding standard.
*   Use clear and descriptive variable and function names.
*   Write inline documentation (DocBlocks) for classes, methods, and complex logic.
*   Before committing, you can run static analysis tools if configured (e.g., PHPStan).

### Running Tests

We use PHPUnit for testing. To run the test suite:

```bash
composer test
```
or
```bash
./vendor/bin/phpunit
```

---

## Architecture Overview

RakunCMS is built on a custom micro-framework designed to be lightweight, fast, and PSR-compliant. It avoids heavy dependencies like Symfony HTTP Foundation or Illuminate Support, opting for minimal PSR implementations where possible.

### Core Micro-Framework

The core of the framework is located in `src/Framework/`.

#### Directory Structure

```
rakuncms/
├── src/
│   ├── Cms/
│   │   ├── Cache/
│   │   ├── Cli/
│   │   ├── Content/
│   │   ├── Events/
│   │   ├── Herd/
│   │   ├── Http/
│   │   ├── I18n/
│   │   ├── Integrations/
│   │   ├── Mail/
│   │   ├── Mcp/
│   │   ├── Middleware/
│   │   ├── Queue/
│   │   ├── Search/
│   │   ├── Seo/
│   │   └── Template/
│   └── Framework/
│       ├── Application.php
│       ├── Container.php
│       ├── Dispatcher.php
│       ├── helpers.php
│       ├── NotFoundException.php
│       └── Router.php
```

#### Application Cycle

1.  **`index.php`**: Creates the container and starts the Application.
2.  **`Application::run()`**: Gets the Request (ServerRequestInterface), passes it to the Dispatcher.
3.  **`Dispatcher::dispatch()`**: Executes the middleware pipeline and finally the Router.
4.  **`Router::dispatch()`**: Matches the route and executes the controller or action.
5.  **Controller/Action**: Returns a Response (ResponseInterface).
6.  **`Dispatcher`**: The middleware pipeline processes the output.
7.  **`Application::run()`**: Sends the response to the browser (using `SapiEmitter`).

#### Core Components

*   **`Container`**: Implements `psr/container`. Handles dependency injection. Core services are bound here.
*   **`Router`**: Defines and matches routes. Supports parameters (`{id}`). Maps routes to Closures or Controller classes.
*   **`Dispatcher`**: Implements `psr/15` middleware. Manages the execution pipeline.

#### Middleware Pipeline (Example)

1.  `ErrorHandlerMiddleware`
2.  `MaintenanceMiddleware`
3.  `SessionMiddleware`
4.  `SecurityHeadersMiddleware`
5.  `RouterMiddleware` (Ends the pipeline if route matched)

#### Service Providers (Herd)

RakunCMS uses a "Herd" pattern (similar to Service Providers in Laravel). These classes register services in the container.

*   `CoreHerd`: Registers Router, View, EventDispatcher.
*   `CmsHerd`: Registers CMS specific services (ContentManager, ThemeService).
*   `MailHerd`: Registers Mailer.
*   `DbHerd` (Optional): Registers Database connection.

---

### CMS Module Architecture

The CMS part of RakunCMS (inside `src/Cms/`) provides the features for managing content via Flat-Files.

#### Content Manager

Responsible for reading, parsing, and caching Markdown files.

*   **`ContentRepository`**: Interface for accessing content.
*   **`FlatFileContentRepository`**: Implementation reading from `content/`. Uses `Spatie\YamlFrontMatter` to parse metadata.
*   **`MarkdownParser`**: Converts Markdown to HTML (using `Parsedown` or similar).

#### Yoyo (Reactive Components)

RakunCMS includes a reactive component system called Yoyo (inspired by Livewire, using HTMX).

*   **`YoyoComponent`**: Base class for components.
*   **`YoyoManager`**: Handles Yoyo requests and rendering.

#### Templating (Twig)

*   Uses Twig as the template engine.
*   The `View` service wraps Twig Environment.
*   RakunCMS provides custom Twig extensions for Yoyo, SEO, and CMS helpers.

---

### Database (Optional)

While primarily Flat-File, RakunCMS can support a database (e.g., SQLite, MySQL) via PDO. This is useful for plugins or complex features (like forms or user management).

---

### Caching

*   **File Cache**: Basic caching using file system.
*   **OPcache**: Recommended for production.
*   **Full Page Cache**: Optional middleware to cache the entire HTML response for blazing fast speeds.

---

### SEO & WebMCP

*   **WebMCP**: RakunCMS integrates with the WebMCP protocol to provide context to AI agents.
*   **SEO Service**: Manages Open Graph, JSON-LD, meta tags based on content frontmatter.

---

### Background Queue

RakunCMS includes a simple file-based queue system for background tasks (e.g., sending emails, indexing search).

*   `storage/queue/pending`
*   `storage/queue/processing`
*   `storage/queue/failed`

---

### CLI Tool (`rakun`)

Provides commands for generating components, clearing cache, running queue workers.

*   `php rakun make:component Name`
*   `php rakun cache:clear`
*   `php rakun queue:work`
