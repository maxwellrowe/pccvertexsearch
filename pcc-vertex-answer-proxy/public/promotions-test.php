<?php
// Local dashboard uses live Firestore data without an SSO login.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
  http_response_code(404);
  exit;
}
header('Cache-Control: private, no-store');
$pccPromotionsCanManage = true;
$pccPromotionsActor = 'local-developer';
$pccPromotionsBase = '.';
ob_start();
include __DIR__ . '/promotions.php';
$promotionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PCC promoted searches</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/bootstrap-override.css" rel="stylesheet">
  <link href="./css/legacy.css" rel="stylesheet">
  <link href="./css/utilities.css" rel="stylesheet">
  <link href="./css/components.css" rel="stylesheet">
  <link href="./css/pcc-custom.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/2.2.2/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
  <script src="https://cdn.datatables.net/2.2.2/js/dataTables.min.js" defer></script>
  <script src="https://cdn.datatables.net/2.2.2/js/dataTables.bootstrap5.min.js" defer></script>
</head>
<body><main class="container py-4">
  <p class="alert alert-info">Local promotion management — changes update live Firestore rules. Audit identity: local-developer.</p>
  <?= $promotionsHtml ?>
</main></body>
</html>
