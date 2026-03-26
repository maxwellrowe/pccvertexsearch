# pcc-vertex-answer-proxy

Bootstrap 5.3 frontend plus PHP 8.1+ REST proxy for the Vertex AI Search Engine Answer API.

## Features

- `public/api/answer.php` proxy endpoint (POST-only)
- `public/api/recrawl.php` protected endpoint for Cloud Scheduler-triggered site recrawls
- Regional Discovery Engine endpoint defaulted to `https://us-discoveryengine.googleapis.com`
- Prompt preamble with server-side current-date injection
- `includeCitations` enabled and normalized citation output
- Basic IP rate limiting (token bucket using filesystem state)
- Filesystem cache for responses
- Basic JSON-line request logging
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
  storage/
    cache/
    rate/
    logs/
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

- Ensure `storage/cache`, `storage/rate`, and `storage/logs` are writable by PHP.
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
  storage/
    cache/
    rate/
    logs/
  composer.json
```

Notes:
- `service-account.json` must remain server-side and never be committed.
- `storage/*` must be writable by the web server user.
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
3. Confirm `storage/logs/requests.log` is being written.
4. If calls fail, verify:
   - service account file path in `config/config.php`
   - writable permissions on `storage/`
   - allowed origin in `PCC_CORS_ORIGINS`

## SFTP-only deployment checklist (no server terminal)

If your production environment only allows SFTP, you can upload the app directly without Composer on the server.
### 1) Upload to server via SFTP

Upload the following to your target folder (example: `/_resources/vertex/`):

- `public/` (including `api/answer.php`, `api/recrawl.php`, `embed.js`, `widget.php`, `css/`)
- `config/config.php`
- `config/service-account.json`
- `storage/cache/`
- `storage/rate/`
- `storage/logs/`
- `composer.json` (optional)

Important:
- Some SFTP clients skip empty folders. If needed, keep `.gitkeep` files in `storage/cache`, `storage/rate`, and `storage/logs`.

### 2) Set permissions

Ensure the web server/PHP process can write to:

- `storage/cache`
- `storage/rate`
- `storage/logs`

### 3) Add include on any PHP page/template

```php
<?php include $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/widget.php'; ?>
```

### 4) Smoke test

1. Open a page with the include.
2. Click `Ask a Question`.
3. Submit a query and verify response appears.
4. Confirm `storage/logs/requests.log` is created and receiving entries.

## Current implementation notes

- `public/api/answer.php` uses native PHP OAuth (JWT + token exchange via `openssl` and `curl`).
- `public/api/recrawl.php` uses the same native PHP OAuth pattern to call Discovery Engine's `siteSearchEngine:recrawlUris` endpoint.
- Runtime does not require `vendor/` on the server.
- `public/index.php` is a standalone test UI with endpoint auto-resolution for local docroot differences.
- `public/embed.js` is the deployable modal chat widget used by `public/widget.php`.
