# Changelog

## 0.2.90 — 2026-11-18

- Detects and adopts an already-running Laragon receiver started manually from Cmder/PowerShell, allowing accurate status and stop/start control.
- Moves receiver controls into the Active Directory section and preserves the selected configuration section after form actions.


## 0.2.89 — 2026-11-18

- Improved PHP CLI discovery for Laragon when PHP runs as an Apache module; checks PHP_BINDIR, PATH and Laragon's standard PHP directory.


## 0.2.88 — 2026-11-18

- Added simple Start, Stop and Status controls for the Telegram Long Polling receiver in the GLPI configuration page.
- Added Windows/Laragon process control using the PHP CLI and a PID file; Linux control remains reserved for the systemd deployment.


## 0.2.87 — 2026-11-17

- Fixed the AD unlock rate limiter to count only unlock requests, excluding account-status and locked-account listing queries.


## 0.2.83 — 2026-11-17

- Retired the legacy shared AD agent secret after successful migration to per-agent storage, preventing deleted legacy agents from being recreated by a later plugin upgrade.


## 0.2.82 — 2026-11-17

- Added confirmed permanent deletion for notification destinations and rules, AD authorizations and agents, and terminal queue records; retained delivery/AD audit history and blocked deletion when active work still depends on a record.


## 0.2.81 — 2026-11-17

- Improved Windows AD agent diagnostics by logging a bounded exception message and script line for polling/processing failures, without logging credentials, HMAC material or request headers.

## 0.2.80 — 2026-11-17

- Fixed the standalone Long Polling receiver bootstrap to load GLPI's Composer autoloader and boot the GLPI Kernel before accessing GLPI runtime constants.


## 0.2.79 — 2026-11-17

- Fixed the standalone Long Polling CLI receiver bootstrap by explicitly loading its plugin class dependencies after GLPI initialization.

## 0.2.78 — 2026-11-16

- Added `/bloqueados` to list only enabled and locked AD user accounts, with 10-account Telegram pages and per-account selection.
- Added snapshot-bound account selection callbacks and individual one-time unlock confirmation for each selected account.
- Revalidated `Enabled` before unlock; disabled accounts are rejected without modification and recorded as a failed operation.
- Persisted the bounded account snapshot in the AD audit trail and attributed confirmation/cancellation events to the requesting GLPI user.
- Added contract coverage for list authorization, result auditing and disabled-account outcomes.

## 0.2.77 — 2026-11-16

- Fixed the Windows PowerShell 5.1 HMAC diagnostic to write the exact UTF-8 request body to a temporary file and send it with curl `--data-binary @file`, preventing native argument parsing from altering embedded JSON quotes after the body hash is calculated.


## 0.2.69 — 2026-11-16

- Added a registry of individually identified AD agents with individual encrypted HMAC secrets, administrator approval and revocation.
- Migrated the existing lab secret to pending agent `SVRSNGPRTG-LAB` without regenerating or displaying it.
- Added target-agent routing so agents can claim only requests assigned to their own identifier.
- Added GLPI controls to register agents, approve or revoke them, and select the default request target.
- Restricted endpoint authentication to approved, active agents.

## 0.2.68 — 2026-11-15

- Registered the HMAC-authenticated Windows AD agent endpoint as a stateless GLPI 11 plugin path, preventing anonymous machine-to-machine requests from being redirected to the interactive login page.

## 0.2.67 — 2026-11-15

- Removed the production URL default from the Windows provisioning script; the operator must explicitly provide the HTTPS test or deployment endpoint.

# Changelog

## 0.2.66 — 2026-11-15

- Added a deduplicated notification-queue fallback when immediate Telegram delivery of an AD result fails; successful direct delivery remains immediate and does not wait for cron.

# Changelog

## 0.2.65 — 2026-11-15

- Documented the per-user command rate limits for status checks and unlock requests.

# Changelog

## 0.2.64 — 2026-11-15

- Added per-user and private-chat request rate limits: 10 status queries per minute and 3 unlock requests per five minutes.
- Added contract assertions for the unlock rate-limit query.

# Changelog

## 0.2.63 — 2026-11-15

- Updated the AD confirmation test database mock to recognize the joined authorization lookup (`SELECT r.request_uuid`) introduced by the confirmation-time permission recheck.

# Changelog

## 0.2.62 — 2026-11-15

- Revalidated the requester's active GLPI account, AD unlock permission and original private Telegram destination at confirmation time, closing the revocation race between request creation and confirmation.
- Recorded the originating Telegram Chat ID as the confirmation/cancellation audit actor.

# Changelog

## 0.2.61 — 2026-11-15

- Added the same fixed HMAC interoperability vector to PHP contract tests that is executed by Windows PowerShell 5.1 CI.

# Changelog

## 0.2.60 — 2026-11-15

- Added a Windows PowerShell 5.1 runtime test vector to verify that the agent HMAC bytes and canonical LF-delimited string match PHP exactly.

# Changelog

## 0.2.59 — 2026-11-15

- Updated the AD integration runbook to document the current HMAC endpoint, claim/complete contract, Windows agent prototype and remaining lab gates.
- Extended the GLPI smoke test to assert the Telegram update idempotency column exists after install/upgrade.

# Changelog

## 0.2.58 — 2026-11-15

- Added Telegram update ID as a unique idempotency key on AD requests, preventing duplicate queued commands when Long Polling retries an update after a transient send/receive error.
- Added an additive installer migration for existing GLPI installations.

# Changelog

## 0.2.57 — 2026-11-15

- Rebuilt the Windows secret provisioning script as a complete file after repeated line-ending edits corrupted its URL validation block; PowerShell 5.1 parser CI remains mandatory.

# Changelog

## 0.2.56 — 2026-11-15

- Restored the URL-validation block body and closing brace in the Windows provisioning script; Windows PowerShell 5.1 parser CI guards this file.

# Changelog

## 0.2.55 — 2026-11-15

- Corrected the Windows PowerShell 5.1 installer URL-validation expression; the parser workflow now checks the exact script after every change.

# Changelog

## 0.2.54 — 2026-11-15

- Fixed the multiline HTTPS endpoint validation condition in the Windows secret provisioning script after the Windows PowerShell 5.1 parser caught the syntax error.

# Changelog

## 0.2.53 — 2026-11-15

- Bounded HTTP body reads to 8 KB before JSON parsing and required the application/json content type on the agent endpoint.
- Changed Windows agent configuration ACL grants to well-known SIDs so provisioning works consistently on localized Windows Server installations.
- Used explicit UTF-8 encoder construction in the provisioning script.

# Changelog

## 0.2.52 — 2026-11-15

- Added a Windows runner that parses both agent scripts with Windows PowerShell 5.1 syntax rules on every push and pull request.

# Changelog

## 0.2.51 — 2026-11-15

- Aligned the Windows agent HMAC canonical string with PHP's LF-only newline format and the exact base64url secret string used as the signing key.
- Switched UTF-8 encoder construction to the explicit .NET constructor supported by Windows PowerShell 5.1.

# Changelog

## 0.2.50 — 2026-11-15

- Added a Windows PowerShell 5.1 AD agent prototype that polls the authenticated GLPI API, executes only the two allowlisted AD operations and submits structured results.
- Added a DPAPI LocalMachine provisioning script with restrictive ProgramData ACLs for the service account.
- Documented the lab-only rollout boundary; the existing SongaADAgent service is not modified by these files.

# Changelog

## 0.2.49 — 2026-11-15

- Added contract coverage for atomic agent claims, 90-second leases, attempt counters, lease-token validation and allowlisted completion results.
- Extended GLPI smoke coverage for the HMAC nonce table and authenticated agent endpoint.
- Corrected audit attribution so Telegram confirmations and agent claim/completion events are distinguishable.

# Changelog

## 0.2.48 — 2026-11-15

- Added an HMAC-authenticated HTTPS agent endpoint with `claim` and `complete` operations.
- Added 90-second request leases, expired-lease recovery and a maximum of three agent attempts.
- Restricted result codes and returned fields; the agent cannot submit arbitrary PowerShell output or free-form Telegram messages.
- Delivered completion results directly to the originating private Telegram chat, independent of the notification cron.

# Changelog

## 0.2.47 — 2026-11-15

- Added encrypted storage for the AD agent's 256-bit HMAC secret using the plugin's protected sodium key.
- Added an administrator-only action to generate or rotate the agent secret and display it once for secure provisioning.

# Changelog

## 0.2.46 — 2026-11-15

- Added HMAC-SHA256 request signing for the Windows AD agent, bound to method, endpoint path, timestamp, nonce, agent ID and exact request body.
- Added a database-backed unique nonce registry with expiry to reject replayed requests.
- Added standalone HMAC contract tests and wired them into PHP CI.

# Changelog

## 0.2.45 — 2026-11-15

- Extended the GLPI integration smoke test to verify that the independent Telegram Long Polling receiver is autoloadable.

# Changelog

## 0.2.44 — 2026-11-15

- Added a standalone persistent Telegram update receiver using 25-second Long Polling, separate from the notification cron.
- Added private-chat-only handling for /estado and /desbloquear, GLPI user resolution, confirmation/cancel inline buttons, and callback acknowledgment.
- Added a process lock, persisted Telegram update offset, and startup protection against an active webhook.
- Repeated unlock requests for the same account and private chat invalidate older unconfirmed tokens so only one confirmation remains active.

# Changelog

## 0.2.43 — 2026-11-15

- Extended the GLPI 11.0.11 integration smoke test to verify creation of all three AD tables and autoloading of the AD request service.

# Changelog

## 0.2.42 — 2026-11-15

- Added Telegram Bot API Long Polling with a bounded 25-second wait, independent of the notification cron.
- Added callback-query acknowledgment and optional inline reply markup support for confirmation messages.
- Preserved existing notification send behavior when no reply markup is supplied.

# Changelog

## 0.2.41 — 2026-11-15

- Fixed the AD test database fixture to capture the generated request UUID despite SQL line breaks, allowing the confirmation lookup test to exercise the full success path.

# Changelog

## 0.2.40 — 2026-11-15

- Corrected the AD confirmation test fixture to emulate request lookup by token hash and the updated two-argument confirmation API.

# Changelog

## 0.2.39 — 2026-11-15

- Fixed the standalone AD contract test assertions and added coverage for the Telegram callback-sized confirmation token.
- Reduced unlock confirmation token to 32 URL-safe characters so it fits Telegram's 64-byte callback-data limit with a short action prefix.
- Confirmation now locates the pending request by token hash and private chat, then atomically consumes it; replay attempts cannot reuse it.
- Fixed the authorization editor so an unchecked Active box actually deactivates an existing permission.

# Changelog

## 0.2.38 — 2026-11-15

- Added an administration section to grant or revoke AD status and unlock permissions per active GLPI user.
- Unlock permission also grants status visibility; disabling both permissions deactivates the authorization.
- Kept the authorization management behind the existing GLPI configuration-update right and CSRF-protected form.

# Changelog

## 0.2.37 — 2026-11-15

- Added the AD authorization and confirmation contract test to the PHP 8.2/8.3 CI workflow.
- Made the new AD contract test self-contained so it can run independently in CI.

# Changelog

## 0.2.36 — 2026-11-15

- Added explicit per-user AD permissions (view account status and unlock accounts).
- Added a request service that enforces active private Telegram chat binding and active GLPI user authorization.
- Added one-time, five-minute unlock confirmation tokens stored only as SHA-256 hashes; confirmation atomically consumes the token and queues the request.
- Added contract tests for authorization constraints, token handling, expiry and replay-resistant confirmation.

# Changelog

## 0.2.35 — 2026-11-15

- Fixed a pre-existing PHP syntax error in the wildcard subscription contract test so the PHP 8.2/8.3 CI matrix can run.

## 0.2.34 — 2026-11-15

- Added the initial persistent request queue for the controlled Active Directory integration (account_status and unlock_account operations).
- Added confirmation expiry, one-time confirmation token hash, agent lease fields, and structured execution result fields.
- Added a separate AD audit table to retain requester, confirmation, agent and execution events.
- Documented the event-driven design: Telegram command reception and the Windows agent run independently of the notification cron.
- This is a schema and architecture foundation only; no AD command endpoint or executable agent operation is enabled yet.

## 0.2.32 — 2026-11-14

- Added source-scoped `*` subscriptions for external plugins, allowing one rule per plugin and destination to receive all its events.
- Added a guided wildcard option for custom plugin sources while keeping native GLPI event subscriptions explicit.
- Added API documentation and contract coverage for wildcard routing.


## 0.2.31 — 2026-11-14

- Added optional HTML formatting to the public notification enqueue API and persisted the format in the delivery queue.
- Deliver HTML notifications using Telegram's supported HTML parse mode while preserving plain-text compatibility for existing consumers.
- Added automatic plain-text fallback for Telegram formatting/entity rejection, without retrying transport or unrelated API failures.
- Added an installer migration for the queue `parse_mode` column.

## 0.2.30 — 2026-11-09

- Preserved `glpi / ticket.assigned` as a broadcast event for all subscribed destinations.
- Added `glpi / ticket.assigned.to_user` for notifications limited to the private Telegram destination linked to the assigned GLPI user.
- Added explicit event labels and administration guidance to distinguish broadcast and user-specific assignment alerts.


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
