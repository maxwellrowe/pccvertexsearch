"""Exercise PHP endpoints in an isolated copy; no network or production logs."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid

BASE = Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory(prefix='pcc-tracking-') as tmp:
    root = Path(tmp)
    (root / 'public/api').mkdir(parents=True)
    (root / 'config').mkdir()
    (root / 'storage/cache').mkdir(parents=True)
    for name in ['answer.php', 'feedback.php', 'tracking.php']:
        shutil.copy(BASE / 'public/api' / name, root / 'public/api' / name)
    (root / 'config/config.php').write_text("<?php return ['rate'=>['enabled'=>false], 'google_sheets_log'=>['enabled'=>false], 'project_id'=>'test', 'location'=>'us', 'engine_id'=>'test'];")
    runner = root / 'run.php'
    runner.write_text("""<?php
$_POST = json_decode($argv[2], true);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'curl-test';
$_SERVER['REMOTE_ADDR'] = '192.0.2.123';
include __DIR__ . '/public/api/' . $argv[1] . '.php';
""")

    def request(endpoint, body):
        result = subprocess.run(['php', str(runner), endpoint, json.dumps(body)], text=True, capture_output=True, check=True)
        assert not result.stderr, result.stderr
        return json.loads(result.stdout)

    def rows(name):
        return [json.loads(line) for line in (root / f'storage/logs/{name}.jsonl').read_text().splitlines()]

    context = {key: str(uuid.uuid4()) for key in ['session_id', 'conversation_id', 'query_id']}
    context.update(page_url='https://pasadena.edu/ask?private=value#fragment', is_test='1')
    error = request('answer', dict(context, q=''))
    assert error['error'] and not error['meta']['tracking']['answer_id']
    assert rows('question_answer_log_v2')[0]['status'] == 'error'

    # Seed an actual cache HIT, including another requester's Vertex session.
    material = {'q':'How do I enroll?', 'sid':'-', 'project_id':'test', 'location':'us', 'engine_id':'test',
                'prompt_version':'2026-06-18-approved-routing-urls-v2'}
    key = hashlib.sha256(json.dumps(material, separators=(',', ':')).encode()).hexdigest()
    cache = {'ts':int(time.time()), 'answer':'Visit admissions.', 'references':[{'uri':'https://pasadena.edu/admissions/'},
             {'uri':'https://pasadena.edu/admissions/'}], 'meta':{'session':'another-visitor'}}
    (root / f'storage/cache/{key}.json').write_text(json.dumps(cache))
    answers = []
    for _ in range(2):
        context['query_id'] = str(uuid.uuid4())
        answers.append(request('answer', dict(context, q=material['q'])))
    assert answers[0]['meta']['tracking']['answer_id'] != answers[1]['meta']['tracking']['answer_id']
    assert answers[0]['meta']['session'] == ''
    log = rows('question_answer_log_v2')[-1]
    assert log['reference_urls'] == 'https://pasadena.edu/admissions/'
    assert log['page_url'] == 'https://pasadena.edu/ask'
    assert log['is_test'] and log['bot_suspected']
    assert log['query_timestamp'].endswith('Z') and log['answer_timestamp'].endswith('Z')

    feedback = dict(answers[-1]['meta']['tracking'], page_url=context['page_url'])
    rating_id = str(uuid.uuid4())
    for rating in ['up', 'down']:
        assert request('feedback', dict(feedback, rating=rating, event_id=rating_id))['ok']
        saved_rating_id = rating_id
        rating_id = str(uuid.uuid4())
    assert request('feedback', dict(feedback, event_type='feedback_comment', event_id=str(uuid.uuid4()),
                                   parent_event_id=saved_rating_id, feedback='More details'))['ok']
    assert request('feedback', dict(feedback, event_type='escalation', event_id=str(uuid.uuid4())))['ok']
    events = rows('interaction_events')
    assert [r['rating'] for r in events] == ['up', 'down', '', '']
    assert events[-1]['destination_url'] == 'https://pasadena.edu/student-services/get-help/index.php'
    assert request('feedback', {'rating':'down'})['error']
    assert len(rows('interaction_events')) == 4
    for name in ['question_answer_log_v2', 'interaction_events']:
        assert '192.0.2.123' not in (root / f'storage/logs/{name}.jsonl').read_text()
    assert not (root / 'storage/logs/question_log.csv').exists()
    print('PHP integration passed: errors, cache IDs, references, both ratings, comments, escalation, flags, and no IPs.')
