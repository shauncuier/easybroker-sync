# EasyBroker Sync for WordPress

Cross-post WordPress property listings to [EasyBroker](https://www.easybroker.com/) and pull your own listings back into WordPress for display.

## ✨ Features

- **Push to EasyBroker** — Publish or update a WordPress property and it's automatically synced to EasyBroker (and its connected portals).
- **Pull from EasyBroker** — Import your own EasyBroker listings into a dedicated `eb_property` post type with full detail (images, pricing, location, etc.).
- **Custom Post Type** — `eb_property` with Operation, Property Type, and Location taxonomies.
- **Shortcode** — `[eb_properties]` to display listings anywhere (filterable by limit, operation, type, collaboration).
- **Bundled Templates** — Archive and single-property templates included; your theme can override them.
- **Admin Settings** — API key + test connection, currency, agent, image handling, sync frequency, manual sync, and a sync log.
- **WP-Cron Scheduling** — Automatic sync on a configurable schedule.
- **Security Hardened** — SSRF protection on image ingestion, rate-limit handling, pre-flight validation, and more.

## 📥 Installation

### From GitHub Releases (recommended)

1. Go to the [**Releases**](https://github.com/shauncuier/easybroker-sync/releases) page.
2. Download the latest **`easybroker-sync.zip`**.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**.
4. Choose the downloaded `.zip` file and click **Install Now**.
5. Activate the plugin.

### Manual

1. Clone or download this repository.
2. Copy the `easybroker-sync` folder into `wp-content/plugins/`.
3. Activate **EasyBroker Sync** in the Plugins admin page.

## ⚙️ Configuration

1. Go to **Properties → EasyBroker Sync** in the WordPress admin.
2. Enter your EasyBroker API key and click **Test connection**.
   - Alternatively, define `EBS_API_KEY` in `wp-config.php` (recommended for production):
     ```php
     define( 'EBS_API_KEY', 'your-api-key-here' );
     ```
3. Configure currency, image handling, and sync frequency, then **Save**.

## 📋 Requirements

- WordPress 6.0+
- PHP 7.4+
- An [EasyBroker](https://www.easybroker.com/) account with API access

## 📝 Changelog

### 0.3.0
- Added: concurrency locks — pull and push never overlap (fixes cron vs. manual button race)
- Added: push time budget so push never hits `max_execution_time`
- Fixed: PHP 8 safety — `sanitize_amount()` and numeric attributes no longer fatal on malformed values
- Added: ISO-4217 currency sanitizer (3 uppercase letters); used in admin settings and field map
- Security: meta-box save rejects non-scalar POST values; API import skips non-scalar values
- Fixed: SSRF check handles IPv6 bracket notation correctly
- Improved: partial pagination errors are logged; AJAX shows "already running" message
- Added: `Update URI: false` header to prevent accidental wordpress.org updates
- Cleanup: uninstall removes lock options
- Dev: new tests for `sanitize_amount`, `sanitize_currency`, and non-numeric legacy meta

### 0.2.0
- Security & hardening pass
- Fixed: images no longer re-sent on update (no more duplicates)
- Fixed: agent sent as account email, not `agent_id`
- Added: pre-flight validation, SSRF protection, HTTP 429 handling
- Added: per-property "Push to EasyBroker now" button
- Dev: PHPCS config, Composer dev deps, PHPUnit tests

### 0.1.0
- Initial release: push, pull, CPT, shortcode, templates, admin, scheduler

## 📄 License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
