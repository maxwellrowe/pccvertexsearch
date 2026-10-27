# Embed reporting behind Modern Campus SSO

The dashboard is a PHP include, not a browser script that fetches a public API. Its Firestore reads happen during the protected page request. It writes no files and makes no changes to Firestore. Deploy `reports/` alongside `config/` and `public/`.

Inside the protected template, **after the server-side SSO check has succeeded**, include:

```php
<?php
// This branch must only execute for an authenticated, authorized staff user.
$pccDashboardAuthorized = true;
$pccDashboardBase = '/_resources/vertex/public';
require $_SERVER['DOCUMENT_ROOT'] . '/_resources/vertex/public/dashboard.php';
?>
```

Do not set this flag on a public page or rely on JavaScript, hidden HTML, or the Modern Campus editing login as the authorization check. An unauthenticated request must be rejected before the include executes. The include denies direct requests and defaults to denied when the flag is absent. The flag is an integration contract, not an authentication system.

Use a PHP-capable source asset/template. If Modern Campus renders the asset as literal text rather than executing PHP, put the include in the published PHP template. This cannot be installed as only a `<script>` tag. There is no separate reporting data endpoint to secure.

Load the same Bootstrap/PCC CSS as the search page, plus your existing DataTables JavaScript and its Bootstrap styling. Load DataTables before `dashboard.js` executes. Tables remain readable without DataTables, but sorting/search/pagination require it.

The response must have `Cache-Control: private, no-store`; set this in the protected template before any output (the include also attempts to set it). Exclude the protected page from shared/CDN caches. Keep `config/` and `reports/` inaccessible from the web, as with the existing credential setup.

The service account needs Firestore read access to database `pccaisearch`; credentials stay server-side. This dashboard uses the existing configured project and database. Each refresh reads up to 2,001 documents per collection; a Next batch link appears when more than 2,000 records remain. Each batch has its own counts, searches, and exports; use browser Back to revisit prior batches, or Load report to restart. Dates use Los Angeles time with no 31-day restriction; table timestamps retain the stored ISO timestamps.

Counts exclude tests and suspected bots by default, include all environments, and describe the loaded date window. Search counts exclude retry attempts. Feedback counts count events, not unique respondents or satisfaction percentages. Related answers are shown only when the query is also in the loaded window. Performance stage timings are not stored in these collections and are not available in this report.

Before publishing, verify signed-out requests to the host page reveal no report or data, direct requests to `public/dashboard.php` return 403, and authorized staff can load both tables and change the date range. Test SSO on the actual deployed host; local checks cannot establish how the Modern Campus template authenticates users.

## Local review

Open `http://127.0.0.1:8080/dashboard-test.php` with the local PHP server running. This page reads live Firestore data using the existing configured credentials and requires no SSO. It accepts loopback requests only and supports the date and traffic filters. The live-site embed continues to rely on the host page for authorization.

CSV export buttons work with the existing DataTables API and need no additional extension. They export all matching rows in the loaded batch across table pages, with the original stored fields and full answer text. Table search affects export, but does not change summary cards. CSV fields escape quotes and protect against spreadsheet formula evaluation. Large reporting periods require separate batch exports; whole-period aggregation and a single all-batches export are not included.

## AJAX updates

The updated dashboard uses `management-ui.js`, `api/management-access.php`, `api/management-fragment.php`, and `api/promotions.php` for signed, one-hour access to in-place report updates. Deploy these with `dashboard.php`, `dashboard.js`, and `reports/dashboard-data.php`. Filters and next/previous batches no longer reload the protected page or change its query string. The endpoint requires a signed report capability issued by the authorized page; it is not an unauthenticated reporting endpoint. See `promotions-setup.md` for token and host-login deployment details.
