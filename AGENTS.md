# AGENTS.md — GP Translate with OpenAI

## Project Overview

GlotPress plugin that adds OpenAI-powered machine translation. Extends GlotPress (as a WordPress plugin) with an AI translation button, configurable per-user or globally. Originally by meloniq, maintained in the Ultimate Multisite organization.

Default branch is `master`.

## Build Commands

```bash
composer install    # Install PHP dependencies (orhanerday/open-ai)
```

No npm build step. No minified assets.

## Lint Commands

```bash
vendor/bin/phpcs --standard=phpcs.xml .    # WordPress coding standards
vendor/bin/phpcbf --standard=phpcs.xml .   # Auto-fix violations
```

## Project Structure

```
gp-translate-with-openai/
├── gp-translate-with-openai.php   # Plugin entry point (namespace: Meloniq\GpOpenaiTranslate)
├── src/
│   ├── class-config.php           # Configuration handling
│   ├── class-locales.php          # Locale mapping for OpenAI
│   ├── class-translate.php        # Core translation logic
│   ├── class-admin-page.php       # Settings page
│   ├── class-settings.php         # Global settings
│   ├── class-profile.php          # Per-user settings on profile page
│   ├── class-frontend.php         # GlotPress frontend integration
│   └── class-ajax.php             # AJAX handlers for translation requests
├── assets/                        # CSS/JS for the GlotPress UI
├── .wordpress-org/                # WordPress.org plugin assets
├── phpcs.xml                      # PHPCS config (WordPress standard)
├── .editorconfig                  # Tabs, UTF-8, LF line endings
├── composer.json
└── readme.txt                     # WordPress.org readme
```

## Code Style & Conventions

- **PHP version**: >= 7.4
- **Coding standard**: WordPress (via `phpcs.xml`)
- **Namespace**: `Meloniq\GpOpenaiTranslate`
- **Indentation**: Tabs (per `.editorconfig`)
- **File naming**: `class-{name}.php` in `src/`
- **No autoloader for plugin classes** — manually `require_once`'d in setup function
- **Vendor autoloader** loaded for the `orhanerday/open-ai` dependency
- **Text domain**: `gp-translate-with-openai`

## Key Patterns

- Global `$gpoai_translate` array holds class instances
- Setup runs on `after_setup_theme` hook
- Per-user API keys override global key (set via user profile)
- AJAX-based translation triggered from GlotPress translation editor
- Uses OpenAI Chat Completions API via `orhanerday/open-ai` library
