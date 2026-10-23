<?php
declare(strict_types=1);

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

function fetch_service_account_access_token(array $serviceAccount, string $scope): string {
  $now = time();

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
  if ($accessToken === '') {
    throw new RuntimeException('OAuth token response missing access_token.');
  }

  return $accessToken;
}
