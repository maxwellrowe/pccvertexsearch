<?php
declare(strict_types=1);
header('Cache-Control: private, no-store');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') { http_response_code(405); exit; }
require_once __DIR__.'/management-access.php';
try {
  $config=require __DIR__.'/../../config/config.php';
  $claims=pcc_admin_claims($_SERVER['HTTP_X_PCC_ADMIN_TOKEN']??null,$config);
} catch (Throwable $e) {
  http_response_code(403); echo 'Your dashboard access has expired. Reopen the protected page to sign in again.'; exit;
}
// Only server-signed claims grant access; request fields cannot choose a role or actor.
$pccAdminAjax=true;
$_SERVER['REQUEST_URI']=$claims['path'];
$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/api/management-fragment.php',2)),'/');
try {
  if ($claims['mode']==='promotions') {
    $pccPromotionsCanManage=true;
    $pccPromotionsActor=$claims['actor'];
    $pccPromotionsBase=$base;
    $_GET=['promotion_edit'=>$_POST['promotion_edit']??''];
    require __DIR__.'/../promotions.php';
  } else {
    $pccDashboardAuthorized=true;
    $pccDashboardBase=$base;
    $_GET=$_POST;
    require __DIR__.'/../dashboard.php';
  }
} catch (Throwable $e) {
  http_response_code(500); echo 'Unable to load this section. Please try again.';
}
