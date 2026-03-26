<?php
// public/api/recrawl.php

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

function read_json_file(string $path): ?array {
  if (!is_readable($path)) {
    return null;
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    return null;
  }
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function write_json_file(string $path, array $data): void {
  file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function json_encode_safe(array $value): string {
  $json = json_encode($value, JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) {
    throw new RuntimeException('Failed to encode JSON payload.');
  }
  return $json;
}

function b64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function load_service_account(string $path): array {
  $raw = file_get_contents($path);
  if ($raw === false) {
    throw new RuntimeException('Failed to read service account file.');
  }
  $json = json_decode($raw, true);
  if (!is_array($json)) {
    throw new RuntimeException('Invalid service account JSON.');
  }

  $email = (string) ($json['client_email'] ?? '');
  $privateKey = (string) ($json['private_key'] ?? '');
  $tokenUri = (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token');
  if ($email === '' || $privateKey === '') {
    throw new RuntimeException('Service account JSON missing client_email or private_key.');
  }

  return [
    'client_email' => $email,
    'private_key' => $privateKey,
    'token_uri' => $tokenUri,
  ];
}

function fetch_service_account_access_token(array $serviceAccount, string $scope, string $cachePath): string {
  $cached = read_json_file($cachePath);
  $now = time();
  if (
    is_array($cached)
    && !empty($cached['access_token'])
    && isset($cached['expires_at'])
    && ((int) $cached['expires_at'] > ($now + 60))
  ) {
    return (string) $cached['access_token'];
  }

  $tokenUri = (string) ($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token');
  $claims = [
    'iss' => $serviceAccount['client_email'],
    'sub' => $serviceAccount['client_email'],
    'scope' => $scope,
    'aud' => $tokenUri,
    'iat' => $now,
    'exp' => $now + 3600,
  ];

  $header = ['alg' => 'RS256', 'typ' => 'JWT'];
  $segments = [
    b64url_encode(json_encode_safe($header)),
    b64url_encode(json_encode_safe($claims)),
  ];
  $signingInput = implode('.', $segments);
  $signature = '';
  $ok = openssl_sign($signingInput, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
  if (!$ok) {
    throw new RuntimeException('Failed to sign JWT. Check OpenSSL and private key format.');
  }
  $jwt = $signingInput . '.' . b64url_encode($signature);

  $postBody = http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $jwt,
  ]);

  $ch = curl_init($tokenUri);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS => $postBody,
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  $errNo = curl_errno($ch);
  $err = curl_error($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($errNo) {
    throw new RuntimeException('OAuth token request failed: ' . $err);
  }
  $data = json_decode((string) $resp, true);
  if (!is_array($data) || $status >= 400) {
    throw new RuntimeException('OAuth token request error: HTTP ' . $status . ' ' . (string) $resp);
  }

  $accessToken = (string) ($data['access_token'] ?? '');
  $expiresIn = (int) ($data['expires_in'] ?? 3600);
  if ($accessToken === '') {
    throw new RuntimeException('OAuth token response missing access_token.');
  }

  write_json_file($cachePath, [
    'access_token' => $accessToken,
    'expires_at' => $now + max(60, $expiresIn - 60),
  ]);

  return $accessToken;
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

function append_recrawl_log(
  string $path,
  string $job,
  array $uris,
  int $httpCode,
  array $response,
  int $elapsedMs
): void {
  ensure_dir(dirname($path));
  $line = json_encode([
    'ts' => date('c'),
    'job' => $job,
    'uri_count' => count($uris),
    'uris' => $uris,
    'status' => $httpCode,
    'operation' => $response['name'] ?? null,
    'done' => $response['done'] ?? null,
    'elapsed_ms' => $elapsedMs,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if (is_string($line)) {
    file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
  }
}

$config = require __DIR__ . '/../../config/config.php';

$storageBase = realpath(__DIR__ . '/../../storage') ?: (__DIR__ . '/../../storage');
$cacheDir = $storageBase . '/cache';
$preferredLogDir = $storageBase . '/logs';
$tmpLogDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/pcc-vertex-answer-proxy/logs';
$resolvedLogDir = resolve_writable_dir([$preferredLogDir, $tmpLogDir]);
$logDir = $resolvedLogDir ?: $preferredLogDir;
ensure_dir($cacheDir);
if (!$resolvedLogDir) {
  error_log('No writable log directory found. Tried: ' . $preferredLogDir . ' | ' . $tmpLogDir);
}

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
  $tokenCachePath = $cacheDir . '/google_oauth_token.json';
  $accessToken = fetch_service_account_access_token(
    $serviceAccount,
    'https://www.googleapis.com/auth/cloud-platform',
    $tokenCachePath
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

append_recrawl_log($logDir . '/recrawl.log', $job, $uris, $httpCode, $data, $elapsedMs);

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
