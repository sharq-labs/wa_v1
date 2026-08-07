# Architecture

## One repository, one application

Laravel serves the API (`routes/api.php`), public compliance pages (`routes/web.php`) and the React SPA (`resources/views/app.blade.php` catch-all). React lives in `resources/js` and is built by Vite. Authentication is Sanctum SPA cookie auth (same origin, CSRF protected).

## Multi-tenancy

```
User → Workspace → WhatsApp Account → Contacts → Conversations → Messages → Automations
```

- Every tenant table carries `workspace_id` with a foreign key.
- The `{workspace}` route parameter + `ResolveWorkspace` middleware verify membership on every request; `workspace_id` from request bodies is never trusted.
- `WorkspacePolicy` gates capability levels (owner > admin > manager > agent > viewer).
- `BelongsToWorkspace` trait provides `scopeForWorkspace()` used by all queries.
- Isolation is covered by dedicated tests (`WorkspaceIsolationTest`).

## Messaging provider abstraction

```
MessagingProviderInterface
├── FakeWhatsAppProvider   (dev/tests/demos, records sends in-memory)
└── MetaWhatsAppProvider   (official Graph API, version configurable)
```

`MessagingManager` resolves the provider per WhatsApp account (`accounts.provider` wins over the `WHATSAPP_PROVIDER` default). Nothing outside `App\Services\Messaging` knows Graph API details, so new channels (Instagram, Telegram…) plug in without touching the engine.

`MessagingEligibilityService` is the single source of truth for the 24-hour customer service window — the UI and API consult it instead of hard-coding policy.

## Inbound pipeline

```
Meta webhook POST
→ MetaWebhookController (signature check, raw event stored idempotently, 200 OK)
→ ProcessWebhookEvent job (queue: whatsapp-webhooks)
→ WhatsAppEventProcessor (messages / statuses / template updates)
→ InboundMessageService.ingest (idempotent on provider_message_id;
   resolves contact + conversation, updates counters, broadcasts)
→ ProcessInboundMessageAutomation job (queue: automations)
   1. pending reply-wait? → engine.resumeReply()
   2. bot paused? → stop
   3. TriggerMatcher → engine.start() (highest priority, or all if configured)
   4. no match → workspace fallback (message / automation)
```

Idempotency is enforced twice: `webhook_events.unique(provider, event_id)` (deterministic hash of message/status ids) and `messages` lookup by `provider_message_id`.

## Automation engine

- **Definitions** are JSON (`nodes[] + edges[]`) drawn in the builder and stored as `automations.draft_definition`.
- **Publishing** snapshots the draft into an immutable `automation_versions` row (plus relational `automation_nodes`/`automation_edges`) after `FlowValidator` passes. Published versions are never mutated; running executions keep their version.
- **Execution**: `AutomationEngine.executeFrom()` walks nodes via `NodeHandlerRegistry` → one `NodeHandlerInterface` class per node type (no god service). Each step is recorded in `automation_run_steps` (the run timeline).
- **Waiting** is always persisted in `automation_waits` — Ask Question / Buttons create `reply` waits consumed by the next inbound message; Delay / Wait Until create `delay|until` waits resumed by delayed queue jobs plus the `automation:resume-due-waits` scheduler safety net. Server restarts lose nothing.
- **Safety**: `MAX_AUTOMATION_STEPS` per run, `MAX_AUTOMATION_DEPTH` for Start-Automation nesting, waitless-cycle detection at publish time, SSRF guards on the HTTP node (scheme allowlist, private-IP blocking after DNS resolution, timeout + response-size caps).
- **Context**: `AutomationContext` exposes workspace/contact/conversation/message/account/run/variables and resolves `{{contact.*}} {{custom.*}} {{variables.*}} {{workspace.*}} {{agent.*}} {{message.*}}` through `VariableInterpolator` (missing-variable behaviour configurable: empty/default/fail).

## Simulator

`SimulationService` interprets the draft definition against a virtual contact with state kept in cache — full Ask Question/buttons behaviour, run log, node highlighting — and never touches the provider or the CRM.

## Realtime

Laravel Reverb broadcasts on private channels `workspace.{id}` and `user.{id}`, plus presence channel `conversation.{id}` for agent-collision protection. Events: NewMessage, MessageStatusChanged, ConversationUpdated, ConversationAssigned, AgentStatusChanged, AgentTyping (ShouldBroadcastNow), AutomationStatusChanged, ContactUpdated.

## Assignment

`AssignmentService` supports manual, specific-agent, team round-robin (pointer persisted in `assignment_states`, survives restarts) and least-active (availability + online status + max/active conversation counts).

## Billing

Two deliberately separate financial concepts:

1. **Platform subscription** — our revenue. `plans`/`plan_features` in the database, `BillingProviderInterface` (`ManualBillingProvider` implemented; Stripe/Paymob/PayTabs pluggable), `EntitlementsService` as the only place limits/features are checked.
2. **Meta WhatsApp usage** — Meta's revenue, surfaced in the billing UI as an explicitly separate item and never merged into ours.

## Queues

Horizon consumes: `whatsapp-webhooks`, `whatsapp-messages`, `automations`, `campaigns`, `notifications`, `default`. Campaigns chunk recipients (`campaign_chunk_size`) and pace sends (`campaign_messages_per_second`) — never inside HTTP requests.

## Error handling

`bootstrap/app.php` renders every API error in the `{success, message, errors}` envelope; 500s are reported internally and show a friendly message unless `APP_DEBUG`. Tokens are encrypted at rest (`whatsapp_accounts.access_token` cast) and hidden from serialization.
