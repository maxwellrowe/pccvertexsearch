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

At the beginning of the template, before HTML output, run your existing SSO **and explicit edit-permission check**, then prepare the component:

```php
<?php
// Run your site's real authorization logic before this block.
// $canManagePromotions and $authenticatedStaffId must come from trusted server-side identity/roles.
$pccPromotionsCanManage = $canManagePromotions;
$pccPromotionsActor = $authenticatedStaffId;
$pccPromotionsBase = '/_resources/vertex/public';
ob_start();
require $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/promotions.php';
$promotionsBody = ob_get_clean();
?>
```

In the body, output `<?= $promotionsBody ?>`. The component must execute before output because it sets CSRF cookies, no-store headers and post-save redirects. Direct requests to the component are denied. The existing reporting authorization flag does not grant write access. There is no public management API endpoint. If the host cannot provide an identity plus a distinct edit permission, connect that authorization before publishing the manager.

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
