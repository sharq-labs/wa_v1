# Implementation Status

Last updated: 2026-08-04.

"Completed" means frontend + backend + database + authorization + validation + business logic + tests work together — not merely that a UI exists.

## Completed

| Area | Backend | Frontend | Tests |
| --- | --- | --- | --- |
| Auth (register/login/logout/verify/forgot/reset/change password/profile) | ✅ | ✅ | ✅ `AuthTest` |
| Workspaces (create/update/switch/delete/logo/onboarding) + multi-workspace | ✅ | ✅ | ✅ |
| Tenant isolation (middleware + policies + scopes + FK constraints) | ✅ | n/a | ✅ `WorkspaceIsolationTest` |
| Roles (owner/admin/manager/agent/viewer) + invitations | ✅ | ✅ | ✅ |
| Agents (status/availability/max conversations) + teams | ✅ | ✅ | ✅ |
| Assignment: manual, team round-robin (persisted pointer), least-active | ✅ | ✅ | ✅ round-robin rotation test |
| WhatsApp provider abstraction (interface + fake + Meta Cloud API) | ✅ | n/a | ✅ (fake used across suite) |
| Meta Embedded Signup architecture (config endpoint, code exchange, token encryption) | ✅ | ✅ (settings hook) | — |
| Webhooks: verification, signature check, raw storage, queued processing, idempotency | ✅ | n/a | ✅ `WebhookTest` incl. duplicate delivery |
| Contacts CRM (search/filter/sort/tags/custom fields/export CSV/archive/block) | ✅ | ✅ | ✅ |
| Tags + custom fields (10 types) usable by automation | ✅ | ✅ | ✅ |
| Conversations + messages (all types/statuses, 24h window via eligibility service) | ✅ | ✅ | ✅ `InboxAgentTest` |
| Realtime inbox: 3-pane, filters, composer, media, templates, notes, typing, presence collision protection | ✅ | ✅ | ✅ backend paths |
| Bot pause/resume per conversation (paused bot never auto-replies) | ✅ | ✅ | ✅ |
| Automation engine: queued execution, handler-per-node, context, interpolation, conditions | ✅ | n/a | ✅ `AutomationAcceptanceTest` |
| Versioning: immutable published versions, draft editing, relational node/edge snapshot | ✅ | ✅ | ✅ `AutomationPublishingTest` |
| Ask Question (validation, error reply, persisted DB waits — restart safe) | ✅ | ✅ | ✅ |
| Interactive buttons with per-button branches + save-to | ✅ | ✅ | ✅ |
| Condition node (sources, 14 operators, AND/OR, TRUE/FALSE branches) | ✅ | ✅ | ✅ + unit tests |
| Delay / Wait Until via delayed jobs + scheduler safety net (no sleep, no memory state) | ✅ | ✅ | ✅ |
| HTTP Request node with SSRF protection + response→variable mapping; Send Webhook | ✅ | ✅ | — (guard logic in place) |
| Loop protection (max steps, nesting depth, waitless-cycle validation) | ✅ | ✅ | ✅ |
| Automation priority (highest wins; allow-multiple workspace option) + fallback | ✅ | ✅ | ✅ |
| Flow builder: canvas, node library (32 types), settings panel, drag/drop, undo/redo, copy/paste/duplicate, snap grid, minimap, autosave | n/a | ✅ | ✅ node catalog tests |
| Publish validation with per-node errors surfaced in UI | ✅ | ✅ | ✅ |
| Simulator: WhatsApp-style chat, Ask Question behaviour, node highlighting, run state + log, no provider calls | ✅ | ✅ | ✅ |
| Run timeline (steps with input/output/errors, variables) | ✅ | ✅ | ✅ |
| Templates: wizard (7 steps incl. sample values + preview), list, sync (manual/scheduled/webhook), delete | ✅ | ✅ | ✅ `InboxAgentTest` template cases |
| Template variable mapper (static/contact/custom/workspace/agent) + preview | ✅ | ✅ | ✅ frontend `templatePreview` tests |
| Campaigns: audience (all/tag/segment/contacts), chunked queued sending, pacing, opt-out skip, pause/cancel, recipients | ✅ | ✅ | ✅ `CampaignTest` |
| Segments with dynamic filter matching + preview endpoint | ✅ | ✅ | ✅ |
| Billing: plans in DB, ManualBillingProvider, entitlements service, limit enforcement with upgrade messaging | ✅ | ✅ | ✅ `EntitlementsTest` |
| Meta usage separated from platform subscription in billing UI/API | ✅ | ✅ | ✅ |
| Dashboard metrics + charts; analytics with period filters | ✅ | ✅ | — (query-only) |
| Platform admin: overview, users, workspaces, webhook logs, failed runs/jobs, health, plans, settings | ✅ | ✅ | — (super-admin gated) |
| Audit log (login, invites, roles, WhatsApp connect, publishes, campaign send, assignment, subscription…) | ✅ | ✅ | — |
| Compliance pages (privacy/terms/data-deletion/support/company, admin-editable) | ✅ | n/a | ✅ |
| i18n English/Arabic with RTL switching | n/a | ✅ | — |
| Seeders: plans + Demo Company (agents Ahmed/Sara, Sales team, contacts, tags, fields, fake approved templates, published Lead Qualification Bot) | ✅ | n/a | seed-verified |
| Docker Compose (app/nginx/mysql/redis/horizon/reverb/scheduler), Horizon queues, scheduler commands | ✅ | n/a | — |

## Acceptance tests (spec §72–75)

- §72 Lead Qualification end-to-end (contact→conversation→trigger→welcome→question→wait→save→buttons→save→budget→invalid retry→condition→tag→round-robin assign→pause→persisted run; paused bot silent) — **passing**
- §73 Agent flows (history, fields, tags, assignment, reply, note, template, pause/resume; paused bot never auto-replies) — **passing**
- §74 Template `sales_followup` mapping {{1}}→first_name, {{2}}→custom.service, preview, send, stored + inbox display — **passing**
- §75 Idempotency (duplicate webhook produces zero duplicates anywhere) — **passing**

**Test totals: 60 backend (Pest, 196 assertions) + 11 frontend (Vitest) — all green.** `npm run build`, `lint`, `typecheck`, and `vendor/bin/pint` pass.

## In progress / partial

- **Meta Embedded Signup frontend dialog** — backend exchange + config endpoint complete; the FB.login JS dialog needs a real `META_APP_ID` on a production domain (settings shows a hint until configured).
- **Media inbound download** — inbound media ids are kept in the message payload; fetching binary from the Graph media endpoint is not yet wired.
- **Automation triggers** — tag added/removed, custom-field changed, webhook and scheduled triggers exist as node types but only message-driven triggers (incoming/keyword/new contact/button/list) currently fire.

## Remaining (future work)

- Stripe/Paymob/PayTabs billing providers (interface ready), invoices/receipts
- Meta per-conversation usage reporting (architecture allows it)
- Public API tokens for customers (Sanctum PATs ready) + API docs portal
- Contact import (CSV), broadcast lists UI beyond campaigns
- Mentions notifications for internal notes; email notifications
- Multi-channel expansion (Instagram/Messenger/Telegram) on the provider abstraction

## Known issues

- Horizon requires pcntl/posix → runs in Docker/Linux only; on Windows dev use `php artisan queue:work` (composer platform config allows install).
- The SPA bundle is a single chunk (~795 kB min); code-splitting the flow builder would improve first load.
- Simulator executes Start Automation nodes as a log entry only (does not descend into child flows).
