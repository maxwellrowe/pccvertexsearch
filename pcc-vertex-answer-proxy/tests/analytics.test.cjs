const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const base = path.join(__dirname, '..');

function browser() {
  let now = new Date('2026-09-09T12:00:00Z').getTime();
  const storage = new Map(), requests = [];
  class Clock extends Date { constructor(value = now) { super(value); } static now() { return now; } }
  const context = { window: {}, crypto: { randomUUID }, URL, URLSearchParams, Date: Clock,
    location: { origin: 'https://pasadena.edu', pathname: '/ask', search: '?pcc_test=1&private=value' },
    localStorage: { getItem: k => storage.get(k), setItem: (k,v) => storage.set(k,v) },
    fetch: async (url, options) => { requests.push(options); return { ok: true, json: async () => ({ok:true}) }; } };
  vm.runInNewContext(fs.readFileSync(path.join(base, 'public/analytics.js'), 'utf8'), context);
  return { create: () => context.window.PccAnalytics.create('/feedback'), requests,
    advance: n => { now += n; }, context };
}

test('session survives navigation, expires at 30 minutes, and resets conversation independently', () => {
  const b = browser(), a = b.create();
  const first = a.begin(false, 'How do I enroll in classes?');
  a.complete(first.payload, 'vertex-session');
  b.advance(29 * 60 * 1000);
  const next = b.create().begin(false, 'How do I enroll in classes now?');
  assert.equal(first.payload.session_id, next.payload.session_id);
  assert.equal(next.payload.previous_query_id, first.payload.query_id);
  assert.equal(next.payload.rephrase_status, 'suspected');
  assert.equal(next.sid, 'vertex-session');
  a.newChat();
  const fresh = a.begin();
  assert.equal(fresh.payload.session_id, first.payload.session_id);
  assert.notEqual(fresh.payload.conversation_id, first.payload.conversation_id);
  assert.equal(fresh.payload.turn_number, '1');
  assert.equal(fresh.sid, '');
  b.advance(30 * 60 * 1000);
  assert.notEqual(a.begin().payload.session_id, first.payload.session_id);
  const beforeDay = a.begin();
  b.advance(24 * 60 * 60 * 1000);
  assert.notEqual(a.begin().payload.session_id, beforeDay.payload.session_id);
});

test('storage unavailable, suggested follow-up, event linkage, and help clicks', async () => {
  const b = browser();
  b.context.localStorage.getItem = () => { throw Error('blocked'); };
  b.context.localStorage.setItem = () => { throw Error('blocked'); };
  const a = b.create(), first = a.begin();
  assert.equal(a.begin(true).payload.query_kind, 'suggested_follow_up');
  assert.equal(a.begin().payload.session_id, first.payload.session_id);
  const tracking = { ...first.payload, answer_id: randomUUID() };
  const event = await a.event(tracking, 'feedback', {rating: 'down'});
  await a.event(tracking, 'feedback_comment', {feedback: 'Details', parent_event_id: event});
  assert.equal(b.requests[1].body.get('parent_event_id'), event);
  assert.equal(b.requests[0].body.get('page_url'), 'https://pasadena.edu/ask');
  assert.equal(b.requests[0].body.get('is_test'), '1');
  const handlers = {};
  a.watchHelp({addEventListener: (type, fn) => { handlers[type] = fn; }}, () => tracking);
  handlers.click({type: 'click', target: {closest: () => ({href:'https://pasadena.edu/student-services/get-help/index.php'})}});
  assert.equal(b.requests[2].body.get('event_type'), 'escalation');
  assert.equal(b.requests[2].keepalive, true);
  handlers.click({type: 'click', target: {closest: () => ({href:'https://example.com/'})}});
  assert.equal(b.requests.length, 3);
});

for (const file of ['embed.js', 'index.php']) {
  test(`${file}: rating click saves before optional comment and locks both rating buttons`, async () => {
    const source = fs.readFileSync(path.join(base, 'public', file), 'utf8');
    const names = ['setFeedbackLoading', 'lockFeedbackChoice', 'submitFeedback', 'showFeedbackThanks',
      'handlePositiveFeedback', 'handleNegativeFeedbackStart', 'handleNegativeFeedbackSubmit'];
    const declarations = names.map(name => {
      const start = source.search(new RegExp('(?:async )?function ' + name + '\\('));
      const rest = source.slice(start);
      const end = rest.slice(1).search(/\n\s*(?:async )?function /);
      return rest.slice(0, end + 1);
    }).join('\n');
    const elements = new Map();
    const node = name => {
      if (!elements.has(name)) elements.set(name, { disabled:false, value:'', focus() {},
        classList: {add() {}, remove() {}} });
      return elements.get(name);
    };
    const events = [];
    const c = {crypto: {randomUUID}, currentFeedbackContext: {tracking:{answer_id:randomUUID()}},
      tracker: {event: async (...args) => {events.push(args);}},
      $: node, el: new Proxy({}, {get: (_, name) => node(name)}),
      setError: message => {throw Error(message);}, showError: message => {throw Error(message);} };
    vm.createContext(c);
    vm.runInContext(declarations, c);
    await c.handleNegativeFeedbackStart();
    assert.equal(events.length, 1);
    assert.equal(events[0][1], 'feedback');
    assert.equal(events[0][2].rating, 'down');
    const input = node(file === 'embed.js' ? 'feedbackInput' : '#feedbackInput');
    input.value = 'Helpful details';
    await c.handleNegativeFeedbackSubmit({preventDefault() {}});
    assert.equal(events[1][1], 'feedback_comment');
    assert.equal(events[1][2].parent_event_id, events[0][3]);
    assert.equal(node(file === 'embed.js' ? 'feedbackPositive' : '#feedbackPositiveBtn').disabled, true);
    assert.equal(node(file === 'embed.js' ? 'feedbackNegative' : '#feedbackNegativeBtn').disabled, true);
    c.currentFeedbackContext = {tracking:{answer_id:randomUUID()}};
    await c.handlePositiveFeedback();
    assert.equal(events[2][2].rating, 'up');
  });
}

test('logging=0 propagates to answer requests and suppresses events without breaking sessions', async () => {
  const b = browser();
  b.context.location.search = '?timings=1&logging=0';
  const tracker = b.create();
  const request = tracker.begin(false, 'Test');
  assert.equal(tracker.loggingEnabled, false);
  assert.equal(request.payload.logging, '0');
  tracker.complete(request.payload, 'test-session');
  assert.equal(tracker.begin().sid, 'test-session');
  await tracker.event(request.payload, 'feedback', { rating: 'up' });
  await tracker.event(request.payload, 'escalation');
  assert.equal(b.requests.length, 0);
  b.context.location.search = '';
  const normal = b.create();
  assert.equal(normal.loggingEnabled, true);
  assert.equal(normal.begin().payload.logging, '1');
  await normal.event(request.payload, 'feedback', { rating: 'up' });
  assert.equal(b.requests.length, 1);
});
