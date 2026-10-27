<?php
// Host must supply an explicit management permission and trusted administrator identity.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($pccPromotionsCanManage ?? false) !== true || !is_string($pccPromotionsActor ?? null) || trim($pccPromotionsActor) === '') {
  http_response_code(403); return;
}
require_once __DIR__ . '/api/management-access.php';
$pccPromotionEscape = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pccPromotionError = ''; $pccPromotionNotice = ''; $pccPromotionRows = []; $pccPromotionMatches = null;
$pccPromotionEdit = ['title' => '', 'url' => '', 'description' => '', 'keywords' => [], 'match_type' => 'exact', 'priority' => 0, 'enabled' => true, 'start_date' => null, 'end_date' => null];
$pccPromotionId = ''; $pccPromotionReady = false;
// SSO can forward its own POST to this page; only our forms are mutations.
$pccPromotionSubmission = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
  && array_key_exists('promotion_action', $_POST);
try {
  if (!headers_sent()) header('Cache-Control: private, no-store');
  $pccPromotionConfig = $config ?? require __DIR__ . '/../config/config.php';
  $pccAdminTicket = pcc_admin_ticket('promotions', $pccPromotionsActor, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $pccPromotionConfig);
  $pccPromotionAccount = load_service_account($pccPromotionConfig['service_account_json']);
  $pccPromotionScope = $pccPromotionsActor . ':' . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
  $pccPromotionCsrf = promotion_form_token($pccPromotionScope, $pccPromotionAccount['private_key']);
  if ($pccPromotionSubmission) {
    if (!promotion_form_token_valid($_POST['promotion_csrf'] ?? null, $pccPromotionScope, $pccPromotionAccount['private_key'])) {
      if (!headers_sent()) http_response_code(403);
      throw new InvalidArgumentException('Your form has expired or could not be verified. Reload this page and try again.');
    }
  }
  $pccPromotionToken = fetch_service_account_access_token($pccPromotionAccount, 'https://www.googleapis.com/auth/datastore');
  $pccPromotionRows = promotion_rules($pccPromotionConfig, $pccPromotionToken);
  $pccPromotionReady = true;
  $action = $_POST['promotion_action'] ?? '';
  if ($pccPromotionSubmission) {
    $id = $_POST['promotion_id'] ?? '';
    if (!is_string($id)) throw new InvalidArgumentException('Invalid ID.');
    $current = null;
    foreach ($pccPromotionRows as $r) if ($r['id'] === $id) $current = $r;
    if (in_array($action, ['toggle', 'delete'], true) && !$current) throw new InvalidArgumentException('Promotion not found.');
    if ($action === 'save') {
      $pccPromotionId = $id;
      $pccPromotionEdit = promotion_validate($_POST);
      promotion_save($pccPromotionConfig, $pccPromotionToken, $id, $_POST, $pccPromotionsActor);
      $pccPromotionNotice = 'Promotion saved.';
    } elseif ($action === 'toggle') {
      $current['enabled'] = !$current['enabled'];
      promotion_save($pccPromotionConfig, $pccPromotionToken, $id, $current, $pccPromotionsActor);
      $pccPromotionNotice = 'Status updated.';
    } elseif ($action === 'delete') {
      if (($_POST['confirm_delete'] ?? '') !== '1') throw new InvalidArgumentException('Confirm deletion first.');
      promotion_id($id);
      promotion_request($pccPromotionConfig, $pccPromotionToken, 'DELETE', '/' . rawurlencode($id));
      if (promotion_cache_available()) apcu_delete(promotion_cache_key($pccPromotionConfig));
      $pccPromotionNotice = 'Promotion deleted.';
    } elseif ($action === 'test') {
      $query = $_POST['test_query'] ?? '';
      if (!is_string($query) || strlen($query) > 2000) throw new InvalidArgumentException('Test query is too long.');
      $pccPromotionMatches = promotion_matches($pccPromotionRows, $query);
    } else throw new InvalidArgumentException('Unknown action.');
    if ($pccPromotionNotice !== '') {
      if (!empty($pccAdminAjax)) {
        $pccPromotionRows = promotion_rules($pccPromotionConfig, $pccPromotionToken);
        $pccPromotionReady = true;
      } else {
      // Relative URL keeps navigation on this protected page even after body output.
      if (!headers_sent()) { header('Location: ?promotion_saved=1', true, 303); exit; }
      echo '<div class="alert alert-success" role="status">Changes saved. <a href="?promotion_saved=1">Continue to promotions</a></div>';
      echo '<script>window.location.replace("?promotion_saved=1");</script>';
      return;
      }
    }
  }
  $edit = $_GET['promotion_edit'] ?? '';
  if (is_string($edit) && $edit !== '') {
    promotion_id($edit);
    foreach ($pccPromotionRows as $r) if ($r['id'] === $edit) { $pccPromotionEdit = $r; $pccPromotionId = $edit; }
  }
  $pccPromotionReady = true;
} catch (InvalidArgumentException $e) { $pccPromotionError = $e->getMessage(); }
catch (Throwable $e) { error_log('Promotion management request failed.'); $pccPromotionError = 'Unable to load or save promotions. Check the service-account configuration, Firestore permissions, and server network access.'; }
$pe = $pccPromotionEscape;
?>
<section id="pcc-promotions" class="my-4" data-admin-token="<?= $pe($pccAdminTicket ?? '') ?>" data-admin-endpoint="<?= $pe(rtrim($pccPromotionsBase ?? '/_resources/vertex/public', '/') . '/api/management-fragment.php') ?>">
  <h2 class="h3">Promoted searches</h2>
  <p class="text-muted">Guide searchers to useful resources with featured links. Lower priority numbers appear first.</p>
  <?php if ($pccPromotionError): ?><p class="alert alert-danger" role="alert"><?= $pe($pccPromotionError) ?></p><?php endif; ?>
  <?php if ($pccPromotionNotice !== '' || isset($_GET['promotion_saved'])): ?><p class="alert alert-success" role="status">Changes saved.</p><?php endif; ?>
  <?php if ($pccPromotionReady): ?>
  <div class="card mb-4"><div class="card-body">
    <h3 class="h5"><?= $pccPromotionId ? 'Edit promotion' : 'Add promotion' ?></h3>
    <form method="post" class="row g-3">
      <input type="hidden" name="promotion_csrf" value="<?= $pe($pccPromotionCsrf) ?>">
      <input type="hidden" name="promotion_action" value="save">
      <input type="hidden" name="promotion_id" value="<?= $pe($pccPromotionId) ?>">
      <?php foreach (['title' => 'Title', 'url' => 'Destination URL'] as $key => $label): ?>
      <div class="col-md-6"><label class="form-label" for="promotion-<?= $key ?>"><?= $label ?></label><input class="form-control" id="promotion-<?= $key ?>" name="<?= $key ?>" type="<?= $key === 'url' ? 'url' : 'text' ?>" maxlength="<?= $key === 'url' ? 2048 : 200 ?>" required value="<?= $pe($pccPromotionEdit[$key]) ?>"></div>
      <?php endforeach; ?>
      <div class="col-12"><label class="form-label" for="promotion-description">Description</label><textarea class="form-control" id="promotion-description" name="description" maxlength="1000"><?= $pe($pccPromotionEdit['description']) ?></textarea></div>
      <div class="col-md-6"><label class="form-label" for="promotion-keywords">Keywords or phrases (one per line)</label><textarea class="form-control" id="promotion-keywords" name="keywords" rows="4" required><?= $pe(implode("\n", $pccPromotionEdit['keywords'])) ?></textarea></div>
      <div class="col-md-6"><label class="form-label" for="promotion-match">Match type</label><select id="promotion-match" class="form-select" name="match_type"><?php foreach (['exact', 'contains', 'regex'] as $type): ?><option <?= $pccPromotionEdit['match_type'] === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select><p class="small text-muted mt-2">Any keyword can trigger the result. Regex is advanced: use literals, anchors, character classes or | alternatives; groups and repetition are not supported.</p></div>
      <div class="col-md-3"><label class="form-label" for="promotion-priority">Priority</label><input class="form-control" id="promotion-priority" name="priority" type="number" min="-10000" max="10000" value="<?= $pe($pccPromotionEdit['priority']) ?>"></div>
      <?php foreach (['start_date' => 'Start (Pacific time)', 'end_date' => 'End (Pacific time)'] as $key => $label): ?>
      <div class="col-md-3"><label class="form-label" for="promotion-<?= $key ?>"><?= $label ?></label><input class="form-control" type="datetime-local" id="promotion-<?= $key ?>" name="<?= $key ?>" value="<?= $pe(empty($pccPromotionEdit[$key]) ? '' : (new DateTimeImmutable($pccPromotionEdit[$key]))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d\TH:i')) ?>"></div>
      <?php endforeach; ?>
      <div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" id="promotion-enabled" type="checkbox" name="enabled" value="1" <?= $pccPromotionEdit['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="promotion-enabled">Enabled</label></div></div>
      <div class="col-12 d-flex gap-3"><button class="btn btn-primary">Save promotion</button><a class="btn btn-outline-secondary" href="?">New / cancel edit</a></div>
    </form>
  </div></div>
  <div class="card mb-4"><div class="card-body">
    <h3 class="h5">Test a query</h3><form method="post" class="d-flex gap-3">
      <input type="hidden" name="promotion_csrf" value="<?= $pe($pccPromotionCsrf) ?>"><input type="hidden" name="promotion_action" value="test">
      <label class="visually-hidden" for="promotion-test">Search query</label><input id="promotion-test" class="form-control" name="test_query" maxlength="500" required value="<?= $pe(is_string($_POST['test_query'] ?? null) ? $_POST['test_query'] : '') ?>"><button class="btn btn-primary text-nowrap">Test query</button>
    </form>
    <?php if ($pccPromotionMatches !== null): ?><div class="mt-3" role="status"><p><?= count($pccPromotionMatches) ?> matching promotions (current date and time).</p><ol><?php foreach ($pccPromotionMatches as $r): ?><li><?= $pe($r['title']) ?> — <?= $pe($r['url']) ?></li><?php endforeach; ?></ol></div><?php endif; ?>
  </div></div>
  <div class="card border rounded-3"><div class="card-body p-3"><div class="table-responsive" style="font-size:.85rem"><table id="pcc-promotions-table" class="table table-sm table-hover align-middle w-100">
    <thead class="table-light"><tr><th>Title / destination</th><th>Keywords</th><th>Match</th><th>Priority</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($pccPromotionRows as $r): ?><tr>
      <td><div class="fw-semibold"><?= $pe($r['title'] ?? '') ?></div><div class="small text-muted text-break"><?= $pe($r['url'] ?? '') ?></div></td><td class="text-break"><?= $pe(implode(' · ', $r['keywords'] ?? [])) ?></td><td><?= $pe($r['match_type'] ?? '') ?></td><td><?= $pe($r['priority'] ?? 0) ?></td><td><span class="badge <?= !empty($r['enabled']) ? 'bg-success' : 'bg-secondary' ?>"><?= !empty($r['enabled']) ? 'Enabled' : 'Disabled' ?></span></td><td><?= $pe($r['updated_at'] ?? '') ?></td>
      <td><div class="d-flex flex-wrap align-items-start gap-2"><a class="btn btn-outline-primary btn-sm py-1 px-2" href="?promotion_edit=<?= $pe(rawurlencode($r['id'])) ?>">Edit</a>
        <form method="post" class="m-0"><input type="hidden" name="promotion_csrf" value="<?= $pe($pccPromotionCsrf) ?>"><input type="hidden" name="promotion_id" value="<?= $pe($r['id']) ?>"><button class="btn btn-outline-secondary btn-sm py-1 px-2" name="promotion_action" value="toggle"><?= !empty($r['enabled']) ? 'Disable' : 'Enable' ?></button></form>
        <details><summary class="btn btn-outline-danger btn-sm py-1 px-2">Delete…</summary><form method="post" class="border rounded p-2 mt-2"><input type="hidden" name="promotion_csrf" value="<?= $pe($pccPromotionCsrf) ?>"><input type="hidden" name="promotion_id" value="<?= $pe($r['id']) ?>"><label class="d-block mb-2"><input type="checkbox" name="confirm_delete" value="1" required> Confirm permanent deletion</label><button class="btn btn-danger btn-sm py-1 px-2" name="promotion_action" value="delete">Delete</button></form></details>
      </div></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <?php endif; ?>
</section>
<script src="<?= $pe(rtrim($pccPromotionsBase ?? '/_resources/vertex/public', '/') . '/management-ui.js?v=20261027-3') ?>" defer></script>
<script src="<?= $pe(rtrim($pccPromotionsBase ?? '/_resources/vertex/public', '/') . '/promotions.js?v=20261027-3') ?>" defer></script>
