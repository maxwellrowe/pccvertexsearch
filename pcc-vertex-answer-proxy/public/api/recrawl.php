<?php
// public/api/recrawl.php

declare(strict_types=1);
require_once __DIR__ . '/google-auth.php';

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function request_json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    return [];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    json_out(['error' => 'Invalid JSON body.'], 400);
  }

  return $decoded;
}

function request_header_value(string $name): string {
  $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return trim((string) ($_SERVER[$serverKey] ?? ''));
}

$config = require __DIR__ . '/../../config/config.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(['error' => 'Method not allowed. Use POST.'], 405);
}

$schedulerConfig = is_array($config['scheduler'] ?? null) ? $config['scheduler'] : [];
$secretHeader = trim((string) ($schedulerConfig['header_name'] ?? 'X-Scheduler-Secret'));
$expectedSecret = trim((string) ($schedulerConfig['secret'] ?? ''));
if ($expectedSecret === '') {
  json_out(['error' => 'Scheduler secret is not configured.'], 500);
}

$providedSecret = request_header_value($secretHeader);
if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
  json_out(['error' => 'Unauthorized.'], 401);
}

$body = request_json_body();
$job = trim((string) ($body['job'] ?? ''));
if ($job === '') {
  json_out(['error' => 'Missing required field: job'], 400);
}

$jobs = is_array($config['recrawl']['jobs'] ?? null) ? $config['recrawl']['jobs'] : [];
$uris = $jobs[$job] ?? null;
if (!is_array($uris) || $uris === []) {
  json_out(['error' => 'Unknown or empty recrawl job.', 'job' => $job], 404);
}

$projectId = trim((string) ($config['project_id'] ?? ''));
$location = trim((string) ($config['location'] ?? ''));
$dataStoreId = trim((string) ($config['data_store_id'] ?? ''));
$apiHost = rtrim((string) ($config['recrawl_api_host'] ?? 'https://discoveryengine.googleapis.com'), '/');
if ($projectId === '' || $location === '' || $dataStoreId === '') {
  json_out(['error' => 'Missing required Discovery Engine recrawl configuration.'], 500);
}

$saPath = (string) ($config['service_account_json'] ?? '');
if ($saPath === '' || !is_readable($saPath)) {
  json_out(['error' => 'Service account JSON not found or not readable.'], 500);
}

try {
  $serviceAccount = load_service_account($saPath);
  $accessToken = fetch_service_account_access_token(
    $serviceAccount,
    'https://www.googleapis.com/auth/cloud-platform'
  );
} catch (Throwable $e) {
  json_out(['error' => 'Auth error', 'message' => $e->getMessage()], 500);
}

$siteSearchEngine = sprintf(
  'projects/%s/locations/%s/dataStores/%s/siteSearchEngine',
  $projectId,
  $location,
  $dataStoreId
);
$url = $apiHost . '/v1/' . $siteSearchEngine . ':recrawlUris';
$payload = ['uris' => array_values($uris)];

$start = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json',
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsedMs = (int) round((microtime(true) - $start) * 1000);

if ($curlErrNo) {
  json_out(['error' => 'Upstream request failed', 'details' => $curlErr], 502);
}

$data = json_decode((string) $response, true);
if (!is_array($data)) {
  json_out(['error' => 'Invalid upstream response', 'status' => $httpCode, 'raw' => $response], 502);
}


if ($httpCode >= 400) {
  json_out([
    'error' => 'Discovery Engine recrawl API error',
    'status' => $httpCode,
    'job' => $job,
    'details' => $data,
    'meta' => ['elapsed_ms' => $elapsedMs],
  ], $httpCode);
}

json_out([
  'ok' => true,
  'job' => $job,
  'uri_count' => count($uris),
  'operation' => $data,
  'meta' => ['elapsed_ms' => $elapsedMs],
]);
