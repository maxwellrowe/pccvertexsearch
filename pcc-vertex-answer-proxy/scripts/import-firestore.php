<?php
// CLI only. Defaults to validation; --write explicitly enables remote writes.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../public/api/firestore.php';
$config = require __DIR__ . '/../config/config.php';
$options = getopt('', ['questions:', 'events:', 'write']);
if (!isset($options['questions']) && !isset($options['events'])) {
  fwrite(STDERR, "Usage: php scripts/import-firestore.php --questions=questions.csv --events=events.csv [--write]\n");
  exit(1);
}
try {
  $records = [];
  foreach (['questions' => 'question_answer_log_v2', 'events' => 'interaction_events'] as $option => $collection) {
    if (!isset($options[$option])) continue;
    $file = fopen($options[$option], 'rb');
    if (!$file) throw new RuntimeException('Cannot open ' . $option . ' CSV.');
    $headers = fgetcsv($file, 0, ',', '"', '');
    if (!$headers) throw new RuntimeException('Missing CSV headers.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    if (count(array_unique($headers)) !== count($headers)) throw new RuntimeException('Duplicate CSV headers.');
    $line = 1;
    while (($values = fgetcsv($file, 0, ',', '"', '')) !== false) {
      $line++;
      if ($values === [null]) continue;
      if (count($values) !== count($headers)) throw new RuntimeException("$option row $line: column count mismatch.");
      $payload = array_combine($headers, $values);
      $payload['environment'] = $payload['environment'] ?? $config['firestore_log']['environment'];
      if ($collection === 'question_answer_log_v2') $payload['event_type'] = 'question_answer';
      if ((string) ($payload['schema_version'] ?? '') !== '2') throw new RuntimeException("$option row $line: requires schema_version 2.");
      $id = firestore_document_id($payload, $collection);
      firestore_fields($payload, $collection);
      $key = $collection . '/' . $id;
      if (isset($records[$key]) && $records[$key][1] !== $payload) throw new RuntimeException("$option row $line: conflicting duplicate ID.");
      $records[$key] = [$collection, $payload];
    }
    fclose($file);
  }
  echo count($records) . " unique documents validated.\n";
  if (!isset($options['write'])) { echo "Dry run only; no authentication or remote writes. Add --write to import.\n"; exit; }
  $token = fetch_service_account_access_token(load_service_account($config['service_account_json']), 'https://www.googleapis.com/auth/cloud-platform');
  $refreshAt = time() + 3000;
  $failures = 0;
  foreach ($records as [$collection, $payload]) {
    if (time() >= $refreshAt) {
      $token = fetch_service_account_access_token(load_service_account($config['service_account_json']), 'https://www.googleapis.com/auth/cloud-platform');
      $refreshAt = time() + 3000;
    }
    if (!firestore_write($payload, $collection, $config['firestore_log'], $token)) $failures++;
  }
  echo (count($records) - $failures) . " created or already present; $failures failed. Reruns do not overwrite existing documents.\n";
  exit($failures ? 1 : 0);
} catch (Throwable $e) {
  fwrite(STDERR, 'Import stopped (' . get_class($e) . '): check CSV types/IDs, configuration and credentials.' . PHP_EOL);
  exit(1);
}
