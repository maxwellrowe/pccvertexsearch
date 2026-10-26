<?php
declare(strict_types=1);
require_once __DIR__ . '/google-auth.php';

function promotion_normalize(string $text): string {
  $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
  return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}
function promotion_regex(string $pattern): string {
  // Deliberately small regex subset: literals, anchors, character classes and alternation.
  // Reject repetition, groups, lookarounds and backreferences to avoid pathological work.
  if ($pattern === '' || strlen($pattern) > 200 || preg_match('/[()*+?{}]|\\\\[1-9gk]/', $pattern)) {
    throw new InvalidArgumentException('Regex supports literals, anchors, character classes and | alternatives; repetition and groups are not allowed.');
  }
  $regex = '~(*LIMIT_MATCH=10000)(*LIMIT_RECURSION=100)' . str_replace('~', '\\~', $pattern) . '~iu';
  if (@preg_match($regex, '') === false) throw new InvalidArgumentException('Invalid regular expression.');
  return $regex;
}
function promotion_validate(array $input): array {
  $r = [];
  foreach (['title' => 200, 'url' => 2048, 'description' => 1000] as $key => $limit) {
    if (!is_string($input[$key] ?? '')) throw new InvalidArgumentException('Invalid ' . $key . '.');
    $r[$key] = trim($input[$key] ?? '');
    if (strlen($r[$key]) > $limit) throw new InvalidArgumentException(ucfirst($key) . ' is too long.');
  }
  if ($r['title'] === '') throw new InvalidArgumentException('Title is required.');
  if (!filter_var($r['url'], FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($r['url'], PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) || parse_url($r['url'], PHP_URL_USER) !== null) throw new InvalidArgumentException('Enter a valid HTTP or HTTPS URL without credentials.');
  $r['match_type'] = $input['match_type'] ?? 'exact';
  if (!in_array($r['match_type'], ['exact', 'contains', 'regex'], true)) throw new InvalidArgumentException('Invalid match type.');
  $keywords = $input['keywords'] ?? [];
  if (is_string($keywords)) $keywords = preg_split('/\R/u', $keywords);
  if (!is_array($keywords) || count($keywords) > 30) throw new InvalidArgumentException('Use 1–30 keywords, one per line.');
  $r['keywords'] = [];
  foreach ($keywords as $keyword) {
    if (!is_string($keyword) || strlen($keyword) > 200) throw new InvalidArgumentException('Each keyword must be at most 200 bytes.');
    $keyword = trim($keyword);
    if ($keyword === '') continue;
    if ($r['match_type'] === 'regex') promotion_regex($keyword);
    $r['keywords'][] = $keyword;
  }
  $r['keywords'] = array_values(array_unique($r['keywords']));
  if (!$r['keywords']) throw new InvalidArgumentException('At least one keyword is required.');
  $priority = filter_var($input['priority'] ?? 0, FILTER_VALIDATE_INT);
  if ($priority === false || abs($priority) > 10000) throw new InvalidArgumentException('Priority must be an integer between -10000 and 10000.');
  $r['priority'] = $priority;
  $r['enabled'] = in_array($input['enabled'] ?? false, [true, '1', 1], true);
  foreach (['start_date', 'end_date'] as $key) {
    $value = $input[$key] ?? null;
    if ($value === null || $value === '') { $r[$key] = null; continue; }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) throw new InvalidArgumentException('Invalid date.');
    $date = new DateTimeImmutable($value, new DateTimeZone('America/Los_Angeles'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('Invalid date.');
    $r[$key] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
  }
  if ($r['start_date'] && $r['end_date'] && $r['end_date'] <= $r['start_date']) throw new InvalidArgumentException('End date must be after start date.');
  return $r;
}
function promotion_matches(array $rules, string $query, ?int $now = null): array {
  $query = promotion_normalize($query);
  if ($query === '' || strlen($query) > 2000) return [];
  $now ??= time();
  $matches = [];
  foreach ($rules as $rule) {
    try { $r = promotion_validate($rule); } catch (Throwable $e) { continue; }
    if (!$r['enabled'] || ($r['start_date'] && strtotime($r['start_date']) > $now) || ($r['end_date'] && strtotime($r['end_date']) <= $now)) continue;
    foreach ($r['keywords'] as $keyword) {
      $word = promotion_normalize($keyword);
      $hit = $r['match_type'] === 'exact' ? $query === $word : ($r['match_type'] === 'contains'
        ? preg_match('~(?<![\pL\pN])' . preg_quote($word, '~') . '(?![\pL\pN])~u', $query) === 1
        : @preg_match(promotion_regex($keyword), $query) === 1);
      if ($hit) { $matches[] = $r + ['id' => $rule['id'] ?? '']; break; }
    }
  }
  usort($matches, static fn($a, $b) => ($a['priority'] <=> $b['priority']) ?: strcmp($a['title'], $b['title']) ?: strcmp($a['id'], $b['id']));
  $seen = [];
  return array_values(array_filter($matches, static function($r) use (&$seen) {
    $key = promotion_url_key($r['url']);
    if (isset($seen[$key])) return false;
    $seen[$key] = true; return true;
  }));
}
function promotion_url_key(string $url): string {
  $p = parse_url($url);
  if (!$p) return $url;
  return strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($p['path'] ?? '', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
}
function promotion_decode(array $doc): array {
  $r = ['id' => basename($doc['name'])];
  foreach ($doc['fields'] ?? [] as $key => $value) {
    if (isset($value['arrayValue'])) $r[$key] = array_map(static fn($v) => $v['stringValue'] ?? '', $value['arrayValue']['values'] ?? []);
    else $r[$key] = $value['stringValue'] ?? $value['timestampValue'] ?? $value['booleanValue'] ?? (isset($value['integerValue']) ? (int) $value['integerValue'] : null);
  }
  return $r;
}
function promotion_request(array $config, string $token, string $method, string $suffix = '', ?array $body = null, int $timeout = 3000): array {
  $s = $config['firestore_log'];
  $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($s['project_id']) . '/databases/' . rawurlencode($s['database_id']) . '/documents/promoted_searches' . $suffix;
  $ch = curl_init($url);
  $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT_MS => min(500, $timeout), CURLOPT_TIMEOUT_MS => $timeout];
  if ($body !== null) $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
  curl_setopt_array($ch, $options);
  $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('Promotion storage request failed.');
  return $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
function promotion_cache_key(array $config): string {
  return 'pcc-promotions-v1-' . hash('sha256', $config['firestore_log']['project_id'] . '/' . $config['firestore_log']['database_id']);
}
function promotion_cache_available(): bool { return function_exists('apcu_enabled') && apcu_enabled(); }
function promotion_rules(array $config, string $token, bool $public = false): array {
  $key = promotion_cache_key($config);
  if ($public && promotion_cache_available()) { $cached = apcu_fetch($key, $ok); if ($ok) return $cached; }
  $rows = []; $page = ''; $deadline = microtime(true) + ($public ? 0.8 : 10);
  do {
    $remaining = (int) (($deadline - microtime(true)) * 1000);
    if ($remaining < 1) throw new RuntimeException('Promotion read timeout.');
    $data = promotion_request($config, $token, 'GET', '?pageSize=1000' . ($page ? '&pageToken=' . rawurlencode($page) : ''), null, $remaining);
    foreach ($data['documents'] ?? [] as $doc) $rows[] = promotion_decode($doc);
    $page = $data['nextPageToken'] ?? '';
    if (count($rows) > 5000) throw new RuntimeException('Too many promotion rules.');
  } while ($page);
  if (promotion_cache_available()) apcu_store($key, $rows, 600);
  return $rows;
}
function promotion_save(array $config, string $token, string $id, array $input, string $actor): void {
  $r = promotion_validate($input);
  $now = gmdate('Y-m-d\TH:i:s\Z');
  $r['updated_at'] = $now; $r['updated_by'] = $actor;
  if ($id === '') { $r['created_at'] = $now; $r['created_by'] = $actor; }
  else promotion_id($id);
  $fields = [];
  foreach ($r as $key => $v) {
    $fields[$key] = $v === null ? ['nullValue' => null] : (is_bool($v) ? ['booleanValue' => $v] : (is_int($v) ? ['integerValue' => (string) $v] : (is_array($v) ? ['arrayValue' => ['values' => array_map(static fn($x) => ['stringValue' => $x], $v)]] : [in_array($key, ['start_date', 'end_date', 'created_at', 'updated_at']) ? 'timestampValue' : 'stringValue' => $v])));
  }
  $suffix = $id === '' ? '' : '/' . rawurlencode($id) . '?currentDocument.exists=true&' . implode('&', array_map(static fn($k) => 'updateMask.fieldPaths=' . rawurlencode($k), array_keys($fields)));
  promotion_request($config, $token, $id === '' ? 'POST' : 'PATCH', $suffix, ['fields' => $fields]);
  if (promotion_cache_available()) apcu_delete(promotion_cache_key($config));
}
function promotion_id(string $id): void {
  if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) throw new InvalidArgumentException('Invalid promotion ID.');
}
function promotion_public(array $config, string $token, string $query): array {
  try {
    return array_map(static fn($r) => ['title' => $r['title'], 'uri' => $r['url'], 'description' => $r['description'], 'promoted' => true], promotion_matches(promotion_rules($config, $token, true), $query));
  } catch (Throwable $e) { error_log('Promoted search unavailable; continuing with organic results.'); return []; }
}
