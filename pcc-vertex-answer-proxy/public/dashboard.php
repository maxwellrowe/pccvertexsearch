<?php
// Include ONLY after the host page has completed its server-side SSO check.
// A direct request, or an include without explicit authorization, returns no data.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($pccDashboardAuthorized ?? false) !== true) {
  http_response_code(403);
  return;
}
header('Cache-Control: private, no-store');
require_once __DIR__ . '/../reports/dashboard-data.php';
$pccDashboardBase = $pccDashboardBase ?? '/_resources/vertex/public';
$pccReport = pcc_dashboard_load($_GET);
$pccEscape = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section id="pcc-report" class="my-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div><p class="small text-primary fw-bold text-uppercase mb-1">PCC Search Insights</p>
    <h2 class="h3 mb-1">Reporting &amp; feedback</h2>
    <p class="text-muted mb-0">See what people ask, how search responds, and where it can improve.</p></div>
    <span class="badge bg-light text-dark border rounded-pill px-3 py-2">Firestore reporting</span>
  </div>
  <div class="card border rounded-3 mb-4">
    <div class="card-body p-3 p-lg-4">
      <form method="get" class="row g-3 align-items-center">
        <div class="col-12 col-sm-6 col-lg-3">
          <label for="pcc-report-from" class="form-label small fw-bold mb-2">From</label>
          <input id="pcc-report-from" class="form-control" type="date" name="report_from" required value="<?= $pccEscape($pccReport['from']) ?>">
        </div>
        <div class="col-12 col-sm-6 col-lg-3">
          <label for="pcc-report-to" class="form-label small fw-bold mb-2">Through</label>
          <input id="pcc-report-to" class="form-control" type="date" name="report_to" required value="<?= $pccEscape($pccReport['to']) ?>">
        </div>
        <div class="col-12 col-md-8 col-lg-4">
          <label for="pcc-report-traffic" class="form-label small fw-bold mb-2">Traffic</label>
          <select id="pcc-report-traffic" class="form-select" name="report_traffic"><option value="normal">Exclude tests / suspected bots</option><option value="all" <?= $pccReport['all'] ? 'selected' : '' ?>>All traffic</option></select>
        </div>
        <div class="col-12 col-md-4 col-lg-2">
          <span class="d-none d-md-block small mb-2" aria-hidden="true">&nbsp;</span>
          <button class="btn btn-primary w-100 text-nowrap" type="submit">Load report</button>
        </div>
      </form>
      <p class="small text-muted mb-0 mt-3">Pacific time &middot; Custom date ranges &middot; All environments included</p>
    </div>
  </div>
  <?php if (isset($pccReport['error'])): ?>
    <p class="alert alert-danger" role="alert"><?= $pccEscape($pccReport['error']) ?></p>
  <?php else: ?>
    <?php if ($pccReport['limited'] || $pccReport['continuation']): ?>
      <div class="alert alert-info">Showing one batch of up to 2,000 records per collection. Counts, table searches, and exports apply to this batch only.
      <?php if ($pccReport['limited']): ?><a class="btn btn-primary btn-sm ms-3" href="?<?= $pccEscape(http_build_query($pccReport['next'])) ?>">Next batch &rarr;</a><?php else: ?><strong>Final batch.</strong><?php endif; ?>
      <span class="d-block small mt-2">Use your browser’s Back button for the previous batch, or Load report to restart. Batches are read live; they are not a fixed snapshot.</span></div>
    <?php endif; ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-5 g-3 mb-3">
      <?php foreach ($pccReport['counts'] as $label => $count): ?>
        <div class="col"><div class="card h-100 border rounded-3"><div class="card-body p-3">
          <p class="small text-muted mb-2"><?= $pccEscape($label) ?></p>
          <strong class="display-6 fw-semibold text-primary"><?= $pccEscape(number_format($count)) ?></strong>
        </div></div></div>
      <?php endforeach; ?>
    </div>
    <details class="small text-muted mb-4"><summary>How these numbers are counted</summary><p class="mt-2 mb-0">Counts describe the loaded batch. Searches exclude retry attempts. Errors count failed attempts, including retries. Feedback counts are submitted events, not a satisfaction rate; users who leave no feedback are not represented.</p></details>
    <div class="card border rounded-3 mb-4">
    <div class="card-header bg-light p-3 d-flex align-items-center justify-content-between gap-3">
      <div><h3 class="h5 mb-1">Questions and answers</h3><p class="small text-muted mb-0">Explore searches and open a row’s details to read the full response.</p></div>
      <span class="badge bg-white text-dark border rounded-pill"><?= count($pccReport['questions']) ?> loaded</span>
    </div>
    <div class="card-body p-3 p-lg-4"><div class="table-responsive"><table class="table table-hover align-middle pcc-report-table w-100">
      <thead class="table-light"><tr><th>Time</th><th>Question / answer</th><th>Status</th><th>Environment</th></tr></thead><tbody>
      <?php foreach ($pccReport['questions'] as $row): ?>
        <tr data-export="<?= $pccEscape(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE)) ?>"><td><?= $pccEscape($row['query_timestamp'] ?? '') ?></td><td>
          <div class="fw-semibold mb-2"><?= $pccEscape($row['question'] ?? '') ?></div>
          <details class="small"><summary class="text-primary mb-2">View answer and details</summary><div class="bg-light border rounded p-3 my-2 text-break" style="white-space:pre-wrap"><?= $pccEscape($row['answer'] ?? '') ?></div>
          <p class="small text-muted text-break mt-3 mb-2">Query ID: <?= $pccEscape($row['query_id'] ?? '') ?><br>Retry of: <?= $pccEscape($row['retry_of_query_id'] ?? '') ?><br>Page: <?= $pccEscape($row['page_url'] ?? '') ?></p>
          <ul><?php foreach (($row['reference_urls'] ?? []) as $url): ?><li><?= $pccEscape($url) ?></li><?php endforeach; ?></ul></details>
        </td><td><span class="badge <?= (int) ($row['http_status'] ?? 0) >= 400 ? 'bg-danger' : 'bg-secondary' ?>"><?= $pccEscape(($row['status'] ?? '') . ' ' . ($row['http_status'] ?? '')) ?></span>
          <?php if (trim((string) ($row['error'] ?? '')) !== ''): ?>
            <div class="small text-muted text-break mt-2" style="white-space:pre-wrap"><?= $pccEscape($row['error']) ?></div>
          <?php endif; ?>
        </td><td><span class="badge bg-light text-dark border"><?= $pccEscape($row['environment'] ?? '') ?></span></td></tr>
      <?php endforeach; ?></tbody></table></div></div></div>
    <div class="card border rounded-3 mb-4">
    <div class="card-header bg-light p-3 d-flex align-items-center justify-content-between gap-3">
      <div><h3 class="h5 mb-1">Feedback and interactions</h3><p class="small text-muted mb-0">Read comments alongside the questions that prompted them.</p></div>
      <span class="badge bg-white text-dark border rounded-pill"><?= count($pccReport['events']) ?> loaded</span>
    </div>
    <div class="card-body p-3 p-lg-4"><div class="table-responsive"><table class="table table-hover align-middle pcc-report-table w-100">
      <thead class="table-light"><tr><th>Time</th><th>Event / rating</th><th>Feedback and question</th><th>Environment</th></tr></thead><tbody>
      <?php foreach ($pccReport['events'] as $row): ?>
        <tr data-export="<?= $pccEscape(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE)) ?>"><td><?= $pccEscape($row['timestamp'] ?? '') ?></td><td><?= $pccEscape($row['event_type'] ?? '') ?><?php if (!empty($row['rating'])): ?><span class="badge d-inline-block ms-2 <?= $row['rating'] === 'up' ? 'bg-success' : 'bg-danger' ?>"><?= $row['rating'] === 'up' ? 'Helpful' : 'Not helpful' ?></span><?php endif; ?></td><td>
          <?php if (trim((string) ($row['feedback'] ?? '')) !== ''): ?>
            <div class="bg-light border rounded p-3 my-2 text-break" style="white-space:pre-wrap"><?= $pccEscape($row['feedback']) ?></div>
          <?php else: ?>
            <p class="small text-muted my-2"><?= ($row['event_type'] ?? '') === 'feedback' ? 'Rating submitted. Written comments appear as separate events.' : 'No written comment on this event.' ?></p>
          <?php endif; ?>
          <details class="small"><summary class="text-primary mb-2">View related question</summary>
          <?php $question = $pccReport['by_id'][$row['query_id'] ?? ''] ?? null; ?>
          <p><?= $pccEscape($question['question'] ?? 'Related question is outside the loaded records. Search its query ID in a wider date range.') ?></p>
          <?php if (trim((string) ($question['answer'] ?? '')) !== ''): ?>
            <div class="bg-light border rounded p-3 my-2 text-break" style="white-space:pre-wrap"><?= $pccEscape($question['answer']) ?></div>
          <?php endif; ?>
          <p class="small text-muted text-break mt-3 mb-2">Query ID: <?= $pccEscape($row['query_id'] ?? '') ?><br>Destination: <?= $pccEscape($row['destination_url'] ?? '') ?></p></details>
        </td><td><span class="badge bg-light text-dark border"><?= $pccEscape($row['environment'] ?? '') ?></span></td></tr>
      <?php endforeach; ?></tbody></table></div></div></div>
  <?php endif; ?>
</section>
<script src="<?= $pccEscape(rtrim($pccDashboardBase, '/') . '/dashboard.js') ?>" defer></script>
