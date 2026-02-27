<?php
// public/api/answer.php

declare(strict_types=1);

// -----------------------------
// Helpers
// -----------------------------
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

function client_ip(): string {
  // Prefer X-Forwarded-For if behind ALB/CloudFront, otherwise REMOTE_ADDR
  $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($xff) {
    $parts = array_map('trim', explode(',', $xff));
    if (!empty($parts[0])) {
      return $parts[0];
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function origin_allowed(string $origin, array $allowlist): bool {
  if (!$origin) {
    return false;
  }
  foreach ($allowlist as $allowed) {
    if ($allowed === $origin) {
      return true;
    }
  }
  return false;
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

// Token bucket: state saved per IP
function rate_limit(string $key, int $capacity, int $refillPerMin, string $dir): bool {
  ensure_dir($dir);
  $path = $dir . '/' . hash('sha256', $key) . '.json';
  $now = microtime(true);

  $state = read_json_file($path) ?? [
    'tokens' => $capacity,
    'ts' => $now
  ];

  $tokens = (float) ($state['tokens'] ?? $capacity);
  $ts = (float) ($state['ts'] ?? $now);

  $elapsed = max(0.0, $now - $ts);
  $refillPerSec = $refillPerMin / 60.0;
  $tokens = min($capacity, $tokens + ($elapsed * $refillPerSec));

  $allowed = $tokens >= 1.0;
  if ($allowed) {
    $tokens -= 1.0;
  }

  write_json_file($path, ['tokens' => $tokens, 'ts' => $now]);
  return $allowed;
}

// Conservative date/tense cleanup (optional safety net):
// If model returns "will be available ... October 13, 2025" and that date is in the past,
// rewrite common patterns. (We keep it intentionally narrow.)
function tense_safety_net(string $text): string {
  $today = new DateTimeImmutable('today');

  // Match "will be available ... on <Month> <d>, <yyyy>"
  $pattern = '/\bwill be available\b(.*?\b(?:on|starting on)\s+([A-Z][a-z]+)\s+(\d{1,2}),\s+(\d{4}))/i';
  $text = preg_replace_callback($pattern, function ($m) use ($today) {
    $month = $m[2];
    $day = $m[3];
    $year = $m[4];
    $dt = DateTimeImmutable::createFromFormat('F j, Y', "$month $day, $year");
    if ($dt && $dt < $today) {
      return preg_replace('/\bwill be available\b/i', 'is available', $m[0]);
    }
    return $m[0];
  }, $text);

  // Match "begins on <date>" when date is past -> "began on <date>"
  $pattern2 = '/\bbegins\b(.*?\bon\s+([A-Z][a-z]+)\s+(\d{1,2}),\s+(\d{4}))/i';
  $text = preg_replace_callback($pattern2, function ($m) use ($today) {
    $month = $m[2];
    $day = $m[3];
    $year = $m[4];
    $dt = DateTimeImmutable::createFromFormat('F j, Y', "$month $day, $year");
    if ($dt && $dt < $today) {
      return preg_replace('/\bbegins\b/i', 'began', $m[0]);
    }
    return $m[0];
  }, $text);

  return $text;
}

// Extract citations in a best-effort way.
// The API returns structured citation metadata when includeCitations=true.
function normalize_citations($citations): array {
  if (!is_array($citations)) {
    return [];
  }
  $out = [];
  foreach ($citations as $c) {
    // Structures vary; try common fields.
    $title = $c['title'] ?? $c['source']['title'] ?? $c['documentTitle'] ?? null;
    $uri = $c['uri'] ?? $c['url'] ?? $c['source']['uri'] ?? $c['documentUri'] ?? null;

    // Some APIs return "references" arrays; try the first if present.
    if (!$uri && isset($c['references']) && is_array($c['references']) && isset($c['references'][0])) {
      $r = $c['references'][0];
      $title = $title ?? ($r['title'] ?? null);
      $uri = $uri ?? ($r['uri'] ?? $r['url'] ?? null);
    }

    // Only include plausible citations
    if ($uri || $title) {
      $out[] = [
        'title' => $title ?: ($uri ?: 'Source'),
        'uri' => $uri,
      ];
    }
  }
  // de-dupe by uri/title
  $seen = [];
  $dedup = [];
  foreach ($out as $row) {
    $k = ($row['uri'] ?? '') . '|' . ($row['title'] ?? '');
    if (isset($seen[$k])) {
      continue;
    }
    $seen[$k] = true;
    $dedup[] = $row;
  }
  return $dedup;
}

function normalize_related_questions($items): array {
  if (!is_array($items)) {
    return [];
  }
  $out = [];
  foreach ($items as $item) {
    $q = trim((string) $item);
    if ($q !== '') {
      $out[] = $q;
    }
  }
  return array_values(array_unique($out));
}

function normalize_references($references): array {
  if (!is_array($references)) {
    return [];
  }
  $out = [];
  foreach ($references as $r) {
    if (!is_array($r)) {
      continue;
    }
    $title = $r['title'] ?? $r['documentTitle'] ?? $r['chunkInfo']['documentMetadata']['title'] ?? null;
    $uri = $r['uri'] ?? $r['url'] ?? $r['documentUri'] ?? $r['chunkInfo']['documentMetadata']['uri'] ?? null;
    $snippet = $r['snippet'] ?? $r['chunkInfo']['content'] ?? null;
    if ($title || $uri || $snippet) {
      $out[] = [
        'title' => $title ?: ($uri ?: 'Reference'),
        'uri' => $uri,
        'snippet' => $snippet,
      ];
    }
  }

  $seen = [];
  $dedup = [];
  foreach ($out as $row) {
    $k = ($row['uri'] ?? '') . '|' . ($row['title'] ?? '');
    if (isset($seen[$k])) {
      continue;
    }
    $seen[$k] = true;
    $dedup[] = $row;
  }
  return $dedup;
}

function extract_search_results(array $data): array {
  $rows = [];
  $steps = $data['answer']['steps'] ?? [];
  if (!is_array($steps)) {
    return [];
  }

  foreach ($steps as $step) {
    if (!is_array($step)) {
      continue;
    }

    $candidateBuckets = [];
    if (isset($step['observation']['searchResults']) && is_array($step['observation']['searchResults'])) {
      $candidateBuckets[] = $step['observation']['searchResults'];
    }
    if (isset($step['actions']) && is_array($step['actions'])) {
      foreach ($step['actions'] as $action) {
        if (isset($action['observation']['searchResults']) && is_array($action['observation']['searchResults'])) {
          $candidateBuckets[] = $action['observation']['searchResults'];
        }
      }
    }

    foreach ($candidateBuckets as $bucket) {
      foreach ($bucket as $r) {
        if (!is_array($r)) {
          continue;
        }
        $title = $r['title'] ?? $r['document']['title'] ?? $r['chunkInfo']['documentMetadata']['title'] ?? null;
        $uri = $r['uri'] ?? $r['url'] ?? $r['document']['uri'] ?? $r['chunkInfo']['documentMetadata']['uri'] ?? null;
        $description = $r['description']
          ?? $r['summary']
          ?? $r['snippet']
          ?? $r['document']['description']
          ?? $r['document']['summary']
          ?? $r['document']['snippet']
          ?? $r['chunkInfo']['content']
          ?? null;
        if ($title || $uri || $description) {
          $rows[] = [
            'title' => $title ?: ($uri ?: 'Result'),
            'uri' => $uri,
            'description' => $description,
            'snippet' => $description,
          ];
        }
      }
    }
  }

  $seen = [];
  $dedup = [];
  foreach ($rows as $row) {
    $k = ($row['uri'] ?? '') . '|' . ($row['title'] ?? '');
    if (isset($seen[$k])) {
      continue;
    }
    $seen[$k] = true;
    $dedup[] = $row;
  }
  return $dedup;
}

function is_summary_fallback_message(string $answerText): bool {
  $normalized = mb_strtolower(trim($answerText));
  if ($normalized === '') {
    return false;
  }
  return str_contains($normalized, 'a summary could not be generated for your search query');
}

function format_search_results_fallback(array $searchResults): string {
  if (!$searchResults) {
    return 'I could not generate a direct answer from the indexed content for that question. Please try a more specific question.';
  }

  $lines = ['I could not generate a direct summary for that question, but here are the most relevant results:'];
  foreach (array_slice($searchResults, 0, 5) as $idx => $row) {
    $title = trim((string) ($row['title'] ?? 'Result'));
    $desc = trim((string) ($row['description'] ?? $row['snippet'] ?? ''));
    $line = ($idx + 1) . '. ' . $title;
    if ($desc !== '') {
      $line .= ' — ' . $desc;
    }
    $lines[] = $line;
  }
  $lines[] = 'Open the references below for full details.';
  return implode("\n", $lines);
}

function search_results_from_references(array $references): array {
  $rows = [];
  foreach ($references as $r) {
    if (!is_array($r)) {
      continue;
    }
    $title = $r['title'] ?? null;
    $uri = $r['uri'] ?? null;
    $description = $r['snippet'] ?? null;
    if ($title || $uri || $description) {
      $rows[] = [
        'title' => $title ?: ($uri ?: 'Result'),
        'uri' => $uri,
        'description' => $description,
        'snippet' => $description,
      ];
    }
  }
  return $rows;
}

// -----------------------------
// Load config + setup storage
// -----------------------------
$config = require __DIR__ . '/../../config/config.php';

$storageBase = realpath(__DIR__ . '/../../storage') ?: (__DIR__ . '/../../storage');
$cacheDir = $storageBase . '/cache';
$rateDir = $storageBase . '/rate';
$logDir = $storageBase . '/logs';
ensure_dir($cacheDir);
ensure_dir($rateDir);
ensure_dir($logDir);

// -----------------------------
// Security headers
// -----------------------------
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Frame-Options: SAMEORIGIN');
// Adjust CSP as you embed; keep API strict:
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self';");

// -----------------------------
// CORS (for embedding)
// -----------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowlist = $config['cors_allow_origins'] ?? [];
if ($origin && origin_allowed($origin, $allowlist)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  // If you want cookies cross-site, you’ll need SameSite=None; Secure and allow credentials.
  // header('Access-Control-Allow-Credentials: true');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// -----------------------------
// Method guard
// -----------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(['error' => 'Method not allowed. Use POST.'], 405);
}

// -----------------------------
// Rate limiting
// -----------------------------
$ip = client_ip();
if (($config['rate']['enabled'] ?? true) === true) {
  $cap = (int) ($config['rate']['capacity'] ?? 20);
  $ref = (int) ($config['rate']['refill_per_min'] ?? 20);
  $key = 'ip:' . $ip;
  if (!rate_limit($key, $cap, $ref, $rateDir)) {
    json_out(['error' => 'Rate limit exceeded. Please try again shortly.'], 429);
  }
}

// -----------------------------
// Input
// -----------------------------
$q = trim((string) ($_POST['q'] ?? ''));
if ($q === '') {
  json_out(['error' => 'Missing required parameter: q'], 400);
}
if (mb_strlen($q) > 500) {
  json_out(['error' => 'Query too long (max 500 characters).'], 400);
}

$sidRaw = trim((string) ($_POST['sid'] ?? ''));
if ($sidRaw !== '' && mb_strlen($sidRaw) > 1024) {
  json_out(['error' => 'Session id too long.'], 400);
}
if ($sidRaw === '') {
  // "-" asks Discovery Engine to create a new session for this conversation.
  $sidRaw = '-';
}

// -----------------------------
// userPseudoId cookie (server-side)
// -----------------------------
if (empty($_COOKIE['vid'])) {
  $vid = bin2hex(random_bytes(16));
  setcookie('vid', $vid, [
    'expires' => time() + 60 * 60 * 24 * 365,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  $_COOKIE['vid'] = $vid;
}

// -----------------------------
// Cache (query + engine identity)
// -----------------------------
$cacheEnabled = ($config['cache']['enabled'] ?? true) === true;
$cacheTtl = (int) ($config['cache']['ttl_seconds'] ?? 300);

$cacheKeyMaterial = json_encode([
  'q' => $q,
  'sid' => $sidRaw,
  'project_id' => $config['project_id'],
  'location' => $config['location'],
  'engine_id' => $config['engine_id'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$cacheKey = hash('sha256', $cacheKeyMaterial ?: $q);
$cachePath = $cacheDir . '/' . $cacheKey . '.json';

if ($cacheEnabled && is_readable($cachePath)) {
  $cached = read_json_file($cachePath);
  if ($cached && isset($cached['ts']) && (time() - (int) $cached['ts'] <= $cacheTtl)) {
    $cached['meta']['cache'] = 'HIT';
    json_out($cached, 200);
  }
}

// -----------------------------
// Build serving config + endpoint
// -----------------------------
$projectId = $config['project_id'];
$location = $config['location'];
$engineId = $config['engine_id'];
$apiHost = rtrim((string) $config['api_host'], '/');

$servingConfig = "projects/$projectId/locations/$location/collections/default_collection/engines/$engineId/servingConfigs/default_serving_config";
$url = $apiHost . "/v1/$servingConfig:answer";
$sessionParent = "projects/$projectId/locations/$location/collections/default_collection/engines/$engineId/sessions";
$session = $sidRaw;
if (!str_starts_with($session, 'projects/')) {
  // Keep session ids URL/path-safe if client sends a plain token.
  $session = preg_replace('/[^A-Za-z0-9._~-]/', '', $session) ?: '-';
  $session = $sessionParent . '/' . $session;
}

// -----------------------------
// Date injection + preamble
// (Prompt preamble + citations are supported by Answer API)
// -----------------------------
$today = date('F j, Y');

$preambleTemplate = <<<TXT
Use a formal, friendly, and helpful tone. Write in the first person and address the user directly as “you.” Incorporate occasional, light, G-rated horse puns with a mild dad-joke personality (do not overuse humor). Use inclusive language. Keep responses brief and concise unless the user asks for more detail. Respond in the voice and persona of Lance O’Lot, the Pasadena City College mascot. Prioritize clarity and accuracy over humor.

Assume today's date is {CURRENT_DATE} and use it as the reference point for all time-based statements.

When information includes dates, you must normalize the wording relative to today's date:
- If the referenced date is in the past, use past or present-perfect tense (e.g., “started,” “became available,” “is now available”).
- If the referenced date is in the future, use future tense.
- Avoid phrases like “will be available” for dates that have already passed.
- Prefer present-tense institutional language when something is currently available.

You may adjust tense and temporal phrasing even when grounded source content uses different wording, as long as the factual meaning remains accurate.

When adapting or summarizing existing text, rewrite time-sensitive language so it reflects the present day rather than copying future-facing language verbatim.

If information is uncertain or unavailable in the provided context, state that clearly and briefly without unnecessary hedging. Do not include generic disclaimers about lacking access unless the information is truly unavailable.
TXT;
$preamble = str_replace('{CURRENT_DATE}', $today, $preambleTemplate);

$body = [
  'query' => ['text' => $q],
  'session' => $session,
  'answerGenerationSpec' => [
    'promptSpec' => ['preamble' => $preamble],
    'includeCitations' => true,
    'ignoreLowRelevantContent' => false,
    'ignoreNonAnswerSeekingQuery' => false,
    'ignoreAdversarialQuery' => false,
    'ignoreJailBreakingQuery' => false,
  ],
  'userPseudoId' => (string) ($_COOKIE['vid'] ?? 'anon'),
];

// -----------------------------
// Auth (service account)
// -----------------------------
$saPath = (string) ($config['service_account_json'] ?? '');
if (!$saPath || !is_readable($saPath)) {
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

// -----------------------------
// Call API
// -----------------------------
$start = microtime(true);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json',
  ],
  CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_TIMEOUT => 25,
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
    'error' => 'Discovery Engine API error',
    'status' => $httpCode,
    'details' => $data,
    'meta' => ['elapsed_ms' => $elapsedMs],
  ], $httpCode);
}

// -----------------------------
// Extract + optional safety-net cleanup
// -----------------------------
$answerText = (string) ($data['answer']['answerText'] ?? '');
$answerText = tense_safety_net($answerText);

$citationsRaw = $data['answer']['citations'] ?? [];
$citations = normalize_citations($citationsRaw);
$relatedQuestions = normalize_related_questions($data['answer']['relatedQuestions'] ?? []);
$references = normalize_references($data['answer']['references'] ?? []);
$searchResults = extract_search_results($data);
if (!$searchResults && $references) {
  $searchResults = search_results_from_references($references);
}
$sessionOut = (string) (
  $data['session']
  ?? $data['sessionInfo']['name']
  ?? $data['answer']['session']
  ?? ''
);
if (is_summary_fallback_message($answerText)) {
  $answerText = format_search_results_fallback($searchResults);
}

// -----------------------------
// Log
// -----------------------------
$logLine = json_encode([
  'ts' => date('c'),
  'ip' => $ip,
  'q' => $q,
  'sid' => $sidRaw,
  'session' => $session,
  'session_out' => $sessionOut ?: null,
  'elapsed_ms' => $elapsedMs,
  'cache' => 'MISS',
  'http' => $httpCode,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($logDir . '/requests.log', $logLine . PHP_EOL, FILE_APPEND);

// -----------------------------
// Output + cache store
// -----------------------------
$out = [
  'answer' => $answerText,
  'citations' => $citations,
  'related_questions' => $relatedQuestions,
  'references' => $references,
  'search_results' => $searchResults,
  'meta' => [
    'elapsed_ms' => $elapsedMs,
    'cache' => 'MISS',
    'session' => $sessionOut ?: $session,
  ],
];

if (($config['debug']['enabled'] ?? false) && ($config['debug']['return_raw'] ?? false)) {
  $out['raw'] = $data;
}

if ($cacheEnabled) {
  $toCache = $out;
  $toCache['ts'] = time();
  write_json_file($cachePath, $toCache);
}

json_out($out, 200);
