<?php
require __DIR__ . '/../public/api/promotions.php';
function verify($value, $name) { if (!$value) throw new RuntimeException($name); }
function invalid($input) { try { promotion_validate($input); return false; } catch (InvalidArgumentException $e) { return true; } }
$r = ['id'=>'a', 'title'=>'Transcripts', 'url'=>'https://pasadena.edu/transcripts/', 'description'=>'Order a transcript.', 'keywords'=>['transcripts', 'records'], 'match_type'=>'exact', 'priority'=>1, 'enabled'=>true];
verify(count(promotion_matches([$r], '  TRANSCRIPTS  '))===1, 'Exact normalization');
verify(count(promotion_matches([$r], 'records'))===1, 'OR keywords');
verify(count(promotion_matches([$r], 'nothing'))===0, 'No match');
$c = array_replace($r, ['match_type'=>'contains']);
verify(count(promotion_matches([$c], 'How do I request TRANSCRIPTS?'))===1, 'Contains case');
verify(count(promotion_matches([$c], 'transcriptsXYZ'))===0, 'Word boundary');
$other = array_replace($r, ['id'=>'b','url'=>'https://pasadena.edu/records','priority'=>0]);
verify(promotion_matches([$r,$other], 'records')[0]['id']==='b', 'Priority');
verify(count(promotion_matches([$r,array_replace($r,['id'=>'b'])], 'records'))===1, 'Duplicate promoted URL');
verify(count(promotion_matches([array_replace($r,['enabled'=>false])], 'records'))===0, 'Disabled');
verify(count(promotion_matches([array_replace($r,['start_date'=>'2030-01-01T00:00:00Z'])], 'records', strtotime('2026-01-01')) )===0, 'Scheduled');
verify(count(promotion_matches([array_replace($r,['end_date'=>'2020-01-01T00:00:00Z'])], 'records'))===0, 'Expired');
verify(invalid(array_replace($r,['url'=>'javascript:alert(1)'])), 'URL protocol');
verify(invalid(array_replace($r,['match_type'=>'regex','keywords'=>['(a+)+$']])), 'Expensive regex');
verify(invalid(array_replace($r,['match_type'=>'regex','keywords'=>['[']])), 'Invalid regex');
verify(count(promotion_matches([array_replace($r,['match_type'=>'regex','keywords'=>['^records$']])], 'RECORDS'))===1, 'Safe regex');
verify(promotion_url_key('https://PASADENA.edu/records/#x')===promotion_url_key('https://pasadena.edu/records'), 'URL canonicalization');
verify(invalid(array_replace($r,['end_date'=>'2026-02-30T00:00'])), 'Invalid calendar date');
$token = promotion_form_token('admin:/manager', 'test-secret', 1800000000);
verify(promotion_form_token_valid($token, 'admin:/manager', 'test-secret', 1800000001), 'Valid form token');
verify(!promotion_form_token_valid($token, 'other:/manager', 'test-secret', 1800000001), 'Actor binding');
verify(!promotion_form_token_valid($token, 'admin:/other', 'test-secret', 1800000001), 'Page binding');
verify(!promotion_form_token_valid($token, 'admin:/manager', 'test-secret', 1800003600), 'Token expiry');
verify(!promotion_form_token_valid($token . 'x', 'admin:/manager', 'test-secret', 1800000001), 'Tampered token');
verify(!promotion_form_token_valid(null, 'admin:/manager', 'test-secret'), 'Missing token');
ob_start(); include __DIR__ . '/../public/promotions.php'; $body=ob_get_clean();
verify(http_response_code()===403 && $body==='', 'Unauthorized management denied');
echo "Promotion matching, validation, schedules, ordering, deduplication and unauthorized access passed.\n";
