# EasyBroker Sync — AI Agent Rules

## Project Overview
- **Type**: WordPress plugin (PHP)
- **Slug**: `easybroker-sync`
- **Main file**: `easybroker-sync.php`
- **GitHub repo**: `https://github.com/shauncuier/easybroker-sync`

## Release Process

When the user asks to release a new version, follow these steps **in order**:

### Step 1: Bump the version number in ALL 4 locations
The version string must be updated in exactly these files:

1. **`easybroker-sync.php`** line ~6 — the `Version:` header in the plugin docblock
2. **`easybroker-sync.php`** line ~21 — the `EBS_VERSION` constant
3. **`readme.txt`** line ~7 — the `Stable tag:` field
4. **`README.md`** — the Changelog section (add a new version entry at the top)

### Step 2: Update the Changelog
- Add a new version section to **`readme.txt`** under `== Changelog ==` (above existing entries).
- Add a matching section to **`README.md`** under `## 📝 Changelog`.
- Summarize the changes made since the last release.

### Step 3: Commit, tag, and push
```bash
git add -A
git commit -m "Release vX.Y.Z"
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin main --tags
```

> **Important**: The tag format MUST be `vX.Y.Z` (with the `v` prefix). The GitHub Actions workflow at `.github/workflows/release.yml` triggers on `v*` tags.

### Step 4: Verify the release
- Check `https://api.github.com/repos/shauncuier/easybroker-sync/actions/runs?per_page=1` to confirm the workflow is running.
- Check `https://api.github.com/repos/shauncuier/easybroker-sync/releases` to confirm the release was created with `easybroker-sync.zip` attached.
- The download URL will be: `https://github.com/shauncuier/easybroker-sync/releases/download/vX.Y.Z/easybroker-sync.zip`

## Project Structure
```
easybroker-sync/
├── easybroker-sync.php    # Main plugin file (version, constants, autoloader, hooks)
├── readme.txt             # WordPress.org-style readme (version, changelog)
├── README.md              # GitHub readme (installation, changelog)
├── uninstall.php          # Cleanup on plugin deletion
├── index.php              # Silence is golden
├── composer.json          # Dev dependencies only
├── phpcs.xml.dist         # PHPCS config (WordPress-Extra)
├── phpunit.xml.dist       # PHPUnit config
├── QA-CHECKLIST.md        # Manual QA checklist
├── .gitattributes         # Excludes dev files from release zip
├── .github/
│   └── workflows/
│       └── release.yml    # Auto-builds zip + creates GitHub Release on v* tag
├── assets/
│   ├── admin.css          # Admin styles
│   ├── admin.js           # Admin JS (AJAX, settings page)
│   ├── public.css         # Frontend property display styles
│   └── index.php
├── includes/
│   ├── class-plugin.php   # Main plugin orchestrator (singleton)
│   ├── class-admin.php    # Settings page, meta boxes, admin UI
│   ├── class-ajax.php     # AJAX handlers (sync now, push, test connection)
│   ├── class-api-client.php # EasyBroker API HTTP client
│   ├── class-cpt.php      # Custom post type + taxonomies registration
│   ├── class-field-map.php # WP↔EB field mapping for push/pull
│   ├── class-images.php   # Image download/attach with SSRF protection
│   ├── class-logger.php   # Ring-buffer sync log
│   ├── class-meta.php     # Meta boxes for property editing
│   ├── class-pull.php     # Pull listings from EasyBroker → WP
│   ├── class-push.php     # Push listings from WP → EasyBroker
│   ├── class-scheduler.php # WP-Cron scheduling
│   ├── class-shortcode.php # [eb_properties] shortcode
│   └── index.php
├── templates/
│   ├── archive-eb_property.php  # Property archive template
│   ├── single-eb_property.php   # Single property template
│   └── index.php
└── tests/
    ├── bootstrap.php
    ├── test-field-map.php
    ├── test-images.php
    └── index.php
```

## Coding Standards
- Follow **WordPress-Extra** PHPCS standards (see `phpcs.xml.dist`).
- Use the `EBS_` class prefix for all plugin classes.
- Autoloader maps `EBS_Some_Class` → `includes/class-some-class.php`.
- All option keys use `ebs_` prefix.
- Maintain existing comments and docstrings unless explicitly asked to change them.
