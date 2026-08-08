# Meta WhatsApp Tech Provider / App Review Setup

WhatsFlow can run locally with the fake provider, but onboarding real customer WhatsApp Business Accounts requires a production Meta app, a verified business identity, public compliance pages, and WhatsApp Embedded Signup.

This document is the production checklist for Meta App Review and WhatsApp Tech Provider onboarding.

## 1. Production prerequisites

Before submitting anything to Meta, deploy WhatsFlow on its final HTTPS domain and set production values for:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com

WHATSAPP_PROVIDER=meta
META_APP_ID=...
META_APP_SECRET=...
META_EMBEDDED_SIGNUP_CONFIG_ID=...
META_WEBHOOK_VERIFY_TOKEN=<long-random-secret>
META_GRAPH_API_VERSION=<supported-version>

COMPLIANCE_COMPANY_NAME="Your Product Name"
COMPLIANCE_LEGAL_NAME="Your Exact Legal Company Name"
COMPLIANCE_COMPANY_ADDRESS="Your registered/business address"
COMPLIANCE_SUPPORT_EMAIL=support@example.com
COMPLIANCE_PRIVACY_EMAIL=privacy@example.com
COMPLIANCE_SUPPORT_URL=https://example.com/support
```

The company/legal identity should match the identity used in the Meta Business Portfolio. Do not submit App Review with `.test`, localhost, placeholder emails, placeholder addresses, or fake legal information.

## 2. Meta Developer App

1. Create a Meta developer app associated with the company Business Portfolio.
2. Add the WhatsApp product.
3. Add Facebook Login for Business / WhatsApp Embedded Signup as required by the current Meta dashboard.
4. Create the Embedded Signup configuration and copy its configuration ID into `META_EMBEDDED_SIGNUP_CONFIG_ID`.
5. Add the production domain to the app's allowed domains/origins and configure the HTTPS URLs requested by Meta.
6. Keep the Graph API version configurable through `META_GRAPH_API_VERSION`; never hard-code it in application code.

The App Secret stays server-side. It is never returned by `GET /api/workspaces/{id}/meta/embedded-signup/config`.

## 3. WhatsApp webhook

Production callback:

```text
https://app.example.com/api/webhooks/meta/whatsapp
```

Verify token:

```text
META_WEBHOOK_VERIFY_TOKEN
```

Subscribe to the WhatsApp Business Account webhook fields required by the product, including messages and template-status updates.

WhatsFlow validates `X-Hub-Signature-256`, stores webhook deliveries idempotently, acknowledges quickly, and processes work through queues.

## 4. Embedded Signup flow implemented by WhatsFlow

The real connection flow is available at:

```text
/settings/whatsapp/connect
```

End-to-end sequence:

1. The browser fetches the safe Embedded Signup configuration from the WhatsFlow backend.
2. The Facebook JavaScript SDK opens `FB.login` using the WhatsApp Embedded Signup configuration.
3. WhatsFlow listens only to trusted HTTPS `facebook.com` origins for the `WA_EMBEDDED_SIGNUP` session event.
4. Meta returns an authorization code to the login callback and WABA / phone details through the session event.
5. The customer selects and confirms a six-digit two-step verification PIN in WhatsFlow.
6. The browser sends the authorization code, WABA ID, phone-number ID, optional business ID, and PIN to the authenticated backend endpoint.
7. The backend exchanges the authorization code for the business access token. The token is never sent back to the browser.
8. The backend fetches the selected WABA and verifies that the returned phone-number ID actually belongs to it.
9. The backend registers the number through:

```text
POST /{phone_number_id}/register
```

with:

```json
{
  "messaging_product": "whatsapp",
  "pin": "<six-digit-pin>"
}
```

10. The backend subscribes the app to the WABA through `/{waba_id}/subscribed_apps`.
11. Only after registration and webhook subscription succeed is the WhatsApp account stored as connected.
12. The access token is encrypted at rest. The six-digit PIN is used for registration and is not stored by WhatsFlow.

If registration or webhook subscription fails, no connected account is persisted.

## 5. Permissions / App Review

Check the Meta App Dashboard at submission time because Meta can change permission requirements.

For the current WhatsFlow Embedded Signup + Cloud API flow, prepare review evidence for these permissions:

- `business_management` — required by Meta's Embedded Signup release flow.
- `whatsapp_business_management` — WABA, phone and template management / Embedded Signup release flow.
- `whatsapp_business_messaging` — sending WhatsApp messages and authenticating Cloud API phone registration operations.

Request the level of Advanced Access required by the current Meta dashboard.

## 6. App Review evidence to prepare

Create a dedicated reviewer account on the production deployment. Do not expose a permanent demo password in source control. Use a temporary reviewer password and remove or rotate it after review.

Record clear videos showing the permission being used, not just screenshots of the UI.

### Evidence A — Embedded Signup / business management

1. Log in to WhatsFlow.
2. Go to Settings → WhatsApp → Connect WhatsApp.
3. Open the Meta Embedded Signup dialog.
4. Select the test/reviewer Business Portfolio, WABA and number.
5. Finish onboarding.
6. Return to WhatsFlow and show the number as Connected.
7. Show that the account can sync its WhatsApp templates.

### Evidence B — WhatsApp Business Management

1. Open Templates.
2. Choose the connected Meta number.
3. Create or synchronize a real template.
4. Show its status in WhatsFlow and the corresponding Meta WhatsApp account.

### Evidence C — WhatsApp Business Messaging

1. Open a real conversation in Inbox.
2. Send an allowed free-form message inside the service window or an approved template when required.
3. Show the message arriving on the recipient WhatsApp account.
4. Reply from WhatsApp.
5. Show the inbound reply arriving in the WhatsFlow Inbox through the Meta webhook.
6. If useful, show delivered/read status updating from Meta webhook events.

Reviewer evidence should use real Meta test/reviewer assets available to the app rather than the local fake provider.

## 7. Public legal / compliance URLs

These routes are publicly available and must resolve on the final production domain:

```text
/legal/privacy
/legal/terms
/legal/data-deletion
/legal/support
/legal/company
```

Before App Review, verify that all five pages show the real company name, legal entity, address, support email, and privacy contact. Content can also be overridden from Platform Admin settings (`compliance.*`).

## 8. Business / Tech Provider readiness checklist

Before submitting:

- Meta Business Portfolio uses the real company identity.
- Business verification is completed or in the state required by the current Tech Provider flow.
- Two-factor authentication is enabled for the relevant Meta business/admin accounts.
- The Meta app belongs to the correct Business Portfolio.
- Production HTTPS domain is live and allowed in the Meta app configuration.
- Privacy Policy, Terms, Data Deletion, Support and Company pages are public.
- Embedded Signup configuration ID is production-ready.
- Webhook verification succeeds from Meta.
- A real WABA/phone can complete Embedded Signup.
- Phone registration succeeds.
- WABA webhook subscription succeeds.
- A real outbound message can be sent.
- A real inbound message reaches the Inbox.
- Template create/sync/status flow works with Meta.
- App Review videos and reviewer instructions are prepared for every requested permission.

After App Review, complete the WhatsApp Tech Provider / Access Verification steps shown in the Meta dashboard for the company/app before onboarding external customers broadly.

## 9. Template synchronization

Manual:

- Templates → Sync templates, or
- Settings → WhatsApp → Sync templates for a specific number.

Scheduled:

```text
templates:sync-all
```

runs hourly. `message_template_status_update` webhooks update approval/rejection state in WhatsFlow.

## 10. Local development

Use the fake provider for normal local development when Meta is not required:

```env
WHATSAPP_PROVIDER=fake
```

The real Facebook Embedded Signup dialog should be tested on a registered HTTPS domain because Meta controls domain/origin restrictions. A tunnel can help webhook development, but final App Review evidence should use the production/reviewer environment.
