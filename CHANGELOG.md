# Changelog

## 0.2.29 — 2026-11-09

- Scoped subscribed `glpi / ticket.assigned` notifications for private destinations to the GLPI user linked to that destination.
- Preserved broadcast assignment notifications for groups, supergroups and channels.
- Clarified personal versus group assignment subscription behavior in the administration page.
- Added contract coverage for technician-specific subscription filtering.

## 0.2.27 — 2026-11-07

- Automatically populate destination name and unique alias from the selected GLPI user's full name and login; keep manual entry for unlinked Telegram users and groups.

## 0.2.26 — 2026-11-07

- Use GLPI's dedicated native user dropdown for Telegram destination associations, enabling searches by username, first name, surname and full name, matching ticket user selectors.

## 0.2.25 — 2026-11-07

- Replaced free-text native event subscription fields with guided module and event selectors, retaining custom plugin identifiers.
- Added combined recipient routing so a notification can reach explicit users and subscribed groups at the same time.
- Routed native ticket assignment events to the assigned technician's linked private Telegram destination and retained group subscriptions.
- Added contract coverage for combined technician and group delivery.

## 0.2.24 — 2026-11-07

- Replaced the full user list and numeric user-ID field with GLPI's searchable user dropdown when creating or editing Telegram destinations.
- Kept the GLPI user association optional and restricted destination management to administrators with configuration rights.

## 0.2.23 — 2026-11-07

- Fixed duplicate CSRF validation in the legacy configuration action. GLPI 11.0.11 validates POST requests in its central `CheckCsrfListener`; the plugin no longer consumes the same token a second time.

## 0.2.20 — 2026-11-07

- Fixed SQL identifier escaping in the scheduled SLA subscription lookup for MariaDB.

## 0.2.19 — 2026-11-07

- Fixed the CommonGLPI getTypeName override signature for GLPI 11.0.11.
- Added a compatibility test that loads the plugin menu class against GLPI parent method signatures.

## 0.2.18 — 2026-11-07

- Hardened admin actions by checking database outcomes for destination/subscription state changes and manual retries.
- Added PHP 8.2 and 8.3 to the CI syntax and contract-test matrix.

## 0.2.17 — 2026-11-07

- Validate Telegram delivery responses include a message ID before marking a queue item as sent.
- Added a worker preflight that preserves queued messages until the bot token is configured.
- Added automated coverage for the unconfigured-token queue behavior.

## 0.2.16 — 2026-11-07

- Keep queue items pending until a bot token is configured instead of exhausting retries prematurely.
- Separate lifetime audit numbering from the retry-cycle counter so manual retries receive a fresh eight-attempt cycle.
- Document both registered cron tasks and SLA alert timing.

## 0.2.15 — 2026-11-07

- Added scheduled SLA due-soon and overdue notifications for open tickets.
- Added bounded ID-cursor scanning to avoid repeatedly scanning the same first batch.
- Registered a separate five-minute SLA cron task.
- Added contract tests for the scheduled SLA worker and cursor persistence.

## 0.2.14 — 2026-11-07

- Added GLPI 11.0.11 `POST_INVENTORY` integration for successful inventory imports.
- Added opt-in `glpi / inventory.received` event with device ID, asset type and agent version.
- Documented the inventory hook behavior and event subscription.

## 0.2.13 — 2026-11-07

- Added safe validation for API source/event identifiers and explicit database lookup failures.
- Added a database write check when saving the encrypted bot token.
- Added contract tests for invalid input, alias/user routing, subscription routing and duplicate queue inserts.
- Extended CI to run the API contract tests alongside PHP syntax checks.

## 0.2.12 — 2026-11-07

- Added destination editing while preserving immutable aliases used by subscriptions and API consumers.
- Added status/source filters and pagination to delivery history.
- Added native ticket SLA assignment/deadline change notifications.
- Fixed assigned-user event detection to use GLPI's actor constant.
- Corrected successful delivery attempt counters and failed-attempt reporting.
- Hardened installation checks and required PHP extension/version checks.
- Added standalone API contract tests to GitHub Actions.

## 0.2.11 — 2026-11-07

- Guard diagnostic logging so even logging failures cannot interrupt native GLPI operations.

## 0.2.10 — 2026-11-07

- Use GLPI's `CommonITILActor::ASSIGN` constant when detecting ticket user/group assignments.
- Confirmed the current PHP syntax workflow passes for the implementation files.

## 0.2.9 — 2026-11-07

- Disable destination test actions until a bot token is configured.
- Validate token availability again on the destination test endpoint.

## 0.2.8 — 2026-11-07

- Added validation for Telegram numeric chat IDs and public channel usernames.
- Added a destination test action that enqueues a message through the central queue.

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
