# Outreach Tracker

A small CRM inside WordPress admin for tracking business outreach: who you contacted, when, what they said, and when to follow up. I built it for my own use because a spreadsheet stopped working once the list got past a few hundred rows.

It's a single-file plugin, about 600 lines.

## What it does

- Add businesses with contact details, zone, city, industry and notes.
- Status pipeline: Not sent → Sent → Follow-up 1 → Follow-up 2 → Replied → Demo sent → Won, plus a Do Not Contact status.
- A "Follow-ups due" list, a search box, and filters by zone and status.
- Works on mobile, so I can update it right after a call.
- All data stays in the site's own database.

## How it's built

- **Custom table:** `{prefix}outreach_businesses`, created with `dbDelta()` on activation.
- **REST API:** routes registered on `rest_api_init`. The admin screen talks to them with `fetch()`, so there are no full page reloads.
- **Permissions:** every route checks `manage_options` through a `permission_callback`, and requests carry the REST nonce (`X-WP-Nonce`).
- **Writes:** inserts, updates and deletes use `$wpdb->insert / update / delete`. Lookups by ID use `$wpdb->prepare()`, and input is validated before saving.
- **One admin page** under `admin_menu`, with vanilla JS and CSS inline in the plugin file.

## Install

Copy `outreach-tracker.php` into `wp-content/plugins/outreach-tracker/` and activate it. It appears in the admin menu as **Outreach Tracker**.

## Ideas for later

- CSV import and export
- Reminder emails for due follow-ups
