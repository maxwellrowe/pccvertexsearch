# Tracking update

## Deploy

1. In the existing spreadsheet, open **Extensions → Apps Script**. Replace the receiver code with [`scripts/google-sheets-webhook.gs`](../scripts/google-sheets-webhook.gs).
2. Under **Project Settings → Script properties**, add `WEBHOOK_TOKEN` with the same token already configured in the PHP proxy. The token belongs in settings, not the script source.
3. Update the existing web app deployment to a **new version**, retaining its current execution/access settings and `/exec` URL. Saving code alone does not update a versioned deployment. Deploy the receiver first; it also accepts the old proxy's payloads during rollout, leaving the legacy IP column blank.
4. Upload these files together, preserving their relative locations:
   - `public/analytics.js` (new)
   - `public/embed.js`
   - `public/index.php`
   - `public/api/tracking.php` (new)
   - `public/api/answer.php`
   - `public/api/feedback.php`
5. Invalidate cached copies of `embed.js`, or set a new `$pccWidgetVersion` in the widget include. Keep the existing PHP configuration and webhook URL/token.
6. Visit a chat page with `?pcc_test=1`. Ask a question, click thumbs-down without entering a comment, submit an optional comment, and click **Visit Help Centers & Support**. Ask a second question and give it thumbs-up. Confirm the records in both new tabs. The first delivery creates each tab automatically.

The existing `question_answer_log` and `feedback` tabs remain intact. Historical IDs and events cannot be reconstructed. The new logs do not backfill old rows.

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

New records omit raw IP addresses. Page URLs omit query strings and fragments. IP addresses are still used by the existing rate limiter; historical files and ordinary hosting access logs are unaffected. Questions, answers, and optional comments remain verbatim content and may contain information supplied by users.

The proxy writes IP-free local backups to `storage/logs/question_answer_log_v2.jsonl` and `storage/logs/interaction_events.jsonl` before attempting Sheets delivery. It no longer appends normal query/feedback traffic to the old CSV files or `requests.log`. Monitor the new JSONL files instead. Keep the existing storage directory inaccessible from the web.

The receiver serializes writes and deduplicates by query/event ID. Re-delivering the same JSONL record will not create another sheet row. Local backups can contain duplicate delivery attempts; deduplicate them by the same IDs if analyzing those files directly. Sheets failures are reported to the PHP error log without logging the secret or response body. Delivery is best effort: there is no automatic replay worker. Saved JSONL records can be reposted to the webhook if delivery fails.

The proxy follows the redirect used by [Google Apps Script Content Service](https://developers.google.com/apps-script/guides/content) and checks the JSON `ok` field, since receiver errors can arrive with HTTP 200.

## Verification

Run from the repository root:

```sh
node --test pcc-vertex-answer-proxy/tests/analytics.test.cjs
python3 pcc-vertex-answer-proxy/tests/tracking_integration.py
```

These tests use browser/Sheets mocks and an isolated PHP configuration with network logging disabled. They cover expiry, storage fallback, linkage, events, receiver deduplication, formula-as-text handling, cache hits, validation errors, timestamps, and IP omission. A live Apps Script/production smoke test is still required after deployment.
