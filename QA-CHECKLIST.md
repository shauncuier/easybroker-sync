# EasyBroker Sync — QA & Security Checklist

Run before every release. Requires a WordPress install with the plugin active and a **staging**
site with public URLs (EasyBroker fetches images by URL).

## Static analysis
- [ ] `composer install`
- [ ] `composer lint` (PHPCS, WordPress-Extra) → 0 errors
- [ ] Install & run **Plugin Check (PCP)** → 0 errors
- [ ] `find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l` → no syntax errors

## Unit tests
- [ ] `composer test` (PHPUnit against wordpress-tests-lib) → green
  - payload has required fields + `operations[0].active === true`
  - images sent on create, **not** on update
  - `agent` only set for a valid email
  - `validate()` flags missing fields
  - `from_easybroker()` sanitizes incoming values
  - `is_safe_remote_url()` blocks non-http(s), private/loopback hosts

## Security
- [ ] `wp option get ebs_settings` — key present but option **not autoloaded**
      (`wp db query "SELECT autoload FROM $(wp config get table_prefix)options WHERE option_name='ebs_settings'"` → `off`/`no`)
- [ ] API key never rendered in HTML source of the settings page
- [ ] Define `EBS_API_KEY` in wp-config → settings field locks, constant wins
- [ ] SSRF: temporarily point an image URL at `http://169.254.169.254/` → import rejected + logged
- [ ] Access control: each `wp_ajax_ebs_*` action returns 403 for a Subscriber and logged-out user
- [ ] Nonces: submitting the meta box / settings / AJAX with a bad nonce is rejected

## Functional — push (WordPress → EasyBroker)
- [ ] Create a property, fill required fields, use the **Location lookup**, publish
- [ ] Sync status → `synced`, `eb_public_id` stored, appears in EasyBroker dashboard
- [ ] Correct **agent** assigned (from per-post email or default)
- [ ] Edit + republish twice → EasyBroker record updates, **no duplicate images** (PATCH omits images)
- [ ] Missing a required field → status `error` with a clear message, **no API call made**
- [ ] After 5 consecutive failures the listing stops auto-retrying; **Push now** resets and retries

## Functional — pull (EasyBroker → WordPress)
- [ ] **Import from EasyBroker** creates own listings with description, gallery, location
- [ ] Multi-page accounts import fully (paginator follows `next_page`)
- [ ] Collaboration agencies appear in the roster table
- [ ] Re-import does not clobber locally edited own listings

## Houzez integration (when the Houzez theme is active)
- [ ] Properties list table shows the **EasyBroker** column (status badge + EB ID)
- [ ] Each Houzez property editor shows the **EasyBroker Sync** side box
- [ ] **Bulk sync** (Properties → EasyBroker Sync → "Sync all Houzez properties"):
  - link pass connects title-matching listings (check Sync Log for "Linked existing…")
  - linked listings **PATCH** the existing EB record (no duplicate created in EasyBroker)
  - unlinked listings are pushed as new; failures appear in the Sync Log with reasons
- [ ] Manual **EasyBroker ID** field links a listing; next push updates that EB record
- [ ] Location auto-resolves from Houzez City/State; unresolvable → clear error + lookup works
- [ ] Property type maps via alias (Land→Lot etc.); unmappable → clear error + override works
- [ ] Price with `USD` postfix pushes as USD; otherwise the default currency
- [ ] **Import from EasyBroker** creates a native Houzez property (price, beds/baths,
      gallery in `fave_property_images`, For Sale/For Rent status, map coordinates)
      that renders correctly in the theme's card + detail UI
- [ ] Re-running import does not duplicate (matches by EB ID, then by title)

## Resilience
- [ ] Set `DISABLE_WP_CRON` → warning notice shown; **Push now** / **Import** still work
- [ ] Simulate HTTP 429 (mock) → request honors `Retry-After` once, then errors gracefully

## Front-end
- [ ] `[eb_properties]` grid renders; single + archive templates display correctly
- [ ] All output escaped; no PHP notices with `WP_DEBUG` on
