# Promoted search management

Promotions are separate from Vertex grounding. The existing `:answer` call is unchanged. PHP returns `promoted_results`, removes matching URLs from the organic display list without reordering it, and both search frontends render featured links before organic results. Answers, grounding references, logging and feedback retain their existing behavior.

## Deploy

Upload:
- `public/api/promotions.php` and updated `public/api/answer.php`
- Updated `public/embed.js` and `public/index.php` (bump the embed script version in your asset)
- `public/promotions.php` and `public/promotions.js`
- Updated `public/dashboard.php` for the management navigation link

Do not deploy `promotions-test.php` or `dashboard-test.php`; those local wrappers intentionally bypass SSO for loopback requests.

Create a published PHP page at `/strategic-communications-and-marketing/ai-search-promotions.php`. Reuse the site's Bootstrap/PCC styles and DataTables JavaScript/Bootstrap integration. No new JavaScript dependencies or DataTables extensions are required.

Inside the protected page body, after your existing SSO and edit-permission check, include the component:

```php
<?php
// Run your site's real authorization logic before this block.
// $canManagePromotions and $authenticatedStaffId must come from trusted server-side identity/roles.
$pccPromotionsCanManage = $canManagePromotions;
$pccPromotionsActor = $authenticatedStaffId;
$pccPromotionsBase = '/_resources/vertex/public';
require $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/promotions.php';
?>
```

The component supports body-only PHP assets: no output buffering, cookies or sessions are required. Forms use signed tokens bound to administrator identity and page path, expiring after one hour. If headers have already been sent, successful saves navigate with JavaScript and provide a Continue link as a fallback; use that link rather than refreshing a submitted form. Direct requests to the component are denied. The existing reporting flag does not grant edit access. Configure the protected host page to avoid shared caching; a body include cannot change cache headers already sent. If the host cannot provide individual staff identity, an explicit shared audit identity is supported, but all edits will be attributed to it.

The existing dashboard links to the suggested management URL. Override `$pccPromotionsUrl` before including the dashboard if your published path differs.

## Firestore and IAM

Use the existing project `pcc-success-cent-1530898874821` and database `pccaisearch`. The collection `promoted_searches` is created automatically on the first saved promotion; no placeholder documents, schema import, extra collections or indexes are required. Firestore assigns document IDs.

The existing service account needs document list/get/create/update/delete permissions. If its existing Editor role is still present, no additional role should be needed. A narrower standard role for these data operations is Cloud Datastore User (`roles/datastore.user`); no Firebase Admin or Owner role is required. Server OAuth/IAM protects storage; this does not depend on browser Firestore rules. Live read access was verified; production writes have not been exercised.

Stored fields: title, url, description, keywords (array), match_type, priority (integer), enabled (boolean), optional start_date/end_date (timestamps or null), created_at/updated_at (timestamps), created_by/updated_by (trusted administrator identity). Updates preserve creation metadata. Deleting a promotion deletes its metadata as well; no separate audit history is introduced.

## Matching and performance

- Exact matching normalizes case and repeated whitespace. Keywords are OR conditions.
- Contains matching respects Unicode letter/number boundaries.
- Regex accepts raw patterns without delimiters. Supported subset: literals, anchors, character classes, alternation. Groups, repetition quantifiers, lookarounds and backreferences are rejected. PCRE match/recursion limits provide an additional bound.
- Lower numeric priority appears first; ties use title and document ID. Active start is inclusive; end is exclusive. Form dates use Pacific time and storage uses UTC.
- URLs must use HTTP/HTTPS without embedded credentials. Duplicate comparison ignores fragments and trailing slashes, normalizes scheme/hostname case, and preserves query strings. It does not treat different schemes or www aliases as equivalent.
- Optional APCu caches rule lists for 600 seconds. No disk writes, sessions or filesystem cache are used. Administrative changes invalidate the local APCu entry. Other server instances may retain their cached version for up to 10 minutes.
- Without APCu, each successful answer makes a bounded Firestore list lookup (800 ms total budget, normally one request for up to 1,000 rules). This can add latency; it is measured separately as `promotions` in request timings. Enable APCu in the web PHP runtime for the cache. A lookup failure omits featured links and emits a generic server error-log message, without failing normal search.
- Administrative reads allow up to 10 seconds. A 5,000-rule guard bounds this small management tool. Concurrent edits are last-write-wins; updates cannot recreate a deleted document.

## Local review

Open `http://127.0.0.1:8080/promotions-test.php`. It reads and writes **live Firestore**, using audit identity `local-developer`. The reporting preview also links to it. Test Query reads rules and runs the same matching function as public search; it does not call Gemini. Delete requires explicit confirmation.

## Validation

- `php tests/promotions.php`
- `php -d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close tests/promotions-storage.php` (mock APCu; run without the APCu extension)
- `python3 tests/tracking_integration.py`
- `node --test tests/analytics.test.cjs tests/search-ui.test.cjs`

Checks cover exact/contains/regex matches, OR keywords, priority, disabled/scheduled/expired rules, bad URLs and regexes, no matches, cache refresh, generated IDs and metadata, unavailable storage, organic deduplication, unchanged references, authorization denial and existing search/logging/feedback paths. Live local read and missing-CSRF rejection were also checked. Browser visual/accessibility review and the actual Modern Campus role mapping remain deployment checks; no full WCAG conformance claim is made.


## In-place management and reporting

Deploy the shared `public/management-ui.js`, `public/api/management-access.php`, and `public/api/management-fragment.php`, plus the updated `public/promotions.php`, `public/promotions.js`, `public/dashboard.php`, `public/dashboard.js`, and `reports/dashboard-data.php`. Keep `public/api/promotions.php` current as it supplies token validation. Existing page-body includes remain unchanged.

The protected host page issues a signed, one-hour capability to the browser after its authorization gate. Report capabilities only render read-only reports; promotion capabilities are issued only when management permission is granted. The separate fragment endpoint validates the capability on every POST, and promotion writes also validate their form token. It does not accept roles or identities from unsigned request fields. Tokens remain in the page DOM and request header, never URL parameters or persistent browser storage. Do not cache/share authenticated page HTML. Reopen the protected page if access expires; successful fragment updates refresh the one-hour token.

Forms, edit/cancel links and next/previous report batches update the section through same-origin fetch. The browser URL does not change, and no page-login redirect is followed. If your server protects the entire resources directory with the same per-request login, the `api/management-fragment.php` endpoint must be reachable under its own signed-token check; otherwise AJAX cannot bypass that server-level gate. No access is granted without a valid token. Cross-page navigation between reporting and management still goes through the host login.

Ensure the site's CSP permits same-origin scripts and fetch requests, and deploy all files together. Browser JavaScript is required for the in-place behavior; old full-page forms remain the fallback. Save requests are not automatically retried after network errors because a write may already have completed.
