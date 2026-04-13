<?php
// check-operation.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This script must be run from the command line.\n");
  exit(1);
}

function stderr(string $message): void {
  fwrite(STDERR, $message . PHP_EOL);
}

function b64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function json_encode_safe(array $value): string {
  $json = json_encode($value, JSON_UNESCAPED_SLASHES);
  if (!is_string($json)) {
    throw new RuntimeException('Failed to encode JSON payload.');
  }
  return $json;
}

function load_service_account(string $path): array {
  $raw = file_get_contents($path);
  if ($raw === false) {
    throw new RuntimeException('Failed to read service account file: ' . $path);
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

function fetch_service_account_access_token(array $serviceAccount): string {
  $now = time();
  $tokenUri = (string) ($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token');
  $claims = [
    'iss' => $serviceAccount['client_email'],
    'sub' => $serviceAccount['client_email'],
    'scope' => 'https://www.googleapis.com/auth/cloud-platform',
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
  if ($accessToken === '') {
    throw new RuntimeException('OAuth token response missing access_token.');
  }

  return $accessToken;
}

function usage(): void {
  $script = basename(__FILE__);
  $message = <<<TXT
Usage:
  php {$script} <operation-name-or-url> [--watch] [--interval=5]

Examples:
  php {$script} "projects/146887127754/locations/us/collections/default_collection/dataStores/pcc-website_1767107779222/operations/recrawl-uris-123"
  php {$script} "https://us-discoveryengine.googleapis.com/v1/projects/146887127754/locations/us/collections/default_collection/dataStores/pcc-website_1767107779222/operations/recrawl-uris-123"
  php {$script} "projects/146887127754/locations/us/collections/default_collection/dataStores/pcc-website_1767107779222/operations/recrawl-uris-123" --watch --interval=10
TXT;

  fwrite(STDERR, $message . PHP_EOL);
}

$args = $argv;
array_shift($args);
if ($args === []) {
  usage();
  exit(1);
}

$operationInput = '';
$watch = false;
$interval = 5;

foreach ($args as $arg) {
  if ($arg === '--watch') {
    $watch = true;
    continue;
  }
  if (str_starts_with($arg, '--interval=')) {
    $value = substr($arg, strlen('--interval='));
    $interval = max(1, (int) $value);
    continue;
  }
  if ($operationInput === '') {
    $operationInput = $arg;
    continue;
  }
}

if ($operationInput === '') {
  usage();
  exit(1);
}

$config = require __DIR__ . '/config/config.php';
$serviceAccountPath = (string) ($config['service_account_json'] ?? (__DIR__ . '/config/service-account.json'));
$apiHost = rtrim((string) ($config['recrawl_api_host'] ?? 'https://us-discoveryengine.googleapis.com'), '/');

if (!is_readable($serviceAccountPath)) {
  stderr('Service account JSON not found or not readable: ' . $serviceAccountPath);
  exit(1);
}

$operationUrl = preg_match('~^https?://~i', $operationInput) === 1
  ? $operationInput
  : $apiHost . '/v1/' . ltrim($operationInput, '/');

try {
  $serviceAccount = load_service_account($serviceAccountPath);
  $accessToken = fetch_service_account_access_token($serviceAccount);
} catch (Throwable $e) {
  stderr($e->getMessage());
  exit(1);
}

do {
  $ch = curl_init($operationUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $accessToken,
      'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);

  $response = curl_exec($ch);
  $errNo = curl_errno($ch);
  $err = curl_error($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($errNo) {
    stderr('Operation request failed: ' . $err);
    exit(1);
  }

  $data = json_decode((string) $response, true);
  if (!is_array($data)) {
    stderr('Invalid operation response: ' . (string) $response);
    exit(1);
  }

  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

  if ($status >= 400) {
    exit(1);
  }

  $done = (bool) ($data['done'] ?? false);
  if (!$watch || $done) {
    exit(0);
  }

  sleep($interval);
} while (true);
