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

### 3. SIEMPRE usar MCP Chrome DevTools para pruebas interactivas de navegador

```
REGLA: No usar curl ni wget para verificar UI de forma manual o interactiva.
       Usar las herramientas MCP de Chrome DevTools (`mcp__plugin_chrome-devtools-mcp_chrome-devtools__*`)
       para smoke tests manuales. Para pruebas E2E programáticas se usa Playwright
       SOLO como librería JS en specs bajo `src/test/e2e/`.

PERMITIDO:
  - mcp__plugin_chrome-devtools-mcp_chrome-devtools__navigate_page
  - mcp__plugin_chrome-devtools-mcp_chrome-devtools__take_snapshot
  - mcp__plugin_chrome-devtools-mcp_chrome-devtools__take_screenshot
  - Tests programáticos: `npx playwright test` con specs en `src/test/e2e/`
  - curl SOLO para verificar APIs JSON (no páginas HTML)

PROHIBIDO:
  - mcp__playwright__* (Playwright MCP prohibido para smoke tests manuales)
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

## Template Resolution

Cuando una request llega a una entry (`/{locale}/{collection?}/{slug}`), el `TemplateResolver` (`src/Cms/Template/TemplateResolver.php`) decide qué `.twig` rinde. Orden estricto, primera coincidencia gana:

1. **Frontmatter `template:`** del `.md` → `{valor}.twig`. Override por entry. WP imports usan esto: `template: "blog-post"` → `blog-post.twig`.
2. **`collections.{name}.default_template`** del `rakun.yaml` → `{valor}.twig`. Si el archivo NO existe, lanza `TemplateNotFoundException` (cero fallback silencioso — fue el footgun que motivó la hardening). Útil para colecciones cuyo render es un template explícito que NO sigue la convención de path.
3. **`templates/{collection}/{slug}.twig`** — override por path para una entry concreta.
4. **`templates/{collection}/show.twig`** — convención por colección.
5. **`templates/_layouts/{collection}.twig`** — convención por layout.
6. **`templates/_layouts/page.twig`** — fallback final hardcoded.

Reglas operativas:
- Si declaras `default_template` en el YAML pero el archivo no existe, el sitio cae en 500 con mensaje legible. Eso es intencional.
- Si quieres dejar que la convención decida (5), no declares `default_template`.
- El cambio se invalida tras `bin/rakun cache:clear` (templates compilados + page cache).

Tests del contrato: `tests/Cms/Template/TemplateResolverTest.php`.

## Cache & Deploy

RakunCMS tiene cinco niveles de cache que `bin/rakun cache:clear` purga atómicamente:

| Path | Qué guarda | Vida |
|---|---|---|
| `cache/templates/` | Templates Twig compilados (`.php`) | Hasta `cache:clear` |
| `cache/content-index.php` | Índice de entries (PHP array) | Hasta `cache:clear` o `index:rebuild` |
| `cache/pages/` | HTML completo por URI (10k+ archivos típicos) | Hasta `cache:clear` |
| `cache/data/` | PSR-16 generic store | Hasta `cache:clear` o TTL |
| `cache/dependencies.php` | Tracking de invalidación cruzada | Hasta `cache:clear` |

**`auto_reload=false` en producción** (`Engine.php:45`) es intencional: Twig no detecta cambios de fuente. La única invalidación canónica es `cache:clear`. En shared hosting (Plesk/cPanel sin SSH) se ejecuta vía CLI del panel o cron one-shot post-deploy.

**El comando acepta `--base`** para apuntar a un sitio específico desde cualquier cwd:
```bash
herd php vendor/bin/rakun cache:clear --base=/path/to/site
```

Sin `--base` usa `getcwd()` (comportamiento legacy preservado).

`PageCacheWriter` solo cachea: `GET` + `200 OK` + `Content-Type: text/html` + body no vacío. Nunca cachea `/api/*` ni `/yoyo*`. Contrato verificado en `tests/Cms/Middleware/PageCacheMiddlewareTest.php`.

SOP de deploy completo: `docs/sop/deploy-cache.md`.

## Release & Versioning

`rkn/cms` se distribuye vía Packagist. Convención obligatoria:

- **Tags estables (`vX.Y.Z`)** — para todo lo que un sitio consume vía `composer require rkn/cms:^X.Y` con `minimum-stability` por defecto (`stable`).
- **NO tags `-alphaN` / `-betaN` / `-rcN`** en `main` salvo coordinación explícita con el sitio. Composer ignora pre-releases bajo `minimum-stability: stable`; el síntoma es "el tag se publica en Packagist pero `composer update` no lo ve". Si el sitio quisiera consumirlos, tendría que añadir `minimum-stability: alpha` + `prefer-stable: true` o sufijo `@alpha` en la constraint — fricción operacional que no escala.
- **Si necesitas validar antes del release estable**: usa una rama (`next`, `wip-feature`) sin tag. El sitio puede pinear `dev-next` para probar. Cuando esté listo, merge a `main` y tag estable.
- **Cambios breaking** → bump de minor (`v1.6 → v1.7`) en track 1.x. Major (`v2.0`) requiere coordinación de migración con los sitios consumidores.
- **Hotfixes en una versión estable previa**: crea rama `release/1.6.x` desde el tag, fix, tag patch (`v1.6.7`). No retroactives a tags alpha.

Tags históricos `-alphaN` (v1.6.5-alpha1, v1.6.3-alpha1, etc.) son legacy de cuando se permitía esa convención. No replicar.

## Key Reference Documents

| Document | When to consult |
|----------|----------------|
| `../docs/rakuncms-arquitectura-v2.md` | Architecture decisions, stack rationale |
| `../.claude/skills/directives-zero.md` | Mandatory conventions (always) |
| `../.claude/skills/testing-local.md` | Running tests locally |
| `../.claude/skills/fix-workflow.md` | Fixing failing tests |

## Bash Timeout

All Bash commands must use a 10-minute timeout (600000ms).
