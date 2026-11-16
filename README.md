# DynaPulse

**Call intelligence and keyword alerting for NetSapiens.** DynaPulse turns recorded calls into
readable transcripts, summaries and sentiment, watches every conversation for the words that matter
to your business, and emails the right people the moment a call is flagged — with a one-click link to
listen to the recording.

- **Manager portal per domain** — sign in with the same credentials you use for NetSapiens.
- **Keyword watchlist + notification list** — admins define multiple keywords and the email
  addresses that should hear about them. Alerts include caller, called number, date/time, AI summary,
  what triggered the notification, and a **Listen** button.
- **NetSapiens Call Intelligence** is the source for transcript, summary, topics and sentiment — no
  third-party speech-to-text or LLM is involved.
- **Reports and scheduled exports** — dashboards, CSV/XLSX downloads, and emailed digests on a daily,
  weekly or monthly cadence.
- **Dark mode by default** (with a light theme), toast notifications, keyboard shortcuts, and a
  responsive layout.

---

## Contents

1. [Requirements](#requirements)
2. [Architecture](#architecture)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [NetSapiens setup](#netsapiens-setup)
6. [Scheduled jobs](#scheduled-jobs)
7. [How alerting works](#how-alerting-works)
8. [Roles](#roles)
9. [Exports and reports](#exports-and-reports)
10. [Security notes](#security-notes)
11. [Testing](#testing)
12. [Troubleshooting](#troubleshooting)

---

## Requirements

| Component | Version / notes |
|---|---|
| PHP | **8.5** (NTS recommended), extensions: `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `json`, `zip`, `fileinfo` |
| Web server | Apache 2.4 (**XAMPP** — `.htaccess` included) or IIS 10+ (`web.config` included); nginx needs the same two rewrite lines |
| Database | SQLite 3 — one file under `private\storage\db` |
| NetSapiens | API v2 access (`/tokens`, `/domains/{domain}/cdrs`, `/transcriptions`, `/recordings`, `/subscriptions`) |
| Mail | Microsoft 365 / Outlook **SMTP AUTH** (`smtp.office365.com:587`, STARTTLS) |
| OS | Windows Server 2016+ (Task Scheduler runs the CLI worker) |

No Composer packages are required at runtime; the app ships with its own autoloader, SMTP client,
MIME builder and XLSX writer. `composer.json` only defines PSR-4 autoloading for development.

## Architecture

```
D:\www\dynapulse\public          <- document root (or a sub-folder of the site)
├── index.php                    <- front controller
├── .htaccess                    <- Apache/XAMPP rewrite + security
├── web.config                   <- IIS rewrite rules, security headers
├── favicon.svg
└── assets\{css,js}

D:\private\dynapulse             <- application (outside the web root)
├── .env                         <- secrets (never served)
├── bootstrap.php                <- Env → Config → Logger → DB → Container
├── routes.php
├── bin\console.php              <- CLI worker (poll, process, notify, reports, prune)
├── migrations\001_init.sql
├── src\                         <- PSR-4 DynaPulse\*
│   ├── Http\                    <- kernel, router, request/response, middleware, controllers
│   ├── Repository\              <- SQLite access layer
│   ├── NetSapiens\              <- API client, CDR mapper, transcription value object
│   ├── Service\                 <- auth, ingest, processing, matching, alerts, reports, export
│   ├── Mail\                    <- SMTP transport + MIME builder
│   └── Support\                 <- config, env, logging, time, strings
├── views\                       <- PHP templates (dashboard, calls, alerts, admin…)
├── tests\                       <- dependency-free suite with a NetSapiens fake
└── storage\                     <- logs, sessions, SQLite, cached recordings
```

**Ingestion has two independent paths** that converge on the same queue:

1. **Webhook** — a NetSapiens `cdr` event subscription POSTs completed calls to
   `/webhook/netsapiens/{secret}`. Posts are JSON arrays and validated twice: the secret in the path
   and (when configured) the `X-Correlation-ID` header.
2. **Poller** — `console.php poll` queries `/domains/{domain}/cdrs` with an overlapping time window,
   so calls are never lost if a webhook post fails. Duplicates merge on the NetSapiens call id.

Each queued call is then processed: Call Intelligence transcript/summary/topics/sentiment →
recording lookup → keyword matching → one grouped alert email.

## Installation

### 1. Place the files

Copy the repository so that the layout matches the tree above:

```
public\   ->  D:\www\DynaPulse\public
everything else -> D:\private\DynaPulse
```

If your paths differ, create `public\private_path.php` containing the absolute path to the private
folder, e.g.

```php
<?php return 'D:\\apps\\DynaPulse-private';
```

(You can also set the `DYN_PULSE_PRIVATE` environment variable in IIS.)

### Sub-folder deployments

`public\` does not have to be a site root. If the site root is `D:\www` and DynaPulse lives in
`D:\www\dynapulse\public`, the portal is simply reached at `http://<server>/dynapulse/public/`.

The URL prefix is detected automatically from `DOCUMENT_ROOT` / `SCRIPT_FILENAME`, and every link,
form action, asset, redirect, cookie path and e-mail URL is generated with that prefix, so both
layouts work without configuration:

```
Site root deployment                     Sub-folder deployment
D:\www\dynapulse\public   ->  /          D:\www                    ->  /
                                         D:\www\dynapulse\public   ->  /dynapulse/public/
```

If the web server hides that information (a reverse proxy that rewrites the path, for example), pin
it explicitly with `public\base_path.php`:

```php
<?php return '/dynapulse/public';
```

The `DYN_PULSE_BASE` environment variable does the same thing. `dp-check.php` shows the detected
prefix under *Request → URL prefix*.

### 2. Configure the web server

**Apache / XAMPP**

Put the public folder inside `htdocs`, e.g. `D:\xampp\htdocs\dynapulse\public`, and keep the
private folder outside it (`D:\private\dynapulse`). Then:

1. In `httpd.conf` make sure `LoadModule rewrite_module modules/mod_rewrite.so` is **not**
   commented out (XAMPP enables it by default).
2. The htdocs `<Directory>` block must say `AllowOverride All` (XAMPP default). With
   `AllowOverride None` Apache ignores `.htaccess`.
3. Restart Apache from the XAMPP control panel.

`public\.htaccess` rewrites every non-file request to `index.php`, and denies dotfiles,
`.sql`/`.sqlite`/`.log`/`.bak` files and `composer.json`. Apache runs as a service account that
normally already has write access to `storage`; if the portal says otherwise, grant that account
Modify on `D:\private\dynapulse\storage`.

> Without `.htaccess` the portal still opens at `http://server/dynapulse/public/` (index.php is the
> directory index) but `/login`, `/calls`, `/dashboard`… return
> “The requested URL was not found on this server”.

**IIS 10+**

Create the site with **Physical path** `D:\www\dynapulse\public` (or point it at the parent folder
for a sub-folder deployment). Install the *URL Rewrite* module. `web.config` already:

- blocks dotfiles and database/log/backup extensions,
- optionally forces HTTPS (the rule ships commented out, because a server reached by IP address
  usually has no certificate — uncomment it once the site has a TLS binding),
- rewrites every non-file request to `index.php`,
- sets MIME types for `.svg`/`.woff2` and 7-day static caching.

Make sure the application pool identity can **write** to `D:\private\dynapulse\storage` (logs,
sessions, SQLite, recording cache).

### 3. Create the environment file

```bat
copy D:\private\DynaPulse\.env.example D:\private\DynaPulse\.env
```

Edit `.env` (see [Configuration](#configuration)), then generate the application key:

```bat
cd /d D:\private\DynaPulse
php bin\console.php key:generate
```

### 4. Create the schema

```bat
php bin\console.php migrate
```

The schema is also created automatically on the first HTTP request when the database is empty.

### 5. Sign in

Open the portal, sign in with a NetSapiens user (e.g. `admin@acme.com`). **The first successful
sign-in becomes the DynaPulse administrator**; every later user is provisioned as a manager of their
own domain. Promote or disable people under *Administration → Portal users*.

Add the domains you want to monitor under **Administration → Domains**, or press *Sync from
NetSapiens*.

## Configuration

All secrets live in `D:\private\DynaPulse\.env`. Behavioural settings (alert threshold, retention,
recipients fallbacks) live in the database and are edited under **Administration → Settings**.

| Key | Purpose |
|---|---|
| `APP_URL` | Public base URL, used in emails and signed recording links. **No trailing slash.** |
| `APP_KEY` | 32+ random chars; signs the “Listen” links. Generate with `console.php key:generate`. |
| `APP_TIMEZONE` | Fallback timezone for domains without their own. |
| `PUBLIC_PATH` | Optional absolute path to the public folder if it is not the site root. |
| `DB_DATABASE` | Optional path to the SQLite file; defaults to `storage\db\dynapulse.sqlite`. |
| `NS_BASE_URL` | `https://ns-api.com` or your own NetSapiens API host. |
| `NS_API_KEY` | Machine API key used to read CDRs, transcripts and recordings. |
| `NS_CLIENT_ID` / `NS_CLIENT_SECRET` | OAuth client used to validate manager sign-ins via `POST /tokens`. |
| `WEBHOOK_SECRET` | Long random string; part of the webhook URL. Treat it as a password. |
| `PROCESS_INLINE` | Process a call immediately after the webhook post (default `true`). |
| `POLL_ENABLED` | Allow the poller to run for domains with polling enabled. |
| `POLL_LOOKBACK_MINUTES` | Overlap window (default 10) so no CDR is missed. |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_ENCRYPTION` | Outlook defaults: `smtp.office365.com`, `587`, `starttls`. |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | Sender mailbox and **app password** (SMTP AUTH must be enabled). |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` / `MAIL_REPLY_TO` | Envelope and display names. |
| `ALLOW_PUBLIC_AUDIO_LINKS` | Enables signed recording links in alert emails (default `true`). |
| `RECORDING_CACHE` | Cache recordings under `storage\recordings` (saves NetSapiens bandwidth). |
| `LOG_LEVEL` | `debug`, `info`, `warning`, `error`. |

Settings you can change in the UI without touching `.env`: alert master switch, minimum severity,
transcript retries, dashboard window, retention, poll lookback, extra always-copy recipients,
default report recipients, and auto-import of unknown domains.

## NetSapiens setup

1. **Create an API key** for the machine account DynaPulse uses to read CDRs, transcriptions and
   recordings. Put it in `NS_API_KEY`.
2. **Create an OAuth client** (client id + secret) for password grants and put both in `.env`. The
   token response’s `domain` field defines the portal scope of the signed-in user.
3. **Create the CDR event subscription.** Either press *Create subscription* under
   **Settings → Ingestion**, or run:

   ```bat
   php bin\console.php subscription:create --scope=all
   ```

   This registers `model=cdr` with `post-url=https://your-host/webhook/netsapiens/<WEBHOOK_SECRET>`.
   NetSapiens requires a publicly reachable **HTTPS** endpoint with a valid certificate.
4. NetSapiens event subscriptions are delivered on a short poll interval (a few seconds), not in real
   time. DynaPulse retries transcript lookups with backoff until Call Intelligence reports
   `finished`, then sends the alert.

## Scheduled jobs

Point Windows Task Scheduler at these commands. All are idempotent and safe to overlap.

| Command | Suggested cadence | What it does |
|---|---|---|
| `php bin\console.php poll` | every 5 minutes | Pulls recent CDRs per pollable domain (safety net). |
| `php bin\console.php process` | every 5 minutes | Drains the processing queue (transcript, recording, matching). |
| `php bin\console.php notify` | every minute | Sends queued alert emails not already sent inline. |
| `php bin\console.php reports:run` | every 15 minutes | Sends report schedules that are due. |
| `php bin\console.php prune` | nightly | Deletes records older than the retention window. |

Create the task with **Start in** set to `D:\private\DynaPulse` so relative paths resolve, or use the
absolute PHP binary path, e.g.

```bat
"C:\Program Files\PHP\php.exe" D:\private\DynaPulse\bin\console.php process
```

Useful diagnostics: `console.php status` (counters plus per-domain last poll / last error),
`console.php ns:test` (checks the API key, the domain list and a real CDR query),
`console.php transcript:test --call=ID [--apply]` (fetches that call's Call Intelligence payload and
shows what was parsed), `console.php calls:reprocess` (retries calls that have a job id but no stored
transcript),
`console.php domains:sync` (import the domains the API key can see),
`console.php test-mail --to=you@example.com`, `console.php migrate`, `console.php help`.

## How alerting works

1. A completed call arrives (webhook or poller) and is stored with the raw CDR.
2. The worker fetches Call Intelligence data: summary, topics, per-segment sentiment and percentages.
3. Every **active** keyword is matched against the fields you chose (summary, topics, transcript,
   caller number, called number) using phrase, whole-word or regex matching.
4. Matches below the minimum severity are recorded but do not email.
5. **One grouped email per call** goes to the domain’s active recipients (plus the global fallback
   list), so five keywords on one call produce one message, not five.
6. The email contains the caller and called numbers, date/time in the domain timezone, duration, the
   Call Intelligence summary, the exact trigger list (keyword, field, snippet, severity) and a
   **Listen to the recording** button. The button uses an HMAC-signed URL, so it works without a
   portal login and expires automatically.

Delivery status is visible per call (*Alerts* card) and per keyword on the *Alerts* page.

## Roles

| Role | Scope |
|---|---|
| **Administrator** | All domains (with an *All domains* switcher), portal users, domains, global keywords, recipients, SMTP/ingestion settings, exports. |
| **Manager** | Everything for their own domain: calls, recordings, keywords, recipients, report schedules. |
| **Viewer** | Read-only access to calls, alerts and reports for their domain. |

The last active administrator cannot be demoted or disabled from the UI.

## Exports and reports

- **Calls** and **Alerts** tables export to **CSV** and **XLSX** with the current filters applied.
- **Transcripts** download as plain text from the call page; recordings download through the proxy.
- **Report schedules** (Reports page) support daily/weekly/monthly cadence, look-back window,
  recipient list, and optional CSV/XLSX attachments. Each run is recorded with call/alert counts and
  any delivery error.

## Security notes

- The private folder is outside the web root; only `public\` is served.
- Logins are validated against NetSapiens; DynaPulse stores no passwords.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` behind HTTPS.
- CSRF tokens are required on every state-changing route, including login.
- Login attempts are rate limited per identity + IP.
- The portal sends a nonce-based CSP, `X-Frame-Options: DENY`, `nosniff` and HSTS on HTTPS.
- The NetSapiens API key never reaches the browser: recordings stream through `AudioController`.
- Signed recording links are scoped to one call, expire (30 days by default) and can be disabled with
  `ALLOW_PUBLIC_AUDIO_LINKS=false`.

## Testing

The suite has no external dependencies and uses an in-memory NetSapiens fake plus a throwaway SQLite
database:

```bat
php tests\run.php
```

It covers CDR mapping, keyword matching (phrase/word/regex/case), HMAC link signing, XLSX generation,
and a full ingest → process → match → alert-queue cycle.

Useful extras while developing:

```bat
php bin\console.php status
php bin\console.php poll 24
php bin\console.php process --limit=10 --budget=45
```

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| “DynaPulse cannot find its private folder” | Create `public\private_path.php` with the absolute private path, or set `DYN_PULSE_PRIVATE`. |
| Apache: “The requested URL was not found on this server” for `/login` | `public\.htaccess` is missing, or `AllowOverride None` is set for the folder in `httpd.conf`. The folder URL still loads because `index.php` is the directory index. |
| Apache: HTTP 500 mentioning `RewriteEngine` | mod_rewrite is not loaded, or `.htaccess` overrides are not allowed — see *Configure the web server*. |
| “Page not found” on the address you opened | The address must include the portal folder, e.g. `http://server/dynapulse/public/`. Open `dp-check.php` and compare *Request → URL prefix* with the address in the browser. |
| Login says credentials were not accepted | `NS_CLIENT_ID`/`NS_CLIENT_SECRET` wrong, or the NetSapiens user lacks API scope. Check `storage\logs`. |
| Emails never arrive | SMTP AUTH disabled on the mailbox, wrong app password, or `MAIL_FROM_ADDRESS` not matching the sender. Use *Send test*. |
| Alerts show “Email not configured” | `MAIL_*` values missing in `.env`; the portal logs `alert.blocked`. |
| Calls appear but no transcripts | Run `php bin\console.php transcript:test --call=ID`. It prints the exact job id, `YYYYMM` and orig/term call ids sent to `/domains/{domain}/transcriptions`, the HTTP status and the parsed summary/segments. `--apply` saves the result and re-runs matching. `php bin\console.php calls:reprocess` retries every call that has a job id but no transcript (useful after a fix, or when processing gave up after 8 attempts). |
| `transcript:test` says HTTP 404 | NetSapiens has no Call Intelligence row for that job/date/call id yet — the job may still be running, or the CDR carried no `call-intelligence-job-id` (Call Intelligence was off when the call happened). |
| Transcript stored but alerts never fired | Keywords are matched against summary, topics and transcript text; check the keyword's *fields* and its severity against `alert_min_severity`. |
| Recording button 404s | No recording on file in NetSapiens (recording disabled for that user), or the media URL expired before download. |
| **No calls at all** (dashboard and Calls list empty) | Nothing has been ingested yet. Run `php bin\console.php ns:test` — it tells you whether the API key works, which domains are configured and whether `/domains/{domain}/cdrs` returns rows. Then `php bin\console.php domains:sync` and `php bin\console.php poll 72`. Without the webhook or a scheduled `poll`, no CDR ever reaches the portal. The same information is on *Settings → Environment health → Ingestion*. |
| `ns:test` says 0 CDR rows for a domain | The window is empty, the domain name does not match NetSapiens exactly, or the API key cannot read CDRs for that domain (HTTP 403). Try `--hours=168` and check the domain name in NetSapiens. |
| `ns:test` says HTTP 401/403 on `/domains` | `NS_API_KEY` is wrong or expired, or the key is scoped to a different domain. |
| Calls exist but the list is empty | The list defaults to the last 30 days (`dashboard_days`); widen the date range on the Calls page. |
| Webhook returns 403 | `WEBHOOK_SECRET` in the URL does not match `.env`, or the subscription id differs from `WEBHOOK_SUBSCRIPTION_ID`. |
| Domain missing after switching | Run *Sync from NetSapiens* under Administration → Domains. |

Logs are written daily to `D:\private\DynaPulse\storage\logs\app-YYYY-MM-DD.log`; the last lines are
also visible under *Settings → Environment health*.
