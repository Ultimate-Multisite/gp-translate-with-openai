# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin that adds OpenAI-powered translation to GlotPress. Supports OpenAI-compatible APIs (Ollama, etc.), glossary context, locale-specific instructions, async automation via Action Scheduler, quality testing against wordpress.org reference translations, and WP-CLI commands.

## Development Commands

```bash
# Check code standards (WordPress coding standards)
vendor/bin/phpcs

# Auto-fix code standard violations
vendor/bin/phpcbf

# Install dependencies
composer install
```

There are no automated tests (PHPUnit), no JS build step, and no CI/CD pipeline.

## Local Development Environment

Uses `@wordpress/env` (Docker-based) with GlotPress pre-installed.

```bash
# Start the environment
npx @wordpress/env start

# Stop
npx @wordpress/env stop

# Run WP-CLI commands
npx @wordpress/env run cli wp <command>

# Clean rebuild
npx @wordpress/env destroy
npx @wordpress/env start
```

- **Dev site:** http://localhost:8892 (admin / password)
- **Test site:** http://localhost:8893
- **GlotPress:** http://localhost:8892/projects/
- **Config:** `.wp-env.json` — ports, plugins, `GP_URL_BASE`, lifecycle scripts

Lifecycle scripts handle setup automatically on start:
- Permalinks set to `/%postname%/` with `.htaccess` (`--hard`)
- `gpoai_base_url` pointed at local Ollama (`http://127.0.0.1:11434`)
- Sample GlotPress project with es/de/fr translation sets and 10 originals (`bin/setup-sample-data.php`)
- Composer dependencies installed on first setup (`afterSetup`)

## Architecture

**Entry point:** `gp-translate-with-openai.php` — hooks `after_setup_theme`, loads all classes from `src/`.

**Namespace:** `Meloniq\GpOpenaiTranslate`

### Core Classes (in `src/`)

- **Config** — Settings hierarchy: per-user settings override global. Manages API key, model, temperature, system prompt (with template placeholders: `{SOURCE_LANGUAGE}`, `{TARGET_LANGUAGE}`, `{LOCALE_INSTRUCTIONS}`, `{GLOSSARY}`, `{NEIGHBORING_STRINGS}`, `{CONTEXT}`).
- **Translate** — Singleton. Core translation engine. Sends strings to OpenAI with neighboring strings context, glossary terms, and locale instructions. Handles single and batch translation.
- **Automation** — Hooks GlotPress import events, schedules batch translation jobs via Action Scheduler (group: `gpoai_automation`, hook: `gpoai_translate_batch`, batch size: 10).
- **Glossary** — Fetches GlotPress glossary entries (cached 24h via transients), matches terms in source text, formats for LLM prompt context.
- **Locale_Instructions** — Hardcoded translation guidelines for ~12 locales (es, de, fr, it, hu, zh, ko, ar, pt, tr, sv, ru).
- **Quality_Test** — Admin UI + AJAX for comparing plugin translations against wordpress.org human translations. Tracks similarity %, exact matches, duration, tokens.
- **Settings** — Registers WordPress settings fields (API key, base URL, model, prompt, temperature, glossary toggle, max concurrent requests, automation locales).
- **CLI** — WP-CLI `quality_test <locale>` command with debug logging support.
- **Frontend** — Injects "Translate with OpenAI" button into GlotPress translation UI.
- **Ajax** — `wp_ajax_gpoai_translate` and `wp_ajax_gpoai_refresh_models` endpoints.
- **API** — Model discovery with 1-hour cache. Filters for chat-capable models.

### Key Patterns

- **User/global config hierarchy** — Config class checks user meta before falling back to global options.
- **Singleton** — Translate class uses `instance()`.
- **Transient caching** — Models (1h), glossary entries (24h).
- **Action Scheduler** — Async batch processing for automation.
- **Debug callback** — Translate accepts a debug callback for CLI/logging output.
- **Direct GlotPress table queries** — Glossary and automation classes query GP tables directly via `GP::$glossary`, `GP::$glossary_entry`.

## Dependencies

- **PHP** >= 7.4, **WordPress** >= 4.9, **GlotPress** plugin required
- `orhanerday/open-ai` ^5.3 — OpenAI API client
- `woocommerce/action-scheduler` ^3.6 — Background job scheduling
