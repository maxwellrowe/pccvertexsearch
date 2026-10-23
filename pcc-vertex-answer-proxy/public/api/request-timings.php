<?php
declare(strict_types=1);

// cURL timestamps are cumulative seconds. Return non-overlapping intervals.
// An unfinished milestone stays null rather than looking like a fast success.
function vertex_transfer_timings(array $info, float $tlsTime, int $curlError): array {
  $last = 0.0;
  $stages = [];
  $milestones = [
    'DNS lookup' => (float) ($info['namelookup_time'] ?? 0),
    'TCP connection' => (float) ($info['connect_time'] ?? 0),
    'TLS handshake' => $tlsTime,
    'Transfer preparation' => (float) ($info['pretransfer_time'] ?? 0),
    'Request upload + wait for first byte' => (float) ($info['starttransfer_time'] ?? 0),
  ];
  foreach ($milestones as $name => $time) {
    if ($time <= 0 || $time < $last) {
      $stages[$name] = null;
      continue;
    }
    $stages[$name] = round(($time - $last) * 1000, 2);
    $last = $time;
  }
  $total = (float) ($info['total_time'] ?? 0);
  $received = (float) ($info['starttransfer_time'] ?? 0) > 0;
  $stages[$received ? 'Response download' : 'Time until failure after last milestone'] = round(max(0, $total - $last) * 1000, 2);
  return [
    'stages_ms' => $stages,
    'total_ms' => round($total * 1000, 2),
    'first_byte_from_start_ms' => $received ? round((float) $info['starttransfer_time'] * 1000, 2) : null,
    'complete' => $curlError === 0,
  ];
}
