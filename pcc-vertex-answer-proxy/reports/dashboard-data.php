<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/api/google-auth.php';

function pcc_report_value(array $value) {
  foreach (['stringValue', 'timestampValue', 'booleanValue', 'doubleValue'] as $type) {
    if (array_key_exists($type, $value)) return $value[$type];
  }
  if (isset($value['integerValue'])) return (int) $value['integerValue'];
  if (isset($value['arrayValue'])) return array_map('pcc_report_value', $value['arrayValue']['values'] ?? []);
  return null;
}

function pcc_report_query(array $settings, string $token, string $collection, string $field, string $start, string $end, string $cursor = ''): array {
  if ($cursor === 'end') return ['rows' => [], 'next' => 'end'];
  $filters = [];
  foreach ([['GREATER_THAN_OR_EQUAL', $start], ['LESS_THAN', $end]] as [$op, $date]) {
    $filters[] = ['fieldFilter' => ['field' => ['fieldPath' => $field], 'op' => $op, 'value' => ['timestampValue' => $date]]];
  }
  $query = ['structuredQuery' => [
    'from' => [['collectionId' => $collection]],
    'where' => ['compositeFilter' => ['op' => 'AND', 'filters' => $filters]],
    'orderBy' => [['field' => ['fieldPath' => $field], 'direction' => 'DESCENDING'], ['field' => ['fieldPath' => '__name__'], 'direction' => 'DESCENDING']],
    'limit' => 2001,
  ]];
  if ($cursor !== '') {
    $decoded = json_decode((string) base64_decode($cursor, true), true);
    $prefix = 'projects/' . $settings['project_id'] . '/databases/' . $settings['database_id'] . '/documents/' . $collection . '/';
    if (!is_array($decoded) || !is_string($decoded['time'] ?? null) || !is_string($decoded['name'] ?? null)
        || !str_starts_with($decoded['name'], $prefix) || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $decoded['time'])) {
      throw new InvalidArgumentException('Invalid report cursor. Load the report again.');
    }
    $query['structuredQuery']['startAt'] = ['before' => false, 'values' => [
      ['timestampValue' => $decoded['time']], ['referenceValue' => $decoded['name']],
    ]];
  }
  $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($settings['project_id']) . '/databases/' . rawurlencode($settings['database_id']) . '/documents:runQuery';
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($query, JSON_THROW_ON_ERROR), CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30]);
  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($response === false || $status !== 200) throw new RuntimeException('Report query failed.');
  $documents = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
  $rows = [];
  $last = null;
  $more = false;
  foreach ($documents as $item) {
    if (isset($item['error'])) throw new RuntimeException('Report query failed.');
    if (isset($item['document'])) {
      if (count($rows) === 2000) { $more = true; break; }
      $last = $item['document'];
      $rows[] = array_map('pcc_report_value', $last['fields'] ?? []);
    }
  }
  return ['rows' => $rows, 'next' => $more ? base64_encode(json_encode(['time' => $last['fields'][$field]['timestampValue'], 'name' => $last['name']], JSON_THROW_ON_ERROR)) : 'end'];
}

function pcc_dashboard_load(array $input, ?array $config = null): array {
  $tz = new DateTimeZone('America/Los_Angeles');
  $today = new DateTimeImmutable('today', $tz);
  $report = ['from' => is_string($input['report_from'] ?? null) ? $input['report_from'] : $today->modify('-6 days')->format('Y-m-d'),
    'to' => is_string($input['report_to'] ?? null) ? $input['report_to'] : $today->format('Y-m-d'),
    'all' => ($input['report_traffic'] ?? '') === 'all'];
  try {
    $dates = [];
    foreach (['from', 'to'] as $key) {
      $date = DateTimeImmutable::createFromFormat('!Y-m-d', $report[$key], $tz);
      if (!$date || $date->format('Y-m-d') !== $report[$key]) throw new InvalidArgumentException('Enter valid dates.');
      $dates[] = $date;
    }
    [$from, $to] = $dates;
    if ($to < $from) throw new InvalidArgumentException('The end date must be on or after the start date.');
    $config ??= require __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../public/api/management-access.php';
    $report['admin_ticket'] = pcc_admin_ticket('report', '', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', $config);
    $token = fetch_service_account_access_token(load_service_account($config['service_account_json']), 'https://www.googleapis.com/auth/datastore');
    $start = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $end = $to->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $report['limited'] = false;
    $report['continuation'] = !empty($input['report_questions_cursor']) || !empty($input['report_events_cursor']);
    $report['next'] = ['report_from' => $report['from'], 'report_to' => $report['to'], 'report_traffic' => $report['all'] ? 'all' : 'normal'];
    foreach (['questions' => ['question_answer_log_v2', 'query_timestamp'], 'events' => ['interaction_events', 'timestamp']] as $key => [$collection, $field]) {
      $cursor = $input['report_' . $key . '_cursor'] ?? '';
      if (!is_string($cursor) || strlen($cursor) > 2048) throw new InvalidArgumentException('Invalid report cursor.');
      $page = pcc_report_query($config['firestore_log'], $token, $collection, $field, $start, $end, $cursor);
      $report['next']['report_' . $key . '_cursor'] = $page['next'];
      $report['limited'] = $report['limited'] || $page['next'] !== 'end';
      $report[$key] = array_values(array_filter($page['rows'], static fn($r) => $report['all'] || (empty($r['is_test']) && empty($r['bot_suspected']))));
    }
    $report['by_id'] = array_column($report['questions'], null, 'query_id');
    $count = static fn($rows, $predicate) => count(array_filter($rows, $predicate));
    $report['counts'] = [
      'Searches' => $count($report['questions'], static fn($r) => empty($r['retry_of_query_id'])),
      'Retry attempts' => $count($report['questions'], static fn($r) => !empty($r['retry_of_query_id'])),
      'Failed attempts' => $count($report['questions'], static fn($r) => (int) ($r['http_status'] ?? 0) >= 400),
      'Positive feedback events' => $count($report['events'], static fn($r) => ($r['rating'] ?? '') === 'up'),
      'Negative feedback events' => $count($report['events'], static fn($r) => ($r['rating'] ?? '') === 'down'),
    ];
  } catch (InvalidArgumentException $e) {
    $report['error'] = $e->getMessage();
  } catch (Throwable $e) {
    $report['error'] = 'Unable to load reporting data. Check the server credentials, Firestore read permissions, and network access.';
  }
  return $report;
}
