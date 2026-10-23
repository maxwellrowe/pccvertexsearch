# pcc-vertex-answer-proxy

Bootstrap 5.3 frontend plus PHP 8.1+ REST proxy for the Vertex AI Search Engine Answer API.

## Firestore logging

Logging now defaults to Firestore in project `pcc-success-cent-1530898874821`,
database `pccaisearch`; Google Sheets delivery has been removed. See
[Firestore setup and CSV import](docs/firestore-setup.md) for required IAM,
deployment files, field types, and dry-run migration instructions. No local
storage or cross-request OAuth cache is introduced.

## Features

- `public/api/answer.php` proxy endpoint (POST-only)
- `public/api/recrawl.php` protected endpoint for Cloud Scheduler-triggered site recrawls
- Regional Discovery Engine endpoint defaulted to `https://us-discoveryengine.googleapis.com`
- Prompt preamble with server-side current-date injection
- `includeCitations` enabled and normalized citation output
- Stateless server runtime: no answer/token caches, filesystem logs, or file-backed rate limiter
- Firestore analytics delivery (no local backup)
- CORS allowlist
- Security headers
- Bootstrap test UI and optional `embed.js` script
- Optional `public/widget.php` include for drop-in site integration

## File layout

```text
pcc-vertex-answer-proxy/
  public/
    index.php
    widget-test.php
    embed.js
    widget.php
    api/
      answer.php
  config/
    config.php
    service-account.json   (DO NOT COMMIT)
  composer.json            (optional for runtime; kept for project metadata)
  .gitignore
  README.md
```

## Quick start

1. Put your service account credentials at `config/service-account.json`.
2. Optional environment variables:
```bash
export PCC_GCP_PROJECT_ID="pcc-success-cent-1530898874821"
export PCC_DISCOVERY_LOCATION="us"
export PCC_DISCOVERY_ENGINE_ID="pcc-test-ai-agent-search_1767107861879"
export PCC_DISCOVERY_API_HOST="https://us-discoveryengine.googleapis.com"
export PCC_CORS_ORIGINS="https://www.pasadena.edu,https://example.com"
```
3. Run locally:
```bash
php -S 127.0.0.1:8080 -t public
```
4. Open `http://127.0.0.1:8080`.
5. Optional widget-only test page: `http://127.0.0.1:8080/widget-test.php`.

Recrawl-specific optional environment variables:
```bash
export PCC_DISCOVERY_DATA_STORE_ID="pcc-website_1767107779222"
export PCC_DISCOVERY_RECRAWL_API_HOST="https://discoveryengine.googleapis.com"
export PCC_SCHEDULER_SECRET="replace-with-long-random-secret"
```

## Notes

- No writable application directories are required. Configuration and service-account credentials must be readable.
- Keep `config/service-account.json` server-side only.
- Ensure PHP has `curl` and `openssl` extensions enabled (used for OAuth token exchange).

## Modern Campus deployment (`/_resources` pattern)

This is the recommended approach for integrating into an existing PHP site where you want a single deployable folder and a simple include.

### 1) Deploy one folder

Example destination:

```text
/_resources/vertex/
  public/
    api/answer.php
    api/recrawl.php
    embed.js
    widget.php
    widget-test.php
    css/
  config/
    config.php
    service-account.json
  composer.json
```

Notes:
- `service-account.json` must remain server-side and never be committed.
- Do not deploy storage directories; runtime does not read or write them.
- No Composer install is required for runtime (the proxy now uses native PHP auth).

### 2) CORS allowlist

Set origins in env or config so embedded pages can call the API:

```bash
PCC_CORS_ORIGINS="https://www.yoursite.edu,https://staging.yoursite.edu"
```

### 3) Include the launcher on any PHP template/page

Use `public/widget.php`:

```php
<?php include $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/widget.php'; ?>
```

Default paths used by `widget.php`:
- Script: `/_resources/vertex/public/embed.js`
- API endpoint: `/_resources/vertex/public/api/answer.php`

### 5) Cloud Scheduler recrawl endpoint

This project includes a protected scheduler endpoint at:

```text
/_resources/vertex/public/api/recrawl.php
```

Expected request:

```http
POST /_resources/vertex/public/api/recrawl.php
X-Scheduler-Secret: <your-secret>
Content-Type: application/json
```

```json
{
  "job": "daily_crawl"
}
```

Default config includes the `daily_crawl` job with these URLs:

- `https://pasadena.edu/`
- `https://pasadena.edu/current-students/guide-to-spring.php`
- `https://pasadena.edu/future-students/winter.php`
- `https://pasadena.edu/current-students/guide-to-fall.php`
- `https://pasadena.edu/current-students/guide-to-summer.php`
- `https://pasadena.edu/calendars/index.php`
- `https://pasadena.edu/calendars/registration.php`
- `https://pasadena.edu/calendars/exam-calendars.php`
- `https://pasadena.edu/calendars/academic.php`

Recommended Cloud Scheduler settings for this job:

- Schedule: `0 3 * * *`
- Time zone: `GMT`
- Method: `POST`
- URL: `https://<your-host>/_resources/vertex/public/api/recrawl.php`
- Header: `X-Scheduler-Secret: <same value as PCC_SCHEDULER_SECRET>`
- Body: `{"job":"daily_crawl"}`

The recrawl endpoint calls:

- `POST https://discoveryengine.googleapis.com/v1/projects/{PROJECT_ID}/locations/{LOCATION}/dataStores/{DATA_STORE_ID}/siteSearchEngine:recrawlUris`

Make sure the service account in `config/service-account.json` has permission to recrawl the Site Search datastore.

Optional overrides before include:

```php
<?php
$pccWidgetScript = '/_resources/vertex/public/embed.js';
$pccWidgetEndpoint = '/_resources/vertex/public/api/answer.php';
include $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/widget.php';
?>
```

### 4) Validate after deploy

1. Open a page with the include and click `Ask a Question`.
2. Confirm the modal opens and submits.
3. Confirm the response succeeds; if logging is enabled, verify its Firestore document.
4. If calls fail, verify:
   - service account file path in `config/config.php`
   - allowed origin in `PCC_CORS_ORIGINS`

## SFTP-only deployment checklist (no server terminal)

If your production environment only allows SFTP, you can upload the app directly without Composer on the server.
### 1) Upload to server via SFTP

Upload the following to your target folder (example: `/_resources/vertex/`):

- `public/` (including `api/answer.php`, `api/recrawl.php`, `embed.js`, `widget.php`, `css/`)
- `config/config.php`
- `config/service-account.json`
- `composer.json` (optional)

### 2) Set permissions

PHP needs read access to configuration and the service-account file. No writable
storage directory is needed.

### 3) Add include on any PHP page/template

```php
<?php include $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/widget.php'; ?>
```

### 4) Smoke test

1. Open a page with the include.
2. Click `Ask a Question`.
3. Submit a query and verify response appears.
4. Verify Firestore delivery if enabled, or use `?logging=0` to bypass it.

## Current implementation notes

- Analytics setup, field definitions, deployment steps, and tests: [Tracking update](docs/tracking-setup.md). New analytics use `question_answer_log_v2` and `interaction_events`, without local filesystem backups.

- `public/api/answer.php` uses native PHP OAuth (JWT + token exchange via `openssl` and `curl`).
- `public/api/recrawl.php` uses the same native PHP OAuth pattern to call Discovery Engine's `siteSearchEngine:recrawlUris` endpoint.
- Runtime does not require `vendor/` on the server.
- `public/index.php` is a standalone test UI with endpoint auto-resolution for local docroot differences.
- `public/embed.js` is the deployable modal chat widget used by `public/widget.php`.

### Temporary request timing diagnostics

Open `http://127.0.0.1:8080/?timings=1` or
`http://127.0.0.1:8080/widget-test.php?timings=1`, then ask a question.
The expanded panel above the answer reports browser setup, each request/retry,
PHP stages (including authentication, Vertex, answer processing,
and synchronous analytics delivery), cache status, and DOM update time.
Remove `timings=1` to hide the panel and stop requesting server diagnostics.
The API includes fresh timings only when POST `debug_timings=1` is supplied;
there is no answer cache.

PHP total excludes response serialization and transfer. The approximate time
outside PHP also includes server queueing and browser JSON parsing. Vertex time
is the whole upstream round trip; search and generation cannot be separated
by these measurements. Analytics logging measures the Firestore request when enabled. Every query calls
Vertex, and the timing panel reports `Cache DISABLED`.

### Disable logging for a test

Add `?logging=0` to the containing page URL (standalone or embedded widget).
Combine with diagnostics: `http://127.0.0.1:8080/?timings=1&logging=0`.
This skips Firestore delivery,
including failed attempts and retries. Browser feedback and help-click events
are suppressed, and feedback controls are hidden while logging is disabled.
The timing panel reports `Logging disabled`. Remove `logging=0` to restore
default logging. The switch is per page/request, not a persistent setting.
Browser session state and hosting/access/error logs are unaffected. API clients can use POST `logging=0` for the same write bypass.

Vertex diagnostics also show DNS lookup, TCP connection, TLS handshake,
transfer preparation, request upload plus first-byte wait, and response download.
These intervals are derived from cURL cumulative timestamps and are included in
the Vertex request total, not added to it. First byte from request start is
reported separately as a cumulative measurement. Upload/wait includes network
transit and upstream processing, not just model generation. Failed transfers
mark unreached milestones unavailable.
Deploy `public/api/request-timings.php` alongside the updated `answer.php` and
`public/analytics.js` to enable this breakdown.

### Search UI and inline page

Both modal views keep the search input and submitted query visible above results.
Answers longer than 450 characters show a plain-text preview and an accessible
Read more / Read less button; the complete formatted answer is preserved.
References are inside the bordered answer section. The Bootstrap progress bar
is explicitly estimated, reaches 90% around six seconds, and stays below 100%
until results are ready. Errors stop it; repeated submissions are blocked while
loading. Logging and timing switches continue to work.

Preview the inline view at `/search-test.php?logging=0` or automatically search
with `/search-test.php?q=registration&logging=0`. Inline manual searches start a
new conversation; suggested follow-ups retain conversation context. Submitting
updates the URL's query parameter without navigation.

For your existing PHP template, include this between its header and footer:

```php
<?php
$pccSearchBase = '/_resources/vertex/public';
include $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/search.php';
?>
```

The fragment emits no page shell or header/footer. The host must load Bootstrap
5 CSS; inline mode does not require Bootstrap's modal JavaScript. To use a query
parameter other than `q`, set `$pccSearchQueryParam = 'query';` before including.
Only one widget/search instance is supported per page. Use this inline fragment
instead of the floating widget on your dedicated search page.

An ordinary search form elsewhere can send visitors to your template:

```html
<form action="/search.php" method="get" role="search">
  <label for="site-search">Search PCC</label>
  <input id="site-search" name="q" type="search" maxlength="500">
  <button type="submit">Search</button>
</form>
```

Replace `/search.php` above with the URL of your actual search template.
Deploy the updated `index.php` and `embed.js`, plus new `search-ui.js`, `search.php`,
and (optionally) `search-test.php`. Keep the existing analytics/API files alongside
them. Hard-refresh cached JavaScript after deployment.

### Read-only server deployment

Answer and OAuth token caches, file-backed rate limiting, local analytics backups,
and recrawl logs have been removed. `PCC_CACHE_*` and `PCC_RATE_*` settings no longer
apply. Each answer/recrawl request performs a fresh OAuth exchange. Repeated
questions call Vertex again, so cache speedups are no longer available.

Firestore delivery is synchronous; `logging=0` bypasses it.
There is no durable local retry queue or backup when delivery fails. Browser
localStorage still maintains conversation state; it does not write on the server.
Hosting-managed access/error logs are separate from application storage.

The old `csv-write-test.php` now returns HTTP 410 without writing anything. Existing
storage files are left untouched but never used. Deploy all updated PHP endpoints,
`config/config.php`, and the retired write-test page together. If rate limiting is
needed before database-backed storage returns, configure it at the hosting/proxy
layer; the application no longer enforces its old per-IP bucket.
