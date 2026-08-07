# Meta WhatsApp Business Platform Setup

The platform runs fully on the **fake provider** (`WHATSAPP_PROVIDER=fake`) without any Meta configuration. This document covers connecting the real Meta Cloud API and preparing for App Review / Tech Provider onboarding.

## 1. Meta Developer App

1. Create a **Business-type app** at <https://developers.facebook.com/apps>.
2. Add the **WhatsApp** product.
3. Note your **App ID** and **App Secret** → `META_APP_ID`, `META_APP_SECRET`.
4. Keep the Graph API version configurable — set `META_GRAPH_API_VERSION` (e.g. `v21.0`). It is never hard-coded.

```env
WHATSAPP_PROVIDER=meta
META_APP_ID=…
META_APP_SECRET=…
META_GRAPH_API_VERSION=v21.0
META_WEBHOOK_VERIFY_TOKEN=<random long string>
META_CONFIG_ID=…                    # login configuration
META_EMBEDDED_SIGNUP_CONFIG_ID=…    # embedded signup configuration
META_REDIRECT_URI=https://yourapp.com/api/meta/oauth/callback
```

All values are read via `config/meta.php`.

## 2. Webhook

- Callback URL: `https://yourapp.com/api/webhooks/meta/whatsapp`
- Verify token: the value of `META_WEBHOOK_VERIFY_TOKEN`
- Subscribe to the **whatsapp_business_account** object with fields: `messages`, `message_template_status_update`.

The endpoint verifies `X-Hub-Signature-256` (HMAC-SHA256 with the app secret), stores the raw event idempotently and acknowledges immediately; processing is queued (`whatsapp-webhooks`).

Local development: expose your app with `ngrok http 8000` and use the ngrok URL.

## 3. Embedded Signup

1. In the app dashboard → **Facebook Login for Business** → create a configuration of type **WhatsApp Embedded Signup**; copy its id into `META_EMBEDDED_SIGNUP_CONFIG_ID`.
2. The frontend fetches `GET /api/workspaces/{id}/meta/embedded-signup/config` (app id + config id) and launches `FB.login` with `config_id`.
3. The dialog returns an **authorization code** plus `waba_id`/`phone_number_id` (via the message event). The frontend posts them to `POST /api/workspaces/{id}/meta/embedded-signup/complete`.
4. The backend exchanges the code for a **business token**, resolves the WABA + phone number, subscribes the app to WABA webhooks, and stores the account with the token **encrypted at rest**. The token is never sent to the browser.

## 4. Permissions for App Review

Request: `whatsapp_business_management` (WABA/template management) and `whatsapp_business_messaging` (send/receive). For Tech Provider onboarding also complete Business Verification for your Meta Business account.

## 5. App Review demo flows (Reviewer Mode)

Seed the database (`php artisan migrate:fresh --seed`) and give reviewers `admin@demo.test` / `password`. Everything works on the fake provider — no live number needed:

1. **Login** → dashboard.
2. **Settings → WhatsApp Accounts** → see the connected number and the "Connect WhatsApp" button.
3. **Templates** → list, "Sync templates", create a template through the 7-step wizard (WhatsApp-style preview included).
4. **Inbox** → open the demo conversation, send a message, send the approved `sales_followup` template with variable mapping.
5. **Automations** → open "Lead Qualification Bot" → **Test** → chat "سعر" in the WhatsApp-style simulator and watch the flow run (questions, buttons, condition, handoff) with live node highlighting.

## 6. Compliance pages

Publicly served (required by Meta review): `/legal/privacy`, `/legal/terms`, `/legal/data-deletion`, `/legal/support`, `/legal/company`. Content is editable from Platform Admin → Settings (`compliance.*` keys).

## 7. Template sync

Manual: Templates → "Sync templates" (or per account in Settings). Scheduled: `templates:sync-all` runs hourly. Status webhooks (`message_template_status_update`) update approval/rejection in realtime.
