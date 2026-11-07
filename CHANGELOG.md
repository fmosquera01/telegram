# Changelog

## 0.2.7 — 2026-11-07

- Return a clear API error when no active destination or subscription matches a notification.

## 0.2.6 — 2026-11-07

- Logged non-sensitive queue persistence failures without interrupting GLPI operations.
- Preserved continuous attempt numbering after manual retries.

## 0.2.5 — 2026-11-07

- Added persistent audit records for each Telegram delivery attempt.
- Added an administration view for individual attempts and delivery outcomes.
- Added total-attempt and failed-attempt counts to the queue history.
- Ensured audit-write failures do not trigger duplicate Telegram sends.

## 0.2.4 — 2026-11-07

- Added recipient routing by linked GLPI user ID.
- Added group-based ticket assignment notifications.
- Added validation for recipient values and maximum message byte size.
- Added API consumer documentation.
- Clarified plugin isolation, security boundaries and current validation gaps.

## 0.2.3 — 2026-11-07

- Added native notifications for ticket assignment to GLPI groups.
- Added recipient resolution by linked GLPI user ID using `user:<id>`.
- Quoted SQL identifiers that may conflict with MariaDB/MySQL keywords.
- Clarified supported recipient selectors and native event coverage.

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
