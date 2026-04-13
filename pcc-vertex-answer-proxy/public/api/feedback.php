<?php
declare(strict_types=1);

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ensure_dir(string $path): void {
  if (!is_dir($path)) {
    mkdir($path, 0775, true);
  }
}

function resolve_writable_dir(array $candidates): ?string {
  foreach ($candidates as $candidate) {
    $dir = rtrim((string) $candidate, '/');
    if ($dir === '') {
      continue;
    }
    if (is_dir($dir)) {
      if (is_writable($dir)) {
        return $dir;
      }
      continue;
    }
    if (@mkdir($dir, 0775, true) && is_writable($dir)) {
      return $dir;
    }
  }
  return null;
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

function post_google_sheets_log(array $sheetConfig, array $payload): void {
  $enabled = ($sheetConfig['enabled'] ?? false) === true;
  $url = trim((string) ($sheetConfig['webhook_url'] ?? ''));
  if (!$enabled || $url === '') {
    return;
  }

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json) || $json === '') {
    return;
  }

  $headers = ['Content-Type: application/json'];
  $token = trim((string) ($sheetConfig['webhook_token'] ?? ''));
  $postUrl = $url;
  if ($token !== '' && stripos($url, 'token=') === false) {
    $postUrl .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
  }
  if ($token !== '') {
    $headers[] = 'X-Webhook-Token: ' . $token;
  }

  $timeout = max(1, (int) ($sheetConfig['timeout_seconds'] ?? 3));

  $ch = curl_init($postUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $response = curl_exec($ch);
  $errNo = curl_errno($ch);
  $err = curl_error($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($errNo || $status >= 400) {
    error_log('Google Sheets feedback webhook failed. HTTP ' . $status . ' curl_errno=' . $errNo . ' err=' . $err . ' body=' . (string) $response);
  }
}

function append_feedback_log_csv(
  string $path,
  string $timestamp,
  string $question,
  string $answer,
  string $rating,
  string $feedback,
  string $pageUrl,
  string $ipAddress
): void {
  ensure_dir(dirname($path));

  $fh = fopen($path, 'ab');
  if ($fh === false) {
    error_log('Feedback CSV log write failed (fopen): ' . $path);
    return;
  }

  if (!flock($fh, LOCK_EX)) {
    error_log('Feedback CSV log write failed (flock): ' . $path);
    fclose($fh);
    return;
  }

  if (ftell($fh) === 0) {
    fputcsv($fh, ['timestamp', 'question', 'answer', 'rating', 'feedback', 'page_url', 'ip_address']);
  }

  fputcsv($fh, [$timestamp, $question, $answer, $rating, $feedback, $pageUrl, $ipAddress]);
  fflush($fh);
  flock($fh, LOCK_UN);
  fclose($fh);
}

$config = require __DIR__ . '/../../config/config.php';

$storageBase = realpath(__DIR__ . '/../../storage') ?: (__DIR__ . '/../../storage');
$preferredLogDir = $storageBase . '/logs';
$tmpLogDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/pcc-vertex-answer-proxy/logs';
$resolvedLogDir = resolve_writable_dir([$preferredLogDir, $tmpLogDir]);
$logDir = $resolvedLogDir ?: $preferredLogDir;

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

$question = trim((string) ($_POST['question'] ?? ''));
$answer = trim((string) ($_POST['answer'] ?? ''));
$rating = trim((string) ($_POST['rating'] ?? ''));
$feedback = trim((string) ($_POST['feedback'] ?? ''));
$pageUrl = request_page_url();
$ip = client_ip();

if ($question === '' || $answer === '') {
  json_out(['error' => 'Missing required parameters: question and answer.'], 400);
}
if (!in_array($rating, ['up', 'down'], true)) {
  json_out(['error' => 'Invalid rating value.'], 400);
}
if ($rating === 'down' && $feedback === '') {
  json_out(['error' => 'Feedback is required for a thumbs down rating.'], 400);
}
if ($rating === 'up') {
  json_out(['ok' => true], 200);
}

$question = mb_substr($question, 0, 500);
$answer = mb_substr($answer, 0, 10000);
$feedback = mb_substr($feedback, 0, 500);
$timestamp = date('c');

append_feedback_log_csv(
  $logDir . '/feedback_log.csv',
  $timestamp,
  $question,
  $answer,
  $rating,
  $feedback,
  $pageUrl,
  $ip
);

post_google_sheets_log(($config['google_sheets_log'] ?? []), [
  'event_type' => 'feedback',
  'sheet' => 'feedback',
  'timestamp' => $timestamp,
  'question' => $question,
  'answer' => $answer,
  'rating' => $rating,
  'feedback' => $feedback,
  'page_url' => $pageUrl,
  'ip' => $ip,
  'ip_address' => $ip,
]);

json_out(['ok' => true], 200);
