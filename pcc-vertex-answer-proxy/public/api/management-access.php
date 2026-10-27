<?php
declare(strict_types=1);
require_once __DIR__ . '/promotions.php';
function pcc_admin_ticket(string $mode, string $actor, string $path, array $config): string {
  $claims = base64_encode(json_encode(['mode'=>$mode, 'actor'=>$actor, 'path'=>$path], JSON_THROW_ON_ERROR));
  $secret = load_service_account($config['service_account_json'])['private_key'];
  return $claims . ':' . promotion_form_token('admin-ajax:' . $claims, $secret);
}
function pcc_admin_claims($ticket, array $config): array {
  if (!is_string($ticket) || strlen($ticket)>8192) throw new RuntimeException('Invalid access token.');
  $parts=explode(':',$ticket,2);
  $secret=load_service_account($config['service_account_json'])['private_key'];
  if (count($parts)!==2 || !promotion_form_token_valid($parts[1], 'admin-ajax:'.$parts[0], $secret)) throw new RuntimeException('Expired access token.');
  $claims=json_decode((string) base64_decode($parts[0],true),true);
  if (!is_array($claims) || !in_array($claims['mode']??'', ['report','promotions'],true) || !is_string($claims['actor']??null) || !is_string($claims['path']??null)) throw new RuntimeException('Invalid access token.');
  return $claims;
}
