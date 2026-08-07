# Database Schema

MySQL 8, utf8mb4. Every tenant-owned table has `workspace_id` (FK, cascade). Key tables below; see `database/migrations` for full definitions.

## Identity & tenancy

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `users` | Platform users | `is_super_admin`, `current_workspace_id`, `locale` |
| `workspaces` | Tenants | `owner_id`, `slug` (unique), `timezone`, `currency`, `locale`, `settings` JSON (automation fallback / allow_multiple / working hours), `onboarded_at`, soft deletes |
| `workspace_users` | Membership | `role` (owner/admin/manager/agent/viewer), unique (workspace, user) |
| `workspace_invitations` | Invites | `token` unique, `expires_at`, `status` |

## Agents & teams

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `agent_profiles` | Per-workspace agent state | `availability`, `status` (online/offline/away/busy), `maximum_conversations`, `last_active_at` |
| `agent_teams` | Teams | `assignment_strategy` (round_robin/least_active) |
| `agent_team_users` | Team membership | unique (team, user) |
| `assignment_states` | Persisted round-robin pointers | unique (workspace, pool_type, pool_id), `last_assigned_user_id` |

## WhatsApp

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `whatsapp_accounts` | Connected numbers | `provider` (meta/fake), `waba_id`, `phone_number_id` (indexed), `access_token` **encrypted**, `quality_rating`, `messaging_limit`, `status`, soft deletes |
| `webhook_events` | Raw provider events | unique (`provider`, `event_id`) → idempotency, `status`, `attempts`, `error_message` |
| `whatsapp_templates` | Message templates | unique (account, name, language), `status` (draft/pending/approved/rejected/paused/disabled), `buttons` JSON, `variables` JSON (samples), `rejection_reason`, `usage_count` |

## CRM

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `contacts` | CRM contacts | unique (workspace, phone_number); indexes on (workspace, wa_id), (workspace, status), (workspace, last_message_at); `opt_in_status`, soft deletes |
| `tags` / `contact_tag` | Labels | unique (workspace, name) |
| `custom_fields` | Field definitions | unique (workspace, key), `type` (10 types), `options` JSON |
| `contact_custom_field_values` | Values | unique (contact, field) |

## Conversations & messages

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `conversations` | Threads | unique (workspace, account, contact); `status` (open/pending/closed), `automation_status` (active/paused), `last_inbound_at` (drives 24h window), `unread_count`, `first_agent_reply_at`; indexes on status / assigned_user / assigned_team / last_message_at |
| `messages` | All messages | `provider_message_id` (indexed), `direction`, `sender_type` (contact/bot/agent/system), `message_type` (13 types), `status` (queued→read/failed), `payload` JSON, timestamps per status; index (conversation, created_at) |
| `conversation_notes` | Internal notes | `mentions` JSON — never sent to WhatsApp |

## Automation

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `automations` | Bot definitions | `status` (draft/published/paused/archived), `priority`, `published_version_id`, `draft_definition` JSON |
| `automation_versions` | Immutable snapshots | unique (automation, version), `definition` JSON |
| `automation_nodes` / `automation_edges` | Relational snapshot per version | `node_id`, `type`, `config` JSON / `source_handle` (branch id) |
| `automation_runs` | Executions | `uuid`, `status` (running/waiting/paused/completed/failed/cancelled), `current_node_id`, `steps_executed`, `depth`, `parent_run_id`, `error` |
| `automation_run_steps` | Execution timeline | `node_id`, `node_type`, `status`, `input`/`output` JSON, `error` |
| `automation_waits` | Persisted waiting state | `wait_type` (reply/delay/until), `config` JSON (save_to, validation, buttons…), `resume_at`, `invalid_attempts`, `status`; index (conversation, status), (status, resume_at) |
| `automation_variables` | Run variables | unique (run, key) |

## Campaigns & segments

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `segments` | Dynamic audiences | `filters` JSON ({match, conditions[]}) — contacts computed at send time |
| `campaigns` | Template broadcasts | `status` (7 states), `audience_type`/`audience_config`, `variable_mappings`, counters |
| `campaign_recipients` | Materialised audience | unique (campaign, contact) → rerun-safe, `status`, `message_id` |

## Billing

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `plans` / `plan_features` | Plan catalogue | features as key/value (`unlimited` supported) — limits live in DB, not code |
| `subscriptions` | Platform subscriptions | `provider` (manual/stripe/…), `status`, period bounds |
| `subscription_usage` | Metered usage | unique (workspace, key, period `YYYY-MM`) |

## Platform

| Table | Purpose |
| --- | --- |
| `audit_logs` | Who did what: action, subject morph, properties JSON, ip, user agent |
| `system_settings` | Admin-editable settings incl. compliance page content, feature flags |
| `data_deletion_requests` | GDPR / Meta data-deletion tracking |
