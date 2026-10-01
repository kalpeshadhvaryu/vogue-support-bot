# Vogue Support Bot

Grok-powered customer support for [Vogue Hosting](https://voguehosting.com). Version 1 runs as a WHMCS addon on the same server as the client area at `my.voguehosting.com`. It answers from your products, knowledgebase, announcements, and an FAQ you edit in the addon, and it can look up the current client's services, domains, invoices, and tickets.

Three channels share one conversation store:

- **WHMCS client area.** A chat widget for logged-in clients. Account tools use that WHMCS session.
- **voguehosting.com.** One script tag. Anonymous visitors get public answers and can open a ticket. They cannot see account data.
- **WhatsApp via Interakt.** Inbound `message_received` webhooks. The sender's number is matched to a client phone. Billing details wait until they confirm the account email or the last 4 digits of an invoice.

The model is [xAI Grok](https://docs.x.ai) through the OpenAI-compatible chat completions API, with function calling. The default model id is `grok-4.7`, which is the id in xAI's current function-calling docs and the Grok 4.7 model card. Change it in the addon settings when you want a different model.

## Architecture

```text
Client area widget  ──session + CSRF──►  callback/client-chat.php ─┐
voguehosting.com    ──CORS + token────►  callback/public-chat.php ─┼─► ChatOrchestrator
Interakt webhook    ──HMAC signature──►  callback/whatsapp.php    ─┘        │
                                                                          Grok (api.x.ai)
                                                                          tool calls
                                                                               │
                                                                               ▼
                                                                     WHMCS localAPI
                                                                     (this client's id only)
```

PHP code lives in `modules/addons/voguesupportbot`. Classes are PSR-4 under `lib/` (`VogueHosting\SupportBot\`). WHMCS loads them with `lib/Autoload.php`. Composer is only needed to run the unit tests on a developer machine. Do not run Composer on the WHMCS server, and do not commit `vendor/`.

Secrets (xAI key, Interakt key, webhook secret) are WHMCS addon settings in `tbladdonmodules`. They are not hard-coded and they are not in this repository.

Tables, created on activate:

| Table | Purpose |
| --- | --- |
| `mod_voguesupportbot_conversations` | Channel, client id, identity, handoff ticket, token use |
| `mod_voguesupportbot_messages` | User, assistant, and tool-audit messages |
| `mod_voguesupportbot_kb_cache` | Keyword cache of products, articles, and announcements |
| `mod_voguesupportbot_rate_limits` | Per client, IP, or phone |
| `mod_voguesupportbot_webhook_events` | Interakt message ids, so retries are not answered twice |

Deactivate removes the addon settings. The tables stay unless **Drop tables on deactivate** is on.

## Requirements

- WHMCS 8.x on PHP 8.1 or newer, with curl, json, and mbstring (WHMCS already needs these).
- An xAI API key.
- For WhatsApp: an Interakt account on a plan that can send incoming-message webhooks, plus the API key from Developer Settings.
- A WHMCS admin user the bot can pass to `localAPI`, and a support department id for tickets.

## Install

1. Copy the folder `modules/addons/voguesupportbot` into the WHMCS root, so the module file is:

   `modules/addons/voguesupportbot/voguesupportbot.php`

2. In the WHMCS admin area, open **System Settings > Addon Modules** (older installs: **Setup > Addon Modules**).

3. Find **Vogue Support Bot**, click **Activate**, then **Configure**.

4. Grant your admin role access to the addon on that same page, or the conversation log stays hidden.

5. Fill in the settings below and save. Leave the channel toggles off until the keys and the department id are in place.

6. Open **Addons > Vogue Support Bot** to copy the website snippet and the Interakt webhook URL, and to read the conversation log.

## Configure

| Setting | What to put |
| --- | --- |
| xAI API key | Key from the [xAI console](https://console.x.ai). Stored as a password field. |
| Model | `grok-4.7` unless you have a reason to change it. |
| Brand voice | How the assistant should sound. Safety rules are added in code and win over this text. |
| FAQ and extra context | Policies or plan notes that are not in the knowledgebase. |
| Interakt API key | Developer Settings secret. Sent as `Authorization: Basic` plus the key, which is how Interakt's published curl example uses it. |
| Interakt webhook secret | The secret you type into Interakt when you save the webhook. The addon checks `Interakt-Signature` against the raw body. |
| WhatsApp template name | Approved template used **only** when the 24-hour customer-care window is closed. Give it exactly one body variable. Language defaults to `en`. |
| Allowed website origins | One origin per line, with `https`, for example `https://voguehosting.com` and `https://www.voguehosting.com`. No wildcards. The WHMCS system URL is allowed as well. |
| Human handoff department ID | Numeric id from **Support > Support Departments**. New tickets and handoffs both open here. |
| WHMCS API admin username | See permissions below. |
| Channel toggles | Client area, website embed, WhatsApp. Each is independent. |
| Max tokens / conversation budget | Defaults are 4096 per call and 12000 per conversation. A conversation that hits the budget asks the customer to open a ticket. On WhatsApp they can send `new chat`. |
| Messages per 10 minutes | Default 12, counted separately per client, per site IP, and per phone. |
| Default phone country code | `91`. Used when a WHMCS phone has no country code, so `9876543210` matches WhatsApp `+91 98765 43210`. |
| Trust X-Forwarded-For | Off unless WHMCS sits behind a proxy you control. |
| Drop tables on deactivate | Off. Turn on only when you want the tables deleted. |

Set **Configuration > System Settings > General Settings > WHMCS System URL** to the public `https://my.voguehosting.com` address. The widget and webhook URLs are built from it.

## Client area widget

Turn on **Enable client area chat**. The `ClientAreaFooterOutput` hook adds the widget for logged-in clients. No template edit.

The browser posts to `callback/client-chat.php` with the WHMCS session cookie and a CSRF token stored in that session. The client id always comes from `$_SESSION['uid']`. The model cannot pass a different one.

Guests in the client area see the widget only when the website channel is also on. That path is anonymous.

## Website embed

Turn on **Enable website embed**, add the marketing origins, then paste the snippet from the addon admin page into `voguehosting.com`. It is one script tag:

```html
<script
  src="https://my.voguehosting.com/modules/addons/voguesupportbot/assets/widget.js"
  data-endpoint="https://my.voguehosting.com/modules/addons/voguesupportbot/callback/public-chat.php"
  data-channel="website"
  data-title="Vogue Hosting"
  defer
></script>
```

The script calls the public endpoint with `credentials: 'omit'`, so a WHMCS session cookie is not sent. CORS echoes the request origin only when it is on the allow-list. Visitors can ask about plans and can open a ticket (the assistant will ask for a name and email). They cannot list services or invoices, and an email typed into the chat is not used to look up an account.

## Interakt webhook

1. Turn on **Enable WhatsApp** and save the API key and webhook secret.
2. In Interakt, open [Developer Settings](https://app.interakt.ai/settings/developer-setting).
3. Subscribe to incoming customer messages (`message_received`). Delivery-status events are acknowledged and ignored.
4. Set the webhook URL shown in the addon admin page:

   `https://my.voguehosting.com/modules/addons/voguesupportbot/callback/whatsapp.php`

5. Use the same secret as **Interakt webhook secret**.

Interakt signs the raw body with HMAC-SHA256 and sends `sha256=` plus the hex digest in `Interakt-Signature`. A bad signature is rejected with HTTP 401. A valid customer message is acknowledged with HTTP 200 before the model runs, using `fastcgi_finish_request` when PHP-FPM provides it, because Interakt disables a webhook after repeated timeouts (they ask for a response within about 3 seconds and they do not retry).

Replies go to `POST https://api.interakt.ai/v1/public/message/`.

- Inside 24 hours of the customer's last message, the addon sends a session text message (`type: Text`).
- Outside that window it sends the configured template. Free-form text is not sent, because WhatsApp would reject it.

Phone matching ignores spaces, dashes, a leading `0`, and a `+91` country code (or whatever default you set). If several clients share the number, nothing is linked until the email or invoice check identifies exactly one of them. More than five matches are treated as anonymous.

## WHMCS API permissions

`localAPI` runs as **WHMCS API admin username**. On WHMCS 8 the username is what lets a client-area request act with staff rights. That admin role needs to be able to:

- View clients and client details
- View clients' products/services, domains, and invoices
- List, view, open, and reply to support tickets
- Call `GetProducts` and `GetAnnouncements`

Use a dedicated admin with that role rather than a full super-admin if you can. After a test chat, **System Logs > Module Log** shows each `localAPI` command. Failed commands usually mean this user is missing a permission or the department id is wrong.

There is no WHMCS 8 localAPI command for the knowledgebase. Public articles are read from `tblknowledgebase`. Rows marked private are skipped. Passwords and usernames are not copied out of service records. Ticket replies are refused unless `GetTicket` says the ticket belongs to the linked client. A missing ticket and someone else's ticket return the same "not found" result.

Knowledge is cached in `mod_voguesupportbot_kb_cache` and refreshed on the TTL, or from **Refresh knowledge cache** on the addon page. The model also receives the FAQ field every time. Retrieval is keyword scoring (title matches count more than body matches). There is no vector database.

## What the model can call

| Tool | Who can use it |
| --- | --- |
| `search_knowledge` | Everyone |
| `open_ticket` | Everyone. Anonymous visitors must supply a name and email. That email is not used to attach a client record. |
| `handoff_to_human` | Everyone. The server writes the real transcript onto a ticket in the handoff department and the assistant is told to share the ticket number. |
| `list_services`, `list_domains`, `list_tickets`, `reply_ticket` | Logged-in client area session, or a WhatsApp number matched to one client |
| `list_invoices` | Client area session, or WhatsApp after `verify_identity` |
| `verify_identity` | WhatsApp only, and only before billing is confirmed |

A human handoff on an anonymous chat is opened by the API admin so WHMCS does not need a fake customer email. The transcript is on the ticket. The customer still sees the ticket number in the chat.

## Safety

- Rate limit per client, IP, and phone. Website IPs use `REMOTE_ADDR` unless you enable the proxy option.
- Per-conversation token budget, and a cap on tool rounds (three, then one reply with tools turned off).
- The system prompt tells the model that user text, knowledge, and tool results are data, not instructions, and that those rules override the brand voice.
- Account tools ignore any client id the model tries to send. The validator rejects `clientid`, `userid`, passwords, and unknown fields.
- Public chat does not read the WHMCS session.
- Errors go to `logActivity` and external calls go to `logModuleCall`. The xAI and Interakt keys are passed as module-log redaction strings.
- Widget text is inserted with `textContent`. Admin output is escaped.

## Tests

From this repository (PHP 8.1+ and Composer):

```bash
composer install
composer test
```

PHPUnit covers phone normalisation, knowledge selection, tool-argument rules, the Interakt signature, the 24-hour window, CORS, rate limiting, billing checks, webhook parsing, the Grok response parser, and the tool executor with WHMCS, xAI, and Interakt faked out. Nothing in the suite calls a live API.

## Operations notes and open points

Checked against the docs while building this (October 2026):

- xAI chat completions: `POST https://api.x.ai/v1/chat/completions`, bearer auth, OpenAI-style `tools` / `tool_calls`. The header `x-grok-conv-id` is set to the conversation id, which xAI documents for prompt caching on chat completions. xAI's newer Responses API (`/v1/responses`) is not used. Move to it later if chat-completions tool calls start lagging the docs.
- `reasoning_effort` is not sent. The API default applies. If replies feel slow or get cut off, raise **Max tokens per model call** before changing the model. Reasoning models spend part of `max_tokens` on reasoning.
- Interakt webhooks and the template send body follow the resource-center articles: HMAC in `Interakt-Signature`, and `POST /v1/public/message/` with `countryCode`, `phoneNumber`, and `type: Template`.
- **TODO:** the public Interakt article only prints the template example. Session text uses the same URL with `type: Text` and `data.message`, which matches their inbound `message_content_type` and the community PHP SDK. Confirm that body in Interakt's current Postman collection before relying on WhatsApp replies. If the number cannot be split with your default country code, the request uses `fullPhoneNumber` instead, which also needs that confirmation.
- **TODO:** outside the 24-hour window the template must have exactly one body variable. If your approved template differs, adjust `InteraktPayload::template()` rather than guessing extra variables.
- **TODO:** on PHP without `fastcgi_finish_request` (some non-FPM setups) the webhook stays open until Grok returns. Prefer PHP-FPM. Interakt will not retry a timeout.
- Confirm the API admin can call `GetProducts`, `GetClientsProducts`, `GetInvoices`, `GetClientsDomains`, `GetTickets`, `GetTicket`, `OpenTicket`, `AddTicketReply`, and `GetAnnouncements` by sending one logged-in test message and reading the module log.
- WhatsApp billing checks are only as strong as the phone number plus an email or four invoice digits. They are not a replacement for the client-area login.

## Layout

```text
modules/addons/voguesupportbot/
  voguesupportbot.php          Addon config, activate, deactivate, admin output
  hooks.php                    Client-area widget
  assets/widget.js             One-file widget (styles included)
  callback/                    client-chat.php, public-chat.php, whatsapp.php
  lib/                         PSR-4 classes
tests/                         PHPUnit
```
