# Changelog

## 0.2.2 — 2026-11-07

- Added optional association between a Telegram destination and a GLPI user.
- Added a schema migration for the destination user field.
- Added public API version reporting.
- Documented the native ticket events in the administration page.
- Hardened encryption key creation against concurrent saves.

## 0.2.1 — 2026-11-07

- Added the initial GLPI administration page for bot token, destinations and subscriptions.
- Added Telegram Bot API connectivity check.
- Added destination and subscription enable/disable controls.
- Added recent delivery history and manual retry for failed notifications.
- Added initial native ticket events: created, assigned, status changed and priority changed.
- Added the public PHP enqueue service and persistent queue worker.
- Added encrypted token storage using Sodium and a private key file under GLPI_VAR_DIR.
- Added PHP syntax workflow in GitHub Actions.
- Corrected GLPI 11.0.11 database API usage and PHP namespace imports.

This is a development build. It is not approved for installation or testing yet.
