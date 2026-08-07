# REST API

All endpoints are under `/api`. Authentication: Sanctum SPA cookie (same-origin; fetch `/sanctum/csrf-cookie` first, send `X-XSRF-TOKEN`).

## Envelope

```json
// success
{ "success": true,  "message": "…", "data": { } }
// error
{ "success": false, "message": "…", "errors": { "field": ["…"] } }
```

## Auth

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/register` | name, email, password(+confirmation), workspace_name — creates first workspace |
| POST | `/auth/login` | email, password, remember |
| POST | `/auth/logout` | |
| GET | `/auth/me` | current user + workspaces |
| PUT | `/auth/profile` | name, email, locale |
| PUT | `/auth/password` | current_password, password |
| POST | `/auth/forgot-password` / `/auth/reset-password` | |
| POST | `/auth/email/resend` · GET `/auth/verify-email/{id}/{hash}` | signed |
| POST | `/invitations/accept` | token |

## Webhooks (public)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/webhooks/meta/whatsapp` | Meta verification handshake (`hub_verify_token`) |
| POST | `/webhooks/meta/whatsapp` | signature-verified (`X-Hub-Signature-256`), idempotent, queued processing |

## Workspace-scoped (`/workspaces/{workspace}/…`, membership enforced)

### Workspace & members
`GET|PUT|DELETE /` · `POST /switch` · `POST /logo` · `POST /complete-onboarding` ·
`GET /members` · `PUT /members/{user}/role` · `DELETE /members/{user}` ·
`GET|POST /invitations` · `DELETE /invitations/{invitation}`

### Agents & teams
`GET /agents` · `PUT /agents/self` (status/availability) · `PUT /agents/{profile}` ·
`GET|POST /teams` · `PUT|DELETE /teams/{team}`

### WhatsApp accounts
`GET /whatsapp-accounts` · `POST /whatsapp-accounts/connect-fake` ·
`GET|DELETE /whatsapp-accounts/{account}` · `POST /whatsapp-accounts/{account}/sync-templates` ·
`GET /meta/embedded-signup/config` · `POST /meta/embedded-signup/complete` (code exchange)

### Contacts CRM
`GET /contacts` (search, tag_id, status, whatsapp_account_id, sort, pagination) ·
`POST /contacts` · `GET /contacts/export` (CSV) · `GET|PUT|DELETE /contacts/{contact}` ·
`PUT /contacts/{contact}/status` (active/archived/blocked) · `PUT /contacts/{contact}/tags` ·
`GET|POST|PUT|DELETE /tags…` · `GET|POST|PUT|DELETE /custom-fields…`

### Inbox
`GET /conversations` (scope=mine|unassigned, status, unread, tag_id, assigned_team_id, whatsapp_account_id, search) ·
`GET /conversations/{id}` (includes messaging **eligibility**: can_send_free_form / requires_template / window_expires_at) ·
`POST …/assign` (user_id | team_id + strategy) · `POST …/unassign` · `PUT …/status` ·
`POST …/pause-bot` · `POST …/resume-bot` · `POST …/read` · `POST …/typing` ·
`GET …/messages` · `POST …/messages/text` · `POST …/messages/media` (multipart) ·
`POST …/messages/template` (template_id + variable_mappings) ·
`GET|POST …/notes`

### Automations
`GET|POST /automations` · `GET|PUT|DELETE /automations/{id}` ·
`PUT /automations/{id}/draft` (autosave definition) · `POST /automations/{id}/validate` ·
`POST /automations/{id}/publish` (creates immutable version) · `PUT /automations/{id}/status` ·
`GET /automations/{id}/runs` · `GET /automations/{id}/runs/{uuid}` (timeline) ·
`POST /automations/{id}/simulate/start` · `POST /automations/{id}/simulate/message`

### Templates
`GET /templates` (status, account, search) · `POST /templates` (wizard payload, sample values required) ·
`GET|DELETE /templates/{template}` · `POST /templates/sync`

### Campaigns & segments
`GET|POST /campaigns` · `GET /campaigns/{id}` · `POST /campaigns/{id}/schedule|pause|cancel` ·
`GET /campaigns/{id}/recipients` ·
`GET|POST /segments` · `PUT|DELETE /segments/{segment}` · `POST /segments/preview` (filters → count + sample)

### Billing, analytics, audit
`GET /billing/summary` (platform subscription **separate from** Meta usage) ·
`POST /billing/subscribe` · `POST /billing/cancel` · `GET /plans` (global) ·
`GET /dashboard` · `GET /analytics?period=today|yesterday|7d|30d|custom` · `GET /audit-logs`

## Platform admin (`/admin/…`, super admin only)

`GET /overview` · `GET /users` · `GET /workspaces` · `GET /webhook-events` ·
`GET /failed-runs` · `GET /failed-jobs` · `GET /health` (redis/db/queues/failures) ·
`GET /plans` · `PUT /plans/{plan}` · `GET|PUT /settings`

## Realtime (Reverb)

Private `workspace.{id}`: `message.new`, `message.status`, `conversation.updated`, `conversation.assigned`, `agent.status`, `agent.typing`, `automation.status`, `contact.updated`.
Private `user.{id}`: `conversation.assigned`. Presence `conversation.{id}`: viewer list for collision protection.
