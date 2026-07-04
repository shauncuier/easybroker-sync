=== EasyBroker Sync ===
Contributors: domeluxmerida
Tags: real estate, easybroker, listings, sync, mexico
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cross-post WordPress property listings to EasyBroker, and pull collaboration
(partner) + own listings back into WordPress for display.

== Description ==

EasyBroker Sync connects a WordPress site to the EasyBroker real-estate CRM/MLS
(https://api.easybroker.com/v1). It supports a "cross-posting" workflow:

* Author properties in WordPress and push them to EasyBroker (Beta create/update
  property endpoints), which then syndicates them to connected portals.
* Pull your own EasyBroker listings (with full detail) into a self-contained
  "Property" post type for display, and record the roster of collaboration
  agencies you partner with.
* Keep everything fresh on a WP-Cron schedule plus a manual "Sync now" button.

Note: EasyBroker's public API endpoint GET /collaborations returns your partner
AGENCIES (id + name), not their listings — so the plugin cannot import
partner-agency listings. It imports your own listings and stores the agency
roster for reference.

= Key pieces =

* Custom post type `eb_property` with Operation / Property Type / Location taxonomies.
* `[eb_properties]` shortcode (attributes: limit, operation, type, collaboration).
* Bundled archive + single templates (theme templates override them).
* Admin settings page: API key + Test connection, currency, agent, image mode,
  pull-own toggle, sync frequency, manual sync buttons, and a sync log.

== Installation ==

1. Copy the `easybroker-sync` folder into `wp-content/plugins/`.
2. Activate "EasyBroker Sync" in Plugins.
3. Go to Properties → EasyBroker Sync and enter your API key (or define
   `EBS_API_KEY` in `wp-config.php`). Click "Test connection".
4. Configure currency, image handling and sync frequency, then save.

== Configuration notes ==

* API key: stored in the `ebs_settings` option, or override via a constant:
  `define( 'EBS_API_KEY', 'your-key' );` in wp-config.php (recommended).
* Image push: EasyBroker fetches images from public URLs, so media must be
  reachable from the internet (use a public/staging site, not pure localhost).
* Create/update property endpoints are Beta and may require EasyBroker to enable
  write access on your account/API key.

== Frequently Asked Questions ==

= Can the plugin display other agencies' (collaboration) listings? =
No. EasyBroker's API only returns the partner agencies you collaborate with, not
their individual listings, so those cannot be imported. The plugin imports your
own listings and records the agency roster.

= How do updates work? =
Editing and publishing a non-collaboration property queues a background push. If
the property already has an EasyBroker ID it is updated (PATCH); otherwise it is
created (POST) and the returned ID is stored.

== Changelog ==

= 0.5.0 =
* Added: Spanish property type aliases (casa, departamento, terreno, etc.) for Houzez mapping, with auto-alignment to canonical spellings.
* Added: Inline display of up to 10 validation/sync errors directly in the WordPress admin panel for bulk pushes.
* Improved: Auto-recreation of EasyBroker properties on update when the remote listing has been deleted (HTTP 404).
* Added: Negative caching transient for failed location lookups to avoid redundant API hits.
* Improved: Robust API diagnostics including HTTP status in error logs, recursive array stringification to prevent imploding fatals, and raw response parsing for non-JSON content.
* Optimized: Restrict bulk sync AJAX to only pull 'pending' status records, avoiding retrying failed posts repeatedly in the same run.
* Improved: Include the post title alongside its ID in the sync log.

= 0.4.0 =
* Houzez theme integration.
* Push existing Houzez properties (post type `property`, fave_* fields) to EasyBroker.
* Bulk “Sync all Houzez properties” with a title-matching link pass that updates
  existing EasyBroker listings instead of creating duplicates.
* EasyBroker imports now create native Houzez properties (theme UI) when Houzez
  is active — configurable via the “Import listings as” setting.
* EasyBroker Sync box on Houzez properties: status, Push now, location lookup,
  type/currency/agent overrides.
* EasyBroker status column in the Houzez property list table.
* Houzez location auto-resolution via the EasyBroker /locations endpoint.

= 0.3.0 =
* Added: concurrency locks — pull and push never overlap (cron vs. manual button race condition fixed).
* Added: push time budget — push_pending() stops before hitting max_execution_time (cron 45s, AJAX 20s).
* Fixed: PHP 8 safety — sanitize_amount() and numeric attribute mapping no longer fatal on malformed values.
* Added: ISO-4217 currency sanitizer (3 uppercase letters or empty); used in admin settings and field map.
* Security: meta-box save rejects non-scalar POST values; from_easybroker() skips non-scalar API values.
* Fixed: SSRF check now handles IPv6 bracket notation correctly.
* Improved: partial pagination errors are logged with page number and path so admins know results are incomplete.
* Improved: AJAX sync/push returns a clear "already running" message instead of a generic failure.
* Added: Update URI header set to `false` to prevent accidental updates from wordpress.org.
* Cleanup: uninstall.php removes lock options (ebs_lock_pull, ebs_lock_push).
* Dev: new tests for sanitize_amount, sanitize_currency, and non-numeric legacy meta handling.

= 0.2.0 =
* Security & hardening pass.
* Fixed: images are no longer re-sent on update (no more duplicates in EasyBroker).
* Fixed: agent is now sent as the account email (`agent`), not `agent_id`.
* Added: pre-flight validation before pushing (clear errors, no wasted API calls).
* Added: SSRF protection on image ingestion (scheme/host checks, allowlist filter, per-property cap).
* Added: HTTP 429/Retry-After handling and a max-retry cap.
* Added: per-property “Push to EasyBroker now” button and a DISABLE_WP_CRON notice.
* Security: settings option no longer autoloaded and not exposed via REST; API-key constant preferred.
* Dev: PHPCS (WordPress-Extra) config, Composer dev deps, PHPUnit tests, QA checklist.

= 0.1.0 =
* Initial release: push, pull, CPT, shortcode, templates, admin, scheduler.
