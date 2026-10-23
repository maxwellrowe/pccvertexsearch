<?php
declare(strict_types=1);
require_once __DIR__ . '/tracking.php';

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function client_ip(): string {
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff) {
    $parts = array_map('trim', explode(',', $xff));
    if (!empty($parts[0])) {
      return $parts[0];
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function request_page_url(): string {
  $candidate = trim((string) ($_POST['page_url'] ?? ''));
  if ($candidate !== '') {
    return mb_strlen($candidate) > 2048 ? mb_substr($candidate, 0, 2048) : $candidate;
  }
  $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
  if ($referer !== '') {
    return mb_strlen($referer) > 2048 ? mb_substr($referer, 0, 2048) : $referer;
  }
  return '';
}

function origin_allowed(string $origin, array $allowlist): bool {
  if ($origin === '') {
    return false;
  }
  foreach ($allowlist as $allowed) {
    if ($allowed === $origin) {
      return true;
    }
  }
  return false;
}

$config = require __DIR__ . '/../../config/config.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowlist = $config['cors_allow_origins'] ?? [];
if ($origin && origin_allowed($origin, $allowlist)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(['error' => 'Method not allowed. Use POST.'], 405);
}

$type = (string) ($_POST['event_type'] ?? 'feedback');
if (!in_array($type, ['feedback', 'feedback_comment', 'escalation'], true)) {
  json_out(['error' => 'Invalid event type.'], 400);
}
foreach (['session_id', 'conversation_id', 'query_id', 'answer_id', 'event_id'] as $key) {
  if (tracking_id($key, false) === '') json_out(['error' => 'Missing or invalid ' . $key], 400);
}
$rating = (string) ($_POST['rating'] ?? '');
if ($type === 'feedback' && !in_array($rating, ['up', 'down'], true)) {
  json_out(['error' => 'Invalid rating.'], 400);
}
if ($type === 'feedback_comment' && tracking_id('parent_event_id', false) === '') {
  json_out(['error' => 'Missing feedback event ID.'], 400);
}
$row = tracking_context() + [
  'event_type' => $type,
  'event_id' => tracking_id('event_id'),
  'answer_id' => tracking_id('answer_id'),
  'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
  'parent_event_id' => $type === 'feedback_comment' ? tracking_id('parent_event_id', false) : '',
  'rating' => $type === 'feedback' ? $rating : '',
  'feedback' => $type === 'feedback_comment' ? mb_substr(trim((string) ($_POST['feedback'] ?? '')), 0, 500) : '',
  'escalation_action' => $type === 'escalation' ? 'help_centers_support_click' : '',
  'destination_url' => $type === 'escalation' ? 'https://pasadena.edu/student-services/get-help/index.php' : '',
];
if (!tracking_write($row, 'interaction_events')) json_out(['error' => 'Unable to save event. Please retry.'], 503);
json_out(['ok' => true]);
