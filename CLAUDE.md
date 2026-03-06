# CLAUDE.md — RakunCMS (rkn/cms)

This file provides guidance to Claude Code when working in the `rakuncms/` submodule.

## Project Overview

RakunCMS (`rkn/cms`) is an open-source flat-file CMS built on a custom PHP micro-framework with PSR-7/11/15/16 compliance. Content lives as Markdown files with YAML frontmatter — no database required. Interactive components use Yoyo (htmx-based server-rendered reactivity). Designed for shared hosting (cPanel/Plesk).

## Architecture

- **Framework** (`src/Framework/`, namespace `Rkn\Framework`): ~800-1,200 lines of custom integration code connecting PSR packages. Internal implementation detail.
- **CMS** (`src/Cms/`, namespace `Rkn\Cms`): All business logic lives here, not in the framework.
- **Content Model**: Filesystem IS the site structure. `.md` files are the source of truth. No database.

**Core Stack**:
- PHP 8.2+ with Twig 3.x templates and Yoyo 0.14 reactive components
- `nyholm/psr7` (HTTP), `nikic/fast-route` (routing), `league/commonmark` (Markdown), `spatie/yaml-front-matter` (indexing)
- `phpmailer/phpmailer` (email), `symfony/console` (CLI)
- Custom: PhpFileCache (PSR-16), Translator (i18n), FileQueue (jobs)
- Total vendor/: ~4-5MB

## Reglas Fundamentales

### 1. tests_helper.sh — Edición y Ejecución

```
REGLA: Todo comando/script debe ir via /tests_helper.sh

SEQUENCE:
  1. Write tool: Escribir el comando/script en /tests_helper.sh (usar tool Write, NO Bash)
  2. Bash tool: bash tests_helper.sh (sin chmod, sin ./, sin argumentos extras)

PROHIBIDO:
  - chmod +x ./tests_helper.sh (innecesario)
  - ./tests_helper.sh (usar bash tests_helper.sh)
  - bash tests_helper.sh --algo (sin argumentos)
```

### 2. Scripting SOLO en PHP — NUNCA Python ni JavaScript

```
REGLA: Para scripting, procesamiento de datos y formateo de output,
       usar EXCLUSIVAMENTE PHP, de preferencia via herd php.

PERMITIDO:
  - herd php -r "..."
  - Cualquier comando PHP via herd

PROHIBIDO:
  - python3, python, python3 -m json.tool, python3 -c "..."
  - node, node -e "...", node -p "..."
  - Cualquier otro lenguaje de scripting (ruby, perl, etc.)
```

### 3. SIEMPRE usar MCP Playwright para pruebas de navegador

```
REGLA: No usar curl ni wget para verificar UI.
       Usar las herramientas MCP de Playwright que permiten interacción real con el navegador.

PERMITIDO:
  - mcp__playwright__browser_navigate, browser_snapshot, browser_click, etc.
  - curl SOLO para verificar APIs JSON (no páginas HTML)

PROHIBIDO:
  - curl http://localhost:8080/pagina (para verificar UI)
  - wget para descargar páginas
```

### 4. SIEMPRE usar `herd php` (nunca `php` directo)

```
PERMITIDO:
  - herd php vendor/bin/pest
  - herd php bin/rakun serve
  - herd php -r "..."
  - herd composer ...

PROHIBIDO:
  - php vendor/bin/pest (sin herd)
  - php bin/rakun (sin herd)
  - composer ... (sin herd)
```

## Commands

```bash
# Run tests (always via tests_helper.sh)
herd php vendor/bin/pest                        # Full suite
herd php vendor/bin/pest --filter="NombreTest"  # Specific test

# Static analysis
herd php vendor/bin/phpstan analyse src/

# CLI
herd php bin/rakun            # All CLI commands
herd php bin/rakun serve      # Dev server (localhost:8080)
herd php bin/rakun init       # Scaffold new site
```

## Development Conventions

- **KISS / DRY / YAGNI** — minimum complexity for the current task
- **Reutilize before creating** — search existing code with `rg` before writing new classes/services
- **No mocks/fakes/stubs** in production code — use real implementations
- **No Node.js paradigms** — use native PHP patterns (Jobs, Events, Queues)
- **Tests are requirements** — fix source code, never modify tests to make them pass
- **No `env()` outside `config/`** — always use `config('key.subkey')`
- **PHP recompiles every request** — never run `herd restart php` or `composer dump-autoload`

Never execute `find`, `grep`, `cat`, `ls` directly — use Claude Code's dedicated tools (Glob, Grep, Read).

## Key Reference Documents

| Document | When to consult |
|----------|----------------|
| `../docs/rakuncms-arquitectura-v2.md` | Architecture decisions, stack rationale |
| `../.claude/skills/directives-zero.md` | Mandatory conventions (always) |
| `../.claude/skills/testing-local.md` | Running tests locally |
| `../.claude/skills/fix-workflow.md` | Fixing failing tests |

## Bash Timeout

All Bash commands must use a 10-minute timeout (600000ms).
