<?php
// Dry-run by default. Pass --write to create missing records in configured Firestore.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../public/api/promotions.php';
$options = getopt('', ['file:', 'write', 'actor:']);
try {
  $path = $options['file'] ?? '';
  if (!is_string($path) || !is_file($path)) throw new RuntimeException('Supply --file=/path/to/promotions.xml');
  $raw = file_get_contents($path);
  if ($raw === false || strlen($raw) > 5000000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) throw new RuntimeException('Invalid or unsupported XML.');
  libxml_use_internal_errors(true);
  $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET);
  if (!$xml || $xml->getName() !== 'Promotions') throw new RuntimeException('Expected a Promotions XML document.');
  $groups = []; $excluded = []; $images = 0; $sourceCount = 0;
  foreach ($xml->Promotion as $node) {
    $sourceCount++;
    $a = $node->attributes();
    $description = trim((string) ($a['description'] ?? ''));
    if (strtolower($description) === 'undefined') { $excluded[] = (string) $a['title']; continue; }
    $dates = [];
    foreach (['start_date', 'end_date'] as $key) {
      $value = trim((string) ($a[$key] ?? ''));
      if ($value === '') { $dates[$key] = null; continue; }
      $date = DateTimeImmutable::createFromFormat('!Y/m/d H:i:s O', $value);
      $errors = DateTimeImmutable::getLastErrors();
      if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Invalid date for ' . (string) $a['title']);
      $dates[$key] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    $keywords = [];
    foreach ($node->Query as $q) $keywords[] = trim((string) $q);
    if (!$keywords) $keywords = array_map('trim', explode(',', (string) ($a['queries'] ?? '')));
    foreach (['enabled', 'is_regex'] as $flag) {
      if (isset($a[$flag]) && !in_array((string) $a[$flag], ['true','false'], true)) throw new RuntimeException('Invalid boolean in export.');
    }
    $r = ['title'=>trim((string)$a['title']), 'url'=>trim((string)$a['url']), 'description'=>$description,
      'keywords'=>$keywords, 'match_type'=>(string)($a['is_regex']??'false')==='true' ? 'regex' : 'exact',
      'priority'=>0, 'enabled'=>(string)($a['enabled']??'true')==='true'] + $dates;
    $r = promotion_validate($r);
    $keyFields = $r; unset($keyFields['keywords']);
    // Preserve distinct title/description and date/match settings, even for the same URL.
    $key = hash('sha256', json_encode($keyFields, JSON_THROW_ON_ERROR));
    if (!isset($groups[$key])) $groups[$key] = $r;
    else $groups[$key]['keywords'] = array_merge($groups[$key]['keywords'], $r['keywords']);
    if (!empty((string) ($a['image_url'] ?? ''))) $images++;
  }
  foreach ($groups as &$r) {
    $unique = [];
    foreach ($r['keywords'] as $word) {
      $key = $r['match_type'] === 'regex' ? $word : promotion_normalize($word);
      if (!isset($unique[$key])) $unique[$key] = $word;
    }
    $r['keywords'] = array_values($unique);
    $r = promotion_validate($r);
  }
  unset($r);
  $expired = count(array_filter($groups, static fn($r) => $r['end_date'] && strtotime($r['end_date']) <= time()));
  echo json_encode(['source_records'=>$sourceCount, 'excluded'=>$excluded, 'prepared'=>count($groups),
    'merged_records'=>$sourceCount-count($excluded)-count($groups), 'expired_preserved'=>$expired, 'image_fields_omitted'=>$images], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
  if (!isset($options['write'])) {
    foreach ($groups as $r) echo $r['title'], ' | ', $r['description'], ' | ', implode(', ', $r['keywords']), "\n";
    echo "Dry run only; no database reads or writes.\n"; exit;
  }
  $actor = $options['actor'] ?? 'programmable-search-import';
  if (!is_string($actor) || trim($actor)==='') throw new RuntimeException('A nonempty import actor is required.');
  $config = require __DIR__ . '/../config/config.php';
  $token = fetch_service_account_access_token(load_service_account($config['service_account_json']), 'https://www.googleapis.com/auth/datastore');
  $fingerprint = static function(array $row): string {
    $r = promotion_validate($row);
    $r['keywords'] = array_map(static fn($word) => $r['match_type']==='regex' ? $word : promotion_normalize($word), $r['keywords']);
    sort($r['keywords']);
    return hash('sha256', json_encode($r, JSON_THROW_ON_ERROR));
  };
  $seen = [];
  foreach (promotion_rules($config, $token) as $existing) {
    try { $seen[$fingerprint($existing)] = true; } catch (Throwable $e) { /* Leave unrelated malformed records untouched. */ }
  }
  $created = 0; $skipped = 0;
  foreach ($groups as $r) {
    $key = $fingerprint($r);
    if (isset($seen[$key])) { $skipped++; continue; }
    promotion_save($config, $token, '', $r, $actor);
    $seen[$key] = true; $created++;
    echo 'Created: ', $r['title'], "\n";
  }
  $verified = [];
  foreach (promotion_rules($config, $token) as $row) {
    try { $verified[$fingerprint($row)] = true; } catch (Throwable $e) {}
  }
  foreach ($groups as $r) if (!isset($verified[$fingerprint($r)])) throw new RuntimeException('Read-back verification failed. Rerun to safely resume.');
  echo "Complete: $created created, $skipped already present; all prepared records verified in Firestore.\n";
} catch (Throwable $e) {
  // Do not print upstream exceptions that could contain credentials or tokens.
  fwrite(STDERR, "Import failed. Check the XML, credentials and Firestore access; successful creates are preserved and reruns skip identical records.\n");
  exit(1);
}
