<?php
// Run: php -n -d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close tests/dashboard-pagination.php
foreach (['CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLINFO_HTTP_CODE'] as $i => $constant) if (!defined($constant)) define($constant, $i);
function curl_init($url) { return true; }
function curl_setopt_array($ch, $options) { $GLOBALS['query'] = json_decode($options[CURLOPT_POSTFIELDS], true); }
function curl_exec($ch) { return json_encode($GLOBALS['documents']); }
function curl_getinfo($ch, $key) { return 200; }
function curl_close($ch) {}
require __DIR__ . '/../reports/dashboard-data.php';
function check($condition) { if (!$condition) throw new RuntimeException('Assertion failed'); }
$settings = ['project_id' => 'test', 'database_id' => 'test'];
$prefix = 'projects/test/databases/test/documents/question_answer_log_v2/';
$GLOBALS['documents'] = [];
for ($i = 0; $i < 2001; $i++) $GLOBALS['documents'][] = ['document' => ['name' => $prefix . $i, 'fields' => ['query_timestamp' => ['timestampValue' => '2026-01-01T00:00:00Z']]]];
$page = pcc_report_query($settings, 'token', 'question_answer_log_v2', 'query_timestamp', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z');
check(count($page['rows']) === 2000);
$cursor = json_decode(base64_decode($page['next']), true);
check($cursor['name'] === $prefix . '1999');
$GLOBALS['documents'] = [end($GLOBALS['documents'])];
$last = pcc_report_query($settings, 'token', 'question_answer_log_v2', 'query_timestamp', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z', $page['next']);
check($last['next'] === 'end' && count($last['rows']) === 1);
check($GLOBALS['query']['structuredQuery']['startAt']['before'] === false);
check($GLOBALS['query']['structuredQuery']['startAt']['values'][1]['referenceValue'] === $prefix . '1999');
check(count(pcc_report_query($settings, 'token', 'question_answer_log_v2', 'query_timestamp', '', '', 'end')['rows']) === 0);
echo "Pagination boundary, tied timestamps, exclusive cursor and exhausted collection passed.\n";
