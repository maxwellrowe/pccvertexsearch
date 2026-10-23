<?php
declare(strict_types=1);
require_once __DIR__ . '/firestore.php';

function tracking_id(string $key, bool $generate = true): string {
  $value = (string) ($_POST[$key] ?? '');
  return preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $value) ? $value : ($generate ? bin2hex(random_bytes(16)) : '');
}

function tracking_context(): array {
  $bot = preg_match('/bot|crawler|spider|headless|curl|wget|python-requests/i', $_SERVER['HTTP_USER_AGENT'] ?? '') === 1;
  return [
    'schema_version' => 2,
    'session_id' => tracking_id('session_id'),
    'conversation_id' => tracking_id('conversation_id'),
    'query_id' => tracking_id('query_id'),
    'is_test' => ($_POST['is_test'] ?? '') === '1',
    'bot_suspected' => $bot,
    'bot_reason' => $bot ? 'user_agent_pattern' : '',
    'page_url' => tracking_page_url(),
  ];
}

function tracking_page_url(): string {
  $parts = parse_url((string) ($_POST['page_url'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
  if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) return '';
  return substr($parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/'), 0, 2048);
}

function tracking_logging_enabled(): bool {
  return ($_POST['logging'] ?? '') !== '0';
}

function tracking_write(array $payload, string $name): bool {
  if (!tracking_logging_enabled()) return true;
  global $config;
  $ok = true;
  if (($config['firestore_log']['enabled'] ?? false) === true) {
    $ok = firestore_tracking_write($payload, $name);
  }
  return $ok;
}

function tracking_finish(array &$data, int $code): void {
  global $trackingQuery;
  $data['meta']['logging'] = tracking_logging_enabled() ? 'enabled' : 'disabled';
  if (!isset($trackingQuery)) return;
  $row = $trackingQuery;
  unset($GLOBALS['trackingQuery']);
  $row['answer_id'] = $code < 400 ? substr(hash('sha256', 'answer:' . $row['query_id']), 0, 32) : '';
  $row['answer_timestamp'] = $code < 400 ? gmdate('Y-m-d\TH:i:s\Z') : '';
  $row['completed_timestamp'] = gmdate('Y-m-d\TH:i:s\Z');
  $row['status'] = $code < 400 ? 'success' : 'error';
  $row['http_status'] = $code;
  $row['answer'] = (string) ($data['answer'] ?? '');
  $row['error'] = (string) ($data['error'] ?? '');
  $row['reference_urls'] = reference_urls_for_log([], $data['references'] ?? [], []);
  $row['cache'] = $data['meta']['cache'] ?? '';
  $data['meta']['tracking'] = array_intersect_key($row, array_flip([
    'session_id', 'conversation_id', 'query_id', 'answer_id', 'is_test', 'bot_suspected',
  ]));
  // Form encoding uses 1/0 for boolean flags.
  $data['meta']['tracking']['is_test'] = $row['is_test'] ? '1' : '0';
  $saved = tracking_write($row, 'question_answer_log_v2');
  $data['meta']['logging_delivery'] = tracking_logging_enabled() ? ($saved ? 'completed' : 'failed') : 'skipped';
}
