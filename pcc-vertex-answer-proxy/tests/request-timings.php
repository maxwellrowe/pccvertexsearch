<?php
declare(strict_types=1);
require __DIR__ . '/../public/api/request-timings.php';
function check(bool $ok): void { if (!$ok) throw new RuntimeException('Timing assertion failed'); }
$info = ['namelookup_time' => .01, 'connect_time' => .03,
  'pretransfer_time' => .08, 'starttransfer_time' => 3.08, 'total_time' => 3.10];
$t = vertex_transfer_timings($info, .07, 0);
check($t['stages_ms'] === ['DNS lookup' => 10.0, 'TCP connection' => 20.0,
  'TLS handshake' => 40.0, 'Transfer preparation' => 10.0,
  'Request upload + wait for first byte' => 3000.0, 'Response download' => 20.0]);
check($t['first_byte_from_start_ms'] === 3080.0 && $t['complete']);
check(array_sum($t['stages_ms']) === $t['total_ms']);
$info['starttransfer_time'] = 0;
$info['total_time'] = 25;
$t = vertex_transfer_timings($info, .07, 28);
check(!$t['complete'] && $t['first_byte_from_start_ms'] === null);
check($t['stages_ms']['Request upload + wait for first byte'] === null);
check(array_sum($t['stages_ms']) === 25000.0);
$t = vertex_transfer_timings(['total_time' => 2], 0, 6);
check($t['stages_ms']['DNS lookup'] === null);
check(array_sum($t['stages_ms']) === 2000.0);
echo "Timing breakdown tests passed (success, first-byte timeout, DNS failure).\n";
