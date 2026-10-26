<?php
// php -d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close tests/promotions-storage.php
require __DIR__ . '/../public/api/promotions.php';
function curl_init($url) { return (object) ['url'=>$url]; }
function curl_setopt_array($ch,$options) { $ch->options=$options; }
function curl_exec($ch) { $GLOBALS['requests'][]=$ch; return $GLOBALS['response'] ?? '{}'; }
function curl_getinfo($ch,$option) { return $GLOBALS['status'] ?? 200; }
function curl_close($ch) {}
function apcu_enabled() { return true; }
function apcu_fetch($key,&$ok) { $ok=array_key_exists($key,$GLOBALS['cache']??[]); return $GLOBALS['cache'][$key]??false; }
function apcu_store($key,$value,$ttl) { $GLOBALS['cache'][$key]=$value; $GLOBALS['ttl']=$ttl; }
function apcu_delete($key) { unset($GLOBALS['cache'][$key]); }
function verify($ok,$name) { if (!$ok) throw new RuntimeException($name); }
$c=['firestore_log'=>['project_id'=>'test','database_id'=>'test']];
$r=['title'=>'Example','url'=>'https://pasadena.edu/example','keywords'=>['example'],'match_type'=>'exact','priority'=>1,'enabled'=>true];
$GLOBALS['response']='{"documents":[]}';
promotion_rules($c,'token',true); promotion_rules($c,'token',true);
verify(count($GLOBALS['requests'])===1 && $GLOBALS['ttl']===600,'Cache hit');
promotion_save($c,'token','',$r,'admin@example.edu');
$req=end($GLOBALS['requests']); $fields=json_decode($req->options[CURLOPT_POSTFIELDS],true)['fields'];
verify($req->options[CURLOPT_CUSTOMREQUEST]==='POST' && !str_contains($req->url,'documentId'),'Firestore generated ID');
verify($fields['created_by']['stringValue']==='admin@example.edu' && isset($fields['created_at']['timestampValue']),'Creation audit');
verify(!isset($GLOBALS['cache'][promotion_cache_key($c)]),'Cache invalidated');
promotion_rules($c,'token',true);
verify(count($GLOBALS['requests'])===3,'Cache refresh after edit');
promotion_save($c,'token','existing',$r,'editor@example.edu');
$req=end($GLOBALS['requests']); $fields=json_decode($req->options[CURLOPT_POSTFIELDS],true)['fields'];
verify(!isset($fields['created_at']) && $fields['updated_by']['stringValue']==='editor@example.edu','Preserve creator');
verify(str_contains($req->url,'currentDocument.exists=true'),'Do not recreate deleted documents');
$GLOBALS['status']=503;
verify(promotion_public($c,'token','example')===[],'Storage failure is fail-open');
echo "Promotion cache, refresh, generated IDs, audit metadata and unavailable storage passed.\n";
