<?php
declare(strict_types=1);
require_once __DIR__ . '/google-auth.php';

function firestore_fields(array $payload, string $collection): array {
  static $schemas;
  $schemas ??= json_decode((string) file_get_contents(__DIR__ . '/../../config/firestore-schema.json'), true);
  if (!isset($schemas[$collection])) throw new InvalidArgumentException('Unknown analytics collection.');
  $fields = [];
  foreach ($schemas[$collection] as $key => $type) {
    $value = $payload[$key] ?? null;
    if ($type === 'timestamp') {
      if ($value === null || $value === '') { $fields[$key] = ['nullValue' => null]; continue; }
      // CSV exports must retain ISO timestamps, not ambiguous local dates.
      if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', (string) $value)) throw new InvalidArgumentException('Invalid timestamp: ' . $key);
      $date = new DateTimeImmutable((string) $value);
      $errors = DateTimeImmutable::getLastErrors();
      if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('Invalid timestamp: ' . $key);
      $fields[$key] = ['timestampValue' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')];
    } elseif ($type === 'boolean') {
      if (!in_array($value, [null, '', true, false, 0, 1, '0', '1', 'TRUE', 'FALSE', 'true', 'false'], true)) throw new InvalidArgumentException('Invalid boolean: ' . $key);
      $fields[$key] = ['booleanValue' => in_array($value, [true, 1, '1', 'TRUE', 'true'], true)];
    } elseif ($type === 'integer') {
      if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) throw new InvalidArgumentException('Invalid integer: ' . $key);
      $fields[$key] = $value === null || $value === '' ? ['nullValue' => null] : ['integerValue' => (string) $value];
    } elseif ($type === 'array') {
      $values = is_array($value) ? $value : array_filter(array_map('trim', explode(' | ', (string) $value)));
      $fields[$key] = ['arrayValue' => ['values' => array_map(static fn($url) => ['stringValue' => (string) $url], array_values(array_unique($values)))]];
    } else {
      $fields[$key] = ['stringValue' => (string) ($value ?? '')];
    }
  }
  return $fields;
}

function firestore_document_id(array $payload, string $collection): string {
  $key = $collection === 'question_answer_log_v2' ? 'query_id' : 'event_id';
  $id = (string) ($payload[$key] ?? '');
  if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $id)) throw new InvalidArgumentException('Missing or invalid ' . $key);
  return $id;
}

function firestore_write(array $payload, string $collection, array $settings, string $token): bool {
  $id = firestore_document_id($payload, $collection);
  $fields = firestore_fields($payload, $collection);
  $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($settings['project_id'])
    . '/databases/' . rawurlencode($settings['database_id']) . '/documents/' . rawurlencode($collection)
    . '?documentId=' . rawurlencode($id);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['fields' => $fields], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => max(1, (int) ($settings['timeout_seconds'] ?? 3)),
  ]);
  $response = curl_exec($ch);
  $errno = curl_errno($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode((string) $response, true);
  // Create-only writes never overwrite earlier deliveries with the same ID.
  if (!$errno && (($status >= 200 && $status < 300 && isset($data['name']))
      || ($status === 409 && ($data['error']['status'] ?? '') === 'ALREADY_EXISTS'))) return true;
  error_log('Firestore analytics delivery failed. HTTP ' . $status . ' curl_errno=' . $errno);
  return false;
}

function firestore_tracking_write(array $payload, string $collection): bool {
  global $config, $accessToken;
  try {
    // Reuse Vertex's token within this request; feedback requests authenticate separately.
    if (empty($accessToken)) {
      $accessToken = fetch_service_account_access_token(
        load_service_account($config['service_account_json']), 'https://www.googleapis.com/auth/cloud-platform'
      );
    }
    $payload['environment'] = $config['firestore_log']['environment'] ?? 'production';
    return firestore_write($payload, $collection, $config['firestore_log'], $accessToken);
  } catch (Throwable $e) {
    // Keep credentials, answer content, and upstream response bodies out of error logs.
    error_log('Firestore analytics delivery failed: authentication or payload preparation.');
    return false;
  }
}
