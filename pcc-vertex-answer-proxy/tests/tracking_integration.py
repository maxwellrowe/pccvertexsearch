"""Exercise stateless PHP endpoints with mocked HTTP and filesystem writes forbidden."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import uuid

BASE = Path(__file__).resolve().parents[1]
MOCKS = 'curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close,openssl_sign,mkdir,file_put_contents,fopen,file_get_contents'
with tempfile.TemporaryDirectory(prefix='pcc-stateless-') as tmp:
    root = Path(tmp)
    (root / 'public/api').mkdir(parents=True)
    (root / 'config').mkdir()
    shutil.copy(BASE / 'config/firestore-schema.json', root / 'config/firestore-schema.json')
    for source in (BASE / 'public/api').glob('*.php'):
        shutil.copy(source, root / 'public/api' / source.name)
    (root / 'config/service.json').write_text(json.dumps({'client_email':'test@example.invalid', 'private_key':'mock', 'token_uri':'https://oauth.invalid/token'}))
    (root / 'config/config.php').write_text("""<?php return [
      'project_id'=>'test', 'location'=>'us', 'engine_id'=>'test', 'api_host'=>'https://vertex.invalid',
      'service_account_json'=>__DIR__.'/service.json',
      'firestore_log'=>['enabled'=>true,'project_id'=>'test','database_id'=>'pccaisearch','environment'=>'test'],
      'scheduler'=>['secret'=>'test-secret'], 'data_store_id'=>'test-store',
      'recrawl_api_host'=>'https://vertex.invalid', 'recrawl'=>['jobs'=>['daily'=>['https://pasadena.edu/']]]
    ];""")
    runner = root / 'run.php'
    runner.write_text("""<?php
function mkdir(...$args) { throw new RuntimeException('Filesystem write attempted'); }
function file_put_contents(...$args) { throw new RuntimeException('Filesystem write attempted'); }
function fopen(...$args) { throw new RuntimeException('Filesystem write attempted'); }
function file_get_contents($path) { return $path==='php://input' ? json_encode($_POST) : implode('',file($path)); }
function openssl_sign($data, &$signature, $key, $algorithm) { $signature='mock'; return true; }
function curl_init($url) { return (object)['url'=>$url,'options'=>[]]; }
function curl_setopt_array($ch,$options) { $ch->options=$options; return true; }
function curl_exec($ch) {
  $GLOBALS['calls'][]=$ch->url;
  if (str_contains($ch->url,'firestore.googleapis.com')) {
    $GLOBALS['firestore'][]=['url'=>$ch->url,'body'=>json_decode($ch->options[CURLOPT_POSTFIELDS],true)];
    $status=(int) ($_POST['_test_firestore_status']??200);
    return $status===409 ? '{"error":{"status":"ALREADY_EXISTS"}}' : ($status===200 ? '{"name":"projects/test/databases/pccaisearch/documents/test/test"}' : '{"error":{"status":"PERMISSION_DENIED"}}');
  }
  if (str_contains($ch->url,'oauth.invalid')) return '{"access_token":"mock-token","expires_in":3600}';
  $GLOBALS['vertex_body']=json_decode($ch->options[CURLOPT_POSTFIELDS],true);
  if (str_contains($ch->url,':recrawlUris')) return '{"name":"operations/test","done":false}';
  return json_encode(['answer'=>['answerText'=>'Visit admissions.', 'references'=>[
    ['unstructuredDocumentInfo'=>['title'=>'Admissions','uri'=>'https://pasadena.edu/admissions/']]]],
    'session'=>'projects/test/session']);
}
function curl_getinfo($ch,$option=null) {
  if ($option===CURLINFO_HTTP_CODE) return str_contains($ch->url,'firestore.googleapis.com') ? (int) ($_POST['_test_firestore_status']??200) : 200;
  if ($option!==null) return 0.01;
  return ['total_time'=>0.1,'namelookup_time'=>0.001,'connect_time'=>0.005,'pretransfer_time'=>0.02,'starttransfer_time'=>0.09];
}
function curl_errno($ch) { return 0; }
function curl_error($ch) { return ''; }
function curl_close($ch) {}
register_shutdown_function(function(){fwrite(STDERR,json_encode([
  'firestore'=>$GLOBALS['firestore']??[], 'calls'=>$GLOBALS['calls']??[], 'vertex_body'=>$GLOBALS['vertex_body']??null]));});
$_POST=json_decode($argv[2],true);
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_USER_AGENT']='curl-test';
$_SERVER['REMOTE_ADDR']='192.0.2.123';
$_SERVER['HTTP_X_SCHEDULER_SECRET']=$_POST['_test_secret']??'';
include __DIR__.'/public/api/'.$argv[1].'.php';
""")
    def request(endpoint, body):
        result = subprocess.run(['php','-d','disable_functions='+MOCKS,str(runner),endpoint,json.dumps(body)], text=True,capture_output=True,check=True)
        return json.loads(result.stdout), json.loads(result.stderr.splitlines()[-1])

    context = {key:str(uuid.uuid4()) for key in ['session_id','conversation_id','query_id']}
    context.update(page_url='https://pasadena.edu/ask?private=value',is_test='1')
    error, trace = request('answer',dict(context,q=''))
    assert error['error'] and not error['meta']['tracking']['answer_id']
    assert trace['firestore'][0]['body']['fields']['status']['stringValue']=='error'
    answers=[]
    for _ in range(2):
        context['query_id'] = str(uuid.uuid4())
        answer, trace = request('answer',dict(context,q='How do I enroll?',debug_timings='1'))
        answers.append(answer)
        assert answer['meta']['cache']=='DISABLED'
        assert answer['meta']['logging_delivery']=='completed'
        stored=trace['firestore'][0]
        assert '/databases/pccaisearch/documents/question_answer_log_v2?' in stored['url']
        assert 'documentId='+context['query_id'] in stored['url']
        assert stored['body']['fields']['is_test']=={'booleanValue':True}
        assert 'timestampValue' in stored['body']['fields']['query_timestamp']
        assert 'arrayValue' in stored['body']['fields']['reference_urls']
        assert sum('oauth.invalid' in call for call in trace['calls'])==1
        assert sum('vertex.invalid' in call for call in trace['calls'])==1
        assert trace['vertex_body']['searchSpec']['searchParams']['maxReturnResults']==20
        assert trace['firestore'][0]['body']['fields']['page_url']['stringValue']=='https://pasadena.edu/ask'
        assert '192.0.2.123' not in json.dumps(trace['firestore'])
        assert not {'cache_lookup','cache_write','setup_validation_rate_limit'} & answer['meta']['timings']['stages_ms'].keys()
    assert answers[0]['meta']['tracking']['answer_id']!=answers[1]['meta']['tracking']['answer_id']
    _, trace = request('answer',dict(context,q='How do I enroll?',logging='0'))
    assert not trace['firestore']
    feedback=dict(answers[-1]['meta']['tracking'],event_id=str(uuid.uuid4()))
    for rating in ['up','down']:
        result,trace=request('feedback',dict(feedback,rating=rating))
        assert result['ok'] and trace['firestore'][0]['body']['fields']['rating']['stringValue']==rating
    result,trace=request('feedback',dict(feedback,event_type='feedback_comment',parent_event_id=str(uuid.uuid4()),feedback='Details'))
    assert result['ok'] and trace['firestore'][0]['body']['fields']['feedback']['stringValue']=='Details'
    result,trace=request('feedback',dict(feedback,event_type='escalation'))
    assert result['ok'] and trace['firestore'][0]['body']['fields']['event_type']['stringValue']=='escalation'
    result,_=request('feedback',{'rating':'down'})
    assert result['error']
    for status in [409,403]:
        result,trace=request('feedback',dict(feedback,rating='up',_test_firestore_status=status))
        assert bool(result.get('ok')) == (status==409)
    result,trace=request('answer',dict(context,q='Test',_test_firestore_status=403))
    assert result.get('answer') and result['meta']['logging_delivery']=='failed'
    result,trace=request('recrawl',{})
    assert result['error']=='Unauthorized.' and not trace['calls']
    result,trace=request('recrawl',{'job':'daily','_test_secret':'test-secret'})
    assert not result.get('error'), result
    assert any('oauth.invalid' in call for call in trace['calls'])
    assert any(':recrawlUris' in call for call in trace['calls'])
    assert not (root/'storage').exists()
    print('Stateless PHP integration passed: fresh tokens/answers, Firestore delivery, logging bypass, feedback, validation, no filesystem writes.')
