# Tracking update

## Deploy

Follow [Firestore setup](firestore-setup.md) and deploy the listed PHP, configuration,
and frontend files together. Refresh cached JavaScript. Verify a test question,
rating, optional comment and help-link click in the Firestore collections.
Google Sheets delivery and the Apps Script receiver have been removed.

## Reading the data

`question_answer_log_v2` contains one row per query attempt, including returned errors. Join `interaction_events` using `answer_id` (or `query_id` for the query). An automatic invalid-Vertex-session retry has a fresh query ID and links to its first attempt through `retry_of_query_id`; it retains the user turn number. Count successful query rows for answered questions, and exclude retry attempts when counting user submissions.

Each successful response receives a fresh answer ID, including cache hits. Query time means server receipt; answer time means server response preparation, not confirmed browser display. Timestamps are ISO 8601 UTC strings ending in `Z`. Error rows have no answer ID or answer timestamp.

The anonymous session lasts until 30 minutes without a query or interaction, and resets on the next local calendar day. It survives page navigation on the same origin. **New chat** starts a new conversation within the same visit. Session storage falls back to memory when browser storage is blocked, so navigation cannot preserve that session in that case. Different browser origins do not share session storage. The old year-long visitor cookie is no longer created or used.

`previous_query_id` and `turn_number` show query order. `query_kind` distinguishes an initial query, a suggested follow-up click, and a manually entered subsequent query. A subsequent query alone does not prove it is a follow-up. `rephrase_status=suspected` means at least 60% token-set overlap with the prior question; repeated wording can also trigger this flag. Other queries are `unknown`, not confirmed non-rephrases. `rephrase_reason` documents the rule.

Count **only `event_type=feedback`** for rating totals. `rating` is `up` or `down`. A rating is saved on the button click, without requiring a comment; each answer accepts one rating in the UI. Optional negative comments are `feedback_comment` events linked through `parent_event_id`, so they do not increase rating counts.

`event_type=escalation` with `escalation_action=help_centers_support_click` means a click on the specific PCC help-centers URL inside the answer results, including middle-button clicks. It does not indicate a confirmed handoff or page load. Events retain the original answer's IDs even if the visit expires before the click. Navigation clicks use a keepalive request; browser/network interruption can still prevent delivery.

`reference_urls` contains deduplicated URLs from the answer's displayed References list, separated by ` | `. An empty list leaves the cell blank. Additional search-result URLs and the help CTA are not added to this field.

`is_test` is set explicitly by `?pcc_test=1` on the page that sends the query and follows the answer into its events. Keep that parameter on test pages after navigation. `bot_suspected` uses a conservative user-agent pattern, with `bot_reason=user_agent_pattern` when matched. These are filtering signals, not security controls or proof that unflagged traffic is human.

## Logging and delivery

New records omit raw IP addresses. Page URLs omit query strings and fragments. The file-backed rate limiter has been removed; historical files and ordinary hosting access logs are unaffected. Questions, answers, and optional comments remain verbatim content and may contain information supplied by users.

The proxy delivers directly to Firestore when enabled. It does not create
local logs, backups, or temporary fallback files. `logging=0` skips delivery.
The receiver serializes writes and deduplicates by query/event ID. Delivery is
best effort: there is no durable retry queue or automatic replay worker. Firestore
failures are reported to the hosting-managed PHP error log without recording
secrets or response bodies.

## Verification

Run from the repository root:

```sh
node --test pcc-vertex-answer-proxy/tests/analytics.test.cjs
python3 pcc-vertex-answer-proxy/tests/tracking_integration.py
```

These tests use browser/Firestore mocks and an isolated PHP configuration with mocked HTTP and filesystem write functions forbidden. They cover browser session expiry, linkage, events, create-only deduplication, repeated uncached queries, fresh OAuth exchanges, validation errors, and IP omission. A live production smoke test is still required after deployment.
