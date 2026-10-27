<?php
require __DIR__.'/../public/api/management-access.php';
// No live credentials or network needed.
$path=tempnam(sys_get_temp_dir(),'pcc-admin-test-');
file_put_contents($path,json_encode(['client_email'=>'test@example.invalid','private_key'=>'fixture-secret']));
$config=['service_account_json'=>$path];
try {
  foreach (['report','promotions'] as $mode) {
    $ticket=pcc_admin_ticket($mode,'staff','/protected.php',$config);
    $claims=pcc_admin_claims($ticket,$config);
    if ($claims['mode']!==$mode || $claims['actor']!=='staff') throw new RuntimeException('Claims mismatch');
    $parts=explode(':',$ticket,2);
    $forged=base64_encode(json_encode(['mode'=>'promotions','actor'=>'attacker','path'=>'/protected.php'])).':'.$parts[1];
    $denied=false;
    try { pcc_admin_claims($forged,$config); } catch (RuntimeException $e) { $denied=true; }
    if (!$denied) throw new RuntimeException('Forged ticket accepted');
  }
  $denied=false;
  try { pcc_admin_claims(null,$config); } catch (RuntimeException $e) { $denied=true; }
  if (!$denied) throw new RuntimeException('Missing ticket accepted');
  echo "Admin token scopes, tampering and missing-token rejection passed.\n";
} finally { unlink($path); }
