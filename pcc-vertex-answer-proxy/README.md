# pcc-vertex-answer-proxy

Bootstrap 5.3 frontend plus PHP 8.1+ REST proxy for the Vertex AI Search Engine Answer API.

## Features

- `public/api/answer.php` proxy endpoint (POST-only)
- Regional Discovery Engine endpoint defaulted to `https://us-discoveryengine.googleapis.com`
- Prompt preamble with server-side current-date injection
- `includeCitations` enabled and normalized citation output
- Basic IP rate limiting (token bucket using filesystem state)
- Filesystem cache for responses
- Basic JSON-line request logging
- CORS allowlist
- Security headers
- Bootstrap test UI and optional `embed.js` script

## File layout

```text
pcc-vertex-answer-proxy/
  public/
    index.php
    embed.js
    api/
      answer.php
  config/
    config.php
    service-account.json   (DO NOT COMMIT)
  storage/
    cache/
    rate/
    logs/
  composer.json
  .gitignore
  README.md
```

## Quick start

1. Install dependencies:
```bash
cd pcc-vertex-answer-proxy
composer install
```
2. Put your service account credentials at `config/service-account.json`.
3. Optional environment variables:
```bash
export PCC_GCP_PROJECT_ID="pcc-success-cent-1530898874821"
export PCC_DISCOVERY_LOCATION="us"
export PCC_DISCOVERY_ENGINE_ID="pcc-test-ai-agent-search_1767107861879"
export PCC_DISCOVERY_API_HOST="https://us-discoveryengine.googleapis.com"
export PCC_CORS_ORIGINS="https://www.pasadena.edu,https://example.com"
```
4. Run locally:
```bash
php -S 127.0.0.1:8080 -t public
```
5. Open `http://127.0.0.1:8080`.

## Notes

- Ensure `storage/cache`, `storage/rate`, and `storage/logs` are writable by PHP.
- Keep `config/service-account.json` server-side only.
