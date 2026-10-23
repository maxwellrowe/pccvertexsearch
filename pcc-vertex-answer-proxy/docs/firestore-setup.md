# Firestore logging and CSV migration

Target: project `pcc-success-cent-1530898874821`, named database `pccaisearch`.

## Deployment

Deploy the updated `public/api/answer.php`, `feedback.php`, `tracking.php`,
`recrawl.php`, plus new `google-auth.php` and `firestore.php`. Deploy
`config/config.php` and `config/firestore-schema.json` together. Keep the existing
service-account JSON outside the public directory. No Composer or writable
server storage is required.

The configured service account must have Firestore document-create access to this
database. Service-account REST requests use IAM, not browser Security Rules.
Use a scoped IAM grant/custom role as appropriate; do not open browser rules.
Verify that the Firestore API is enabled. No IAM changes are made by this code.

Defaults:

- `PCC_FIRESTORE_LOG_ENABLED=1`
- `PCC_FIRESTORE_PROJECT_ID=pcc-success-cent-1530898874821`
- `PCC_FIRESTORE_DATABASE_ID=pccaisearch`
- `PCC_FIRESTORE_TIMEOUT_SECONDS=3`
- `PCC_ENVIRONMENT=production` (use `development` locally)

`?logging=0` disables logging. Requests reuse the Vertex OAuth token for
Firestore within the same request. Feedback and validation-error requests obtain
their own token when needed. This is not a persistent OAuth token cache.

Writes are synchronous and best effort; measure `analytics_logging` to assess
latency. An answer is still returned if logging fails, with
`meta.logging_delivery=failed`. Feedback reports HTTP 503 when Firestore delivery
fails so the browser does not falsely thank the user for a saved rating. No local
queue/replay storage exists. Google Sheets delivery is removed; old `PCC_GSHEETS_*` variables are ignored.

## Fields and document IDs

`config/firestore-schema.json` defines each field and type. Fields are created
atomically with the first real document; no manual field creation or schema
placeholder documents are required. Existing `_placeholder` records can be
removed manually after verifying real records.

- `question_answer_log_v2/{query_id}`
- `interaction_events/{event_id}`

Timestamps use Firestore timestamp values, flags use booleans, counts/status codes
use integers, and `reference_urls` is an array. The schema includes the existing
Sheets fields plus `environment`. Empty timestamps become null. Unknown fields
are not imported. Question/answer text and comments are retained as supplied.
Avoid indexing long content fields unless needed for a query.

Writes are create-only. A confirmed `ALREADY_EXISTS` is treated as already
successfully delivered. Existing records are never overwritten. Answer IDs for
new queries are deterministic from query IDs, preserving linkage on repeated
delivery; historical imports retain original answer IDs.

## Import existing Google Sheets rows

Export each current schema-v2 tab separately using Google Sheets CSV download.
Preserve ISO timestamps with explicit time zones and existing IDs. Legacy tabs
without these IDs require a separate migration, not invented replacements.

Header-only templates are provided in `scripts/question_answer_log_v2-template.csv`
and `scripts/interaction_events-template.csv`. They are optional references for
new CSV files, not dummy data to import.

From the project directory, validate both exports first:

```sh
php scripts/import-firestore.php --questions=/path/questions.csv --events=/path/events.csv
```

The default dry run validates all rows without authenticating or writing remotely.
Conflicting duplicate IDs or invalid types stop the import before any writes.
When ready, run the same command with `--write`:

```sh
php scripts/import-firestore.php --questions=/path/questions.csv --events=/path/events.csv --write
```

Run imports from a trusted local terminal; do not upload CSV exports into the
public web directory. The importer refreshes its token for long runs, reports
failed writes, and can be rerun safely. Import before accepting new traffic if
you need historical documents to win ID conflicts. Keep exports until verified.

## Smoke test

Submit a synthetic query with `?pcc_test=1&timings=1` (without `logging=0`), then
submit a feedback rating. Confirm a document in each collection with matching
query/answer IDs and `is_test=true`. Check `meta.logging_delivery` and the timing
panel. Do not infer live IAM access from the mocked tests.
